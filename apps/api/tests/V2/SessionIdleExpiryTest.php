<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\User;
use App\Models\V2\UserSession;
use App\Models\V2\Admin;
use App\Models\V2\AdminSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class SessionIdleExpiryTest extends TestCase
{
    private string $connectionName;
    private mixed $originalDatabaseTimezone;
    private mixed $originalApplicationTimezone;
    private string $originalPhpTimezone;
    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalDatabaseTimezone = config(
            "database.connections.{$this->connectionName}.timezone"
        );
        $this->originalApplicationTimezone = config('app.timezone');
        $this->originalPhpTimezone = date_default_timezone_get();
        config([
            'app.timezone' => 'Asia/Tokyo',
            'v2_identity.origins.user' => 'https://storefront.example.test',
            "database.connections.{$this->connectionName}.timezone" => 'UTC',
        ]);
        date_default_timezone_set('Asia/Tokyo');
        DB::purge($this->connectionName);
        DB::reconnect($this->connectionName);
        DB::beginTransaction();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-04 12:00:00', 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        date_default_timezone_set($this->originalPhpTimezone);
        Auth::forgetGuards();
        DB::purge($this->connectionName);
        config([
            'app.timezone' => $this->originalApplicationTimezone,
            "database.connections.{$this->connectionName}.timezone" => $this->originalDatabaseTimezone,
        ]);
        parent::tearDown();
    }

    public function test_login_cookie_authenticates_session_and_gachas_routes(): void
    {
        $session = $this->login();
        $activity = now()->toImmutable()->utc()->addSeconds(30);
        Carbon::setTestNow($activity->setTimezone('Asia/Tokyo'));
        Auth::forgetGuards();
        $this->withUnencryptedCookie('__Host-oripa_user_session', $session['token'])
            ->getJson('/api/v2/gachas?limit=1')->assertOk();
        self::assertTrue(CarbonImmutable::parse($this->sessionRow($session['hash'])->last_activity_at)
            ->equalTo($activity));
        $this->requestSession($session['token'])->assertOk()->assertJsonPath('authenticated', true);
    }

    public function test_full_login_lifecycle_persists_one_instant_and_caps_repeated_activity(): void
    {
        $issuedAt = CarbonImmutable::parse('2026-09-05 10:00:00.987654', 'Asia/Tokyo');
        Carbon::setTestNow($issuedAt);
        self::assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
        self::assertTrue((bool) DB::table('pg_constraint')
            ->where('conname', 'user_sessions_expiry_check')->value('convalidated'));

        $session = $this->login();
        $current = $issuedAt->utc()->startOfSecond();
        $absolute = $current->addDay();
        $row = $this->sessionRow($session['hash']);
        foreach (['created_at', 'last_activity_at', 'reauthenticated_at'] as $column) {
            self::assertTrue(CarbonImmutable::parse($row->{$column})->equalTo($current));
        }
        self::assertTrue(CarbonImmutable::parse($row->idle_expires_at)->equalTo($current->addHours(12)));
        $this->assertTimestampOrdering($session['hash'], $absolute);

        foreach ([0, 300, 39600, 43200, 72000, 86399] as $elapsedSeconds) {
            $activity = $current->addSeconds($elapsedSeconds);
            Carbon::setTestNow($activity->setTimezone('Asia/Tokyo'));
            $this->requestSession($session['token'])
                ->assertOk()->assertJsonPath('authenticated', true);
            Auth::forgetGuards();
            $this->getJson('/api/v2/gachas?limit=1')->assertOk();
            $this->requestSession($session['token'])
                ->assertOk()->assertJsonPath('authenticated', true);

            $row = $this->sessionRow($session['hash']);
            $expectedIdle = $activity->addHours(12)->min($absolute);
            self::assertTrue(CarbonImmutable::parse($row->idle_expires_at)->equalTo($expectedIdle));
            self::assertTrue(CarbonImmutable::parse($row->last_activity_at)->equalTo($activity));
            $this->assertTimestampOrdering($session['hash'], $absolute);
        }

        Carbon::setTestNow($absolute->setTimezone('Asia/Tokyo'));
        $this->assertSessionExpired($this->requestSession($session['token']));
    }

    public function test_login_caps_initial_idle_lifetime_at_absolute_expiry(): void
    {
        config(['v2_identity.sessions.user.absolute_minutes' => 30]);
        $session = $this->login();
        $absolute = now()->toImmutable()->utc()->addMinutes(30);

        $this->requestSession($session['token'])->assertOk();

        $row = $this->sessionRow($session['hash']);
        self::assertTrue(CarbonImmutable::parse($row->idle_expires_at)->equalTo($absolute));
        $this->assertTimestampOrdering($session['hash'], $absolute);
    }

    public function test_login_uses_the_configured_non_utc_connection_authority_too(): void
    {
        DB::rollBack();
        DB::purge($this->connectionName);
        config(["database.connections.{$this->connectionName}.timezone" => 'Asia/Tokyo']);
        DB::reconnect($this->connectionName);
        DB::beginTransaction();
        self::assertSame('Asia/Tokyo', DB::selectOne('SHOW TIME ZONE')->TimeZone);

        $session = $this->login();

        $this->requestSession($session['token'])->assertOk()->assertJsonPath('authenticated', true);
        $row = $this->sessionRow($session['hash']);
        self::assertTrue(CarbonImmutable::parse($row->created_at)->equalTo(now()));
        $this->assertTimestampOrdering($session['hash'], now()->toImmutable()->addDay());
    }

    public function test_login_session_rotation_and_revocation_preserve_persisted_instants(): void
    {
        $session = $this->login();
        $issuedAt = now()->toImmutable()->utc();
        $manager = app(V2SessionManager::class);
        $request = \Illuminate\Http\Request::create('/api/v2/auth/session');
        $request->cookies->set('__Host-oripa_user_session', $session['token']);
        $active = $manager->requireFreshUserSession($request, $session['user']->getKey(), true);
        self::assertInstanceOf(UserSession::class, $active);
        Carbon::setTestNow($issuedAt->addMinutes(5)->setTimezone('Asia/Tokyo'));

        $preserved = $manager->rotateLockedUserSessionPreservingReauthentication($active);
        $preservedHash = hash('sha256', $preserved['token']);
        $row = $this->sessionRow($preservedHash);
        self::assertTrue(CarbonImmutable::parse($row->created_at)->equalTo($issuedAt));
        self::assertTrue(CarbonImmutable::parse($row->reauthenticated_at)->equalTo($issuedAt));
        $this->assertTimestampOrdering($preservedHash, $issuedAt->addDay());
        $this->requestSession($preserved['token'])->assertOk();

        $rotated = $manager->rotateLockedUserSession(UserSession::query()->findOrFail($preservedHash));
        $rotatedHash = hash('sha256', $rotated['token']);
        $row = $this->sessionRow($rotatedHash);
        self::assertTrue(CarbonImmutable::parse($row->created_at)->equalTo($issuedAt->addMinutes(5)));
        self::assertTrue(CarbonImmutable::parse($row->reauthenticated_at)->equalTo($issuedAt->addMinutes(5)));
        $this->assertTimestampOrdering($rotatedHash, $issuedAt->addDay());
        $this->requestSession($rotated['token'])->assertOk();
        $this->assertSessionExpired($this->requestSession($session['token']));
        $this->assertSessionExpired($this->requestSession($preserved['token']));

        $request->cookies->set('__Host-oripa_user_session', $rotated['token']);
        $manager->revoke($request, V2Realm::User);
        self::assertTrue(CarbonImmutable::parse($this->sessionRow($rotatedHash)->revoked_at)
            ->equalTo($issuedAt->addMinutes(5)));
        $this->assertSessionExpired($this->requestSession($rotated['token']));
    }

    public function test_legacy_future_creation_expires_without_rewriting_or_authentication(): void
    {
        $current = now()->toImmutable()->utc();
        $session = $this->createSession($current, $current->addHours(13));
        $legacyIssuedAt = now()->toImmutable();
        DB::table('user_sessions')->where('session_id_hash', $session['hash'])->update([
            'created_at' => $legacyIssuedAt,
            'last_activity_at' => $legacyIssuedAt,
            'reauthenticated_at' => $legacyIssuedAt,
            'idle_expires_at' => $legacyIssuedAt->addHours(12),
            'absolute_expires_at' => $legacyIssuedAt->addDay(),
        ]);
        $before = $this->sessionRow($session['hash']);
        self::assertTrue(CarbonImmutable::parse($before->created_at)->equalTo($current->addHours(9)));

        $this->assertSessionExpired($this->requestSession($session['token']));
        Auth::forgetGuards();
        $this->getJson('/api/v2/gachas?limit=1')->assertOk();
        self::assertEquals($before, $this->sessionRow($session['hash']));
    }

    public function test_admin_issuance_rotation_and_activity_share_time_authority_and_mfa_gate(): void
    {
        config([
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.webauthn.rp_id' => 'admin.example.test',
            'v2_identity.webauthn.origin' => 'https://admin.example.test',
        ]);
        $admin = Admin::query()->create([
            'email_display' => 'session-admin@example.test',
            'email_normalized' => 'session-admin@example.test',
            'email_verified_at' => now()->toImmutable()->utc(),
            'password_hash' => '$argon2id$synthetic-session-admin-test',
            'role' => V2AdminRole::Operator,
            'state' => V2AdminState::Active,
        ]);
        $manager = app(V2SessionManager::class);
        $unverified = $manager->issue(V2Realm::Admin, $admin->getKey());
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_session', $unverified['token'])
            ->getJson('/admin/api/v2/auth/session')
            ->assertOk()->assertJsonPath('authenticated', false);

        $issuedAt = now()->toImmutable()->utc();
        $verified = $manager->issue(V2Realm::Admin, $admin->getKey(), true);
        $session = AdminSession::query()->findOrFail(hash('sha256', $verified['token']));
        self::assertTrue($session->created_at->equalTo($issuedAt));
        self::assertTrue($session->mfa_verified_at->equalTo($issuedAt));
        $absolute = $session->absolute_expires_at;
        Carbon::setTestNow($issuedAt->addMinute()->setTimezone('Asia/Tokyo'));
        $rotated = $manager->rotateLockedAdminSession($session);
        self::assertTrue($session->refresh()->revoked_at->equalTo($issuedAt->addMinute()));

        foreach ([1, 300, 420] as $elapsedMinutes) {
            $current = $issuedAt->addMinutes($elapsedMinutes);
            Carbon::setTestNow($current->setTimezone('Asia/Tokyo'));
            Auth::forgetGuards();
            $this->withUnencryptedCookie('__Host-oripa_admin_session', $rotated['token'])
                ->getJson('/admin/api/v2/auth/session')
                ->assertOk()->assertJsonPath('authenticated', true);
            $row = AdminSession::query()->findOrFail(hash('sha256', $rotated['token']));
            self::assertTrue($row->created_at->lessThanOrEqualTo($row->last_activity_at));
            self::assertTrue($row->last_activity_at->equalTo($current));
            self::assertTrue($row->idle_expires_at->equalTo($current->addHours(6)->min($absolute)));
            self::assertTrue($row->absolute_expires_at->equalTo($absolute));
        }
    }

    public function test_future_reauthentication_does_not_grant_fresh_authentication(): void
    {
        $session = $this->login();
        DB::table('user_sessions')->where('session_id_hash', $session['hash'])->update([
            'reauthenticated_at' => now()->toImmutable()->utc()->addHour(),
        ]);
        $request = \Illuminate\Http\Request::create('/api/v2/auth/session');
        $request->cookies->set('__Host-oripa_user_session', $session['token']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fresh User Authentication is required.');
        app(V2SessionManager::class)->requireFreshUserSession($request, $session['user']->getKey());
    }

    public function test_legacy_session_with_past_creation_remains_usable_without_reinterpretation(): void
    {
        $current = now()->toImmutable()->utc();
        $session = $this->createSession($current, $current->addHours(13));
        $legacyIssuedAt = now()->toImmutable()->subHours(10);
        DB::table('user_sessions')->where('session_id_hash', $session['hash'])->update([
            'created_at' => $legacyIssuedAt,
            'last_activity_at' => $legacyIssuedAt,
            'reauthenticated_at' => $legacyIssuedAt,
            'idle_expires_at' => $legacyIssuedAt->addHours(12),
            'absolute_expires_at' => $legacyIssuedAt->addDay(),
        ]);
        $before = $this->sessionRow($session['hash']);

        $this->requestSession($session['token'])->assertOk()->assertJsonPath('authenticated', true);

        $after = $this->sessionRow($session['hash']);
        self::assertSame($before->created_at, $after->created_at);
        self::assertSame($before->absolute_expires_at, $after->absolute_expires_at);
        self::assertTrue(CarbonImmutable::parse($after->last_activity_at)->equalTo($current));
        $this->assertTimestampOrdering($session['hash'], CarbonImmutable::parse($before->absolute_expires_at));
    }

    public function test_route_extends_idle_expiry_by_configured_timeout(): void
    {
        $current = now()->toImmutable()->utc();
        $absolute = $current->addMinutes(800);
        $session = $this->createSession($current, $absolute);

        $response = $this->requestSession($session['token']);

        $response
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.id', $session['user']->public_id);
        $row = $this->sessionRow($session['hash']);
        $savedIdle = CarbonImmutable::parse($row->idle_expires_at);
        self::assertTrue($savedIdle->equalTo($current->addMinutes(720)));
        self::assertTrue($savedIdle->lessThan(CarbonImmutable::parse($row->absolute_expires_at)));
        self::assertTrue(CarbonImmutable::parse($row->last_activity_at)->equalTo($current));
    }

    public function test_route_caps_idle_expiry_at_absolute_expiry(): void
    {
        $current = now()->toImmutable()->utc();
        $absolute = $current->addMinutes(30);
        $session = $this->createSession($current, $absolute);

        $response = $this->requestSession($session['token']);

        $response->assertOk()->assertJsonPath('authenticated', true);
        $row = $this->sessionRow($session['hash']);
        self::assertTrue(CarbonImmutable::parse($row->idle_expires_at)->equalTo($absolute));
        self::assertTrue(
            CarbonImmutable::parse($row->idle_expires_at)
                ->lessThanOrEqualTo(CarbonImmutable::parse($row->absolute_expires_at))
        );
        self::assertTrue((bool) DB::table('pg_constraint')
            ->where('conname', 'user_sessions_expiry_check')
            ->value('convalidated'));
    }

    public function test_route_accepts_exact_idle_and_absolute_expiry_boundary(): void
    {
        $current = now()->toImmutable()->utc();
        $absolute = $current->addMinutes(720);
        $session = $this->createSession($current, $absolute);

        $this->requestSession($session['token'])
            ->assertOk()
            ->assertJsonPath('authenticated', true);

        $row = $this->sessionRow($session['hash']);
        self::assertTrue(CarbonImmutable::parse($row->idle_expires_at)->equalTo($absolute));
    }

    public function test_asia_tokyo_application_and_utc_postgres_preserve_the_same_instant(): void
    {
        self::assertSame('Asia/Tokyo', config('app.timezone'));
        self::assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
        $applicationCurrent = CarbonImmutable::parse('2026-09-04 21:34:56', 'Asia/Tokyo');
        Carbon::setTestNow($applicationCurrent);
        $current = $applicationCurrent->utc();
        $absolute = $current->addMinutes(20);
        $session = $this->createSession($current, $absolute);

        $this->requestSession($session['token'])->assertOk();

        $row = $this->sessionRow($session['hash']);
        $savedIdle = CarbonImmutable::parse($row->idle_expires_at);
        self::assertTrue($savedIdle->equalTo($absolute));
        self::assertFalse($savedIdle->equalTo($absolute->addHours(9)));
        self::assertTrue(CarbonImmutable::parse($row->last_activity_at)->equalTo($current));
    }

    public function test_repeated_activity_near_absolute_expiry_never_extends_absolute_lifetime(): void
    {
        $current = now()->toImmutable()->utc();
        $absolute = $current->addMinutes(3);
        $session = $this->createSession($current, $absolute);

        $this->requestSession($session['token'])->assertOk();
        Carbon::setTestNow($current->addMinute()->setTimezone('Asia/Tokyo'));
        $this->requestSession($session['token'])->assertOk();

        $row = $this->sessionRow($session['hash']);
        self::assertTrue(CarbonImmutable::parse($row->idle_expires_at)->equalTo($absolute));
        self::assertTrue(CarbonImmutable::parse($row->absolute_expires_at)->equalTo($absolute));
        self::assertTrue(
            CarbonImmutable::parse($row->last_activity_at)->equalTo($current->addMinute())
        );
    }

    public function test_existing_cookie_and_user_security_semantics_are_preserved(): void
    {
        $this->requestSession()->assertOk()->assertJsonPath('authenticated', false);
        $this->requestSession('malformed-cookie')
            ->assertOk()
            ->assertJsonPath('authenticated', false);
        $this->assertSessionExpired($this->requestSession(hash('sha256', 'missing-session')));

        $current = now()->toImmutable()->utc();
        $expired = $this->createSession(
            $current,
            $current->subMinute(),
            V2UserState::Active,
            $current->subMinute()
        );
        $this->assertSessionExpired($this->requestSession($expired['token']));

        $revoked = $this->createSession($current, $current->addHour(), V2UserState::Active, null, true);
        $this->assertSessionExpired($this->requestSession($revoked['token']));

        $unavailable = $this->createSession($current, $current->addHour(), V2UserState::Suspended);
        $this->assertSessionExpired($this->requestSession($unavailable['token']));

        $valid = $this->createSession($current, $current->addHours(13));
        $this->requestSession($valid['token'])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.id', $valid['user']->public_id);

        $restricted = $this->createSession(
            $current,
            $current->addHours(13),
            V2UserState::Restricted
        );
        $this->requestSession($restricted['token'])
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.state', V2UserState::Restricted->value);
    }

    public function test_real_database_update_failure_is_not_converted_to_anonymous_or_expired(): void
    {
        $current = now()->toImmutable()->utc();
        $session = $this->createSession($current, $current->addHours(13));
        DB::statement(
            'ALTER TABLE user_sessions ADD CONSTRAINT sec018_forced_update_failure '.
            'CHECK (false) NOT VALID'
        );
        $this->withoutExceptionHandling();

        $this->expectException(QueryException::class);
        $this->requestSession($session['token']);
    }

    /**
     * @return array{token: string, hash: string, user: User}
     */
    private function createSession(
        CarbonImmutable $current,
        CarbonImmutable $absolute,
        V2UserState $state = V2UserState::Active,
        ?CarbonImmutable $idle = null,
        bool $revoked = false
    ): array {
        $this->fixtureSequence++;
        $user = User::query()->create([
            'email_display' => "session-idle-{$this->fixtureSequence}@example.test",
            'email_normalized' => "session-idle-{$this->fixtureSequence}@example.test",
            'email_verified_at' => $current,
            'password_hash' => '$argon2id$synthetic-session-idle-test',
            'state' => $state,
        ]);
        $token = hash('sha256', "session-idle-token-{$this->fixtureSequence}");
        $hash = app(V2SessionPolicy::class)->hashSessionId($token);
        DB::table('user_sessions')->insert([
            'session_id_hash' => $hash,
            'user_id' => $user->getKey(),
            'created_at' => $current->subHours(2),
            'last_activity_at' => $current->subMinutes(2),
            'reauthenticated_at' => $current->subHours(2),
            'idle_expires_at' => $idle ?? $current->addMinute(),
            'absolute_expires_at' => $absolute,
            'revoked_at' => $revoked ? $current->subMinute() : null,
        ]);

        return ['token' => $token, 'hash' => $hash, 'user' => $user];
    }

    private function requestSession(?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        $request = $this
            ->withCredentials()
            ->withServerVariables(['HTTPS' => 'on']);
        if ($token !== null) {
            $request = $request->withUnencryptedCookie('__Host-oripa_user_session', $token);
        }

        return $request->getJson('/api/v2/auth/session');
    }

    private function sessionRow(string $hash): object
    {
        $row = DB::table('user_sessions')->where('session_id_hash', $hash)->first();
        self::assertNotNull($row);

        return $row;
    }

    private function login(): array
    {
        $this->fixtureSequence++;
        $email = "session-lifecycle-{$this->fixtureSequence}@example.test";
        $password = 'synthetic lifecycle password';
        $user = User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now()->toImmutable()->utc(),
            'password_hash' => app(V2PasswordPolicy::class)->hash($password),
            'state' => V2UserState::Active,
        ]);
        $preflight = $this->requestSession()->assertOk()->assertJsonPath('authenticated', false);
        $csrf = collect($preflight->headers->getCookies())->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_xsrf'
        );
        self::assertNotNull($csrf);
        Auth::forgetGuards();
        $response = $this
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf->getValue(),
            ])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf->getValue())
            ->postJson('/api/v2/auth/login', ['email' => $email, 'password' => $password]);
        $response->assertOk()->assertJsonPath('authenticated', true);
        $cookie = collect($response->headers->getCookies())->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_session'
        );
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertNull($cookie->getDomain());

        return ['token' => $cookie->getValue(), 'hash' => hash('sha256', $cookie->getValue()), 'user' => $user];
    }

    private function assertTimestampOrdering(string $hash, CarbonImmutable $absolute): void
    {
        $row = $this->sessionRow($hash);
        $created = CarbonImmutable::parse($row->created_at);
        $activity = CarbonImmutable::parse($row->last_activity_at);
        $idle = CarbonImmutable::parse($row->idle_expires_at);
        self::assertTrue($created->lessThanOrEqualTo($activity));
        self::assertTrue($activity->lessThan($idle));
        self::assertTrue($idle->lessThanOrEqualTo($absolute));
        self::assertTrue(CarbonImmutable::parse($row->absolute_expires_at)->equalTo($absolute));
    }

    private function assertSessionExpired(TestResponse $response): void
    {
        $response
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_EXPIRED');
        self::assertNotNull(collect($response->headers->getCookies())->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_session'
        ));
    }
}
