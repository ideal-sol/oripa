<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Reporting\Services\V2ReportingCursor;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class V2FincodePaymentService
{
    private const METHODS = [
        'credit_card' => 'Card',
        'paypay' => 'Paypay',
        'konbini' => 'Konbini',
        'virtual_account' => 'Virtualaccount',
    ];

    public function __construct(
        private readonly V2PaymentService $payments,
        private readonly V2FincodeClient $client,
        private readonly V2FincodeCardService $cards,
        private readonly V2FincodeReturnUrl $returns,
        private readonly V2ReportingCursor $cursor
    ) {
    }

    /** @param array<string, mixed>|null $card */
    public function start(
        User $user,
        string $pointProductId,
        string $method,
        string $idempotencyKey,
        ?array $card = null
    ): array {
        $payType = self::METHODS[$method] ?? null;
        if ($payType === null) {
            throw new V2FincodeException('PAYMENT_METHOD_UNSUPPORTED', 422, 'The payment method is unsupported.');
        }
        $plan = DB::table('point_purchase_plans')
            ->where('public_id', $pointProductId)
            ->select(['id', 'public_id'])
            ->first();
        if ($plan === null) {
            throw new V2FincodeException('POINT_PRODUCT_NOT_FOUND', 404, 'The point product was not found.');
        }
        $ownedCard = $method === 'credit_card'
            ? $this->paymentCard($user, $card)
            : null;
        $orderId = $this->orderId($user, $method, $idempotencyKey, $card);
        try {
            $payment = $this->payments->createPayment(
                $user->id,
                (int) $plan->id,
                'fincode',
                $orderId,
                $idempotencyKey,
                $method
            );
        } catch (V2PaymentException $exception) {
            throw $this->translatePaymentException($exception);
        } catch (V2PointException $exception) {
            throw $this->translateIdempotencyException($exception);
        }

        $attempt = $this->prepareAttempt($payment, $ownedCard);
        if ($attempt->status !== 'calling') {
            return $this->present($payment->public_id, true);
        }

        try {
            $normalReturnUrl = $this->returns->providerNormal($payment->public_id);
            $failureReturnUrl = $this->returns->providerFailure($payment->public_id);
            if ($method === 'credit_card') {
                $response = $this->client->createCardPayment(
                    $payment->provider_payment_id,
                    (int) $payment->amount,
                    $attempt->provider_idempotency_key
                );
                $accessId = $this->requiredString($response, 'access_id');
                $nextUrl = null;
                if ($ownedCard !== null) {
                    $executed = $this->client->executeSavedCard(
                        $payment->provider_payment_id,
                        $accessId,
                        $ownedCard->provider_customer_id,
                        $ownedCard->provider_card_id,
                        $normalReturnUrl,
                        $failureReturnUrl,
                        $attempt->provider_execute_idempotency_key
                    );
                    $nextUrl = $this->optionalActionUrl($executed['acs_url'] ?? null);
                    DB::table('fincode_cards')->where('id', $ownedCard->id)->update([
                        'last_used_at' => now()->startOfSecond(),
                        'updated_at' => now(),
                    ]);
                }
                $this->finishAttempt(
                    $attempt->id,
                    'requires_action',
                    $accessId,
                    null,
                    $nextUrl
                );
                $this->payments->applyProviderStartResult(
                    $payment->id,
                    $ownedCard === null ? 'UNPROCESSED' : 'AUTHENTICATED',
                    'requires_action'
                );
            } else {
                $response = $this->client->createRedirectSession(
                    $payment->provider_payment_id,
                    $payType,
                    (int) $payment->amount,
                    $normalReturnUrl,
                    $failureReturnUrl,
                    $attempt->provider_idempotency_key
                );
                $redirectUrl = $this->requiredHttpsUrl($response, 'link_url');
                $sessionId = $this->requiredString($response, 'id');
                $this->finishAttempt($attempt->id, 'requires_action', null, $sessionId, $redirectUrl);
                $expiresAt = in_array($method, ['konbini', 'virtual_account'], true)
                    ? now()->addDays(3)->startOfSecond()
                    : null;
                $this->payments->applyProviderStartResult(
                    $payment->id,
                    'UNPROCESSED',
                    'requires_action',
                    $expiresAt
                );
            }
        } catch (V2FincodeException $exception) {
            $this->failAttempt($attempt->id, $exception);
            if (! $exception->retryable) {
                try {
                    $this->payments->applyProviderStartResult(
                        $payment->id,
                        'REJECTED',
                        'failed'
                    );
                } catch (Throwable) {
                }
            }
            throw $exception;
        }

        return $this->present($payment->public_id, true);
    }

    public function show(User $user, string $paymentPublicId): array
    {
        return $this->present($paymentPublicId, true, $user->id);
    }

    public function history(
        User $user,
        string $view,
        ?string $cursor,
        int $limit
    ): array {
        if (! in_array($view, ['succeeded', 'unpaid'], true)) {
            throw new V2FincodeException('PAYMENT_HISTORY_VIEW_INVALID', 422, 'The history view is invalid.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new V2FincodeException('PAYMENT_HISTORY_LIMIT_INVALID', 422, 'The history limit is invalid.');
        }
        $cursorId = $this->cursor->decode($cursor);
        $query = DB::table('payments')
            ->where('user_id', $user->id)
            ->where('provider_code', 'fincode')
            ->orderByDesc('id');
        if ($cursorId !== null) {
            $query->where('id', '<', $cursorId);
        }
        if ($view === 'succeeded') {
            $query->where('status', 'succeeded');
        } else {
            $query->whereIn('payment_method', ['konbini', 'virtual_account'])
                ->where('status', 'processing')
                ->where('provider_status', 'AWAITING_CUSTOMER_PAYMENT')
                ->where('expires_at', '>', now());
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(
            fn (object $payment): array => $this->present($payment->public_id, false, $user->id)
        )->all();
        $last = $rows->take($limit)->last();

        return [
            'data' => $items,
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last !== null
                    ? $this->cursor->encode((int) $last->id)
                    : null,
            ],
        ];
    }

    public function resume(User $user, string $paymentPublicId): array
    {
        $payment = DB::table('payments')
            ->where('public_id', $paymentPublicId)
            ->where('user_id', $user->id)
            ->whereIn('payment_method', ['konbini', 'virtual_account'])
            ->where('status', 'processing')
            ->where('provider_status', 'AWAITING_CUSTOMER_PAYMENT')
            ->where('expires_at', '>', now())
            ->first();
        if ($payment === null) {
            throw new V2FincodeException('UNPAID_PAYMENT_NOT_RESUMABLE', 409, 'The unpaid payment cannot be resumed.');
        }
        $attempt = DB::table('fincode_payment_attempts')->where('payment_id', $payment->id)->firstOrFail();
        if ($attempt->redirect_url_ciphertext === null) {
            throw new V2FincodeException('UNPAID_PAYMENT_NOT_RESUMABLE', 409, 'The unpaid payment cannot be resumed.');
        }

        return [
            'payment_id' => $payment->public_id,
            'next_action' => [
                'type' => 'redirect',
                'url' => Crypt::decryptString($attempt->redirect_url_ciphertext),
            ],
        ];
    }

    /** @param array<string, mixed>|null $card */
    private function paymentCard(User $user, ?array $card): ?object
    {
        $source = $card['source'] ?? null;
        if ($source === 'new' && ($card['save'] ?? null) === false) {
            return null;
        }
        if ($source === 'saved' && is_string($card['card_id'] ?? null)) {
            return $this->cards->ownedUsableCard($user, $card['card_id']);
        }
        if (
            $source === 'new'
            && ($card['save'] ?? null) === true
            && is_string($card['registration_intent_id'] ?? null)
            && is_string($card['provider_card_id'] ?? null)
        ) {
            $registered = $this->cards->completeRegistration(
                $user,
                $card['registration_intent_id'],
                $card['provider_card_id']
            );

            return $this->cards->ownedUsableCard($user, $registered['id']);
        }
        throw new V2FincodeException('CARD_SELECTION_INVALID', 422, 'The card selection is invalid.');
    }

    private function prepareAttempt(object $payment, ?object $card): object
    {
        DB::table('fincode_payment_attempts')->insertOrIgnore([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => $payment->id,
            'fincode_card_id' => $card?->id,
            'provider_idempotency_key' => (string) Str::uuid(),
            'provider_execute_idempotency_key' => $card === null ? null : (string) Str::uuid(),
            'status' => 'prepared',
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($payment): object {
            $attempt = DB::table('fincode_payment_attempts')
                ->where('payment_id', $payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($attempt->status, ['requires_action', 'pending', 'completed', 'failed'], true)) {
                return $attempt;
            }
            if ($attempt->status === 'calling') {
                $leaseSeconds = max(60, (int) config('v2_fincode.timeout_seconds') * 2);
                if (
                    $attempt->last_attempted_at !== null
                    && CarbonImmutable::parse($attempt->last_attempted_at)->greaterThan(now()->subSeconds($leaseSeconds))
                ) {
                    throw new V2FincodeException(
                        'PAYMENT_START_IN_PROGRESS',
                        409,
                        'The payment start is already in progress.',
                        true
                    );
                }
            }
            if (
                $attempt->status === 'uncertain'
                && ($attempt->last_attempted_at === null
                    || CarbonImmutable::parse($attempt->last_attempted_at)->lessThanOrEqualTo(now()->subMinutes(29)))
            ) {
                throw new V2FincodeException(
                    'PAYMENT_START_RECONCILIATION_REQUIRED',
                    409,
                    'The payment start requires provider reconciliation.',
                    true
                );
            }
            DB::table('fincode_payment_attempts')->where('id', $attempt->id)->update([
                'status' => 'calling',
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempted_at' => now()->startOfSecond(),
                'last_error_code' => null,
                'updated_at' => now(),
            ]);

            return DB::table('fincode_payment_attempts')->where('id', $attempt->id)->firstOrFail();
        });
    }

    private function finishAttempt(
        int $attemptId,
        string $status,
        ?string $accessId,
        ?string $sessionId,
        ?string $redirectUrl
    ): void {
        DB::table('fincode_payment_attempts')->where('id', $attemptId)->update([
            'status' => $status,
            'provider_access_id' => $accessId,
            'provider_session_id' => $sessionId,
            'redirect_url_ciphertext' => $redirectUrl === null
                ? null
                : Crypt::encryptString($redirectUrl),
            'updated_at' => now(),
        ]);
    }

    private function failAttempt(int $attemptId, V2FincodeException $exception): void
    {
        DB::table('fincode_payment_attempts')->where('id', $attemptId)->update([
            'status' => $exception->retryable ? 'uncertain' : 'failed',
            'last_error_code' => $exception->errorCode,
            'updated_at' => now(),
        ]);
    }

    private function present(
        string $paymentPublicId,
        bool $includeNextAction,
        ?int $expectedUserId = null
    ): array {
        if (! Str::isUuid($paymentPublicId)) {
            throw new V2FincodeException('PAYMENT_NOT_FOUND', 404, 'The payment was not found.');
        }
        $query = DB::table('payments as payment')
            ->join('point_purchase_plans as point_product', 'point_product.id', '=', 'payment.point_purchase_plan_id')
            ->select(['payment.*', 'point_product.public_id as point_product_public_id'])
            ->where('payment.public_id', $paymentPublicId);
        if ($expectedUserId !== null) {
            $query->where('payment.user_id', $expectedUserId);
        }
        $payment = $query->first();
        if ($payment === null) {
            throw new V2FincodeException('PAYMENT_NOT_FOUND', 404, 'The payment was not found.');
        }
        $attempt = DB::table('fincode_payment_attempts')->where('payment_id', $payment->id)->first();
        $nextAction = null;
        if ($includeNextAction && $payment->status === 'requires_action' && $attempt !== null) {
            if ($payment->payment_method === 'credit_card' && $attempt->fincode_card_id === null) {
                $nextAction = [
                    'type' => 'fincode_card_component',
                    'payment_id' => $payment->provider_payment_id,
                    'access_id' => $attempt->provider_access_id,
                    'public_api_key' => $this->publicApiKey(),
                    'tds_type' => '2',
                    'return_url' => $this->returns->providerNormal($payment->public_id),
                    'failure_url' => $this->returns->providerFailure($payment->public_id),
                ];
            } elseif ($attempt->redirect_url_ciphertext !== null) {
                $nextAction = [
                    'type' => $payment->payment_method === 'credit_card'
                        ? 'three_d_secure'
                        : 'redirect',
                    'url' => Crypt::decryptString($attempt->redirect_url_ciphertext),
                ];
            }
        }

        return [
            'id' => $payment->public_id,
            'point_product_id' => $payment->point_product_public_id,
            'method' => $payment->payment_method,
            'status' => $payment->status,
            'amount' => ['amount' => (int) $payment->amount, 'currency' => $payment->currency],
            'grant' => [
                'paid_points' => (int) $payment->paid_point_amount,
                'bonus_points' => (int) $payment->free_point_amount,
            ],
            'expires_at' => $payment->expires_at === null
                ? null
                : CarbonImmutable::parse($payment->expires_at)->utc()->toIso8601ZuluString(),
            'created_at' => CarbonImmutable::parse($payment->created_at)->utc()->toIso8601ZuluString(),
            'succeeded_at' => $payment->succeeded_at === null
                ? null
                : CarbonImmutable::parse($payment->succeeded_at)->utc()->toIso8601ZuluString(),
            'next_action' => $nextAction,
        ];
    }

    /** @param array<string, mixed> $response */
    private function requiredString(array $response, string $key): string
    {
        $value = $response[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > 256) {
            throw new V2FincodeException('FINCODE_RESPONSE_INVALID', 503, 'The payment provider returned an invalid response.', true);
        }

        return $value;
    }

    /** @param array<string, mixed> $response */
    private function requiredHttpsUrl(array $response, string $key): string
    {
        $url = $this->optionalHttpsUrl($response[$key] ?? null);
        if ($url === null) {
            throw new V2FincodeException('FINCODE_RESPONSE_INVALID', 503, 'The payment provider returned an invalid response.', true);
        }

        return $url;
    }

    private function optionalHttpsUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if (($parts['scheme'] ?? null) !== 'https') {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'fincode.jp' && ! str_ends_with($host, '.fincode.jp')) {
            return null;
        }

        return $value;
    }

    private function optionalActionUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);

        return ($parts['scheme'] ?? null) === 'https' ? $value : null;
    }

    private function translatePaymentException(V2PaymentException $exception): V2FincodeException
    {
        return match ($exception->getMessage()) {
            'KONBINI_UNPAID_LIMIT_REACHED' => new V2FincodeException(
                'KONBINI_UNPAID_LIMIT_REACHED',
                409,
                'An unpaid Konbini payment already exists.'
            ),
            'PURCHASE_PLAN_NOT_AVAILABLE' => new V2FincodeException(
                'POINT_PRODUCT_NOT_AVAILABLE',
                409,
                'The point product is not available.'
            ),
            default => new V2FincodeException(
                'PAYMENT_START_REJECTED',
                409,
                'The payment could not be started.'
            ),
        };
    }

    private function translateIdempotencyException(V2PointException $exception): V2FincodeException
    {
        return match ($exception->getMessage()) {
            'IDEMPOTENCY_KEY_REUSED' => new V2FincodeException(
                'IDEMPOTENCY_KEY_REUSED',
                409,
                'The idempotency key was already used with different input.'
            ),
            'IDEMPOTENCY_REQUEST_IN_PROGRESS' => new V2FincodeException(
                'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                409,
                'The idempotent payment request is still in progress.',
                true
            ),
            default => new V2FincodeException(
                'PAYMENT_START_REJECTED',
                409,
                'The payment could not be started.'
            ),
        };
    }

    /** @param array<string, mixed>|null $card */
    private function orderId(User $user, string $method, string $idempotencyKey, ?array $card): string
    {
        $selection = match ($card['source'] ?? null) {
            'saved' => ['source' => 'saved', 'card_id' => $card['card_id'] ?? null],
            'new' => [
                'source' => 'new',
                'save' => $card['save'] ?? null,
                'registration_intent_id' => $card['registration_intent_id'] ?? null,
                'provider_card_id' => $card['provider_card_id'] ?? null,
            ],
            default => null,
        };
        $fingerprint = json_encode([
            'user' => $user->public_id,
            'method' => $method,
            'idempotency_key' => $idempotencyKey,
            'card' => $selection,
        ], JSON_THROW_ON_ERROR);

        return 'o'.substr(hash('sha256', $fingerprint), 0, 26);
    }

    private function publicApiKey(): string
    {
        $key = config('v2_fincode.public_api_key');
        if (! is_string($key) || $key === '' || strlen($key) > 512) {
            throw new V2FincodeException(
                'FINCODE_PUBLIC_CONFIGURATION_UNAVAILABLE',
                503,
                'The payment provider public configuration is unavailable.'
            );
        }

        return $key;
    }
}
