<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class HttpFoundationSecurityTest extends TestCase
{
    public function test_cache_control_directives_survive_long_whitespace_before_separator(): void
    {
        $request = Request::create('/api/v2/auth/session', server: [
            'HTTP_CACHE_CONTROL' => 'private'.str_repeat(' ', 1024).', max-age=60',
        ]);

        self::assertTrue($request->headers->hasCacheControlDirective('private'));
        self::assertSame('60', $request->headers->getCacheControlDirective('max-age'));
        self::assertSame(PREG_NO_ERROR, preg_last_error());
    }

    public function test_space_and_tab_separators_keep_distinct_header_values(): void
    {
        self::assertSame(['first', 'second'], HeaderUtils::split('first   second', ' '));
        self::assertSame(['first value', 'second'], HeaderUtils::split("first value\tsecond", "\t"));
    }

    public function test_request_header_parsing_preserves_separate_realm_cookies_and_session(): void
    {
        $session = new Session(new MockArraySessionStorage);
        $session->set('authenticated_user', 'synthetic-user');
        $request = Request::create('/api/v2/auth/session', cookies: [
            '__Host-oripa_user_session' => 'synthetic-user-session',
            '__Host-oripa_admin_session' => 'synthetic-admin-session',
        ], server: [
            'HTTP_CACHE_CONTROL' => 'no-cache'.str_repeat(' ', 1024).', no-store',
        ]);
        $request->setSession($session);

        self::assertSame('synthetic-user-session', $request->cookies->get('__Host-oripa_user_session'));
        self::assertSame('synthetic-admin-session', $request->cookies->get('__Host-oripa_admin_session'));
        self::assertSame('synthetic-user', $request->getSession()->get('authenticated_user'));
        self::assertTrue($request->headers->hasCacheControlDirective('no-cache'));
        self::assertTrue($request->headers->hasCacheControlDirective('no-store'));
    }
}
