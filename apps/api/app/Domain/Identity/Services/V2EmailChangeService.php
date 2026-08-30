<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\User;
use App\Models\V2\UserEmailChangeRequest;
use App\Models\V2\UserSession;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class V2EmailChangeService
{
    public function __construct(
        private readonly V2EmailNormalizer $emails,
        private readonly V2SecureToken $tokens,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2SessionManager $sessions,
        private readonly V2OutboxService $outbox,
        private readonly V2SecurityEventSink $events
    ) {
    }

    /** @return array{request_id: string, expires_at: \DateTimeInterface} */
    public function start(
        User $user,
        Request $request,
        #[SensitiveParameter] string $newEmail,
        string $redirectPath
    ): array {
        $normalized = $this->emails->normalize($newEmail);
        $display = trim($newEmail);
        $this->assertRedirectAllowed($redirectPath);
        try {
            $this->rateLimiter->assertSubject('email_change_hour', $user->public_id);
            $this->rateLimiter->assertSubject('email_change_day', $user->public_id);
        } catch (V2AuthenticationException $exception) {
            $this->events->record('email_change_rate_limited', [
                'realm' => 'user',
                'subject_id' => $user->public_id,
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }
        if (hash_equals($user->email_normalized, $normalized)) {
            throw $this->unchanged();
        }
        if ($this->claimedByAnotherUser($normalized, (int) $user->getKey())) {
            throw $this->claimed();
        }

        return DB::transaction(function () use (
            $user,
            $request,
            $display,
            $normalized,
            $redirectPath
        ): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $session = $this->activeSession($request, $lockedUser, true);
            if (! $this->eligible($lockedUser)) {
                throw new V2AuthenticationException(
                    'AUTHENTICATION_REQUIRED',
                    401,
                    'Authentication is required.'
                );
            }
            if (hash_equals($lockedUser->email_normalized, $normalized)) {
                throw $this->unchanged();
            }
            if ($this->claimedByAnotherUser($normalized, (int) $lockedUser->getKey())) {
                throw $this->claimed();
            }

            $now = now()->startOfSecond();
            UserEmailChangeRequest::query()
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);
            $rawToken = $this->tokens->generate();
            $change = UserEmailChangeRequest::query()->create([
                'user_id' => $lockedUser->getKey(),
                'new_email_display' => $display,
                'new_email_normalized' => $normalized,
                'token_hash' => $this->tokens->hash($rawToken),
                'initiating_session_hash' => $session->getKey(),
                'redirect_path' => $redirectPath,
                'failed_attempts' => 0,
                'expires_at' => $now->copy()->addMinutes(
                    (int) config('v2_identity.email_change.ttl_minutes', 60)
                ),
                'created_at' => $now,
            ]);
            $this->outbox->enqueue(
                'identity.email-change-verification',
                'user',
                $lockedUser->public_id,
                'identity.email_change.verification_requested',
                [
                    'message_ciphertext' => Crypt::encryptString(json_encode([
                        'recipient' => $display,
                        'user_public_id' => $lockedUser->public_id,
                        'request_public_id' => $change->public_id,
                        'verification_token' => $rawToken,
                        'redirect_path' => $redirectPath,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'encryption_format' => 'laravel-v1',
                ],
                'email-change-verification:'.$change->public_id
            );
            $this->events->record('email_change_requested', [
                'realm' => 'user',
                'subject_id' => $lockedUser->public_id,
                'result' => 'pending_verification',
            ]);

            return [
                'request_id' => $change->public_id,
                'expires_at' => $change->expires_at,
            ];
        }, 3);
    }

    /**
     * @return array{
     *   session: null|array{token: string, absolute_expires_at: \DateTimeInterface},
     *   initiating_session_preserved: bool,
     *   request_session_revoked: bool
     * }
     */
    public function complete(
        string $requestPublicId,
        #[SensitiveParameter] string $token,
        Request $httpRequest
    ): array {
        $tokenHash = $this->tokens->hash($token);
        try {
            $this->rateLimiter->assertSubject(
                'email_change_confirm',
                'request|'.$requestPublicId
            );
            $this->rateLimiter->assertSubject(
                'email_change_confirm',
                'token|'.$tokenHash
            );
        } catch (V2AuthenticationException $exception) {
            $this->events->record('email_change_rate_limited', [
                'realm' => 'user',
                'reason' => strtolower($exception->errorCode),
            ]);
            throw $exception;
        }

        try {
            $result = DB::transaction(function () use (
                $requestPublicId,
                $tokenHash,
                $httpRequest
            ): array {
                $candidate = UserEmailChangeRequest::query()
                    ->where('token_hash', $tokenHash)
                    ->first(['id', 'user_id']);
                if (! $candidate instanceof UserEmailChangeRequest) {
                    $this->recordFailure(null);

                    return ['failure' => true];
                }
                $user = User::query()->whereKey($candidate->user_id)->lockForUpdate()->first();
                $change = UserEmailChangeRequest::query()
                    ->whereKey($candidate->getKey())
                    ->where('token_hash', $tokenHash)
                    ->lockForUpdate()
                    ->first();
                if (! $change instanceof UserEmailChangeRequest) {
                    $this->recordFailure($user instanceof User ? $user->public_id : null);

                    return ['failure' => true];
                }
                $matchesRequest = hash_equals($change->public_id, $requestPublicId);
                $maximumAttempts = (int) config(
                    'v2_identity.email_change.maximum_attempts',
                    5
                );
                $active = $change->used_at === null
                    && $change->revoked_at === null
                    && $change->expires_at->isFuture()
                    && $change->failed_attempts < $maximumAttempts;
                if (! $matchesRequest || ! $active || ! ($user instanceof User && $this->eligible($user))) {
                    if ($change->used_at === null && $change->revoked_at === null) {
                        $attempts = min($maximumAttempts, $change->failed_attempts + 1);
                        $change->forceFill([
                            'failed_attempts' => $attempts,
                            'revoked_at' => $attempts >= $maximumAttempts
                                || ! $change->expires_at->isFuture()
                                || ! ($user instanceof User && $this->eligible($user))
                                    ? now()->startOfSecond()
                                    : null,
                        ])->save();
                    }
                    $this->recordFailure($user instanceof User ? $user->public_id : null);

                    return ['failure' => true];
                }

                $this->lockNormalizedEmail($change->new_email_normalized);
                if ($this->claimedByAnotherUser(
                    $change->new_email_normalized,
                    (int) $user->getKey()
                )) {
                    return ['claimed' => true, 'subject_id' => $user->public_id];
                }

                $initiatingSession = UserSession::query()
                    ->whereKey($change->initiating_session_hash)
                    ->where('user_id', $user->getKey())
                    ->whereNull('revoked_at')
                    ->where('idle_expires_at', '>', now())
                    ->where('absolute_expires_at', '>', now())
                    ->lockForUpdate()
                    ->first();
                $currentSessionHash = $this->sessions->sessionIdHash(
                    $httpRequest,
                    V2Realm::User
                );
                $sameBrowser = $initiatingSession instanceof UserSession
                    && is_string($currentSessionHash)
                    && hash_equals($change->initiating_session_hash, $currentSessionHash);
                $requestSessionBelongsToUser = is_string($currentSessionHash)
                    && UserSession::query()
                        ->whereKey($currentSessionHash)
                        ->where('user_id', $user->getKey())
                        ->whereNull('revoked_at')
                        ->exists();

                $now = now()->startOfSecond();
                $user->forceFill([
                    'email_display' => $change->new_email_display,
                    'email_normalized' => $change->new_email_normalized,
                    'email_verified_at' => $now,
                ])->save();
                $change->forceFill(['used_at' => $now])->save();
                UserEmailChangeRequest::query()
                    ->where('user_id', $user->getKey())
                    ->whereKeyNot($change->getKey())
                    ->whereNull('used_at')
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);
                UserSession::query()
                    ->where('user_id', $user->getKey())
                    ->whereKeyNot($change->initiating_session_hash)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);
                DB::table('user_remember_devices')
                    ->where('user_id', $user->getKey())
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);
                $rotated = $sameBrowser
                    ? $this->sessions
                        ->rotateLockedUserSessionPreservingReauthentication($initiatingSession)
                    : null;
                $this->outbox->enqueue(
                    'identity.email-change-completed',
                    'user',
                    $user->public_id,
                    'identity.email_change.completed_notification_requested',
                    [
                        'message_ciphertext' => Crypt::encryptString(json_encode([
                            'recipient' => $change->new_email_display,
                            'user_public_id' => $user->public_id,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                        'encryption_format' => 'laravel-v1',
                    ],
                    'email-change-completed:'.$change->public_id
                );
                $this->events->record('email_change_succeeded', [
                    'realm' => 'user',
                    'subject_id' => $user->public_id,
                    'result' => $sameBrowser ? 'session_rotated' : 'initiating_session_preserved',
                ]);

                return [
                    'session' => $rotated,
                    'initiating_session_preserved' => $initiatingSession instanceof UserSession,
                    'request_session_revoked' => $requestSessionBelongsToUser && ! $sameBrowser,
                ];
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }
            $this->recordClaimedFailure();
            throw $this->claimed();
        }

        if (($result['claimed'] ?? false) === true) {
            $this->events->record('email_change_failed', [
                'realm' => 'user',
                'subject_id' => $result['subject_id'],
                'reason' => 'email_already_claimed',
            ]);
            throw $this->claimed();
        }
        if (($result['failure'] ?? false) === true) {
            throw $this->invalid();
        }

        return $result;
    }

    private function activeSession(Request $request, User $user, bool $lock): UserSession
    {
        try {
            return $this->sessions->requireActiveUserSession(
                $request,
                (int) $user->getKey(),
                $lock
            );
        } catch (\RuntimeException) {
            throw new V2AuthenticationException(
                'SESSION_EXPIRED',
                401,
                'The user session has expired.'
            );
        }
    }

    private function eligible(User $user): bool
    {
        return $user->email_verified_at !== null
            && in_array($user->state, [V2UserState::Active, V2UserState::Restricted], true);
    }

    private function claimedByAnotherUser(string $normalized, int $userId): bool
    {
        return User::query()
            ->where('email_normalized', $normalized)
            ->whereNotNull('email_verified_at')
            ->where('state', '!=', V2UserState::Closed->value)
            ->whereKeyNot($userId)
            ->exists();
    }

    private function lockNormalizedEmail(string $email): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
        }
    }

    private function recordFailure(?string $subjectId): void
    {
        $context = [
            'realm' => 'user',
            'reason' => 'invalid_or_expired',
        ];
        if ($subjectId !== null) {
            $context['subject_id'] = $subjectId;
        }
        $this->events->record('email_change_failed', $context);
    }

    private function recordClaimedFailure(): void
    {
        $this->events->record('email_change_failed', [
            'realm' => 'user',
            'reason' => 'email_already_claimed',
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            || ($exception->errorInfo[0] ?? null) === '23505';
    }

    private function unchanged(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'EMAIL_UNCHANGED',
            422,
            'The new email address must differ from the current email address.'
        );
    }

    private function claimed(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'EMAIL_ALREADY_CLAIMED',
            409,
            'The email address is already verified by another account.'
        );
    }

    private function invalid(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_EMAIL_CHANGE_REQUEST',
            410,
            'The email change request is invalid or expired.'
        );
    }

    private function assertRedirectAllowed(string $path): void
    {
        $allowed = config('v2_identity.email_change.redirect_allowlist', []);
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
                'The email change redirect is not allowed.'
            );
        }
    }
}
