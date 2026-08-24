<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2FincodeCardService
{
    private const MAX_CARDS = 3;

    public function __construct(private readonly V2FincodeClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function cards(User $user): array
    {
        return [
            'data' => DB::table('fincode_cards')
                ->where('user_id', $user->id)
                ->whereNull('deleted_at')
                ->orderByRaw('last_used_at DESC NULLS LAST')
                ->orderByDesc('id')
                ->get()
                ->map(fn (object $card): array => $this->present($card))
                ->all(),
            'limits' => [
                'maximum' => self::MAX_CARDS,
                'remaining' => max(0, self::MAX_CARDS - $this->activeCardCount($user->id)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function reserveRegistration(User $user, string $idempotencyKey): array
    {
        $customer = $this->customer($user);
        $keyHash = hash('sha256', $idempotencyKey);

        $intent = DB::transaction(function () use ($user, $customer, $keyHash): object {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('fincode_card_registration_intents')
                ->where('idempotency_key_hash', $keyHash)->first();
            if ($existing !== null) {
                if ((int) $existing->user_id !== (int) $user->id) {
                    throw new V2FincodeException(
                        'IDEMPOTENCY_KEY_REUSED',
                        409,
                        'The idempotency key was already used.'
                    );
                }

                return $existing;
            }
            DB::table('fincode_card_registration_intents')
                ->where('user_id', $user->id)
                ->where('status', 'reserved')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired', 'updated_at' => now()]);
            $cards = $this->activeCardCount($user->id);
            $reservations = DB::table('fincode_card_registration_intents')
                ->where('user_id', $user->id)
                ->where('status', 'reserved')
                ->where('expires_at', '>', now())
                ->count();
            if ($cards + $reservations >= self::MAX_CARDS) {
                throw new V2FincodeException(
                    'CARD_LIMIT_REACHED',
                    409,
                    'A maximum of three cards may be registered.'
                );
            }
            $publicId = (string) Str::uuid7();
            DB::table('fincode_card_registration_intents')->insert([
                'public_id' => $publicId,
                'user_id' => $user->id,
                'fincode_customer_id' => $customer->id,
                'idempotency_key_hash' => $keyHash,
                'status' => 'reserved',
                'expires_at' => now()->addMinutes(
                    (int) config('v2_fincode.card_registration_intent_minutes')
                )->startOfSecond(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('fincode_card_registration_intents')
                ->where('public_id', $publicId)->firstOrFail();
        });

        return [
            'id' => $intent->public_id,
            'expires_at' => CarbonImmutable::parse($intent->expires_at)->utc()->toIso8601ZuluString(),
            'provider_context' => [
                'provider' => 'fincode',
                'customer_id' => $customer->provider_customer_id,
                'public_api_key' => $this->publicApiKey(),
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
        $intent = DB::table('fincode_card_registration_intents as intent')
            ->join('fincode_customers as customer', 'customer.id', '=', 'intent.fincode_customer_id')
            ->where('intent.public_id', $intentPublicId)
            ->where('intent.user_id', $user->id)
            ->select(['intent.*', 'customer.provider_customer_id'])
            ->first();
        if ($intent === null) {
            throw new V2FincodeException('CARD_INTENT_NOT_FOUND', 404, 'The card registration was not found.');
        }
        if ($intent->status === 'completed') {
            $card = DB::table('fincode_cards')
                ->where('user_id', $user->id)
                ->where('provider_card_id', $providerCardId)
                ->whereNull('deleted_at')
                ->first();
            if ($card === null) {
                throw new V2FincodeException('CARD_REGISTRATION_CONFLICT', 409, 'The card registration is inconsistent.');
            }

            return $this->present($card);
        }
        if ($intent->status !== 'reserved' || now()->greaterThanOrEqualTo($intent->expires_at)) {
            throw new V2FincodeException('CARD_INTENT_EXPIRED', 409, 'The card registration has expired.');
        }

        $providerCard = $this->client->retrieveCard(
            (string) $intent->provider_customer_id,
            $providerCardId
        );
        $safe = $this->safeCard(
            $providerCard,
            $providerCardId,
            (string) $intent->provider_customer_id
        );

        $card = DB::transaction(function () use ($user, $intent, $providerCardId, $safe): object {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $lockedIntent = DB::table('fincode_card_registration_intents')
                ->where('id', $intent->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('fincode_cards')
                ->where('fincode_customer_id', $lockedIntent->fincode_customer_id)
                ->where('provider_card_id', $providerCardId)
                ->whereNull('deleted_at')
                ->first();
            if ($existing !== null) {
                DB::table('fincode_card_registration_intents')->where('id', $lockedIntent->id)->update([
                    'status' => 'completed',
                    'completed_at' => now()->startOfSecond(),
                    'updated_at' => now(),
                ]);

                return $existing;
            }
            if ($lockedIntent->status !== 'reserved' || now()->greaterThanOrEqualTo($lockedIntent->expires_at)) {
                throw new V2FincodeException('CARD_INTENT_EXPIRED', 409, 'The card registration has expired.');
            }
            if ($this->activeCardCount($user->id) >= self::MAX_CARDS) {
                throw new V2FincodeException('CARD_LIMIT_REACHED', 409, 'A maximum of three cards may be registered.');
            }
            $publicId = (string) Str::uuid7();
            DB::table('fincode_cards')->insert([
                'public_id' => $publicId,
                'user_id' => $user->id,
                'fincode_customer_id' => $lockedIntent->fincode_customer_id,
                'provider_card_id' => $providerCardId,
                'brand' => $safe['brand'],
                'last4' => $safe['last4'],
                'expire_month' => $safe['expire_month'],
                'expire_year' => $safe['expire_year'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('fincode_card_registration_intents')->where('id', $lockedIntent->id)->update([
                'status' => 'completed',
                'completed_at' => now()->startOfSecond(),
                'updated_at' => now(),
            ]);

            return DB::table('fincode_cards')->where('public_id', $publicId)->firstOrFail();
        });

        return $this->present($card);
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

    /** @return array{brand: ?string, last4: string, expire_month: int, expire_year: int} */
    private function safeCard(
        array $providerCard,
        string $expectedCardId,
        string $expectedCustomerId
    ): array
    {
        if (
            ($providerCard['id'] ?? null) !== $expectedCardId
            || ($providerCard['customer_id'] ?? null) !== $expectedCustomerId
        ) {
            throw new V2FincodeException('FINCODE_CARD_RESPONSE_INVALID', 503, 'The card response is invalid.', true);
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
    private function present(object $card): array
    {
        $expired = $this->expired((int) $card->expire_year, (int) $card->expire_month);

        return [
            'id' => $card->public_id,
            'brand' => $card->brand,
            'last4' => $card->last4,
            'expiration' => [
                'month' => (int) $card->expire_month,
                'year' => (int) $card->expire_year,
            ],
            'is_expired' => $expired,
            'can_pay' => ! $expired,
            'last_used_at' => $card->last_used_at === null
                ? null
                : CarbonImmutable::parse($card->last_used_at)->utc()->toIso8601ZuluString(),
        ];
    }

    private function activeCardCount(int $userId): int
    {
        return DB::table('fincode_cards')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->count();
    }

    private function expired(int $year, int $month): bool
    {
        return CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')
            ->endOfMonth()
            ->isPast();
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
