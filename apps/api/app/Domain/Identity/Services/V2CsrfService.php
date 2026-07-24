<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use Illuminate\Http\Request;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class V2CsrfService
{
    public function __construct(
        private readonly V2SessionPolicy $sessions,
        private readonly V2SecureToken $tokens
    ) {
    }

    public function attachIfMissing(Request $request, Response $response, V2Realm $realm): void
    {
        $policy = $this->sessions->forRealm($realm);
        $existing = $request->cookies->get($policy['csrf_cookie']);
        if (is_string($existing) && preg_match('/\A[0-9a-f]{64}\z/', $existing)) {
            return;
        }
        $this->attach($response, $realm, $this->tokens->generate());
    }

    public function rotate(Response $response, V2Realm $realm): void
    {
        $this->attach($response, $realm, $this->tokens->generate());
    }

    public function assertValid(Request $request, V2Realm $realm): void
    {
        $policy = $this->sessions->forRealm($realm);
        $cookie = $request->cookies->get($policy['csrf_cookie']);
        $header = $request->headers->get('X-XSRF-TOKEN');
        if (
            ! is_string($cookie)
            || ! is_string($header)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $cookie)
            || ! hash_equals($cookie, $header)
        ) {
            throw new V2AuthenticationException(
                'CSRF_TOKEN_MISMATCH',
                403,
                'The request could not be verified.'
            );
        }
    }

    private function attach(
        Response $response,
        V2Realm $realm,
        #[SensitiveParameter] string $token
    ): void {
        $policy = $this->sessions->forRealm($realm);
        $response->headers->setCookie(new Cookie(
            $policy['csrf_cookie'],
            $token,
            0,
            '/',
            null,
            true,
            false,
            false,
            $policy['same_site']
        ));
    }
}
