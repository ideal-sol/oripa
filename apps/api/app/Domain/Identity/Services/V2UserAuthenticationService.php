<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Models\V2\User;
use App\Models\V2\UserEmailVerification;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class V2UserAuthenticationService
{
    public function __construct(
        private readonly V2PasswordPolicy $passwordPolicy,
        private readonly V2EmailNormalizer $emails,
        private readonly V2SecureToken $tokens,
        private readonly V2EmailVerificationNotifier $notifier,
        private readonly V2RateLimiter $rateLimiter,
        private readonly V2SessionManager $sessions,
        private readonly V2SecurityEventSink $events
    ) {
    }

    public function register(
        string $email,
        #[SensitiveParameter] string $password,
        string $redirectPath,
        string $ip
    ): User {
        $normalized = $this->emails->normalize($email);
        $this->assertRedirectAllowed($redirectPath);
        $this->rateLimiter->assertGlobal('register_ip', $ip);
        $this->rateLimiter->assertSubject('register_email', $normalized);

        try {
            $passwordHash = $this->passwordPolicy->hash($password);
        } catch (\InvalidArgumentException) {
            throw new V2AuthenticationException(
                'INVALID_REGISTRATION',
                422,
                V2PasswordPolicy::GENERIC_ERROR
            );
        }

        [$user, $rawToken] = DB::transaction(function () use (
            $email,
            $normalized,
            $passwordHash,
            $redirectPath
        ): array {
            $user = User::query()->create([
                'email_display' => trim($email),
                'email_normalized' => $normalized,
                'password_hash' => $passwordHash,
                'state' => V2UserState::PendingVerification,
            ]);
            $rawToken = $this->tokens->generate();
            UserEmailVerification::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => $this->tokens->hash($rawToken),
                'redirect_path' => $redirectPath,
                'expires_at' => now()->addMinutes(
                    (int) config('v2_identity.email_verification.ttl_minutes')
                ),
            ]);

            return [$user, $rawToken];
        });

        $this->notifier->send($user, $rawToken, $redirectPath);
        $this->events->record('register', [
            'realm' => 'user',
            'subject_id' => $user->public_id,
            'result' => 'pending_verification',
        ]);

        return $user;
    }

    public function resend(string $publicId, string $redirectPath): void
    {
        $this->assertRedirectAllowed($redirectPath);
        $user = User::query()
            ->where('public_id', $publicId)
            ->where('state', V2UserState::PendingVerification->value)
            ->whereNull('email_verified_at')
            ->first();
        if ($user === null) {
            return;
        }

        $this->rateLimiter->assertSubject('verification_resend_hour', $publicId);
        $this->rateLimiter->assertSubject('verification_resend_day', $publicId);
        $rawToken = DB::transaction(function () use ($user, $redirectPath): string {
            UserEmailVerification::query()
                ->where('user_id', $user->getKey())
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $rawToken = $this->tokens->generate();
            UserEmailVerification::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => $this->tokens->hash($rawToken),
                'redirect_path' => $redirectPath,
                'expires_at' => now()->addMinutes(
                    (int) config('v2_identity.email_verification.ttl_minutes')
                ),
            ]);

            return $rawToken;
        });
        $this->notifier->send($user, $rawToken, $redirectPath);
    }

    /**
     * @return array{user: User, session: array{token: string, absolute_expires_at: \DateTimeInterface}, redirect_path: string}
     */
    public function verify(string $publicId, #[SensitiveParameter] string $token): array
    {
        try {
            return DB::transaction(function () use ($publicId, $token): array {
                $user = User::query()
                    ->where('public_id', $publicId)
                    ->lockForUpdate()
                    ->first();
                if ($user === null || $user->email_verified_at !== null) {
                    throw $this->invalidVerification();
                }
                $this->lockNormalizedEmail($user->email_normalized);
                $verification = UserEmailVerification::query()
                    ->where('user_id', $user->getKey())
                    ->where('token_hash', $this->tokens->hash($token))
                    ->whereNull('used_at')
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->first();
                if ($verification === null) {
                    throw $this->invalidVerification();
                }

                $user->forceFill([
                    'email_verified_at' => now(),
                    'state' => V2UserState::Active,
                ])->save();
                $verification->forceFill(['used_at' => now()])->save();
                UserEmailVerification::query()
                    ->where('user_id', $user->getKey())
                    ->whereKeyNot($verification->getKey())
                    ->whereNull('used_at')
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
                User::query()
                    ->where('email_normalized', $user->email_normalized)
                    ->whereKeyNot($user->getKey())
                    ->whereNull('email_verified_at')
                    ->where('state', V2UserState::PendingVerification->value)
                    ->update(['state' => V2UserState::Closed->value]);
                $session = $this->sessions->issue(V2Realm::User, (int) $user->getKey());
                $this->events->record('verification_success', [
                    'realm' => 'user',
                    'subject_id' => $user->public_id,
                ]);

                return [
                    'user' => $user,
                    'session' => $session,
                    'redirect_path' => $verification->redirect_path,
                ];
            }, 3);
        } catch (QueryException) {
            $this->events->record('verification_failure', [
                'realm' => 'user',
                'reason' => 'concurrent_conflict',
            ]);
            throw new V2AuthenticationException(
                'EMAIL_ALREADY_VERIFIED',
                409,
                'The email address is already verified by another account.'
            );
        }
    }

    /**
     * @return array{user: User, session: array{token: string, absolute_expires_at: \DateTimeInterface}}
     */
    public function login(
        string $email,
        #[SensitiveParameter] string $password,
        string $ip
    ): array {
        $normalized = $this->emails->normalize($email);
        $this->rateLimiter->assertGlobal('user_login_ip', $ip);
        $this->rateLimiter->assertAccount('user_login_failure', $normalized, $ip);

        $user = User::query()
            ->where('email_normalized', $normalized)
            ->whereNotNull('email_verified_at')
            ->whereIn('state', [V2UserState::Active->value, V2UserState::Restricted->value])
            ->first();
        if ($user === null || ! $this->passwordPolicy->verify($password, $user->password_hash)) {
            $this->rateLimiter->hitAccount('user_login_failure', $normalized, $ip);
            $this->events->record('login_failure', ['realm' => 'user']);
            throw $this->invalidCredentials();
        }
        if ($this->passwordPolicy->needsRehash($user->password_hash)) {
            $user->forceFill(['password_hash' => $this->passwordPolicy->hash($password)])->save();
        }

        $session = $this->sessions->issue(V2Realm::User, (int) $user->getKey());
        $this->events->record('login_success', [
            'realm' => 'user',
            'subject_id' => $user->public_id,
        ]);

        return ['user' => $user, 'session' => $session];
    }

    public function logout(Request $request): void
    {
        $this->sessions->revoke($request, V2Realm::User);
        $this->events->record('logout', ['realm' => 'user']);
    }

    private function assertRedirectAllowed(string $path): void
    {
        $allowed = config('v2_identity.email_verification.redirect_allowlist', []);
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
                'The verification redirect is not allowed.'
            );
        }
    }

    private function lockNormalizedEmail(string $email): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$email]);
        }
    }

    private function invalidVerification(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_VERIFICATION_LINK',
            410,
            'The email verification link is invalid or expired.'
        );
    }

    private function invalidCredentials(): V2AuthenticationException
    {
        return new V2AuthenticationException(
            'INVALID_CREDENTIALS',
            401,
            'The credentials could not be verified.'
        );
    }
}
