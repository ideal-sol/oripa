<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Contracts\V2SuspiciousRecoveryBoundary;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\PasswordResetToken;
use App\Models\V2\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class V2PasswordRecoveryService
{
    public const GENERIC_ACCEPTED = 'If the account is eligible, password reset instructions will be sent.';

    public function __construct(
        private readonly V2EmailNormalizer $emails,
        private readonly V2PasswordPolicy $passwords,
        private readonly V2SecureToken $tokens,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2OutboxService $outbox,
        private readonly V2SecurityEventSink $events,
        private readonly V2SuspiciousRecoveryBoundary $suspiciousRecovery
    ) {
    }

    public function request(
        #[SensitiveParameter] string $email,
        string $redirectPath,
        string $ip
    ): void
    {
        $normalized = $this->emails->normalize($email);
        $this->assertRedirectAllowed($redirectPath);
        try {
            $this->rateLimiter->assertGlobal('password_reset_ip', $ip);
            $this->rateLimiter->assertSubject('password_reset_account', $normalized);
        } catch (V2AuthenticationException $exception) {
            $this->events->record('password_reset_rate_limited', [
                'realm' => 'user',
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }

        $candidate = User::query()
            ->where('email_normalized', $normalized)
            ->whereNotNull('email_verified_at')
            ->whereIn('state', $this->eligibleStateValues())
            ->first();
        if (! $candidate instanceof User) {
            $this->recordGenericRequest();

            return;
        }

        DB::transaction(function () use ($candidate, $redirectPath): void {
            $user = User::query()->whereKey($candidate->getKey())->lockForUpdate()->first();
            if (! $user instanceof User || ! $this->eligible($user)) {
                $this->recordGenericRequest();

                return;
            }

            $now = now()->startOfSecond();
            PasswordResetToken::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);
            $rawToken = $this->tokens->generate();
            $reset = PasswordResetToken::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => $this->tokens->hash($rawToken),
                'redirect_path' => $redirectPath,
                'failed_attempts' => 0,
                'expires_at' => $now->copy()->addMinutes(
                    (int) config('v2_identity.password_reset.ttl_minutes', 60)
                ),
                'created_at' => $now,
            ]);
            $message = Crypt::encryptString(json_encode([
                'recipient' => $user->email_display,
                'user_public_id' => $user->public_id,
                'reset_token' => $rawToken,
                'redirect_path' => $redirectPath,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $this->outbox->enqueue(
                'identity.password-reset',
                'user',
                $user->public_id,
                'identity.password_reset.requested',
                ['message_ciphertext' => $message, 'encryption_format' => 'laravel-v1'],
                'password-reset:'.$reset->public_id
            );
            $this->events->record('password_reset_requested', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'result' => 'accepted',
            ]);
        }, 3);
    }

    /** @return array{redirect_path: string} */
    public function confirm(
        string $userPublicId,
        #[SensitiveParameter] string $token,
        #[SensitiveParameter] string $newPassword
    ): array {
        $tokenHash = $this->tokens->hash($token);
        try {
            $this->rateLimiter->assertSubject(
                'password_reset_confirm',
                'account|'.$userPublicId
            );
            $this->rateLimiter->assertSubject(
                'password_reset_confirm',
                'token|'.$tokenHash
            );
        } catch (V2AuthenticationException $exception) {
            $this->events->record('password_reset_rate_limited', [
                'realm' => 'user',
                'subject_id' => $userPublicId,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }

        $result = DB::transaction(function () use (
            $userPublicId,
            $tokenHash,
            $newPassword
        ): array {
            $candidate = PasswordResetToken::query()
                ->where('token_hash', $tokenHash)
                ->first(['id', 'user_id']);
            if (! $candidate instanceof PasswordResetToken) {
                $this->recordResetFailure($userPublicId);

                return ['failure' => 'invalid'];
            }

            $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
            $reset = PasswordResetToken::query()
                ->whereKey($candidate->getKey())
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            if (! $reset instanceof PasswordResetToken) {
                $this->recordResetFailure(
                    $user instanceof User ? $user->public_id : $userPublicId
                );

                return ['failure' => 'invalid'];
            }
            $belongsToUser = $user instanceof User
                && hash_equals($user->public_id, $userPublicId);
            $eligible = $user instanceof User && $this->eligible($user);
            $maximumAttempts = (int) config(
                'v2_identity.password_reset.maximum_attempts',
                5
            );
            $active = $reset->used_at === null
                && $reset->revoked_at === null
                && $reset->expires_at->isFuture()
                && $reset->failed_attempts < $maximumAttempts;
            if (! $belongsToUser || ! $active || ! $eligible) {
                if ($reset->used_at === null && $reset->revoked_at === null) {
                    $attempts = min($maximumAttempts, $reset->failed_attempts + 1);
                    $reset->forceFill([
                        'failed_attempts' => $attempts,
                        'revoked_at' => $attempts >= $maximumAttempts
                            || ! $reset->expires_at->isFuture()
                            || ! $eligible
                                ? now()->startOfSecond()
                                : null,
                    ])->save();
                }
                $this->recordResetFailure(
                    $user instanceof User ? $user->public_id : $userPublicId
                );

                return ['failure' => 'invalid'];
            }

            if (! $this->passwords->isAllowed($newPassword)) {
                return ['failure' => 'password_policy'];
            }
            $passwordHash = $this->passwords->hash($newPassword);
            $now = now()->startOfSecond();
            $user->forceFill([
                'password_hash' => $passwordHash,
                'password_login_enabled' => true,
            ])->save();
            $reset->forceFill(['used_at' => $now])->save();
            PasswordResetToken::query()
                ->where('user_id', $user->getKey())
                ->whereKeyNot($reset->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);
            $sessionCount = DB::table('user_sessions')
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);
            $rememberCount = DB::table('user_remember_devices')
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);
            $this->outbox->enqueue(
                'identity.password-changed',
                'user',
                $user->public_id,
                'identity.password_changed.notification_requested',
                [
                    'message_ciphertext' => Crypt::encryptString(json_encode([
                        'recipient' => $user->email_display,
                        'user_public_id' => $user->public_id,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'encryption_format' => 'laravel-v1',
                ],
                'password-changed:'.$reset->public_id
            );
            if ($this->suspiciousRecovery->requiresSecurityHold($user, [])) {
                $this->outbox->enqueue(
                    'identity.security-hold',
                    'user',
                    $user->public_id,
                    'identity.security_hold.requested',
                    ['reason_code' => 'verified_recovery_signal'],
                    'security-hold:'.$reset->public_id
                );
            }
            $this->events->record('password_reset_succeeded', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'result' => 'sessions_revoked',
            ]);
            $this->events->record('user_sessions_revoked', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'result' => 'sessions:'.$sessionCount.';devices:'.$rememberCount,
            ]);

            return ['redirect_path' => $reset->redirect_path];
        }, 3);

        if (($result['failure'] ?? null) === 'password_policy') {
            throw new V2AuthenticationException(
                'PASSWORD_POLICY_VIOLATION',
                422,
                V2PasswordPolicy::GENERIC_ERROR
            );
        }
        if (($result['failure'] ?? null) !== null) {
            throw new V2AuthenticationException(
                'INVALID_PASSWORD_RESET',
                410,
                'The password reset request is invalid or expired.'
            );
        }

        return $result;
    }

    private function eligible(User $user): bool
    {
        return $user->email_verified_at !== null
            && in_array($user->state, [V2UserState::Active, V2UserState::Restricted], true);
    }

    /** @return list<string> */
    private function eligibleStateValues(): array
    {
        return [V2UserState::Active->value, V2UserState::Restricted->value];
    }

    private function recordGenericRequest(): void
    {
        $this->events->record('password_reset_requested', [
            'realm' => 'user',
            'result' => 'generic',
        ]);
    }

    private function recordResetFailure(string $subjectId): void
    {
        $this->events->record('password_reset_failed', [
            'realm' => 'user',
            'subject_id' => $subjectId,
            'reason' => 'invalid_or_expired',
        ]);
    }

    private function assertRedirectAllowed(string $path): void
    {
        $allowed = config('v2_identity.password_reset.redirect_allowlist', []);
        if (
            ! str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || parse_url($path, PHP_URL_HOST) !== null
            || ! is_array($allowed)
            || ! in_array($path, $allowed, true)
        ) {
            throw new V2AuthenticationException(
                'INVALID_REDIRECT',
                422,
                'The reset redirect is not allowed.'
            );
        }
    }
}
