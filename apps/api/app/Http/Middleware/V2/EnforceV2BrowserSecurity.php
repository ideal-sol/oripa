<?php

namespace App\Http\Middleware\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2CsrfService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceV2BrowserSecurity
{
    public function __construct(private readonly V2CsrfService $csrf)
    {
    }

    public function handle(Request $request, Closure $next, string $realm): Response
    {
        $resolvedRealm = V2Realm::tryFrom($realm);
        if (! in_array($resolvedRealm, [V2Realm::User, V2Realm::Admin], true)) {
            throw new V2AuthenticationException('AUTHORIZATION_DENIED', 403);
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
                throw new V2AuthenticationException('CSRF_TOKEN_MISMATCH', 403);
            }
            if (! str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')) {
                throw new V2AuthenticationException(
                    'UNSUPPORTED_MEDIA_TYPE',
                    415,
                    'Authentication requests require JSON.'
                );
            }
            $this->assertOrigin($request, $resolvedRealm);
            $this->csrf->assertValid($request, $resolvedRealm);
        }

        return $next($request);
    }

    private function assertOrigin(Request $request, V2Realm $realm): void
    {
        $allowed = config('v2_identity.origins.'.$realm->value);
        if (! is_string($allowed) || ! preg_match('#\Ahttps://[^/]+\z#', $allowed)) {
            throw new V2AuthenticationException(
                'AUTH_SERVICE_UNAVAILABLE',
                503,
                'The authentication service is temporarily unavailable.',
                true,
                30
            );
        }

        $origin = $request->headers->get('Origin');
        if (! is_string($origin) || $origin === '') {
            $referer = $request->headers->get('Referer');
            if (is_string($referer)) {
                $parts = parse_url($referer);
                $origin = isset($parts['scheme'], $parts['host'])
                    ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
                    : null;
            }
        }
        if (! is_string($origin) || ! hash_equals($allowed, $origin)) {
            throw new V2AuthenticationException('CSRF_TOKEN_MISMATCH', 403);
        }
    }
}
