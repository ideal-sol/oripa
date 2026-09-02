<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Referral\Services\V2ReferralRewardService;
use App\Models\V2\SmsVerificationChallenge;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use App\Models\V2\UserSession;
use Illuminate\Database\QueryException;
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
        private readonly V2ReferralRewardService $referrals,
        private readonly V2OutboxService $outbox,
        private readonly V2TemplateMailDeliveryService $mail,
        private readonly V2SecurityEventSink $events
    ) {
    }

    /** @return array<string, mixed> */
    public function status(User $user): array
    {
        $this->assertEligibleUser($user);
        $phone = UserPhoneNumber::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->first();
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->first();
        if ($challenge?->used_at !== null) {
            $challenge = null;
        }
        $canonicalPhone = $phone === null
            ? null
            : Crypt::decryptString($phone->phone_ciphertext);

        return [
            'verified' => $phone !== null,
            'phone' => $canonicalPhone,
            'phone_masked' => $canonicalPhone === null ? null : $this->phones->mask($canonicalPhone),
            'verified_at' => $phone?->verified_at?->toIso8601String(),
            'challenge' => $challenge === null ? null : [
                'id' => $challenge->public_id,
                'status' => $this->publicChallengeStatus($challenge),
                'delivery_state' => $this->publicDeliveryState($challenge),
                'expires_at' => $challenge->expires_at->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function send(User $user, Request $request, string $phone): array
    {
        $this->assertEligibleUser($user);
        try {
            $normalized = $this->phones->normalize($phone);
        } catch (\InvalidArgumentException) {
            throw $this->invalid();
        }

        return $this->issueChallenge($user, $request, $normalized, false);
    }

    /** @return array<string, mixed> */
    public function resend(User $user, Request $request): array
    {
        $this->assertEligibleUser($user);
        $current = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->latest('id')
            ->first();
        if ($current === null) {
            throw $this->invalid();
        }

        return $this->issueChallenge(
            $user,
            $request,
            Crypt::decryptString($current->phone_ciphertext),
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
        $this->assertEligibleUser($user);
        if (! preg_match('/\A[0-9]{6}\z/', $code)) {
            throw $this->invalid();
        }
        try {
            $result = DB::transaction(function () use (
                $user,
                $request,
                $challengePublicId,
                $code
            ): ?array {
                User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $challenge = SmsVerificationChallenge::query()
                    ->where('public_id', $challengePublicId)
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first();
                if (
                    $challenge === null
                    || $challenge->used_at !== null
                    || ! $challenge->expires_at->isFuture()
                ) {
                    if (
                        $challenge !== null
                        && $challenge->used_at === null
                        && $challenge->revoked_at === null
                        && ! $challenge->expires_at->isFuture()
                    ) {
                        $challenge->forceFill(['revoked_at' => now()->startOfSecond()])->save();
                    }
                    $this->recordFailure($user, 'invalid_or_expired');

                    return null;
                }
                $session = $this->session(
                    $request,
                    $user,
                    $challenge->purpose === 'phone_change',
                    true
                );
                if ($challenge->delivery_state === 'pending' || $challenge->delivery_state === 'sending') {
                    throw new V2AuthenticationException(
                        'SMS_DELIVERY_PENDING',
                        409,
                        'SMS delivery is still being processed.',
                        true,
                        2
                    );
                }
                if ($challenge->delivery_state !== 'accepted') {
                    throw new V2AuthenticationException(
                        'SMS_DELIVERY_UNAVAILABLE',
                        503,
                        'SMS delivery is unavailable.',
                        true,
                        60
                    );
                }
                if ($challenge->revoked_at !== null && $challenge->failed_attempts < 5) {
                    $this->recordFailure($user, 'invalid_or_expired');

                    return null;
                }
                $this->rateLimiter->assertSubject('sms_verify', $challengePublicId);
                if ($challenge->revoked_at !== null) {
                    $this->recordFailure($user, 'invalid_or_expired');

                    return null;
                }
                if (! hash_equals($challenge->code_hash, $this->tokens->hash($code))) {
                    $attempts = min(5, $challenge->failed_attempts + 1);
                    $challenge->forceFill([
                        'failed_attempts' => $attempts,
                        'revoked_at' => $attempts >= 5 ? now()->startOfSecond() : null,
                    ])->save();
                    $this->recordFailure($user, 'invalid_code');

                    return null;
                }

                $phoneHashes = $this->correlation->hashes(
                    Crypt::decryptString($challenge->phone_ciphertext)
                );
                $this->lockPhoneHashes($phoneHashes);
                $this->releaseWithdrawnOwnerOrReject($phoneHashes, (int) $user->getKey());
                $now = now()->startOfSecond();
                $existing = UserPhoneNumber::query()
                    ->where('user_id', $user->getKey())
                    ->lockForUpdate()
                    ->first();
                $hadVerifiedPhone = $existing !== null
                    && $existing->verified_at !== null
                    && $existing->revoked_at === null;
                $changed = $hadVerifiedPhone
                    && ! $this->matchesHash($existing->phone_hmac, $phoneHashes);
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
                if (! $hadVerifiedPhone) {
                    $this->referrals->rewardForReferredUser($user);
                }
                if ($changed) {
                    $this->mail->schedule(
                        'phone_changed',
                        'user.phone_changed:'.$challenge->public_id,
                        'user',
                        $user->public_id
                    );
                }
                $rotated = $this->sessions
                    ->rotateLockedUserSessionPreservingReauthentication($session);
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
        } catch (V2AuthenticationException $exception) {
            if (in_array($exception->errorCode, ['RATE_LIMITED', 'AUTH_SERVICE_UNAVAILABLE'], true)) {
                $this->recordRateLimit($user, $exception);
            }
            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isPhoneUniquenessViolation($exception)) {
                throw $this->phoneUnavailable();
            }
            throw $exception;
        }

        if ($result === null) {
            throw $this->invalid();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function issueChallenge(
        User $user,
        Request $request,
        string $normalized,
        bool $resend
    ): array {
        $phoneHashes = $this->correlation->hashes($normalized);
        $this->assertCooldown($user, $phoneHashes);
        try {
            $this->rateLimiter->assertSubject('sms_phone_hour', $phoneHashes[0]);
            $this->rateLimiter->assertSubject('sms_phone_day', $phoneHashes[0]);
        } catch (V2AuthenticationException $exception) {
            $this->recordRateLimit($user, $exception);
            throw $exception;
        }
        $code = sprintf('%06d', random_int(0, 999999));

        return DB::transaction(function () use (
            $user,
            $request,
            $normalized,
            $phoneHashes,
            $code,
            $resend
        ): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $this->assertCooldown($user, $phoneHashes, true);
            $currentPhone = UserPhoneNumber::query()
                ->where('user_id', $user->getKey())
                ->whereNotNull('verified_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if ($currentPhone !== null && $this->matchesHash($currentPhone->phone_hmac, $phoneHashes)) {
                throw new V2AuthenticationException(
                    'PHONE_ALREADY_VERIFIED',
                    409,
                    'The phone number is already verified.'
                );
            }
            $purpose = $currentPhone === null ? 'registration' : 'phone_change';
            $this->session($request, $user, $purpose === 'phone_change', true);
            $old = SmsVerificationChallenge::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $now = now()->startOfSecond();
            if ($old !== null) {
                $old->forceFill(['revoked_at' => $now])->save();
            }
            $challenge = SmsVerificationChallenge::query()->create([
                'user_id' => $user->getKey(),
                'phone_ciphertext' => Crypt::encryptString($normalized),
                'phone_hmac' => $phoneHashes[0],
                'code_hash' => $this->tokens->hash($code),
                'purpose' => $purpose,
                'failed_attempts' => 0,
                'expires_at' => $now->copy()->addMinutes(
                    (int) config('v2_identity.sms_verification.ttl_minutes', 5)
                ),
                'sent_at' => $now,
                'delivery_state' => 'pending',
                'provider_identifier' => 'sms_fours',
            ]);
            $this->outbox->enqueue(
                'identity.sms-verification',
                'user',
                $user->public_id,
                'identity.sms_verification.requested',
                [
                    'message_ciphertext' => Crypt::encryptString(json_encode([
                        'challenge_public_id' => $challenge->public_id,
                        'recipient' => $normalized,
                        'verification_code' => $code,
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
                'status' => 'pending',
                'delivery_state' => 'pending',
                'expires_at' => $challenge->expires_at->toIso8601String(),
            ];
        }, 3);
    }

    /** @param list<string> $phoneHashes */
    private function assertCooldown(User $user, array $phoneHashes, bool $lock = false): void
    {
        $query = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereIn('phone_hmac', $phoneHashes)
            ->latest('sent_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $latest = $query->first();
        if ($latest === null) {
            return;
        }
        $availableAt = $latest->sent_at->addSeconds(
            (int) config('v2_identity.sms_verification.resend_cooldown_seconds', 60)
        );
        if ($availableAt->isFuture()) {
            throw new V2AuthenticationException(
                'RATE_LIMITED',
                429,
                'Another SMS request is available after the cooldown.',
                true,
                max(1, $availableAt->getTimestamp() - now()->getTimestamp())
            );
        }
    }

    /** @param list<string> $phoneHashes */
    private function releaseWithdrawnOwnerOrReject(array $phoneHashes, int $userId): void
    {
        $holder = UserPhoneNumber::query()
            ->whereIn('phone_hmac', $phoneHashes)
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
            $holder->forceFill(['revoked_at' => now()->startOfSecond()])->save();

            return;
        }

        throw $this->phoneUnavailable();
    }

    /** @param list<string> $phoneHashes */
    private function lockPhoneHashes(array $phoneHashes): void
    {
        sort($phoneHashes, SORT_STRING);
        foreach (array_unique($phoneHashes) as $phoneHash) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$phoneHash]);
        }
    }

    /** @param list<string> $candidates */
    private function matchesHash(string $stored, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (hash_equals($stored, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function session(
        Request $request,
        User $user,
        bool $fresh,
        bool $lock
    ): UserSession {
        try {
            return $fresh
                ? $this->sessions->requireFreshUserSession($request, (int) $user->getKey(), $lock)
                : $this->sessions->requireActiveUserSession($request, (int) $user->getKey(), $lock);
        } catch (\RuntimeException) {
            throw new V2AuthenticationException(
                $fresh ? 'FRESH_AUTHENTICATION_REQUIRED' : 'SESSION_EXPIRED',
                $fresh ? 403 : 401,
                $fresh ? 'Fresh authentication is required.' : 'The user session has expired.'
            );
        }
    }

    private function assertEligibleUser(User $user): void
    {
        $current = User::query()->whereKey($user->getKey())->first();
        if (
            ! $current instanceof User
            || ! in_array($current->state, [V2UserState::Active, V2UserState::Restricted], true)
        ) {
            throw new V2AuthenticationException(
                'AUTHENTICATION_REQUIRED',
                401,
                'User authentication is required.'
            );
        }
    }

    private function publicChallengeStatus(SmsVerificationChallenge $challenge): string
    {
        if (! $challenge->expires_at->isFuture()) {
            return 'expired';
        }
        if ($challenge->revoked_at !== null || in_array($challenge->delivery_state, ['failed', 'unknown'], true)) {
            return 'failed';
        }

        return $challenge->delivery_state === 'accepted' ? 'accepted' : 'pending';
    }

    private function publicDeliveryState(SmsVerificationChallenge $challenge): string
    {
        return match ($challenge->delivery_state) {
            'accepted' => 'accepted',
            'failed', 'unknown' => 'failed',
            default => 'pending',
        };
    }

    private function isPhoneUniquenessViolation(QueryException $exception): bool
    {
        $detail = (string) ($exception->errorInfo[2] ?? '');

        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($detail, 'user_phone_numbers_verified_unique');
    }

    private function recordRateLimit(User $user, V2AuthenticationException $exception): void
    {
        $this->events->record('sms_verification_rate_limited', [
            'realm' => 'user',
            'subject_id' => $user->public_id,
            'reason' => strtolower($exception->errorCode),
        ]);
    }

    private function recordFailure(User $user, string $reason): void
    {
        $this->events->record('sms_verification_failed', [
            'realm' => 'user',
            'subject_id' => $user->public_id,
            'reason' => $reason,
        ]);
    }

    private function invalid(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_SMS_VERIFICATION',
            422,
            'The SMS verification request could not be completed.'
        );
    }

    private function phoneUnavailable(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'PHONE_NUMBER_UNAVAILABLE',
            409,
            'This phone number is unavailable. Enter another phone number.'
        );
    }
}
