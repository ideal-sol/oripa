<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Payment\V2\Exceptions\V2PointPurchasePlanException;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Payment\V2\Services\V2PointPurchaseEligibilityService;
use App\Domain\Payment\V2\Services\V2PointPurchasePlanService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminPointPurchasePlanManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_payment.mock_driver' => true,
        ]);
        CarbonImmutable::setTestNow('2026-08-06T09:00:00Z');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_v1_fields_audience_cursor_and_permissions_are_canonical(): void
    {
        $service = app(V2PointPurchasePlanService::class);
        $operator = $this->context(V2AdminRole::Operator);
        self::assertIsArray($service->listing($operator, null)['items']);
        try {
            $service->create($operator, $this->payload(), (string) Str::uuid7());
            self::fail('Operator mutation must be denied.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }

        $owner = $this->context(V2AdminRole::Owner);
        $first = $service->create($owner, $this->payload(), (string) Str::uuid7());
        $second = $service->create($owner, [
            ...$this->payload(),
            'name' => '初回限定',
            'sort_order' => 20,
            'audience_code' => 'first_purchase_users',
        ], (string) Str::uuid7());
        self::assertSame('all_users', $first['data']['audience_code']);
        self::assertSame('first_purchase_users', $second['data']['audience_code']);
        self::assertSame('published', $first['data']['status']);
        self::assertSame(1, $first['data']['revision']);
        self::assertTrue(Str::isUuid($first['data']['id']));

        $page = $service->listing($operator, null, 1);
        self::assertCount(1, $page['items']);
        self::assertNotNull($page['next_cursor']);
        self::assertCount(1, $service->listing($operator, $page['next_cursor'], 1)['items']);
        self::assertArrayNotHasKey('code', $first['data']);
    }

    public function test_create_and_update_are_idempotent_revisioned_audited_and_balance_neutral(): void
    {
        $service = app(V2PointPurchasePlanService::class);
        $context = $this->context(V2AdminRole::Admin);
        $key = (string) Str::uuid7();
        $before = $this->pointCounts();
        $created = $service->create($context, $this->payload(), $key);
        self::assertFalse($created['idempotent_replay']);
        self::assertTrue($service->create($context, $this->payload(), $key)['idempotent_replay']);
        try {
            $service->create($context, [...$this->payload(), 'name' => '別商品'], $key);
            self::fail('A reused key with another payload must fail.');
        } catch (V2PointPurchasePlanException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }

        $updated = $service->update($context, $created['data']['id'], [
            ...$this->payload(),
            'expected_revision' => 1,
            'name' => 'スタンダード改訂',
            'audience_code' => 'first_purchase_users',
        ], (string) Str::uuid7());
        self::assertSame(2, $updated['data']['version']);
        self::assertNotSame($created['data']['id'], $updated['data']['id']);
        self::assertDatabaseHas('point_purchase_plans', [
            'public_id' => $created['data']['id'],
            'status' => 'retired',
        ]);
        try {
            $service->update($context, $created['data']['id'], [
                ...$this->payload(),
                'expected_revision' => 2,
            ], (string) Str::uuid7());
            self::fail('A retired historical version must remain immutable.');
        } catch (V2PointPurchasePlanException $exception) {
            self::assertSame('POINT_PURCHASE_PLAN_REVISION_CONFLICT', $exception->errorCode);
        }
        self::assertSame($before, $this->pointCounts());
        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'payment.plan.created')->count());
        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'payment.plan.updated')->count());
    }

    public function test_validation_revision_and_public_contract_fail_closed(): void
    {
        $service = app(V2PointPurchasePlanService::class);
        $context = $this->context(V2AdminRole::Owner);
        foreach ([
            [...$this->payload(), 'amount' => 0, 'paid_point_amount' => 0],
            [...$this->payload(), 'paid_point_amount' => 999],
            [...$this->payload(), 'audience_code' => 'draw_first_users'],
            [...$this->payload(), 'available_from' => '2026-08-02T00:00:00+09:00',
                'available_until' => '2026-08-01T00:00:00+09:00'],
        ] as $input) {
            try {
                $service->create($context, $input, (string) Str::uuid7());
                self::fail('Invalid input must fail.');
            } catch (V2PointPurchasePlanException $exception) {
                self::assertSame('POINT_PURCHASE_PLAN_INVALID', $exception->errorCode);
            }
        }
        $created = $service->create($context, $this->payload(), (string) Str::uuid7());
        try {
            $service->update($context, $created['data']['id'], [
                ...$this->payload(), 'expected_revision' => 2,
            ], (string) Str::uuid7());
            self::fail('Stale revision must fail.');
        } catch (V2PointPurchasePlanException $exception) {
            self::assertSame('POINT_PURCHASE_PLAN_REVISION_CONFLICT', $exception->errorCode);
        }

        $this->getJson('/admin/api/v2/point-purchase-plans')->assertUnauthorized();
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with(
                $route->uri(), 'admin/api/v2/point-purchase-plans'
            ))
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->map(fn (string $method): string => $method.' '.$route->uri()));
        self::assertContains('GET admin/api/v2/point-purchase-plans', $routes);
        self::assertContains('POST admin/api/v2/point-purchase-plans', $routes);
        self::assertContains('GET admin/api/v2/point-purchase-plans/{planId}', $routes);
        self::assertContains('PUT admin/api/v2/point-purchase-plans/{planId}', $routes);
    }

    public function test_first_purchase_eligibility_uses_only_successful_payments(): void
    {
        $user = $this->user('eligibility');
        $plan = $this->plan('first_purchase_users');
        $eligibility = app(V2PointPurchaseEligibilityService::class);
        self::assertTrue($eligibility->eligible($user, $plan));
        foreach (['created', 'processing', 'failed', 'canceled', 'expired'] as $status) {
            $this->payment($user, $plan, $status);
        }
        self::assertTrue($eligibility->eligible($user, $plan));
        $this->payment($user, $plan, 'succeeded');
        self::assertFalse($eligibility->eligible($user, $plan));

        DB::table('payment_adjustments')->insert([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => DB::table('payments')->where('status', 'succeeded')->value('id'),
            'type' => 'refund',
            'status' => 'succeeded',
            'amount' => 1000,
            'currency' => 'JPY',
            'requested_at' => now(),
            'succeeded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::assertFalse($eligibility->eligible($user, $plan));
    }

    public function test_success_confirmation_rechecks_first_purchase_under_user_lock(): void
    {
        $user = $this->user('completion');
        $plan = $this->plan('first_purchase_users');
        $service = app(V2PaymentService::class);
        $grantsBefore = DB::table('payment_point_grants')->count();
        $first = $service->createPayment(
            $user->id, $plan->id, 'mock', 'first-'.Str::uuid7(), (string) Str::uuid7()
        );
        $second = $service->createPayment(
            $user->id, $plan->id, 'mock', 'second-'.Str::uuid7(), (string) Str::uuid7()
        );
        $firstEvent = $service->recordVerifiedProviderEvent(
            'mock', 'event-first-'.Str::uuid7(), 'payment.succeeded', '{}', [], $first->id
        );
        $secondEvent = $service->recordVerifiedProviderEvent(
            'mock', 'event-second-'.Str::uuid7(), 'payment.succeeded', '{}', [], $second->id
        );
        $service->confirmSucceeded($firstEvent->id);
        try {
            $service->confirmSucceeded($secondEvent->id);
            self::fail('A second first-purchase completion must fail.');
        } catch (V2PaymentException $exception) {
            self::assertSame('POINT_PURCHASE_FIRST_PURCHASE_REQUIRED', $exception->getMessage());
        }
        self::assertDatabaseHas('payments', ['id' => $second->id, 'status' => 'created']);
        self::assertSame($grantsBefore + 1, DB::table('payment_point_grants')->count());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'スタンダード',
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'sort_order' => 10,
            'is_active' => true,
            'available_from' => '2026-08-01T00:00:00+09:00',
            'available_until' => '2026-09-01T00:00:00+09:00',
            'audience_code' => 'all_users',
        ];
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'purchase-'.$role->value.'-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid admin password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $sessionHash = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $sessionHash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now()->subHour(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(7),
        ]);

        return new V2AdminAuthorizationContext(
            $admin->id,
            $admin->public_id,
            $role,
            $sessionHash,
            hash('sha256', $sessionHash),
            (string) Str::uuid7()
        );
    }

    private function user(string $suffix): User
    {
        $email = 'purchase-'.$suffix.'-'.Str::uuid7().'@example.test';

        return User::query()->create([
            'display_name' => 'Synthetic buyer',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid user password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function plan(string $audience): object
    {
        $id = DB::table('point_purchase_plans')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'code' => 'test-'.Str::lower(Str::random(16)),
            'version_no' => 1,
            'name' => 'Eligibility plan',
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 0,
            'currency' => 'JPY',
            'status' => 'published',
            'sort_order' => 1,
            'audience_code' => $audience,
            'revision' => 1,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('point_purchase_plans')->find($id);
    }

    private function payment(User $user, object $plan, string $status): void
    {
        DB::table('payments')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'point_purchase_plan_id' => $plan->id,
            'provider_code' => 'mock',
            'provider_payment_id' => (string) Str::uuid7(),
            'status' => $status,
            'amount' => 1000,
            'currency' => 'JPY',
            'paid_point_amount' => 1000,
            'free_point_amount' => 0,
            'plan_name_snapshot' => $plan->name,
            'plan_code_snapshot' => $plan->code,
            'succeeded_at' => $status === 'succeeded' ? now() : null,
            'failed_at' => $status === 'failed' ? now() : null,
            'canceled_at' => $status === 'canceled' ? now() : null,
            'expired_at' => $status === 'expired' ? now() : null,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function pointCounts(): array
    {
        return [
            'wallets' => DB::table('wallets')->count(),
            'lots' => DB::table('point_lots')->count(),
            'operations' => DB::table('point_operations')->count(),
            'ledgers' => DB::table('point_ledger_entries')->count(),
        ];
    }
}
