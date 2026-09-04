<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\User;
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
    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalDatabaseTimezone = config(
            "database.connections.{$this->connectionName}.timezone"
        );
        $this->originalApplicationTimezone = config('app.timezone');
        config([
            'app.timezone' => 'Asia/Tokyo',
            "database.connections.{$this->connectionName}.timezone" => 'UTC',
        ]);
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
        Auth::forgetGuards();
        DB::purge($this->connectionName);
        config([
            'app.timezone' => $this->originalApplicationTimezone,
            "database.connections.{$this->connectionName}.timezone" => $this->originalDatabaseTimezone,
        ]);
        parent::tearDown();
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
