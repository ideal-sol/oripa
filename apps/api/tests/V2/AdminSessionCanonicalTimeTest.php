<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use App\Models\V2\AdminSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AdminSessionCanonicalTimeTest extends TestCase
{
    private const DASHBOARD = '/admin/api/v2/reports/dashboard/sales/monthly?month=2026-09';
    private const DATA = '/admin/api/v2/reports/dashboard/sales/daily?date=2026-09-09';
    private const PASSWORD = 'synthetic canonical admin password';

    private string $connectionName;
    private mixed $originalDatabaseTimezone;
    private mixed $originalApplicationTimezone;
    private string $originalPhpTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalDatabaseTimezone = config("database.connections.{$this->connectionName}.timezone");
        $this->originalApplicationTimezone = config('app.timezone');
        $this->originalPhpTimezone = date_default_timezone_get();
        config([
            'app.timezone' => 'Asia/Tokyo',
            "database.connections.{$this->connectionName}.timezone" => 'UTC',
            'cache.default' => 'array',
            'v2_identity.transactions.store' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.webauthn.rp_id' => 'admin.example.test',
            'v2_identity.webauthn.origin' => 'https://admin.example.test',
        ]);
        date_default_timezone_set('Asia/Tokyo');
        DB::purge($this->connectionName);
        DB::reconnect($this->connectionName);
        DB::beginTransaction();
        Cache::store('array')->clear();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-09 12:00:00.987654', 'Asia/Tokyo'));
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertSame('UTC', DB::connection()->getConfig('timezone'));
        self::assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        Auth::forgetGuards();
        date_default_timezone_set($this->originalPhpTimezone);
        DB::purge($this->connectionName);
        config([
            'app.timezone' => $this->originalApplicationTimezone,
            "database.connections.{$this->connectionName}.timezone" => $this->originalDatabaseTimezone,
        ]);
        parent::tearDown();
    }

    public function test_fresh_login_authenticates_permissions_dashboard_and_additional_data_without_instant_shift(): void
    {
        $token = $this->login();
        $current = app(V2SessionPolicy::class)->currentTime();
        $row = $this->sessionRow($token);
        self::assertSame(0.0, $current->diffInSeconds($row->created_at));
        self::assertTrue($row->idle_expires_at->equalTo($current->addHours(6)));
        self::assertTrue($row->absolute_expires_at->equalTo($current->addHours(12)));
        $binding = DB::connection()->prepareBindings([$current])[0];
        self::assertSame(0.0, (float) DB::selectOne(
            'SELECT EXTRACT(EPOCH FROM (CAST(? AS timestamptz) - CAST(? AS timestamptz))) AS shift',
            [$binding, $current->toIso8601String()]
        )->shift);

        foreach (['/admin/api/v2/auth/permissions', self::DASHBOARD, self::DATA] as $path) {
            $this->getAsAdmin($token, $path)->assertOk();
        }
        $authorizer = app(V2AdminFreshMfaAuthorizer::class);
        $context = $authorizer->context($this->request($token));
        self::assertSame($row->admin_id, $authorizer->authorizeReporting($context)->id);
        self::assertSame($row->admin_id, $authorizer->validSessionForReauthentication($context, true)->admin_id);
    }

    public function test_fresh_mfa_expires_at_exactly_five_minutes_without_extending_its_window(): void
    {
        $token = $this->login();
        $verifiedAt = $this->sessionRow($token)->mfa_verified_at;
        $authorizer = app(V2AdminFreshMfaAuthorizer::class);
        $context = $authorizer->context($this->request($token));
        Carbon::setTestNow($verifiedAt->addMinutes(5)->subMicrosecond()->setTimezone('Asia/Tokyo'));
        self::assertTrue($authorizer->isFresh($this->sessionRow($token)));
        $authorizer->authorizeQa($context);

        Carbon::setTestNow($verifiedAt->addMinutes(5)->setTimezone('Asia/Tokyo'));
        self::assertFalse($authorizer->isFresh($this->sessionRow($token)));
        $this->assertAuthenticationError(fn () => $authorizer->authorizeQa($context), 'FRESH_AUTHENTICATION_REQUIRED', 403);
        $this->getAsAdmin($token, self::DASHBOARD)->assertOk();
    }

    public function test_idle_expiry_rejects_at_and_after_the_boundary(): void
    {
        $this->assertExpiryBoundary('idle_expires_at');
    }

    public function test_absolute_expiry_rejects_at_and_after_the_boundary(): void
    {
        $this->assertExpiryBoundary('absolute_expires_at');
    }

    public function test_missing_invalid_revoked_and_enrollment_sessions_fail_closed(): void
    {
        $authorizer = app(V2AdminFreshMfaAuthorizer::class);
        foreach (['', 'invalid', str_repeat('a', 64)] as $token) {
            $this->assertAuthenticationError(fn () => $authorizer->context($this->request($token)));
            $this->getAsAdmin($token, self::DASHBOARD)->assertUnauthorized();
        }
        $token = $this->login();
        $this->sessionRow($token)->update(['revoked_at' => app(V2SessionPolicy::class)->currentTime()]);
        $this->assertAuthenticationError(fn () => $authorizer->context($this->request($token)));
        $this->getAsAdmin($token, self::DASHBOARD)->assertUnauthorized();

        $token = $this->login();
        $this->sessionRow($token)->update(['requires_mfa_enrollment' => true]);
        $this->assertAuthenticationError(fn () => $authorizer->context($this->request($token)));
        $this->getAsAdmin($token, self::DASHBOARD)->assertUnauthorized();
    }

    private function assertExpiryBoundary(string $column): void
    {
        $token = $this->login();
        $session = $this->sessionRow($token);
        $expiry = $session->{$column};
        if ($column === 'absolute_expires_at') {
            $session->update(['last_activity_at' => $expiry->subHour(), 'idle_expires_at' => $expiry]);
        }
        $authorizer = app(V2AdminFreshMfaAuthorizer::class);
        Carbon::setTestNow($expiry->subSecond()->setTimezone('Asia/Tokyo'));
        $context = $authorizer->context($this->request($token));
        self::assertSame($session->admin_id, $authorizer->authorizeReporting($context)->id);

        foreach ([0, 1] as $seconds) {
            Carbon::setTestNow($expiry->addSeconds($seconds)->setTimezone('Asia/Tokyo'));
            $this->assertAuthenticationError(fn () => $authorizer->context($this->request($token)));
            $this->assertAuthenticationError(fn () => $authorizer->validSessionForReauthentication($context, true));
            $this->getAsAdmin($token, '/admin/api/v2/auth/permissions')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
            $this->getAsAdmin($token, self::DASHBOARD)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    private function login(): string
    {
        $email = 'canonical-admin-'.Str::uuid7().'@example.test';
        Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => app(V2SessionPolicy::class)->currentTime(),
            'password_hash' => app(V2PasswordPolicy::class)->hash(self::PASSWORD),
            'role' => V2AdminRole::Owner,
            'state' => V2AdminState::Active,
        ]);
        $csrf = str_repeat('b', 64);
        Auth::forgetGuards();
        $response = $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ])->postJson('/admin/api/v2/auth/login', ['email' => $email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('authenticated', true);
        $cookie = $response->getCookie('__Host-oripa_admin_session', false);
        self::assertNotNull($cookie);

        return $cookie->getValue();
    }

    private function sessionRow(string $token): AdminSession
    {
        return AdminSession::query()->findOrFail(app(V2SessionPolicy::class)->hashSessionId($token));
    }

    private function request(string $token): Request
    {
        $request = Request::create(self::DASHBOARD);
        if ($token !== '') {
            $request->cookies->set('__Host-oripa_admin_session', $token);
        }

        return $request;
    }

    private function getAsAdmin(string $token, string $path): TestResponse
    {
        Auth::forgetGuards();

        return $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $token)->getJson($path);
    }

    private function assertAuthenticationError(callable $operation, string $code = 'AUTHENTICATION_REQUIRED', int $status = 401): void
    {
        try {
            $operation();
            self::fail('The Admin authentication boundary must reject the session.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->status);
        }
    }
}
