<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2CsrfService;
use App\Http\Middleware\V2\EnforceV2BrowserSecurity;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class BrowserSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
    }

    public function test_matching_origin_json_and_realm_csrf_pass(): void
    {
        $request = Request::create('/api/v2/auth/login', 'POST');
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Origin', 'https://storefront.example.test');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');
        $request->headers->set('X-XSRF-TOKEN', str_repeat('a', 64));
        $request->cookies->set('__Host-oripa_user_xsrf', str_repeat('a', 64));

        $response = app(EnforceV2BrowserSecurity::class)->handle(
            $request,
            static fn (): Response => new Response('ok'),
            'user'
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_cross_site_wrong_origin_wrong_csrf_and_non_json_fail_closed(): void
    {
        foreach ([
            ['Sec-Fetch-Site', 'cross-site'],
            ['Origin', 'https://attacker.example.test'],
            ['X-XSRF-TOKEN', str_repeat('b', 64)],
            ['Content-Type', 'text/plain'],
        ] as [$header, $value]) {
            $request = Request::create('/admin/api/v2/auth/login', 'POST');
            $request->headers->set('Content-Type', 'application/json');
            $request->headers->set('Origin', 'https://admin.example.test');
            $request->headers->set('Sec-Fetch-Site', 'same-origin');
            $request->headers->set('X-XSRF-TOKEN', str_repeat('a', 64));
            $request->cookies->set('__Host-oripa_admin_xsrf', str_repeat('a', 64));
            $request->headers->set($header, $value);

            try {
                app(EnforceV2BrowserSecurity::class)->handle(
                    $request,
                    static fn (): Response => new Response('unsafe'),
                    'admin'
                );
                self::fail('Unsafe browser request must fail closed.');
            } catch (V2AuthenticationException $exception) {
                self::assertContains($exception->errorCode, [
                    'CSRF_TOKEN_MISMATCH',
                    'UNSUPPORTED_MEDIA_TYPE',
                ]);
            }
        }
    }

    public function test_csrf_cookie_names_are_not_shared(): void
    {
        $response = new Response();
        app(V2CsrfService::class)->rotate($response, V2Realm::User);
        app(V2CsrfService::class)->rotate($response, V2Realm::Admin);
        $cookies = $response->headers->getCookies();

        self::assertSame('__Host-oripa_user_xsrf', $cookies[0]->getName());
        self::assertSame('__Host-oripa_admin_xsrf', $cookies[1]->getName());
        self::assertFalse($cookies[0]->isHttpOnly());
        self::assertFalse($cookies[1]->isHttpOnly());
    }
}
