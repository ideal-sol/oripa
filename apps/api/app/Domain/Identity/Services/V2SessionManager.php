<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\V2Realm;
use App\Models\V2\AdminSession;
use App\Models\V2\UserSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class V2SessionManager
{
    public function __construct(
        private readonly V2SessionPolicy $policy,
        private readonly V2SecureToken $tokens
    ) {
    }

    /**
     * @return array{token: string, absolute_expires_at: \Illuminate\Support\Carbon}
     */
    public function issue(
        V2Realm $realm,
        int $identityId,
        bool $mfaVerified = false,
        bool $requiresMfaEnrollment = false
    ): array {
        $configuration = $this->policy->forRealm($realm);
        $token = $this->tokens->generate();
        $now = now();
        $absolute = $now->copy()->addMinutes($configuration['absolute_minutes']);
        $row = [
            'session_id_hash' => $this->policy->hashSessionId($token),
            $realm === V2Realm::User ? 'user_id' : 'admin_id' => $identityId,
            'created_at' => $now,
            'last_activity_at' => $now,
            'idle_expires_at' => $now->copy()->addMinutes($configuration['idle_minutes']),
            'absolute_expires_at' => $absolute,
            'revoked_at' => null,
        ];
        if ($realm === V2Realm::User) {
            $row['reauthenticated_at'] = $now;
        } else {
            $row['mfa_verified_at'] = $mfaVerified ? $now : null;
            $row['requires_mfa_enrollment'] = $requiresMfaEnrollment;
        }
        DB::table($configuration['table'])->insert($row);

        return ['token' => $token, 'absolute_expires_at' => $absolute];
    }

    public function revoke(Request $request, V2Realm $realm): void
    {
        $raw = $this->rawToken($request, $realm);
        if ($raw === null) {
            return;
        }
        $configuration = $this->policy->forRealm($realm);
        DB::table($configuration['table'])
            ->where('session_id_hash', $this->policy->hashSessionId($raw))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function rawToken(Request $request, V2Realm $realm): ?string
    {
        $configuration = $this->policy->forRealm($realm);
        $raw = $request->cookies->get($configuration['cookie']);

        return is_string($raw) && preg_match('/\A[0-9a-f]{64}\z/', $raw) ? $raw : null;
    }

    public function sessionIdHash(Request $request, V2Realm $realm): ?string
    {
        $raw = $this->rawToken($request, $realm);

        return $raw === null ? null : $this->policy->hashSessionId($raw);
    }

    public function requireFreshUserSession(
        Request $request,
        int $userId,
        bool $lock = false
    ): UserSession {
        $raw = $this->rawToken($request, V2Realm::User);
        if ($raw === null) {
            throw new \RuntimeException('A current User Session is required.');
        }
        $query = UserSession::query()
            ->whereKey($this->policy->hashSessionId($raw))
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('idle_expires_at', '>', now())
            ->where('absolute_expires_at', '>', now());
        if ($lock) {
            $query->lockForUpdate();
        }
        $session = $query->first();
        $freshMinutes = (int) config('v2_identity.user_fresh_auth.minutes', 10);
        if (
            $session === null
            || $session->reauthenticated_at === null
            || ! $session->reauthenticated_at->copy()->addMinutes($freshMinutes)->isFuture()
        ) {
            throw new \RuntimeException('Fresh User Authentication is required.');
        }

        return $session;
    }

    /**
     * @return array{token: string, absolute_expires_at: CarbonImmutable}
     */
    public function rotateLockedAdminSession(AdminSession $session): array
    {
        if ($session->revoked_at !== null) {
            throw new \RuntimeException('The Admin Session is no longer active.');
        }
        $now = CarbonImmutable::now()->startOfSecond();
        $absolute = CarbonImmutable::parse($session->absolute_expires_at);
        if (! $absolute->greaterThan($now)) {
            throw new \RuntimeException('The Admin Session has expired.');
        }
        $configuration = $this->policy->forRealm(V2Realm::Admin);
        $idle = $now->addMinutes($configuration['idle_minutes']);
        if ($idle->greaterThan($absolute)) {
            $idle = $absolute;
        }
        $token = $this->tokens->generate();
        $session->forceFill(['revoked_at' => $now])->save();
        DB::table($configuration['table'])->insert([
            'session_id_hash' => $this->policy->hashSessionId($token),
            'admin_id' => $session->admin_id,
            'mfa_verified_at' => $now,
            'requires_mfa_enrollment' => false,
            'created_at' => $now,
            'last_activity_at' => $now,
            'idle_expires_at' => $idle,
            'absolute_expires_at' => $absolute,
            'revoked_at' => null,
        ]);

        return ['token' => $token, 'absolute_expires_at' => $absolute];
    }

    /**
     * @return array{token: string, absolute_expires_at: CarbonImmutable}
     */
    public function rotateLockedUserSession(UserSession $session): array
    {
        if ($session->revoked_at !== null) {
            throw new \RuntimeException('The User Session is no longer active.');
        }
        $now = CarbonImmutable::now()->startOfSecond();
        $absolute = CarbonImmutable::parse($session->absolute_expires_at);
        if (! $absolute->greaterThan($now)) {
            throw new \RuntimeException('The User Session has expired.');
        }
        $configuration = $this->policy->forRealm(V2Realm::User);
        $idle = $now->addMinutes($configuration['idle_minutes']);
        if ($idle->greaterThan($absolute)) {
            $idle = $absolute;
        }
        $token = $this->tokens->generate();
        $session->forceFill(['revoked_at' => $now])->save();
        DB::table($configuration['table'])->insert([
            'session_id_hash' => $this->policy->hashSessionId($token),
            'user_id' => $session->user_id,
            'reauthenticated_at' => $now,
            'created_at' => $now,
            'last_activity_at' => $now,
            'idle_expires_at' => $idle,
            'absolute_expires_at' => $absolute,
            'revoked_at' => null,
        ]);

        return ['token' => $token, 'absolute_expires_at' => $absolute];
    }

    public function attachSession(
        Response $response,
        V2Realm $realm,
        #[SensitiveParameter] string $token,
        \DateTimeInterface $expiresAt
    ): void {
        $configuration = $this->policy->forRealm($realm);
        $response->headers->setCookie(new Cookie(
            $configuration['cookie'],
            $token,
            $expiresAt,
            '/',
            null,
            true,
            true,
            false,
            $configuration['same_site']
        ));
    }

    public function expireSession(Response $response, V2Realm $realm): void
    {
        $configuration = $this->policy->forRealm($realm);
        $response->headers->setCookie(new Cookie(
            $configuration['cookie'],
            '',
            1,
            '/',
            null,
            true,
            true,
            false,
            $configuration['same_site']
        ));
    }
}
