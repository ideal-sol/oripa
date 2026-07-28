<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\SmsVerificationChallenge;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use App\Models\V2\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class V2SmsVerificationService
{
    public function __construct(
        private readonly V2PhoneNormalizer $phones,
        private readonly V2IdentityCorrelation $correlation,
        private readonly V2SecureToken $tokens,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2SessionManager $sessions,
        private readonly V2OutboxService $outbox,
        private readonly V2SecurityEventSink $events
    ) {
    }

    /** @return array<string, mixed> */
    public function status(User $user): array
    {
        $phone = UserPhoneNumber::query()->where('user_id', $user->getKey())->first();
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        return [
            'verified' => $phone?->verified_at !== null && $phone->revoked_at === null,
            'phone_masked' => $phone === null || $phone->revoked_at !== null
                ? null
                : $this->phones->mask(Crypt::decryptString($phone->phone_ciphertext)),
            'challenge' => $challenge === null ? null : [
                'id' => $challenge->public_id,
                'status' => $challenge->expires_at->isFuture() ? 'pending' : 'expired',
                'expires_at' => $challenge->expires_at->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function send(User $user, Request $request, string $phone, string $ip): array
    {
        $this->assertFresh($request, $user);
        try {
            $normalized = $this->phones->normalize($phone);
        } catch (\InvalidArgumentException) {
            throw $this->invalid();
        }

        return $this->issueChallenge($user, $normalized, $ip, false);
    }

    /** @return array<string, mixed> */
    public function resend(User $user, Request $request, string $ip): array
    {
        $this->assertFresh($request, $user);
        $current = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
        if ($current === null) {
            throw $this->invalid();
        }

        return $this->issueChallenge(
            $user,
            Crypt::decryptString($current->phone_ciphertext),
            $ip,
            true
        );
    }

    /**
     * @return array{status: array<string, mixed>, session: array{token: string, absolute_expires_at: \DateTimeInterface}}
     */
    public function verify(
        User $user,
        Request $request,
        string $challengePublicId,
        #[SensitiveParameter] string $code
    ): array {
        if (! preg_match('/\A[0-9]{6}\z/', $code)) {
            throw $this->invalid();
        }
        $this->assertFresh($request, $user);
        try {
            $this->rateLimiter->assertSubject('sms_verify', $challengePublicId);
        } catch (V2AuthenticationException $exception) {
            $this->events->record('sms_verification_rate_limited', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }

        $result = DB::transaction(function () use (
            $user,
            $request,
            $challengePublicId,
            $code
        ): ?array {
            $session = $this->freshSession($request, $user, true);
            $challenge = SmsVerificationChallenge::query()
                ->where('public_id', $challengePublicId)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();
            if (
                $challenge === null
                || $challenge->used_at !== null
                || $challenge->revoked_at !== null
                || ! $challenge->expires_at->isFuture()
                || $challenge->failed_attempts >= 5
                || ! hash_equals($challenge->code_hash, $this->tokens->hash($code))
            ) {
                if ($challenge !== null && $challenge->used_at === null && $challenge->revoked_at === null) {
                    $attempts = min(5, $challenge->failed_attempts + 1);
                    $challenge->forceFill([
                        'failed_attempts' => $attempts,
                        'revoked_at' => $attempts >= 5 || ! $challenge->expires_at->isFuture()
                            ? now()
                            : null,
                    ])->save();
                }
                $this->events->record('sms_verification_failed', [
                    'realm' => 'user',
                    'subject_id' => $user->public_id,
                    'reason' => 'invalid_or_expired',
                ]);

                return null;
            }

            $this->releaseWithdrawnOwnerOrReject(
                $challenge->phone_hmac,
                (int) $user->getKey()
            );
            $now = now()->startOfSecond();
            $existing = UserPhoneNumber::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();
            $changed = $existing !== null
                && $existing->verified_at !== null
                && ! hash_equals($existing->phone_hmac, $challenge->phone_hmac);
            UserPhoneNumber::query()->updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'phone_ciphertext' => $challenge->phone_ciphertext,
                    'phone_hmac' => $challenge->phone_hmac,
                    'verified_at' => $now,
                    'revoked_at' => null,
                ]
            );
            $challenge->forceFill(['used_at' => $now])->save();
            SmsVerificationChallenge::query()
                ->where('user_id', $user->getKey())
                ->whereKeyNot($challenge->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);
            $rotated = $this->sessions->rotateLockedUserSession($session);
            $this->outbox->enqueue(
                'identity.phone-verified',
                'user',
                $user->public_id,
                'identity.phone_verified.notification_requested',
                [
                    'message_ciphertext' => Crypt::encryptString(json_encode([
                        'recipient' => $user->email_display,
                        'user_public_id' => $user->public_id,
                        'phone_changed' => $changed,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'encryption_format' => 'laravel-v1',
                ],
                'phone-verified:'.$challenge->public_id
            );
            $this->events->record('sms_verification_succeeded', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'result' => $changed ? 'phone_changed' : 'phone_verified',
            ]);
            if ($changed) {
                $this->events->record('phone_changed', [
                    'realm' => 'user',
                    'subject_id' => $user->public_id,
                ]);
            }

            return [
                'status' => $this->status($user),
                'session' => $rotated,
            ];
        }, 3);

        if ($result === null) {
            throw $this->invalid();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function issueChallenge(
        User $user,
        string $normalized,
        string $ip,
        bool $resend
    ): array {
        try {
            $this->rateLimiter->assertSubject('sms_phone_hour', $normalized);
            $this->rateLimiter->assertSubject('sms_phone_day', $normalized);
            $this->rateLimiter->assertGlobal('sms_ip', $ip);
        } catch (V2AuthenticationException $exception) {
            $this->events->record('sms_verification_rate_limited', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }
        $phoneHmac = $this->correlation->hash($normalized);
        $code = sprintf('%06d', random_int(0, 999999));

        return DB::transaction(function () use (
            $user,
            $normalized,
            $phoneHmac,
            $code,
            $resend
        ): array {
            $this->releaseWithdrawnOwnerOrReject($phoneHmac, (int) $user->getKey());
            $old = SmsVerificationChallenge::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $attempts = $old?->failed_attempts ?? 0;
            if ($old !== null) {
                $old->forceFill(['revoked_at' => now()])->save();
            }
            $currentPhone = UserPhoneNumber::query()
                ->where('user_id', $user->getKey())
                ->whereNotNull('verified_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if ($currentPhone !== null && hash_equals($currentPhone->phone_hmac, $phoneHmac)) {
                throw new V2AuthenticationException(
                    'PHONE_ALREADY_VERIFIED',
                    409,
                    'The phone number is already verified.'
                );
            }
            if ($currentPhone !== null) {
                $currentPhone->forceFill([
                    'verified_at' => null,
                    'revoked_at' => now(),
                ])->save();
            }
            $now = now()->startOfSecond();
            $challenge = SmsVerificationChallenge::query()->create([
                'user_id' => $user->getKey(),
                'phone_ciphertext' => Crypt::encryptString($normalized),
                'phone_hmac' => $phoneHmac,
                'code_hash' => $this->tokens->hash($code),
                'purpose' => $currentPhone === null ? 'registration' : 'phone_change',
                'failed_attempts' => $attempts,
                'expires_at' => $now->copy()->addMinutes(
                    (int) config('v2_identity.sms_verification.ttl_minutes', 5)
                ),
                'sent_at' => $now,
            ]);
            $this->outbox->enqueue(
                'identity.sms-verification',
                'user',
                $user->public_id,
                'identity.sms_verification.requested',
                [
                    'message_ciphertext' => Crypt::encryptString(json_encode([
                        'recipient' => $normalized,
                        'verification_code' => $code,
                        'challenge_public_id' => $challenge->public_id,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'encryption_format' => 'laravel-v1',
                ],
                'sms-verification:'.$challenge->public_id
            );
            $this->events->record('sms_verification_sent', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'result' => $resend ? 'resent' : 'sent',
            ]);

            return [
                'accepted' => true,
                'challenge_id' => $challenge->public_id,
                'phone_masked' => $this->phones->mask($normalized),
                'expires_at' => $challenge->expires_at->toIso8601String(),
            ];
        }, 3);
    }

    private function releaseWithdrawnOwnerOrReject(string $phoneHmac, int $userId): void
    {
        $holder = UserPhoneNumber::query()
            ->where('phone_hmac', $phoneHmac)
            ->where('user_id', '<>', $userId)
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->first();
        if ($holder === null) {
            return;
        }
        $releasedOwner = User::query()
            ->whereKey($holder->user_id)
            ->whereIn('state', [
                V2UserState::Closed->value,
                V2UserState::Anonymized->value,
            ])
            ->exists();
        if ($releasedOwner) {
            $holder->forceFill(['revoked_at' => now()])->save();

            return;
        }
        throw new V2AuthenticationException(
            'PHONE_NUMBER_UNAVAILABLE',
            409,
            'The phone number cannot be verified.'
        );
    }

    private function assertFresh(Request $request, User $user): void
    {
        $this->freshSession($request, $user);
    }

    private function freshSession(
        Request $request,
        User $user,
        bool $lock = false
    ): UserSession {
        try {
            return $this->sessions->requireFreshUserSession(
                $request,
                (int) $user->getKey(),
                $lock
            );
        } catch (\RuntimeException) {
            throw new V2AuthenticationException(
                'FRESH_AUTHENTICATION_REQUIRED',
                403,
                'Fresh authentication is required.'
            );
        }
    }

    private function invalid(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_SMS_VERIFICATION',
            422,
            'The SMS verification request could not be completed.'
        );
    }
}
