<?php

namespace App\Http\Middleware\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2RealmBoundary;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceV2Realm
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly V2RealmBoundary $boundary
    ) {
    }

    public function handle(Request $request, Closure $next, string $surface): Response
    {
        $realm = V2Realm::tryFrom($surface) ?? V2Realm::Unknown;
        $existing = $request->attributes->get('v2_realm');
        $existingRealm = is_string($existing) ? V2Realm::tryFrom($existing) : null;

        $this->boundary->assertAllowed(
            surface: $realm,
            userAuthenticated: $this->auth->guard('v2_user')->check(),
            adminAuthenticated: $this->auth->guard('v2_admin')->check(),
            existingRealm: $existingRealm,
            adminMfaVerified: $request->attributes->getBoolean('v2_admin_mfa_verified')
        );

        $request->attributes->set('v2_realm', $realm->value);

        return $next($request);
    }
}
