<?php

namespace App\Domain\Sms\Services;

use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Sms\Contracts\V2SmsProvider;
use App\Domain\Sms\Values\V2SmsDeliveryResult;
use App\Models\V2\OutboxMessage;
use App\Models\V2\SmsVerificationChallenge;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class V2SmsDeliveryWorker
{
    private const TOPIC = 'identity.sms-verification';

    public function __construct(
        private readonly V2OutboxService $outbox,
        private readonly V2SmsProvider $provider
    ) {
    }

    public function run(string $worker, int $limit = 10): int
    {
        $messages = $this->outbox->claim($worker, $limit, null, [self::TOPIC]);
        foreach ($messages as $message) {
            $this->process($message, $worker);
        }

        return $messages->count();
    }

    private function process(OutboxMessage $message, string $worker): void
    {
        try {
            [$challenge, $phone, $code] = $this->prepare($message);
        } catch (Throwable) {
            $this->outbox->markFailed($message->public_id, $worker, 'sms_delivery_payload_invalid');

            return;
        }
        if ($challenge === null) {
            $this->outbox->markFailed($message->public_id, $worker, 'sms_delivery_unavailable');

            return;
        }
        if ($challenge->delivery_state === 'accepted') {
            $this->outbox->markDelivered($message->public_id, $worker);

            return;
        }
        if ($challenge->delivery_state !== 'sending') {
            $this->outbox->markFailed(
                $message->public_id,
                $worker,
                (string) ($challenge->delivery_error_category ?? 'sms_delivery_unavailable')
            );

            return;
        }

        try {
            $result = $this->provider->deliver($phone, $code);
        } catch (Throwable) {
            $result = V2SmsDeliveryResult::unknown('provider_unavailable');
        }
        $state = $this->persist($challenge->id, $result);
        if ($state === 'accepted') {
            $this->outbox->markDelivered($message->public_id, $worker);
        } else {
            $this->outbox->markFailed(
                $message->public_id,
                $worker,
                (string) ($result->errorCategory ?? 'sms_delivery_unavailable')
            );
        }
    }

    /** @return array{0: ?SmsVerificationChallenge, 1: string, 2: string} */
    private function prepare(OutboxMessage $message): array
    {
        [$challengePublicId, $phone, $code] = $this->payload($message);

        return DB::transaction(function () use ($message, $challengePublicId, $phone, $code): array {
            $challenge = SmsVerificationChallenge::query()
                ->where('public_id', $challengePublicId)
                ->lockForUpdate()
                ->first();
            if ($challenge === null) {
                return [null, $phone, $code];
            }
            $userPublicId = DB::table('users')
                ->where('id', $challenge->user_id)
                ->value('public_id');
            try {
                $challengePhone = Crypt::decryptString($challenge->phone_ciphertext);
            } catch (Throwable) {
                $challengePhone = null;
            }
            if (
                ! is_string($userPublicId)
                || ! hash_equals($message->aggregate_public_id, $userPublicId)
                || ! is_string($challengePhone)
                || ! hash_equals($challengePhone, $phone)
                || ! hash_equals($challenge->code_hash, hash('sha256', $code))
            ) {
                $now = now()->startOfSecond();
                $challenge->forceFill([
                    'delivery_state' => 'failed',
                    'delivery_error_category' => 'sms_delivery_payload_invalid',
                    'delivery_attempted_at' => $now,
                    'delivery_failed_at' => $now,
                    'revoked_at' => $challenge->revoked_at ?? $now,
                ])->save();

                return [$challenge->refresh(), $phone, $code];
            }
            if ($challenge->delivery_state === 'accepted') {
                return [$challenge, $phone, $code];
            }
            if ($challenge->delivery_state === 'sending') {
                $challenge->forceFill([
                    'delivery_state' => 'unknown',
                    'delivery_error_category' => 'provider_ambiguous_outcome',
                    'delivery_failed_at' => now()->startOfSecond(),
                    'revoked_at' => $challenge->revoked_at ?? now()->startOfSecond(),
                ])->save();

                return [$challenge->refresh(), $phone, $code];
            }
            if (
                $challenge->delivery_state !== 'pending'
                || $challenge->used_at !== null
                || $challenge->revoked_at !== null
                || ! $challenge->expires_at->isFuture()
            ) {
                if ($challenge->delivery_state === 'pending') {
                    $now = now()->startOfSecond();
                    $challenge->forceFill([
                        'delivery_state' => 'failed',
                        'delivery_error_category' => 'challenge_unavailable',
                        'delivery_attempted_at' => $now,
                        'delivery_failed_at' => $now,
                        'revoked_at' => $challenge->revoked_at ?? $now,
                    ])->save();
                }

                return [$challenge->refresh(), $phone, $code];
            }
            $challenge->forceFill([
                'delivery_state' => 'sending',
                'delivery_attempted_at' => now()->startOfSecond(),
            ])->save();

            return [$challenge->refresh(), $phone, $code];
        }, 3);
    }

    private function persist(int $challengeId, V2SmsDeliveryResult $result): string
    {
        return DB::transaction(function () use ($challengeId, $result): string {
            $challenge = SmsVerificationChallenge::query()->lockForUpdate()->findOrFail($challengeId);
            if ($challenge->delivery_state !== 'sending') {
                return $challenge->delivery_state;
            }
            $now = now()->startOfSecond();
            $challenge->forceFill([
                'delivery_state' => $result->state,
                'provider_request_id' => $result->providerRequestId,
                'delivery_error_category' => $result->errorCategory,
                'delivery_accepted_at' => $result->state === 'accepted' ? $now : null,
                'delivery_failed_at' => $result->state === 'accepted' ? null : $now,
                'revoked_at' => $result->state === 'accepted' ? null : $now,
            ])->save();

            return $result->state;
        }, 3);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function payload(OutboxMessage $message): array
    {
        if (
            $message->topic !== self::TOPIC
            || $message->aggregate_type !== 'user'
            || ! is_string($message->aggregate_public_id)
            || ! Str::isUuid($message->aggregate_public_id)
            || ($message->payload['encryption_format'] ?? null) !== 'laravel-v1'
            || ! is_string($message->payload['message_ciphertext'] ?? null)
        ) {
            throw new RuntimeException('SMS Outbox message is invalid.');
        }
        $payload = json_decode(
            Crypt::decryptString($message->payload['message_ciphertext']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        if (! is_array($payload) || array_keys($payload) !== [
            'challenge_public_id',
            'recipient',
            'verification_code',
        ]) {
            throw new RuntimeException('SMS Outbox payload is invalid.');
        }
        $challengePublicId = $payload['challenge_public_id'] ?? null;
        $phone = $payload['recipient'] ?? null;
        $code = $payload['verification_code'] ?? null;
        if (
            ! is_string($challengePublicId)
            || ! Str::isUuid($challengePublicId)
            || ! is_string($phone)
            || ! preg_match('/\A\+81(?:70|80|90)[0-9]{8}\z/', $phone)
            || ! is_string($code)
            || ! preg_match('/\A[0-9]{6}\z/', $code)
        ) {
            throw new RuntimeException('SMS Outbox payload is invalid.');
        }

        return [$challengePublicId, $phone, $code];
    }
}
