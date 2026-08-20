<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2CsrfService;
use App\Http\Middleware\V2\EnforceV2BrowserSecurity;
use App\Http\Responses\V2ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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

    public function test_bodyless_public_logout_skips_only_json_media_type_requirement(): void
    {
        $request = $this->bodylessPublicLogoutRequest();

        $response = app(EnforceV2BrowserSecurity::class)->handle(
            $request,
            static fn (): Response => new Response('ok'),
            'user'
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_bodyless_public_logout_keeps_origin_csrf_and_cross_site_rejections(): void
    {
        foreach ([
            ['Sec-Fetch-Site', 'cross-site'],
            ['Origin', 'https://attacker.example.test'],
            ['X-XSRF-TOKEN', null],
            ['X-XSRF-TOKEN', str_repeat('b', 64)],
        ] as [$header, $value]) {
            $request = $this->bodylessPublicLogoutRequest();
            if ($value === null) {
                $request->headers->remove($header);
            } else {
                $request->headers->set($header, $value);
            }

            try {
                app(EnforceV2BrowserSecurity::class)->handle(
                    $request,
                    static fn (): Response => new Response('unsafe'),
                    'user'
                );
                self::fail('Unsafe bodyless logout must fail closed.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('CSRF_TOKEN_MISMATCH', $exception->errorCode);
            }
        }
    }

    public function test_non_json_body_remains_rejected_for_logout_auth_and_api_mutations(): void
    {
        foreach ([
            ['/api/v2/auth/logout', 'v2.public.auth.logout'],
            ['/api/v2/auth/login', 'v2.public.auth.login'],
            ['/api/v2/contact-inquiries', 'v2.public.contacts.store'],
        ] as [$uri, $routeName]) {
            $request = $this->requestForNamedRoute($uri, 'POST', $routeName, 'not-json');
            $request->headers->set('Content-Type', 'text/plain');
            $request->headers->set('Origin', 'https://storefront.example.test');
            $request->headers->set('Sec-Fetch-Site', 'same-origin');
            $request->headers->set('X-XSRF-TOKEN', str_repeat('a', 64));
            $request->cookies->set('__Host-oripa_user_xsrf', str_repeat('a', 64));

            try {
                app(EnforceV2BrowserSecurity::class)->handle(
                    $request,
                    static fn (): Response => new Response('unsafe'),
                    'user'
                );
                self::fail('A non-JSON mutation body must fail closed.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('UNSUPPORTED_MEDIA_TYPE', $exception->errorCode);
                self::assertSame(415, $exception->status);
            }
        }
    }

    public function test_bodyless_http_logout_security_failures_remain_typed_problem_details(): void
    {
        $csrf = str_repeat('a', 64);
        foreach ([
            [
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
            ],
            [
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => str_repeat('b', 64),
            ],
            [
                'Origin' => 'https://attacker.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ],
            [
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'cross-site',
                'X-XSRF-TOKEN' => $csrf,
            ],
        ] as $headers) {
            $response = $this
                ->withCredentials()
                ->withServerVariables(['HTTPS' => 'on'])
                ->withHeaders($headers)
                ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
                ->post('/api/v2/auth/logout');

            $response
                ->assertStatus(403)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
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

    public function test_authentication_problem_details_are_private_and_versioned(): void
    {
        $request = Request::create('/api/v2/auth/login', 'POST');
        $response = V2ProblemDetails::fromAuthentication(
            $request,
            new V2AuthenticationException('INVALID_CREDENTIALS', 401)
        );

        self::assertStringContainsString(
            'private',
            (string) $response->headers->get('Cache-Control')
        );
        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
        self::assertSame('2', $response->headers->get('X-Oripa-Api-Version'));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    private function bodylessPublicLogoutRequest(): Request
    {
        $request = $this->requestForNamedRoute(
            '/api/v2/auth/logout',
            'POST',
            'v2.public.auth.logout'
        );
        $request->headers->set('Origin', 'https://storefront.example.test');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');
        $request->headers->set('X-XSRF-TOKEN', str_repeat('a', 64));
        $request->cookies->set('__Host-oripa_user_xsrf', str_repeat('a', 64));

        return $request;
    }

    private function requestForNamedRoute(
        string $uri,
        string $method,
        string $routeName,
        string $content = ''
    ): Request {
        $request = Request::create($uri, $method, [], [], [], [], $content);
        $route = new Route([$method], ltrim($uri, '/'), static fn (): Response => new Response());
        $route->name($routeName);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }
}
