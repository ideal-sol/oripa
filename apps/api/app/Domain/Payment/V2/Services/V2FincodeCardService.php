<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class V2FincodeCardService
{
    private const MAX_CARDS = 3;

    private const LIVE_REGISTRATION_STATUSES = [
        'reserved',
        'starting',
        'requires_action',
        'pending',
    ];

    private const TERMINAL_REGISTRATION_STATUSES = [
        'completed',
        'failed',
        'expired',
        'canceled',
    ];

    private const PROVIDER_PAYMENT_METHOD_STATUSES = [
        'INACTIVATED',
        'AWAITING_CUSTOMER_ACTION',
        'ACTIVATED',
        'FAILED',
    ];

    private const PROVIDER_TDS2_STATUSES = [
        'AUTHENTICATING',
        'CHALLENGE',
        'AUTHENTICATED',
    ];

    public function __construct(
        private readonly V2FincodeClient $client,
        private readonly V2FincodePublicConfiguration $configuration,
        private readonly V2FincodeReturnUrl $returns,
        private readonly V2AuditLogService $audit
    ) {
    }

    /** @return array<string, mixed> */
    public function cards(User $user): array
    {
        $storedCards = $this->storedCardCount((int) $user->id);
        $capacity = $this->registrationCapacity((int) $user->id);

        return [
            'data' => DB::table('fincode_cards')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->orderByRaw('last_used_at DESC NULLS LAST')
                ->orderByDesc('id')
                ->get()
                ->map(fn (object $card): array => $this->presentCard($card))
                ->all(),
            'limits' => [
                'maximum' => self::MAX_CARDS,
                'remaining' => max(0, self::MAX_CARDS - $storedCards),
                'registration_remaining' => $capacity['remaining'],
                'next_capacity_at' => $capacity['next_capacity_at'],
            ],
        ];
    }

    /**
     * Legacy non-3DS reservation retained only for package compatibility.
     *
     * @return array<string, mixed>
     */
    public function reserveRegistration(User $user, string $idempotencyKey): array
    {
        $bootstrap = $this->configuration->bootstrap();
        $customer = $this->customer($user);
        $keyHash = hash('sha256', $idempotencyKey);

        $intent = DB::transaction(function () use ($user, $customer, $keyHash): object {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('fincode_card_registration_intents')
                ->where('idempotency_key_hash', $keyHash)
                ->first();
            if ($existing !== null) {
                if ((int) $existing->user_id !== (int) $user->id || $existing->flow_type !== 'legacy') {
                    throw new V2FincodeException(
                        'IDEMPOTENCY_KEY_REUSED',
                        409,
                        'The idempotency key was already used.'
                    );
                }

                return $existing;
            }
            $this->expireUserRegistrations((int) $user->id);
            $cards = $this->storedCardCount((int) $user->id);
            $reservations = $this->liveRegistrationCount((int) $user->id);
            if ($cards + $reservations >= self::MAX_CARDS) {
                throw $this->cardLimitReached();
            }
            $publicId = (string) Str::uuid7();
            DB::table('fincode_card_registration_intents')->insert([
                'public_id' => $publicId,
                'user_id' => $user->id,
                'fincode_customer_id' => $customer->id,
                'idempotency_key_hash' => $keyHash,
                'flow_type' => 'legacy',
                'status' => 'reserved',
                'expires_at' => $this->registrationExpiry(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('fincode_card_registration_intents')
                ->where('public_id', $publicId)
                ->firstOrFail();
        });

        return [
            'id' => $intent->public_id,
            'expires_at' => $this->timestamp($intent->expires_at),
            'provider_context' => [
                'provider' => 'fincode',
                'customer_id' => $customer->provider_customer_id,
                'public_api_key' => $bootstrap['public_api_key'],
                'tds_type' => '2',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function completeRegistration(
        User $user,
        string $intentPublicId,
        string $providerCardId
    ): array {
        throw new V2FincodeException(
            'CARD_REGISTRATION_3DS_REQUIRED',
            409,
            'Card registration requires canonical 3D Secure 2.0 verification.'
        );
    }

    /** @return array<string, mixed> */
    public function startRegistration(
        User $user,
        #[SensitiveParameter] string $cardToken,
        string $idempotencyKey
    ): array {
        $this->configuration->bootstrap();
        $customer = $this->customer($user);
        $prepared = $this->prepareRegistrationStart($user, $customer, $idempotencyKey);
        $intent = $prepared['intent'];
        if (! $prepared['call_provider']) {
            return $this->replayRegistrationStart($intent);
        }

        try {
            $response = $this->client->createCardPaymentMethod(
                (string) $customer->provider_customer_id,
                $cardToken,
                $this->returns->providerCardRegistrationNormal((string) $intent->public_id),
                $this->returns->providerCardRegistrationFailure((string) $intent->public_id),
                (string) $intent->public_id,
                (string) $intent->provider_idempotency_key
            );
            $provider = $this->safePaymentMethod(
                $response,
                null,
                (string) $customer->provider_customer_id
            );
            $intent = $this->applyRegistrationStartResponse((int) $intent->id, $provider);
        } catch (V2FincodeException $exception) {
            $this->applyRegistrationStartFailure((int) $intent->id, $exception);
            throw $exception->retryable
                ? $this->registrationUnavailable()
                : $this->registrationFailed();
        }

        if ($intent->status === 'failed') {
            throw $this->registrationFailed();
        }

        return $this->presentRegistration($intent);
    }

    /** @return array<string, mixed> */
    public function registration(User $user, string $registrationPublicId): array
    {
        $this->expireRegistrationByPublicId($registrationPublicId, (int) $user->id);
        $intent = $this->ownedRegistration($user, $registrationPublicId);

        return $this->presentRegistration($intent);
    }

    /** @return array<string, mixed> */
    public function reconcileRegistration(User $user, string $registrationPublicId): array
    {
        $intent = $this->ownedRegistration($user, $registrationPublicId);

        return $this->presentRegistration($this->reconcileIntent((int) $intent->id));
    }

    /** @return array<string, mixed> */
    public function cancelRegistration(User $user, string $registrationPublicId): array
    {
        $intent = DB::transaction(function () use ($user, $registrationPublicId): object {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $locked = DB::table('fincode_card_registration_intents')
                ->where('public_id', $registrationPublicId)
                ->where('user_id', $user->id)
                ->where('flow_type', 'three_d_secure_2')
                ->lockForUpdate()
                ->first();
            if ($locked === null) {
                throw $this->registrationNotFound();
            }
            if ($locked->status === 'completed') {
                throw new V2FincodeException(
                    'CARD_REGISTRATION_CONFLICT',
                    409,
                    'A completed card registration cannot be canceled.'
                );
            }
            if (in_array($locked->status, ['failed', 'expired', 'canceled'], true)) {
                return $locked;
            }
            DB::table('fincode_card_registration_intents')->where('id', $locked->id)->update([
                'status' => 'canceled',
                'canceled_at' => now()->startOfSecond(),
                'redirect_url_ciphertext' => null,
                'updated_at' => now(),
            ]);
            $this->audit->record('payment.card_registration.canceled', [
                'target_type' => 'card_registration',
                'target_public_id' => $locked->public_id,
                'metadata' => ['provider' => 'fincode'],
            ]);

            return DB::table('fincode_card_registration_intents')
                ->where('id', $locked->id)
                ->firstOrFail();
        });

        return $this->presentRegistration($intent);
    }

    /** @return array<string, mixed> */
    public function reconcileFromReturn(string $registrationPublicId): array
    {
        if (! Str::isUuid($registrationPublicId)) {
            throw $this->registrationNotFound();
        }
        $intent = DB::table('fincode_card_registration_intents')
            ->where('public_id', $registrationPublicId)
            ->where('flow_type', 'three_d_secure_2')
            ->first();
        if ($intent === null) {
            throw $this->registrationNotFound();
        }

        return $this->presentRegistration($this->reconcileIntent((int) $intent->id));
    }

    /**
     * @param array<string, string|null> $payload
     * @return array<string, mixed>
     */
    public function reconcileFromWebhook(array $payload): array
    {
        $query = DB::table('fincode_card_registration_intents as intent')
            ->join('fincode_customers as customer', 'customer.id', '=', 'intent.fincode_customer_id')
            ->where('intent.flow_type', 'three_d_secure_2')
            ->where('intent.provider_access_id', $payload['access_id'])
            ->select(['intent.*']);
        if ($payload['customer_id'] !== null) {
            $query->where('customer.provider_customer_id', $payload['customer_id']);
        }
        $intent = $query->first();
        if ($intent === null) {
            throw new V2FincodeException(
                'CARD_REGISTRATION_OWNERSHIP_INVALID',
                422,
                'The card registration ownership is invalid.'
            );
        }
        if (
            is_string($payload['client_field_1'])
            && $payload['client_field_1'] !== ''
            && ! hash_equals((string) $intent->public_id, $payload['client_field_1'])
        ) {
            throw new V2FincodeException(
                'CARD_REGISTRATION_OWNERSHIP_INVALID',
                422,
                'The card registration ownership is invalid.'
            );
        }

        $intent = DB::transaction(function () use ($intent, $payload): object {
            $locked = DB::table('fincode_card_registration_intents')
                ->where('id', $intent->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->provider_card_id !== null && $payload['card_id'] !== null
                && ! hash_equals((string) $locked->provider_card_id, $payload['card_id'])) {
                throw new V2FincodeException(
                    'CARD_REGISTRATION_OWNERSHIP_INVALID',
                    422,
                    'The card registration ownership is invalid.'
                );
            }
            $updates = [
                'provider_transaction_id' => $payload['transaction_id'],
                'webhook_received_at' => now()->startOfSecond(),
                'last_error_code' => $this->safeProviderErrorCode($payload['error_code']),
                'updated_at' => now(),
            ];
            if ($payload['card_id'] !== null) {
                $updates['provider_card_id'] = $payload['card_id'];
            }
            DB::table('fincode_card_registration_intents')
                ->where('id', $locked->id)
                ->update($updates);

            return DB::table('fincode_card_registration_intents')
                ->where('id', $locked->id)
                ->firstOrFail();
        });

        if (in_array($intent->status, self::TERMINAL_REGISTRATION_STATUSES, true)) {
            return $this->presentRegistration($intent);
        }

        return $this->presentRegistration($this->reconcileIntent((int) $intent->id));
    }

    /** @return array<string, mixed> */
    public function reconcilePending(int $intentId): array
    {
        return $this->presentRegistration($this->reconcileIntent($intentId));
    }

    public function expireDue(int $limit): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('The expiration limit must be between 1 and 1000.');
        }
        $ids = DB::table('fincode_card_registration_intents')
            ->whereIn('status', self::LIVE_REGISTRATION_STATUSES)
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table('fincode_card_registration_intents')
            ->whereIn('id', $ids->all())
            ->whereIn('status', self::LIVE_REGISTRATION_STATUSES)
            ->update([
                'status' => 'expired',
                'redirect_url_ciphertext' => null,
                'updated_at' => now(),
            ]);
    }

    public function delete(User $user, string $cardPublicId): void
    {
        $card = DB::table('fincode_cards as card')
            ->join('fincode_customers as customer', 'customer.id', '=', 'card.fincode_customer_id')
            ->where('card.public_id', $cardPublicId)
            ->where('card.user_id', $user->id)
            ->select(['card.*', 'customer.provider_customer_id'])
            ->first();
        if ($card === null) {
            throw new V2FincodeException('CARD_NOT_FOUND', 404, 'The card was not found.');
        }
        if ($card->deleted_at !== null) {
            return;
        }
        $this->client->deleteCard($card->provider_customer_id, $card->provider_card_id);
        DB::table('fincode_cards')
            ->where('id', $card->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()->startOfSecond(), 'updated_at' => now()]);
    }

    public function ownedUsableCard(User $user, string $cardPublicId): object
    {
        $card = DB::table('fincode_cards as card')
            ->join('fincode_customers as customer', 'customer.id', '=', 'card.fincode_customer_id')
            ->where('card.public_id', $cardPublicId)
            ->where('card.user_id', $user->id)
            ->where('card.registration_assurance', 'three_d_secure_2')
            ->whereNotNull('card.registration_verified_at')
            ->whereNotNull('card.registration_intent_id')
            ->whereNotNull('card.provider_payment_method_id')
            ->whereNull('card.deleted_at')
            ->select(['card.*', 'customer.provider_customer_id'])
            ->first();
        if ($card === null) {
            throw new V2FincodeException('CARD_NOT_FOUND', 404, 'The card was not found.');
        }
        if ($this->expired((int) $card->expire_year, (int) $card->expire_month)) {
            throw new V2FincodeException('CARD_EXPIRED', 422, 'The card is expired.');
        }

        return $card;
    }

    /** @return array{intent: object, call_provider: bool} */
    private function prepareRegistrationStart(User $user, object $customer, string $idempotencyKey): array
    {
        $keyHash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($user, $customer, $keyHash): array {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $this->expireUserRegistrations((int) $user->id);
            $existing = DB::table('fincode_card_registration_intents')
                ->where('idempotency_key_hash', $keyHash)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (
                    (int) $existing->user_id !== (int) $user->id
                    || $existing->flow_type !== 'three_d_secure_2'
                ) {
                    throw new V2FincodeException(
                        'IDEMPOTENCY_KEY_REUSED',
                        409,
                        'The idempotency key was already used.'
                    );
                }
                $callProvider = in_array($existing->status, ['starting', 'pending'], true)
                    && $existing->provider_payment_method_id === null
                    && ! $this->registrationLeaseActive($existing);
                if ($callProvider) {
                    DB::table('fincode_card_registration_intents')->where('id', $existing->id)->update([
                        'status' => 'starting',
                        'attempt_count' => DB::raw('attempt_count + 1'),
                        'last_attempted_at' => now()->startOfSecond(),
                        'last_error_code' => null,
                        'updated_at' => now(),
                    ]);
                    $existing = DB::table('fincode_card_registration_intents')
                        ->where('id', $existing->id)
                        ->firstOrFail();
                }

                return ['intent' => $existing, 'call_provider' => $callProvider];
            }
            $capacity = $this->registrationCapacity((int) $user->id);
            if ($capacity['remaining'] === 0) {
                throw $this->cardLimitReached();
            }
            $publicId = (string) Str::uuid7();
            DB::table('fincode_card_registration_intents')->insert([
                'public_id' => $publicId,
                'user_id' => $user->id,
                'fincode_customer_id' => $customer->id,
                'idempotency_key_hash' => $keyHash,
                'flow_type' => 'three_d_secure_2',
                'provider_idempotency_key' => (string) Str::uuid(),
                'status' => 'starting',
                'attempt_count' => 1,
                'last_attempted_at' => now()->startOfSecond(),
                'expires_at' => $this->registrationExpiry(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'intent' => DB::table('fincode_card_registration_intents')
                    ->where('public_id', $publicId)
                    ->firstOrFail(),
                'call_provider' => true,
            ];
        });
    }

    /** @return array<string, mixed> */
    private function replayRegistrationStart(object $intent): array
    {
        if ($intent->status === 'failed') {
            throw $this->registrationFailed();
        }
        if ($intent->status === 'expired') {
            throw new V2FincodeException(
                'CARD_INTENT_EXPIRED',
                409,
                'The card registration has expired.'
            );
        }
        if (
            in_array($intent->status, ['starting', 'pending'], true)
            && $intent->provider_payment_method_id === null
            && $intent->last_error_code !== null
        ) {
            throw $this->registrationUnavailable();
        }

        return $this->presentRegistration($intent);
    }

    /**
     * @param array{id: string, customer_id: string, status: string, redirect_url: ?string, access_id: string, tds2_status: ?string} $provider
     */
    private function applyRegistrationStartResponse(int $intentId, array $provider): object
    {
        return DB::transaction(function () use ($intentId, $provider): object {
            $intent = DB::table('fincode_card_registration_intents')
                ->where('id', $intentId)
                ->lockForUpdate()
                ->firstOrFail();
            if (in_array($intent->status, self::TERMINAL_REGISTRATION_STATUSES, true)) {
                return $intent;
            }
            if (
                $intent->provider_payment_method_id !== null
                && ! hash_equals((string) $intent->provider_payment_method_id, $provider['id'])
            ) {
                throw new V2FincodeException(
                    'CARD_REGISTRATION_CONFLICT',
                    409,
                    'The card registration is inconsistent.'
                );
            }
            $failed = $provider['status'] === 'FAILED';
            DB::table('fincode_card_registration_intents')->where('id', $intent->id)->update([
                'provider_payment_method_id' => $provider['id'],
                'provider_access_id' => $provider['access_id'],
                'provider_status' => $provider['status'],
                'provider_tds2_status' => $provider['tds2_status'],
                'redirect_url_ciphertext' => $provider['redirect_url'] === null
                    ? null
                    : Crypt::encryptString($provider['redirect_url']),
                'status' => match ($provider['status']) {
                    'AWAITING_CUSTOMER_ACTION' => 'requires_action',
                    'FAILED' => 'failed',
                    default => 'pending',
                },
                'failed_at' => $failed ? now()->startOfSecond() : null,
                'last_error_code' => $failed ? 'CARD_REGISTRATION_FAILED' : null,
                'updated_at' => now(),
            ]);

            return DB::table('fincode_card_registration_intents')
                ->where('id', $intent->id)
                ->firstOrFail();
        });
    }

    private function applyRegistrationStartFailure(int $intentId, V2FincodeException $exception): void
    {
        DB::table('fincode_card_registration_intents')
            ->where('id', $intentId)
            ->whereNotIn('status', self::TERMINAL_REGISTRATION_STATUSES)
            ->update([
                'status' => $exception->retryable ? 'pending' : 'failed',
                'failed_at' => $exception->retryable ? null : now()->startOfSecond(),
                'last_error_code' => $exception->errorCode,
                'updated_at' => now(),
            ]);
    }

    private function reconcileIntent(int $intentId): object
    {
        $intent = $this->registrationWithCustomer($intentId);
        if (in_array($intent->status, self::TERMINAL_REGISTRATION_STATUSES, true)) {
            return $intent;
        }
        if (now()->greaterThanOrEqualTo($intent->expires_at)) {
            $this->expireIntent((int) $intent->id);

            return $this->registrationWithCustomer($intentId);
        }
        if (
            $intent->flow_type !== 'three_d_secure_2'
            || ! is_string($intent->provider_payment_method_id)
            || $intent->provider_payment_method_id === ''
        ) {
            throw $this->registrationUnavailable();
        }

        try {
            $canonical = $this->client->retrieveCardPaymentMethod(
                (string) $intent->provider_customer_id,
                (string) $intent->provider_payment_method_id
            );
            $provider = $this->safePaymentMethod(
                $canonical,
                (string) $intent->provider_payment_method_id,
                (string) $intent->provider_customer_id
            );
        } catch (V2FincodeException $exception) {
            if ($exception->errorCode === 'CARD_REGISTRATION_3DS_NOT_VERIFIED') {
                $this->failRegistration((int) $intent->id, $exception->errorCode);
                throw $this->registrationFailed();
            }
            if ($exception->errorCode === 'CARD_REGISTRATION_OWNERSHIP_INVALID') {
                $this->failRegistration((int) $intent->id, $exception->errorCode);
                throw $exception;
            }
            $this->markReconciliationUnavailable((int) $intent->id);
            throw $this->registrationUnavailable();
        }

        $this->recordCanonicalRegistrationState((int) $intent->id, $provider);
        if ($provider['status'] === 'FAILED') {
            $this->failRegistration((int) $intent->id, 'CARD_REGISTRATION_FAILED');
            throw $this->registrationFailed();
        }
        if ($provider['status'] !== 'ACTIVATED') {
            return $this->registrationWithCustomer($intentId);
        }
        if ($provider['tds2_status'] !== 'AUTHENTICATED') {
            return $this->registrationWithCustomer($intentId);
        }

        $intent = $this->registrationWithCustomer($intentId);
        if (! is_string($intent->provider_card_id) || $intent->provider_card_id === '') {
            DB::table('fincode_card_registration_intents')->where('id', $intent->id)->update([
                'status' => 'pending',
                'updated_at' => now(),
            ]);

            return $this->registrationWithCustomer($intentId);
        }

        try {
            $providerCard = $this->client->retrieveCard(
                (string) $intent->provider_customer_id,
                (string) $intent->provider_card_id
            );
            $safeCard = $this->safeCard(
                $providerCard,
                (string) $intent->provider_card_id,
                (string) $intent->provider_customer_id
            );
        } catch (V2FincodeException $exception) {
            if ($exception->errorCode === 'CARD_REGISTRATION_OWNERSHIP_INVALID') {
                $this->failRegistration((int) $intent->id, $exception->errorCode);
                throw $exception;
            }
            $this->markReconciliationUnavailable((int) $intent->id);
            throw $this->registrationUnavailable();
        }

        $this->completeVerifiedRegistration($intent, $provider, $safeCard);

        return $this->registrationWithCustomer($intentId);
    }

    /**
     * @param array{id: string, customer_id: string, status: string, redirect_url: ?string, access_id: string, tds2_status: ?string} $provider
     */
    private function recordCanonicalRegistrationState(int $intentId, array $provider): void
    {
        DB::table('fincode_card_registration_intents')
            ->where('id', $intentId)
            ->whereNotIn('status', self::TERMINAL_REGISTRATION_STATUSES)
            ->update([
                'provider_status' => $provider['status'],
                'provider_tds2_status' => $provider['tds2_status'],
                'provider_reconciled_at' => now()->startOfSecond(),
                'status' => match ($provider['status']) {
                    'AWAITING_CUSTOMER_ACTION' => 'requires_action',
                    'FAILED' => 'failed',
                    default => 'pending',
                },
                'failed_at' => $provider['status'] === 'FAILED' ? now()->startOfSecond() : null,
                'last_error_code' => $provider['status'] === 'FAILED'
                    ? 'CARD_REGISTRATION_FAILED'
                    : null,
                'redirect_url_ciphertext' => $provider['status'] === 'AWAITING_CUSTOMER_ACTION'
                    && $provider['redirect_url'] !== null
                    ? Crypt::encryptString($provider['redirect_url'])
                    : null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array{id: string, customer_id: string, status: string, redirect_url: ?string, access_id: string, tds2_status: ?string} $provider
     * @param array{brand: ?string, last4: string, expire_month: int, expire_year: int} $safeCard
     */
    private function completeVerifiedRegistration(object $intent, array $provider, array $safeCard): void
    {
        $result = DB::transaction(function () use ($intent, $provider, $safeCard): string {
            DB::table('users')->where('id', $intent->user_id)->lockForUpdate()->firstOrFail();
            $locked = DB::table('fincode_card_registration_intents')
                ->where('id', $intent->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status === 'completed') {
                return 'completed';
            }
            if (in_array($locked->status, ['failed', 'expired', 'canceled'], true)) {
                return $locked->status;
            }
            if (now()->greaterThanOrEqualTo($locked->expires_at)) {
                DB::table('fincode_card_registration_intents')->where('id', $locked->id)->update([
                    'status' => 'expired',
                    'redirect_url_ciphertext' => null,
                    'updated_at' => now(),
                ]);

                return 'expired';
            }
            if (
                $locked->flow_type !== 'three_d_secure_2'
                || $locked->provider_payment_method_id !== $provider['id']
                || $locked->provider_access_id !== $provider['access_id']
                || $locked->provider_status !== 'ACTIVATED'
                || $locked->provider_tds2_status !== 'AUTHENTICATED'
                || $locked->provider_card_id === null
            ) {
                DB::table('fincode_card_registration_intents')->where('id', $locked->id)->update([
                    'status' => 'failed',
                    'failed_at' => now()->startOfSecond(),
                    'last_error_code' => 'CARD_REGISTRATION_OWNERSHIP_INVALID',
                    'redirect_url_ciphertext' => null,
                    'updated_at' => now(),
                ]);

                return 'failed';
            }
            $existing = DB::table('fincode_cards')
                ->where('registration_intent_id', $locked->id)
                ->first();
            if ($existing !== null) {
                DB::table('fincode_card_registration_intents')->where('id', $locked->id)->update([
                    'status' => 'completed',
                    'completed_at' => $locked->completed_at ?? now()->startOfSecond(),
                    'redirect_url_ciphertext' => null,
                    'updated_at' => now(),
                ]);

                return 'completed';
            }
            $providerCardConflict = DB::table('fincode_cards')
                ->where('fincode_customer_id', $locked->fincode_customer_id)
                ->where('provider_card_id', $locked->provider_card_id)
                ->exists();
            if ($providerCardConflict || $this->verifiedCardCount((int) $locked->user_id) >= self::MAX_CARDS) {
                DB::table('fincode_card_registration_intents')->where('id', $locked->id)->update([
                    'status' => 'failed',
                    'failed_at' => now()->startOfSecond(),
                    'last_error_code' => $providerCardConflict
                        ? 'CARD_REGISTRATION_CONFLICT'
                        : 'CARD_LIMIT_REACHED',
                    'redirect_url_ciphertext' => null,
                    'updated_at' => now(),
                ]);

                return 'failed';
            }
            $publicId = (string) Str::uuid7();
            $verifiedAt = now()->startOfSecond();
            DB::table('fincode_cards')->insert([
                'public_id' => $publicId,
                'user_id' => $locked->user_id,
                'fincode_customer_id' => $locked->fincode_customer_id,
                'registration_intent_id' => $locked->id,
                'provider_card_id' => $locked->provider_card_id,
                'provider_payment_method_id' => $locked->provider_payment_method_id,
                'registration_assurance' => 'three_d_secure_2',
                'registration_verified_at' => $verifiedAt,
                'brand' => $safeCard['brand'],
                'last4' => $safeCard['last4'],
                'expire_month' => $safeCard['expire_month'],
                'expire_year' => $safeCard['expire_year'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('fincode_card_registration_intents')->where('id', $locked->id)->update([
                'status' => 'completed',
                'provider_reconciled_at' => $verifiedAt,
                'completed_at' => $verifiedAt,
                'redirect_url_ciphertext' => null,
                'last_error_code' => null,
                'updated_at' => now(),
            ]);
            $this->audit->record('payment.card_registration.completed', [
                'target_type' => 'card_registration',
                'target_public_id' => $locked->public_id,
                'metadata' => [
                    'provider' => 'fincode',
                    'assurance' => 'three_d_secure_2',
                ],
            ]);

            return 'completed';
        });

        if ($result === 'canceled') {
            throw new V2FincodeException(
                'CARD_REGISTRATION_CANCELED',
                409,
                'The card registration was canceled.'
            );
        }
        if ($result === 'expired') {
            throw new V2FincodeException(
                'CARD_INTENT_EXPIRED',
                409,
                'The card registration has expired.'
            );
        }
        if ($result === 'failed') {
            throw $this->registrationFailed();
        }
    }

    private function markReconciliationUnavailable(int $intentId): void
    {
        DB::table('fincode_card_registration_intents')
            ->where('id', $intentId)
            ->whereNotIn('status', self::TERMINAL_REGISTRATION_STATUSES)
            ->update([
                'status' => 'pending',
                'last_error_code' => 'CARD_REGISTRATION_UNAVAILABLE',
                'updated_at' => now(),
            ]);
    }

    private function failRegistration(int $intentId, string $reasonCode): void
    {
        DB::table('fincode_card_registration_intents')
            ->where('id', $intentId)
            ->whereNotIn('status', self::TERMINAL_REGISTRATION_STATUSES)
            ->update([
                'status' => 'failed',
                'failed_at' => now()->startOfSecond(),
                'last_error_code' => $reasonCode,
                'redirect_url_ciphertext' => null,
                'updated_at' => now(),
            ]);
    }

    private function expireIntent(int $intentId): void
    {
        DB::table('fincode_card_registration_intents')
            ->where('id', $intentId)
            ->whereIn('status', self::LIVE_REGISTRATION_STATUSES)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'redirect_url_ciphertext' => null,
                'updated_at' => now(),
            ]);
    }

    private function expireRegistrationByPublicId(string $registrationPublicId, int $userId): void
    {
        DB::table('fincode_card_registration_intents')
            ->where('public_id', $registrationPublicId)
            ->where('user_id', $userId)
            ->whereIn('status', self::LIVE_REGISTRATION_STATUSES)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'redirect_url_ciphertext' => null,
                'updated_at' => now(),
            ]);
    }

    private function expireUserRegistrations(int $userId): void
    {
        DB::table('fincode_card_registration_intents')
            ->where('user_id', $userId)
            ->whereIn('status', self::LIVE_REGISTRATION_STATUSES)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'redirect_url_ciphertext' => null,
                'updated_at' => now(),
            ]);
    }

    private function ownedRegistration(User $user, string $registrationPublicId): object
    {
        if (! Str::isUuid($registrationPublicId)) {
            throw $this->registrationNotFound();
        }
        $intent = DB::table('fincode_card_registration_intents')
            ->where('public_id', $registrationPublicId)
            ->where('user_id', $user->id)
            ->where('flow_type', 'three_d_secure_2')
            ->first();
        if ($intent === null) {
            throw $this->registrationNotFound();
        }

        return $intent;
    }

    private function registrationWithCustomer(int $intentId): object
    {
        $intent = DB::table('fincode_card_registration_intents as intent')
            ->join('fincode_customers as customer', 'customer.id', '=', 'intent.fincode_customer_id')
            ->where('intent.id', $intentId)
            ->where('intent.flow_type', 'three_d_secure_2')
            ->select(['intent.*', 'customer.provider_customer_id'])
            ->first();
        if ($intent === null) {
            throw $this->registrationNotFound();
        }

        return $intent;
    }

    /** @return array<string, mixed> */
    private function presentRegistration(object $intent): array
    {
        $cardPublicId = null;
        if ($intent->status === 'completed') {
            $cardPublicId = DB::table('fincode_cards')
                ->where('registration_intent_id', $intent->id)
                ->whereNotNull('registration_verified_at')
                ->value('public_id');
            if (! is_string($cardPublicId)) {
                throw new V2FincodeException(
                    'CARD_REGISTRATION_CONFLICT',
                    409,
                    'The card registration is inconsistent.'
                );
            }
        }
        $status = $intent->status === 'starting' ? 'pending' : $intent->status;
        $nextAction = null;
        if ($status === 'requires_action') {
            $url = $this->registrationRedirectUrl($intent->redirect_url_ciphertext);
            if ($url !== null) {
                $nextAction = ['type' => 'three_d_secure', 'url' => $url];
            }
        }

        return [
            'id' => $intent->public_id,
            'status' => $status,
            'expires_at' => $this->timestamp($intent->expires_at),
            'completed_at' => $intent->completed_at === null
                ? null
                : $this->timestamp($intent->completed_at),
            'saved_card_id' => $cardPublicId,
            'next_action' => $nextAction,
        ];
    }

    private function customer(User $user): object
    {
        $customer = DB::transaction(function () use ($user): object {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('fincode_customers')->where('user_id', $user->id)->first();
            if ($existing !== null) {
                if ($existing->status === 'active') {
                    return $existing;
                }
                if ($existing->status === 'calling') {
                    throw new V2FincodeException(
                        'FINCODE_CUSTOMER_PENDING',
                        409,
                        'The payment customer is being created.',
                        true
                    );
                }
                DB::table('fincode_customers')->where('id', $existing->id)->update([
                    'status' => 'calling',
                    'updated_at' => now(),
                ]);

                return DB::table('fincode_customers')->where('id', $existing->id)->firstOrFail();
            }
            $publicId = (string) Str::uuid7();
            DB::table('fincode_customers')->insert([
                'public_id' => $publicId,
                'user_id' => $user->id,
                'provider_customer_id' => 'c'.Str::lower((string) Str::ulid()),
                'provider_idempotency_key' => (string) Str::uuid(),
                'status' => 'calling',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('fincode_customers')->where('public_id', $publicId)->firstOrFail();
        });
        if ($customer->status === 'active') {
            return $customer;
        }
        try {
            $provider = $this->client->createCustomer(
                $customer->provider_customer_id,
                $customer->provider_idempotency_key
            );
            if (($provider['id'] ?? null) !== $customer->provider_customer_id) {
                throw new V2FincodeException(
                    'FINCODE_CUSTOMER_RESPONSE_INVALID',
                    503,
                    'The payment customer response is invalid.',
                    true
                );
            }
            DB::table('fincode_customers')->where('id', $customer->id)->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
        } catch (V2FincodeException $exception) {
            DB::table('fincode_customers')->where('id', $customer->id)->update([
                'status' => $exception->errorCode === 'FINCODE_TIMEOUT' ? 'uncertain' : 'failed',
                'updated_at' => now(),
            ]);
            throw $exception;
        }

        return DB::table('fincode_customers')->where('id', $customer->id)->firstOrFail();
    }

    /**
     * @param array<string, mixed> $response
     * @return array{id: string, customer_id: string, status: string, redirect_url: ?string, access_id: string, tds2_status: ?string}
     */
    private function safePaymentMethod(
        array $response,
        ?string $expectedPaymentMethodId,
        string $expectedCustomerId
    ): array {
        $paymentMethodId = $response['id'] ?? null;
        $customerId = $response['customer_id'] ?? null;
        $status = $response['status'] ?? null;
        $payType = $response['pay_type'] ?? null;
        if (
            ! is_string($paymentMethodId)
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $paymentMethodId)
            || ! is_string($customerId)
            || ! is_string($payType)
            || ! is_string($status)
            || ! in_array($status, self::PROVIDER_PAYMENT_METHOD_STATUSES, true)
        ) {
            throw new V2FincodeException(
                'FINCODE_CARD_REGISTRATION_RESPONSE_INVALID',
                503,
                'The card registration response is invalid.',
                true
            );
        }
        if (
            ($expectedPaymentMethodId !== null && $paymentMethodId !== $expectedPaymentMethodId)
            || $customerId !== $expectedCustomerId
            || $payType !== 'Card'
        ) {
            throw new V2FincodeException(
                'CARD_REGISTRATION_OWNERSHIP_INVALID',
                422,
                'The card registration ownership is invalid.'
            );
        }
        $card = $response['card'] ?? null;
        if (! is_array($card) || array_is_list($card)) {
            throw new V2FincodeException(
                'FINCODE_CARD_REGISTRATION_RESPONSE_INVALID',
                503,
                'The card registration response is invalid.',
                true
            );
        }
        $tdsType = $card['tds_type'] ?? null;
        $tds2Type = $card['tds2_type'] ?? null;
        $tds2Status = $card['tds2_status'] ?? null;
        $accessId = $card['access_id'] ?? null;
        if ($tdsType !== '2' || $tds2Type !== '2') {
            throw new V2FincodeException(
                'CARD_REGISTRATION_3DS_NOT_VERIFIED',
                422,
                'The card registration did not provide the required 3D Secure 2.0 assurance.'
            );
        }
        if (
            ! is_string($accessId)
            || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $accessId)
            || ($tds2Status !== null
                && (! is_string($tds2Status)
                    || ! in_array($tds2Status, self::PROVIDER_TDS2_STATUSES, true)))
        ) {
            throw new V2FincodeException(
                'FINCODE_CARD_REGISTRATION_RESPONSE_INVALID',
                503,
                'The card registration response is invalid.',
                true
            );
        }
        $redirectUrl = $this->providerActionUrl($response['redirect_url'] ?? null);
        if ($status === 'AWAITING_CUSTOMER_ACTION' && $redirectUrl === null) {
            throw new V2FincodeException(
                'FINCODE_CARD_REGISTRATION_RESPONSE_INVALID',
                503,
                'The card registration response is invalid.',
                true
            );
        }

        return [
            'id' => $paymentMethodId,
            'customer_id' => $customerId,
            'status' => $status,
            'redirect_url' => $redirectUrl,
            'access_id' => $accessId,
            'tds2_status' => $tds2Status,
        ];
    }

    /** @return array{brand: ?string, last4: string, expire_month: int, expire_year: int} */
    private function safeCard(
        array $providerCard,
        string $expectedCardId,
        string $expectedCustomerId
    ): array {
        if (
            ($providerCard['id'] ?? null) !== $expectedCardId
            || ($providerCard['customer_id'] ?? null) !== $expectedCustomerId
        ) {
            throw new V2FincodeException(
                'CARD_REGISTRATION_OWNERSHIP_INVALID',
                422,
                'The card registration ownership is invalid.'
            );
        }
        $number = $providerCard['card_no'] ?? null;
        $last4 = is_string($number) ? substr(preg_replace('/\D/', '', $number) ?? '', -4) : '';
        $expire = $providerCard['expire'] ?? null;
        if (! is_string($expire) || ! preg_match('/^(\d{2})(\d{2})$/', $expire, $matches)) {
            throw new V2FincodeException('FINCODE_CARD_RESPONSE_INVALID', 503, 'The card response is invalid.', true);
        }
        $year = 2000 + (int) $matches[1];
        $month = (int) $matches[2];
        if (! preg_match('/^\d{4}$/', $last4) || $month < 1 || $month > 12) {
            throw new V2FincodeException('FINCODE_CARD_RESPONSE_INVALID', 503, 'The card response is invalid.', true);
        }

        return [
            'brand' => is_string($providerCard['brand'] ?? null)
                ? substr($providerCard['brand'], 0, 32)
                : null,
            'last4' => $last4,
            'expire_month' => $month,
            'expire_year' => $year,
        ];
    }

    /** @return array<string, mixed> */
    private function presentCard(object $card): array
    {
        $expired = $this->expired((int) $card->expire_year, (int) $card->expire_month);
        $verified = $card->registration_intent_id !== null
            && $card->provider_payment_method_id !== null
            && $card->registration_assurance === 'three_d_secure_2'
            && $card->registration_verified_at !== null;

        return [
            'id' => $card->public_id,
            'brand' => $card->brand,
            'last4' => $card->last4,
            'expiration' => [
                'month' => (int) $card->expire_month,
                'year' => (int) $card->expire_year,
            ],
            'verification_status' => $verified ? 'verified' : 'unverified',
            'is_expired' => $expired,
            'can_pay' => $verified && ! $expired,
            'last_used_at' => $card->last_used_at === null
                ? null
                : $this->timestamp($card->last_used_at),
        ];
    }

    /** @return array{remaining: int, next_capacity_at: ?string} */
    private function registrationCapacity(int $userId): array
    {
        $verifiedCards = $this->verifiedCardCount($userId);
        $registrations = $this->liveRegistrationCount($userId);
        $remaining = max(0, self::MAX_CARDS - $verifiedCards - $registrations);
        $nextCapacityAt = null;
        if ($remaining === 0 && $verifiedCards < self::MAX_CARDS && $registrations > 0) {
            $expiry = DB::table('fincode_card_registration_intents')
                ->where('user_id', $userId)
                ->whereIn('status', self::LIVE_REGISTRATION_STATUSES)
                ->where('expires_at', '>', now())
                ->min('expires_at');
            $nextCapacityAt = $expiry === null ? null : $this->timestamp($expiry);
        }

        return ['remaining' => $remaining, 'next_capacity_at' => $nextCapacityAt];
    }

    private function storedCardCount(int $userId): int
    {
        return DB::table('fincode_cards')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->count();
    }

    private function verifiedCardCount(int $userId): int
    {
        return DB::table('fincode_cards')
            ->where('user_id', $userId)
            ->where('registration_assurance', 'three_d_secure_2')
            ->whereNotNull('registration_intent_id')
            ->whereNotNull('provider_payment_method_id')
            ->whereNotNull('registration_verified_at')
            ->whereNull('deleted_at')
            ->count();
    }

    private function liveRegistrationCount(int $userId): int
    {
        return DB::table('fincode_card_registration_intents')
            ->where('user_id', $userId)
            ->whereIn('status', self::LIVE_REGISTRATION_STATUSES)
            ->where('expires_at', '>', now())
            ->count();
    }

    private function registrationLeaseActive(object $intent): bool
    {
        if ($intent->last_attempted_at === null) {
            return false;
        }
        $seconds = max(60, (int) config('v2_fincode.timeout_seconds') * 2);

        return CarbonImmutable::parse($intent->last_attempted_at)
            ->greaterThan(now()->subSeconds($seconds));
    }

    private function registrationExpiry(): CarbonImmutable
    {
        return CarbonImmutable::now()
            ->addMinutes((int) config('v2_fincode.card_registration_intent_minutes'))
            ->startOfSecond();
    }

    private function registrationRedirectUrl(mixed $ciphertext): ?string
    {
        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }
        try {
            return $this->providerActionUrl(Crypt::decryptString($ciphertext));
        } catch (DecryptException) {
            return null;
        }
    }

    private function providerActionUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (
            ($parts['scheme'] ?? null) !== 'https'
            || ($host !== 'fincode.jp' && ! str_ends_with($host, '.fincode.jp'))
        ) {
            return null;
        }

        return $value;
    }

    private function safeProviderErrorCode(?string $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value)
            ? $value
            : null;
    }

    private function timestamp(mixed $value): string
    {
        return CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
    }

    private function expired(int $year, int $month): bool
    {
        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')
            ->endOfMonth()
            ->isPast();
    }

    private function cardLimitReached(): V2FincodeException
    {
        return new V2FincodeException(
            'CARD_LIMIT_REACHED',
            409,
            'A maximum of three cards may be registered.'
        );
    }

    private function registrationNotFound(): V2FincodeException
    {
        return new V2FincodeException(
            'CARD_REGISTRATION_NOT_FOUND',
            404,
            'The card registration was not found.'
        );
    }

    private function registrationFailed(): V2FincodeException
    {
        return new V2FincodeException(
            'CARD_REGISTRATION_FAILED',
            422,
            'The card registration failed.'
        );
    }

    private function registrationUnavailable(): V2FincodeException
    {
        return new V2FincodeException(
            'CARD_REGISTRATION_UNAVAILABLE',
            503,
            'The card registration status is temporarily unavailable.',
            true
        );
    }
}
