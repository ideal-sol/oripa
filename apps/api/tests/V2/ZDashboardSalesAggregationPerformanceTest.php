<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Reporting\Services\V2ReportingService;
use App\Models\V2\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZDashboardSalesAggregationPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        config([
            'cache.default' => 'array',
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.pagination.maximum' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_dashboard_queries_are_bounded_without_n_plus_one(): void
    {
        if (getenv('V2_DASHBOARD_AGGREGATION_PERFORMANCE_TEST') !== '1') {
            self::markTestSkipped('Dashboard performance test is opt-in.');
        }
        [$context, $userId, $walletId] = $this->fixture();
        for ($index = 1; $index <= 100; $index++) {
            DB::table('payments')->insert([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'provider_code' => 'synthetic',
                'provider_payment_id' => (string) Str::uuid7(),
                'status' => 'succeeded',
                'amount' => 100,
                'currency' => 'JPY',
                'paid_point_amount' => 100,
                'free_point_amount' => 0,
                'plan_name_snapshot' => 'Synthetic Plan',
                'plan_code_snapshot' => 'synthetic-plan',
                'succeeded_at' => '2026-08-01T00:00:00Z',
                'points_granted_at' => '2026-08-01T00:00:00Z',
                'metadata' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $operationId = DB::table('point_operations')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'operation_type' => 'draw_spend',
                'business_key' => 'dashboard-performance-'.$index,
                'source_type' => 'draw',
                'actor_type' => 'user',
                'actor_id' => $userId,
                'is_qa' => false,
                'occurred_at' => '2026-08-01T00:00:00Z',
                'business_date' => '2026-08-01',
                'metadata' => '{}',
                'created_at' => now(),
            ]);
            DB::table('point_ledger_entries')->insert([
                'point_operation_id' => $operationId,
                'sequence_no' => 1,
                'user_id' => $userId,
                'wallet_id' => $walletId,
                'point_type' => 'paid',
                'entry_type' => 'spend',
                'amount_delta' => -10,
                'wallet_balance_after' => 100000,
                'occurred_at' => '2026-08-01T00:00:00Z',
                'business_date' => '2026-08-01',
                'created_at' => now(),
            ]);
        }

        $currentQueryCount = 0;
        DB::listen(static function () use (&$currentQueryCount): void {
            $currentQueryCount++;
        });
        $started = hrtime(true);
        $service = app(V2ReportingService::class);
        $monthlySales = $service->dashboardMonthlySales($context, '2026-08');
        $monthlySalesQueries = $currentQueryCount;
        $currentQueryCount = 0;
        $monthlyPoints = $service->dashboardMonthlyPoints($context, '2026-08');
        $monthlyPointsQueries = $currentQueryCount;
        $currentQueryCount = 0;
        $dailyPoints = $service->dashboardDailyPoints($context, '2026-08-01', null, 100);
        $dailyPointsQueries = $currentQueryCount;
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;
        $totalQueries = $monthlySalesQueries + $monthlyPointsQueries + $dailyPointsQueries;

        self::assertSame(10000, $monthlySales['summary']['gross_sales_amount']);
        self::assertSame(1000, $monthlyPoints['summary']['paid_consumed']);
        self::assertCount(100, $dailyPoints['items']);
        self::assertLessThanOrEqual(12, $monthlySalesQueries);
        self::assertLessThanOrEqual(12, $monthlyPointsQueries);
        self::assertLessThanOrEqual(12, $dailyPointsQueries);
        self::assertLessThanOrEqual(30, $totalQueries, 'Dashboard query count must remain bounded.');
        self::assertLessThan(2000, $elapsedMilliseconds, 'Dashboard target queries exceeded 2 seconds.');
        fwrite(STDERR, sprintf(
            "MIG-061F performance rows=100 queries=%d/%d/%d total=%d elapsed_ms=%.2f\n",
            $monthlySalesQueries,
            $monthlyPointsQueries,
            $dailyPointsQueries,
            $totalQueries,
            $elapsedMilliseconds
        ));
    }

    /** @return array{V2AdminAuthorizationContext, int, int} */
    private function fixture(): array
    {
        $adminEmail = 'dashboard-performance-admin-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $adminEmail,
            'email_normalized' => $adminEmail,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner,
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
        ]);
        $userEmail = 'dashboard-performance-user-'.Str::uuid7().'@example.test';
        $userId = DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $userEmail,
            'email_normalized' => $userEmail,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $userId,
            'paid_balance' => 100000,
            'free_balance' => 0,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $context = new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );

        return [$context, $userId, $walletId];
    }
}
