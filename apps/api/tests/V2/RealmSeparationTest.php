<?php

namespace Tests\V2;

use App\Auth\V2RealmSessionGuard;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2RealmBoundary;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Http\Middleware\V2\EnforceV2Realm;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class RealmSeparationTest extends TestCase
{
    public function test_user_and_admin_session_policies_are_separate(): void
    {
        $policy = app(V2SessionPolicy::class);
        $user = $policy->forRealm(V2Realm::User);
        $admin = $policy->forRealm(V2Realm::Admin);

        self::assertSame('user_sessions', $user['table']);
        self::assertSame('__Host-oripa_user_session', $user['cookie']);
        self::assertSame(720, $user['idle_minutes']);
        self::assertSame(1440, $user['absolute_minutes']);
        self::assertSame('lax', $user['same_site']);
        self::assertTrue($user['remember']);

        self::assertSame('admin_sessions', $admin['table']);
        self::assertSame('__Host-oripa_admin_session', $admin['cookie']);
        self::assertSame(360, $admin['idle_minutes']);
        self::assertSame(720, $admin['absolute_minutes']);
        self::assertSame('strict', $admin['same_site']);
        self::assertFalse($admin['remember']);
        self::assertNotSame($user['cookie'], $admin['cookie']);
        self::assertNotSame($user['table'], $admin['table']);

        self::assertTrue(config('v2_identity.cookie_security.secure'));
        self::assertTrue(config('v2_identity.cookie_security.http_only'));
        self::assertTrue(config('v2_identity.cookie_security.host_only'));
    }

    public function test_session_ids_are_rotatable_and_only_hashes_are_persistable(): void
    {
        $policy = app(V2SessionPolicy::class);
        $first = $policy->issueOpaqueSessionId();
        $second = $policy->issueOpaqueSessionId();

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first);
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/',
            $policy->hashSessionId($first)
        );
        self::assertNotSame($first, $policy->hashSessionId($first));
    }

    public function test_issued_sessions_use_the_canonical_idle_and_absolute_timeouts(): void
    {
        DB::beginTransaction();
        Carbon::setTestNow('2026-08-07 00:00:00 UTC');

        try {
            $password = app(V2PasswordPolicy::class)->hash('session policy password');
            $userId = (int) DB::table('users')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'email_display' => 'session-policy-user@example.test',
                'email_normalized' => 'session-policy-user@example.test',
                'email_verified_at' => now(),
                'password_hash' => $password,
                'state' => 'active',
            ]);
            $adminId = (int) DB::table('admins')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'email_display' => 'session-policy-admin@example.test',
                'email_normalized' => 'session-policy-admin@example.test',
                'email_verified_at' => now(),
                'password_hash' => $password,
                'role' => 'operator',
                'state' => 'active',
            ]);

            $manager = app(V2SessionManager::class);
            $policy = app(V2SessionPolicy::class);
            $userSession = $manager->issue(V2Realm::User, $userId);
            $adminSession = $manager->issue(V2Realm::Admin, $adminId, true);

            $userRow = DB::table('user_sessions')
                ->where('session_id_hash', $policy->hashSessionId($userSession['token']))
                ->first();
            $adminRow = DB::table('admin_sessions')
                ->where('session_id_hash', $policy->hashSessionId($adminSession['token']))
                ->first();

            self::assertNotNull($userRow);
            self::assertSame(
                now()->addHours(12)->toISOString(),
                Carbon::parse($userRow->idle_expires_at)->toISOString()
            );
            self::assertSame(
                now()->addHours(24)->toISOString(),
                Carbon::parse($userRow->absolute_expires_at)->toISOString()
            );
            self::assertNotNull($adminRow);
            self::assertSame(
                now()->addHours(6)->toISOString(),
                Carbon::parse($adminRow->idle_expires_at)->toISOString()
            );
            self::assertSame(
                now()->addHours(12)->toISOString(),
                Carbon::parse($adminRow->absolute_expires_at)->toISOString()
            );
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }
    }

    public function test_boundary_rejects_cross_realm_unknown_and_unverified_admin(): void
    {
        $boundary = new V2RealmBoundary();

        $this->expectAuthorizationFailure(
            fn () => $boundary->assertAllowed(V2Realm::User, false, true)
        );
        $this->expectAuthorizationFailure(
            fn () => $boundary->assertAllowed(V2Realm::Admin, true, false)
        );
        $this->expectAuthorizationFailure(
            fn () => $boundary->assertAllowed(V2Realm::Admin, false, true, null, false)
        );
        $this->expectAuthorizationFailure(
            fn () => $boundary->assertAllowed(V2Realm::Unknown, false, false)
        );
        $this->expectAuthorizationFailure(
            fn () => $boundary->assertAllowed(V2Realm::Webhook, true, false)
        );
        $this->expectAuthorizationFailure(
            fn () => $boundary->assertAllowed(
                V2Realm::Admin,
                false,
                true,
                V2Realm::User,
                true
            )
        );

        $boundary->assertAllowed(V2Realm::User, true, false);
        $boundary->assertAllowed(V2Realm::Admin, false, true, null, true);
        $boundary->assertAllowed(V2Realm::Webhook, false, false);
        self::assertTrue(true);
    }

    public function test_middleware_sets_one_realm_and_fails_closed(): void
    {
        $auth = Mockery::mock(AuthFactory::class);
        $userGuard = Mockery::mock(Guard::class);
        $adminGuard = Mockery::mock(Guard::class);
        $auth->shouldReceive('guard')->with('v2_user')->andReturn($userGuard);
        $auth->shouldReceive('guard')->with('v2_admin')->andReturn($adminGuard);
        $userGuard->shouldReceive('check')->andReturnFalse();
        $adminGuard->shouldReceive('check')->andReturnTrue();

        $middleware = new EnforceV2Realm($auth, new V2RealmBoundary());
        $request = Request::create('/testing-only', 'GET');
        $request->attributes->set('v2_admin_mfa_verified', true);

        $response = $middleware->handle(
            $request,
            static fn (): Response => new Response('ok'),
            'admin'
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin', $request->attributes->get('v2_realm'));

        $this->expectException(AuthorizationException::class);
        $middleware->handle(
            $request,
            static fn (): Response => new Response('not-reached'),
            'user'
        );
    }

    public function test_database_guards_use_only_their_realm_cookie_and_table(): void
    {
        DB::beginTransaction();

        try {
            $password = app(V2PasswordPolicy::class)->hash('realm guard password');
            $userId = (int) DB::table('users')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'email_display' => 'user-guard@example.test',
                'email_normalized' => 'user-guard@example.test',
                'email_verified_at' => now(),
                'password_hash' => $password,
                'state' => 'active',
            ]);
            $adminId = (int) DB::table('admins')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'email_display' => 'admin-guard@example.test',
                'email_normalized' => 'admin-guard@example.test',
                'email_verified_at' => now(),
                'password_hash' => $password,
                'role' => 'operator',
                'state' => 'active',
            ]);

            $sessions = app(V2SessionPolicy::class);
            $userSessionId = $sessions->issueOpaqueSessionId();
            $adminSessionId = $sessions->issueOpaqueSessionId();
            $createdAt = now()->subSecond();
            $lastActivityAt = $createdAt->copy()->addSecond();
            DB::table('user_sessions')->insert([
                'session_id_hash' => $sessions->hashSessionId($userSessionId),
                'user_id' => $userId,
                'created_at' => $createdAt,
                'last_activity_at' => $lastActivityAt,
                'idle_expires_at' => $lastActivityAt->copy()->addHours(12),
                'absolute_expires_at' => $createdAt->copy()->addHours(24),
            ]);
            DB::table('admin_sessions')->insert([
                'session_id_hash' => $sessions->hashSessionId($adminSessionId),
                'admin_id' => $adminId,
                'mfa_verified_at' => now(),
                'created_at' => $createdAt,
                'last_activity_at' => $lastActivityAt,
                'idle_expires_at' => $lastActivityAt->copy()->addHours(6),
                'absolute_expires_at' => $createdAt->copy()->addHours(12),
            ]);

            $request = Request::create('/testing-only', 'GET');
            $request->cookies->set('__Host-oripa_user_session', $userSessionId);
            $userGuard = new V2RealmSessionGuard(
                V2Realm::User,
                app('auth')->createUserProvider('v2_user'),
                $request,
                $sessions
            );
            $adminGuard = new V2RealmSessionGuard(
                V2Realm::Admin,
                app('auth')->createUserProvider('v2_admin'),
                $request,
                $sessions
            );

            self::assertSame($userId, $userGuard->id());
            self::assertTrue($adminGuard->guest());
            self::assertFalse($userGuard->validate(['password' => 'not-used']));

            $request = Request::create('/testing-only', 'GET');
            $request->cookies->set('__Host-oripa_admin_session', $adminSessionId);
            $userGuard->setRequest($request);
            $adminGuard->setRequest($request);

            self::assertTrue($userGuard->guest());
            self::assertSame($adminId, $adminGuard->id());
        } finally {
            DB::rollBack();
        }
    }

    private function expectAuthorizationFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected the realm boundary to deny access.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }
}
