<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter as LaravelRateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class AdminUserPointAdjustmentApiTest extends TestCase
{
    private const PASSWORD = 'valid point adjustment password';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'oripa.free_point_expiration_days' => 180,
        ]);
        Cache::store('array')->clear();
        Carbon::setTestNow('2026-08-03T12:00:00Z');
        CarbonImmutable::setTestNow('2026-08-03T12:00:00Z');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_and_admin_adjust_paid_and_free_points_without_type_fallback(): void
    {
        $user = $this->user();
        $owner = $this->adminSession(V2AdminRole::Owner);
        $paidGrant = $this->mutate($owner, $user->public_id, [
            'point_type' => 'paid',
            'direction' => 'grant',
            'amount' => 500,
            'reason' => 'Paid balance correction.',
        ])->assertOk()
            ->assertJsonPath('data.paid_balance_before', 0)
            ->assertJsonPath('data.paid_balance_after', 500)
            ->assertJsonPath('data.free_balance_after', 0)
            ->assertJsonPath('idempotent_replay', false);
        self::assertSame('false', $paidGrant->headers->get('Idempotency-Replayed'));
        $paidLot = DB::table('point_lots')->where('point_type', 'paid')->sole();
        self::assertSame(
            now()->startOfSecond()->addDays(180)->getTimestamp(),
            CarbonImmutable::parse($paidLot->expire_at)->getTimestamp()
        );

        Auth::forgetGuards();
        $admin = $this->adminSession(V2AdminRole::Admin);
        $freeGrant = $this->mutate($admin, $user->public_id, [
            'point_type' => 'free',
            'direction' => 'grant',
            'amount' => 300,
            'reason' => 'Free balance correction.',
        ])->assertOk()->assertJsonPath('data.free_balance_after', 300);
        $freeLot = DB::table('point_lots')->where('point_type', 'free')->sole();
        self::assertSame(
            now()->startOfSecond()->addDays(180)->getTimestamp(),
            CarbonImmutable::parse($freeLot->expire_at)->getTimestamp()
        );

        Auth::forgetGuards();
        $this->mutate($owner, $user->public_id, [
            'point_type' => 'paid',
            'direction' => 'deduct',
            'amount' => 125,
            'reason' => 'Paid deduction correction.',
        ])->assertOk()
            ->assertJsonPath('data.paid_balance_after', 375)
            ->assertJsonPath('data.free_balance_after', 300);

        Auth::forgetGuards();
        $this->mutate($admin, $user->public_id, [
            'point_type' => 'free',
            'direction' => 'deduct',
            'amount' => 75,
            'reason' => 'Free deduction correction.',
        ])->assertOk()
            ->assertJsonPath('data.paid_balance_after', 375)
            ->assertJsonPath('data.free_balance_after', 225);

        self::assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 375,
            'free_balance' => 225,
        ]);
        self::assertSame(4, DB::table('point_adjustments')->count());
        self::assertSame(4, DB::table('point_operations')->where('source_type', 'admin_adjustment')->count());
        self::assertSame(4, DB::table('audit_logs')->where('action_code', 'point.admin_adjusted')->count());
        self::assertStringContainsString('private', (string) $freeGrant->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $freeGrant->headers->get('Cache-Control'));
    }

    public function test_permission_authentication_and_browser_security_fail_closed_without_freshness(): void
    {
        $user = $this->user();
        $payload = $this->payload();
        $operator = $this->adminSession(V2AdminRole::Operator);
        $this->mutate($operator, $user->public_id, $payload)
            ->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

        Auth::forgetGuards();
        $this->browserMutation()
            ->postJson('/admin/api/v2/users/'.$user->public_id.'/point-adjustments', $payload)
            ->assertUnauthorized();

        Auth::forgetGuards();
        $owner = $this->adminSession(V2AdminRole::Owner);
        $this->mutate($owner, $user->public_id, [
            ...$payload,
            'unexpected' => 'invalid field',
        ])->assertUnprocessable()->assertJsonPath('code', 'POINT_ADJUSTMENT_INVALID');

        DB::table('admin_sessions')->where('session_id_hash', app(V2SessionPolicy::class)->hashSessionId($owner))->update(V2TimestampFixture::attributes([
            'mfa_verified_at' => now()->subMinutes(5),
        ]));
        Auth::forgetGuards();
        $this->mutate($owner, $user->public_id, $payload)
            ->assertOk()->assertJsonPath('data.amount', $payload['amount']);

        Auth::forgetGuards();
        $disabled = $this->adminSession(V2AdminRole::Admin);
        DB::table('admins')->where('email_normalized', 'like', 'point-adjustment-admin-%')->update([
            'state' => V2AdminState::Disabled->value,
        ]);
        $this->mutate($disabled, $user->public_id, $payload)->assertUnauthorized();

        Auth::forgetGuards();
        $fresh = $this->adminSession(V2AdminRole::Owner);
        $this->asAdmin($fresh)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://evil.example.test'])
            ->postJson('/admin/api/v2/users/'.$user->public_id.'/point-adjustments', $payload)
            ->assertForbidden();
        Auth::forgetGuards();
        $this->asAdmin($fresh)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', str_repeat('c', 64))
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => str_repeat('d', 64),
                'Idempotency-Key' => (string) Str::uuid7(),
            ])
            ->postJson('/admin/api/v2/users/'.$user->public_id.'/point-adjustments', $payload)
            ->assertForbidden();
    }

    public function test_legacy_password_is_discarded_without_authentication_storage_or_logging(): void
    {
        $user = $this->user();
        $session = $this->adminSession(V2AdminRole::Owner);
        $key = (string) Str::uuid7();
        $legacyValues = ['discarded-point-password-one', 'discarded-point-password-two'];
        $queries = [];
        $messages = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [$query->sql, $query->bindings];
        });
        Log::listen(function (MessageLogged $event) use (&$messages): void {
            $messages[] = [$event->message, $event->context];
        });

        foreach ([...$legacyValues, null] as $index => $legacyValue) {
            Auth::forgetGuards();
            $payload = $this->payload();
            if ($legacyValue !== null) {
                $payload['current_password'] = $legacyValue;
            }
            $response = $this->mutate($session, $user->public_id, $payload, $key)
                ->assertOk()->assertJsonPath('idempotent_replay', $index > 0);
            self::assertFalse($this->app['request']->has('current_password'));
            foreach ($legacyValues as $value) {
                self::assertStringNotContainsString($value, $response->getContent());
            }
        }
        self::assertSame(1, DB::table('point_adjustments')->count());
        self::assertSame(1, DB::table('point_operations')->where('source_type', 'admin_adjustment')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action_code', 'point.admin_adjusted')->count());
        self::assertNotEmpty($queries);
        $observed = json_encode([$queries, $messages], JSON_THROW_ON_ERROR);
        foreach (['current_password', ...$legacyValues] as $value) {
            self::assertStringNotContainsString($value, $observed);
        }
    }

    public function test_validation_insufficient_balance_idempotency_and_secret_safe_audit(): void
    {
        $user = $this->user();
        $session = $this->adminSession(V2AdminRole::Owner);
        foreach ([
            [...$this->payload(), 'amount' => 0],
            [...$this->payload(), 'amount' => -1],
            [...$this->payload(), 'amount' => 1.5],
            [...$this->payload(), 'reason' => ''],
        ] as $invalid) {
            Auth::forgetGuards();
            $this->mutate($session, $user->public_id, $invalid)
                ->assertUnprocessable()->assertJsonPath('code', 'POINT_ADJUSTMENT_INVALID');
        }

        $key = (string) Str::uuid7();
        Auth::forgetGuards();
        $first = $this->mutate($session, $user->public_id, $this->payload(), $key)
            ->assertOk()->assertJsonPath('idempotent_replay', false);
        Auth::forgetGuards();
        $replay = $this->mutate($session, $user->public_id, $this->payload(), $key)
            ->assertOk()->assertJsonPath('idempotent_replay', true);
        self::assertEquals($first->json('data'), $replay->json('data'));
        self::assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        self::assertSame(1, DB::table('point_adjustments')->count());

        Auth::forgetGuards();
        $this->mutate($session, $user->public_id, [
            ...$this->payload(),
            'amount' => 51,
        ], $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        Auth::forgetGuards();
        $this->mutate($session, $user->public_id, [
            'point_type' => 'free',
            'direction' => 'deduct',
            'amount' => 1,
            'reason' => 'No paid fallback allowed.',
        ])->assertConflict()->assertJsonPath('code', 'POINT_ADJUSTMENT_INSUFFICIENT_BALANCE');
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 50, 'free_balance' => 0]);

        $audit = DB::table('audit_logs')->where('action_code', 'point.admin_adjusted')->sole();
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        foreach ([self::PASSWORD, 'password_hash', 'session_id', 'cookie'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        self::assertStringNotContainsString('user_id', (string) $audit->metadata_redacted);
        self::assertStringNotContainsString('admin_id', (string) $audit->metadata_redacted);
        self::assertSame($user->public_id, $audit->target_public_id);
    }

    public function test_audit_failure_rolls_back_wallet_lot_operation_adjustment_and_idempotency(): void
    {
        $user = $this->user();
        $session = $this->adminSession(V2AdminRole::Owner);
        config(['v2_audit.hmac_keys.v1' => 'invalid-key-material']);
        $this->withoutExceptionHandling();

        try {
            $this->mutate($session, $user->public_id, $this->payload(), 'rollback-key');
            self::fail('The invalid audit key did not fail closed.');
        } catch (\RuntimeException) {
            // The transaction must be rolled back after the audit boundary rejects the key.
        }

        self::assertDatabaseMissing('wallets', ['user_id' => $user->id]);
        self::assertSame(0, DB::table('point_lots')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('point_operations')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('point_adjustments')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('idempotency_records')->where('scope', 'point.admin_adjustment')->count());
    }

    public function test_adjustments_without_password_exceed_previous_critical_limit(): void
    {
        $user = $this->user();
        $session = $this->adminSession(V2AdminRole::Owner);
        for ($attempt = 1; $attempt <= 11; $attempt++) {
            Auth::forgetGuards();
            $this->mutate($session, $user->public_id, $this->payload())
                ->assertOk()->assertJsonPath('data.paid_balance_after', $attempt * 50);
        }

        self::assertSame(11, DB::table('point_adjustments')->where('user_id', $user->id)->count());
        self::assertSame(11, DB::table('audit_logs')->where('action_code', 'point.admin_adjusted')->count());
    }

    public function test_adjustment_does_not_access_unavailable_limiter(): void
    {
        $user = $this->user();
        $session = $this->adminSession(V2AdminRole::Owner);
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->andThrow(new \RuntimeException('cache unavailable'));
        $this->app->instance(
            V2RateLimiter::class,
            new V2RateLimiter(new LaravelRateLimiter($cache))
        );

        Auth::forgetGuards();
        $this->mutate($session, $user->public_id, $this->payload())
            ->assertOk()->assertJsonPath('data.paid_balance_after', 50);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 50]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'point_type' => 'paid',
            'direction' => 'grant',
            'amount' => 50,
            'reason' => 'Synthetic administrative correction.',
        ];
    }

    private function user(): User
    {
        $email = 'point-adjustment-user-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => 'Synthetic adjustment target',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function adminSession(V2AdminRole $role): string
    {
        $email = 'point-adjustment-'.$role->value.'-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash(self::PASSWORD),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $admin->id,
            'mfa_verified_at' => now()->subMinutes(6),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(8),
            'revoked_at' => null,
        ]));

        return $token;
    }

    private function mutate(
        string $token,
        string $userPublicId,
        array $payload,
        ?string $key = null
    ) {
        return $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', str_repeat('c', 64))
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => str_repeat('c', 64),
                'Idempotency-Key' => $key ?? (string) Str::uuid7(),
            ])
            ->postJson('/admin/api/v2/users/'.$userPublicId.'/point-adjustments', $payload);
    }

    private function browserMutation(): static
    {
        $csrf = str_repeat('b', 64);

        return $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_session', str_repeat('0', 64))
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => (string) Str::uuid7(),
            ]);
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }
}
