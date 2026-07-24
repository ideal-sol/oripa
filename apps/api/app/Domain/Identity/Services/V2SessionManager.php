<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\V2Realm;
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
        if ($realm === V2Realm::Admin) {
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
