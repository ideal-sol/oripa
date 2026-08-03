<?php

namespace Tests\Unit;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Domain\Reporting\Services\V2ReportingService;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2ReportingServiceDashboardAggregationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-08-03T00:00:00Z');
        config([
            'cache.default' => 'array',
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.pagination.maximum' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_sales_use_success_events_jst_boundaries_and_canonical_net_formula(): void
    {
        $context = $this->context(V2AdminRole::Owner);
        $userId = $this->user();
        $this->payment($userId, '2026-07-31T14:59:59Z', 1000);
        $augustPayment = $this->payment($userId, '2026-07-31T15:00:00Z', 2000);
        $this->payment($userId, '2026-08-01T00:00:00Z', 900, 'failed');
        $this->adjustment($augustPayment, 'refund', 'succeeded', '2026-08-01T01:00:00Z', 500);
        $this->adjustment($augustPayment, 'chargeback', 'succeeded', '2026-08-01T02:00:00Z', 300);
        $this->adjustment($augustPayment, 'refund', 'requested', '2026-08-01T03:00:00Z', 100);

        $report = app(V2ReportingService::class)->dashboardMonthlySales($context, '2026-08');

        self::assertSame('Asia/Tokyo', $report['timezone']);
        self::assertSame(2000, $report['summary']['gross_sales_amount']);
        self::assertSame(500, $report['summary']['refund_amount']);
        self::assertSame(300, $report['summary']['chargeback_amount']);
        self::assertSame(1200, $report['summary']['net_sales_amount']);
        self::assertSame(['2026-08-01'], array_column($report['days'], 'date'));
        self::assertSame(1200, $report['days'][0]['summary']['net_sales_amount']);

        $daily = app(V2ReportingService::class)
            ->dashboardDailySales($context, '2026-08-01', null, 20);
        self::assertCount(1, $daily['items']);
        self::assertSame(2000, $daily['summary']['gross_sales_amount']);
        self::assertArrayNotHasKey('provider_payment_id', $daily['items'][0]);
    }

    public function test_point_consumption_uses_spend_ledger_and_excludes_qa_operations(): void
    {
        $context = $this->context(V2AdminRole::Admin);
        $userId = $this->user();
        $walletId = $this->wallet($userId);
        $this->pointSpend($userId, $walletId, false, '2026-07-31T15:00:00Z', 70, 30);
        $this->pointSpend($userId, $walletId, true, '2026-07-31T15:10:00Z', 999, 999);

        $monthly = app(V2ReportingService::class)
            ->dashboardMonthlyPoints($context, '2026-08');
        self::assertTrue($monthly['qa_excluded']);
        self::assertSame(70, $monthly['summary']['paid_consumed']);
        self::assertSame(30, $monthly['summary']['free_consumed']);
        self::assertSame('2026-08-01', $monthly['days'][0]['date']);

        $daily = app(V2ReportingService::class)
            ->dashboardDailyPoints($context, '2026-08-01', null, 20);
        self::assertCount(1, $daily['items']);
        self::assertSame(70, $daily['items'][0]['paid_consumed']);
        self::assertSame(30, $daily['items'][0]['free_consumed']);
        self::assertArrayNotHasKey('point_operation_id', $daily['items'][0]);
    }

    public function test_reversal_history_uses_stable_opaque_cursor_and_typed_occurrence_time(): void
    {
        $context = $this->context(V2AdminRole::Owner);
        $userId = $this->user();
        $payment = $this->payment($userId, '2026-08-01T00:00:00Z', 2000);
        $first = $this->adjustment($payment, 'refund', 'requested', '2026-08-01T01:00:00Z', 100);
        $second = $this->adjustment($payment, 'chargeback', 'succeeded', '2026-08-01T02:00:00Z', 200);

        $page = app(V2ReportingService::class)
            ->dashboardReversals($context, '2026-08-01', '2026-08-01', null, 1);
        self::assertCount(1, $page['items']);
        self::assertSame(DB::table('payment_adjustments')->find($first)->public_id, $page['items'][0]['adjustment_id']);
        self::assertNotNull($page['next_cursor']);

        $next = app(V2ReportingService::class)->dashboardReversals(
            $context,
            '2026-08-01',
            '2026-08-01',
            $page['next_cursor'],
            1
        );
        self::assertSame(DB::table('payment_adjustments')->find($second)->public_id, $next['items'][0]['adjustment_id']);
        self::assertNull($next['next_cursor']);
        self::assertSame('succeeded', $next['items'][0]['status']);
    }

    public function test_invalid_range_and_operator_permission_fail_closed(): void
    {
        $service = app(V2ReportingService::class);
        try {
            $service->dashboardReversals(
                $this->context(V2AdminRole::Owner),
                '2026-08-02',
                '2026-08-01',
                null,
                20
            );
            self::fail('An inverted range must be rejected.');
        } catch (V2ReportingException $exception) {
            self::assertSame('REPORTING_PERIOD_INVALID', $exception->errorCode);
        }

        $this->expectException(V2AuthenticationException::class);
        $service->dashboardMonthlySales($this->context(V2AdminRole::Operator), '2026-08');
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'dashboard-admin-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $hash = hash('sha256', random_bytes(32));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(8),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }

    private function user(): int
    {
        $email = 'dashboard-user-'.Str::uuid7().'@example.test';

        return DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function wallet(int $userId): int
    {
        return DB::table('wallets')->insertGetId([
            'user_id' => $userId,
            'paid_balance' => 10000,
            'free_balance' => 10000,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payment(
        int $userId,
        string $occurredAt,
        int $amount,
        string $status = 'succeeded'
    ): int {
        return DB::table('payments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'point_purchase_plan_id' => null,
            'provider_code' => 'synthetic',
            'provider_payment_id' => (string) Str::uuid7(),
            'status' => $status,
            'amount' => $amount,
            'currency' => 'JPY',
            'paid_point_amount' => $amount,
            'free_point_amount' => 0,
            'plan_name_snapshot' => 'Synthetic Plan',
            'plan_code_snapshot' => 'synthetic-plan',
            'idempotency_record_id' => null,
            'succeeded_at' => $status === 'succeeded' ? $occurredAt : null,
            'failed_at' => $status === 'failed' ? $occurredAt : null,
            'points_granted_at' => $status === 'succeeded' ? $occurredAt : null,
            'metadata' => '{}',
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function adjustment(
        int $paymentId,
        string $type,
        string $status,
        string $occurredAt,
        int $amount
    ): int {
        return DB::table('payment_adjustments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => $paymentId,
            'parent_adjustment_id' => null,
            'type' => $type,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'JPY',
            'requested_at' => $occurredAt,
            'succeeded_at' => $status === 'succeeded' ? $occurredAt : null,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function pointSpend(
        int $userId,
        int $walletId,
        bool $qa,
        string $occurredAt,
        int $paid,
        int $free
    ): void {
        $operationId = DB::table('point_operations')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'operation_type' => 'draw_spend',
            'business_key' => 'dashboard-spend-'.Str::uuid7(),
            'source_type' => 'draw',
            'source_id' => null,
            'actor_type' => 'user',
            'actor_id' => $userId,
            'is_qa' => $qa,
            'qa_draw_execution_id' => null,
            'occurred_at' => $occurredAt,
            'business_date' => CarbonImmutable::parse($occurredAt)->setTimezone('Asia/Tokyo')->toDateString(),
            'metadata' => '{}',
            'created_at' => $occurredAt,
        ]);
        $sequence = 1;
        foreach (['paid' => $paid, 'free' => $free] as $pointType => $amount) {
            if ($amount === 0) {
                continue;
            }
            DB::table('point_ledger_entries')->insert([
                'point_operation_id' => $operationId,
                'sequence_no' => $sequence++,
                'user_id' => $userId,
                'wallet_id' => $walletId,
                'point_lot_id' => null,
                'point_type' => $pointType,
                'entry_type' => 'spend',
                'amount_delta' => -$amount,
                'wallet_balance_after' => 10000,
                'lot_remaining_after' => null,
                'occurred_at' => $occurredAt,
                'business_date' => CarbonImmutable::parse($occurredAt)->setTimezone('Asia/Tokyo')->toDateString(),
                'created_at' => $occurredAt,
            ]);
        }
    }
}
