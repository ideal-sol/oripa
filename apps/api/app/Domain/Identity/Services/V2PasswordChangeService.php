<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class V2PasswordChangeService
{
    public function __construct(
        private readonly V2PasswordPolicy $passwords,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2SessionManager $sessions,
        private readonly V2OutboxService $outbox,
        private readonly V2SecurityEventSink $events
    ) {
    }

    /** @return array{session: array{token: string, absolute_expires_at: \DateTimeInterface}} */
    public function change(
        User $user,
        Request $request,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword
    ): array {
        try {
            $this->rateLimiter->assertSubject('password_change', $user->public_id);
        } catch (V2AuthenticationException $exception) {
            $this->events->record('password_change_rate_limited', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }

        try {
            $result = DB::transaction(function () use (
                $user,
                $request,
                $currentPassword,
                $newPassword
            ): array {
                $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                try {
                    $session = $this->sessions->requireActiveUserSession(
                        $request,
                        (int) $lockedUser->getKey(),
                        true
                    );
                } catch (\RuntimeException) {
                    return ['failure' => 'session'];
                }
                if (! in_array(
                    $lockedUser->state,
                    [V2UserState::Active, V2UserState::Restricted],
                    true
                )) {
                    return ['failure' => 'session'];
                }
                if (
                    ! $lockedUser->password_login_enabled
                    || ! $this->passwords->verify($currentPassword, $lockedUser->password_hash)
                ) {
                    return ['failure' => 'current_password'];
                }
                if ($this->passwords->verify($newPassword, $lockedUser->password_hash)) {
                    return ['failure' => 'unchanged'];
                }
                if (! $this->passwords->isAllowed($newPassword)) {
                    return ['failure' => 'password_policy'];
                }

                $now = now()->startOfSecond();
                $lockedUser->forceFill([
                    'password_hash' => $this->passwords->hash($newPassword),
                    'password_login_enabled' => true,
                ])->save();
                $revokedSessions = DB::table('user_sessions')
                    ->where('user_id', $lockedUser->getKey())
                    ->where('session_id_hash', '!=', $session->getKey())
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);
                $revokedDevices = DB::table('user_remember_devices')
                    ->where('user_id', $lockedUser->getKey())
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);
                $rotated = $this->sessions->rotateLockedUserSession($session);
                $eventId = (string) Str::uuid7();
                $this->outbox->enqueue(
                    'identity.password-changed',
                    'user',
                    $lockedUser->public_id,
                    'identity.password_changed.notification_requested',
                    [
                        'message_ciphertext' => Crypt::encryptString(json_encode([
                            'recipient' => $lockedUser->email_display,
                            'user_public_id' => $lockedUser->public_id,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                        'encryption_format' => 'laravel-v1',
                    ],
                    'password-changed:'.$eventId
                );
                $this->events->record('password_change_succeeded', [
                    'realm' => 'user',
                    'subject_id' => $lockedUser->public_id,
                    'result' => 'session_rotated',
                ]);
                $this->events->record('user_sessions_revoked', [
                    'realm' => 'user',
                    'subject_id' => $lockedUser->public_id,
                    'result' => 'sessions:'.$revokedSessions.';devices:'.$revokedDevices,
                ]);

                return ['session' => $rotated];
            }, 3);
        } catch (V2AuthenticationException $exception) {
            throw $exception;
        }

        if (isset($result['session']) && is_array($result['session'])) {
            return ['session' => $result['session']];
        }

        $failure = $result['failure'] ?? null;
        if (is_string($failure)) {
            $this->events->record('password_change_failed', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'reason' => $failure,
            ]);
        }

        throw match ($failure) {
            'session' => new V2AuthenticationException(
                'SESSION_EXPIRED',
                401,
                'The user session has expired.'
            ),
            'current_password' => new V2AuthenticationException(
                'INVALID_REAUTHENTICATION',
                401,
                'The current password could not be verified.'
            ),
            'unchanged' => new V2AuthenticationException(
                'PASSWORD_UNCHANGED',
                422,
                'The new password must differ from the current password.'
            ),
            'password_policy' => new V2AuthenticationException(
                'PASSWORD_POLICY_VIOLATION',
                422,
                V2PasswordPolicy::GENERIC_ERROR
            ),
            default => new \LogicException('Password Change result is invalid.'),
        };
    }
}
