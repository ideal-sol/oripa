<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Reporting\Services\V2ExportService;
use App\Domain\Reporting\Services\V2ExportWorker;
use App\Domain\Reporting\Services\V2ReportingService;
use App\Models\V2\Admin;
use App\Models\V2\ExportJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ZReportingExportPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('V2_REPORTING_PERFORMANCE_TEST') !== '1') {
            $this->markTestSkipped('Reporting performance test is opt-in.');
        }
        DB::beginTransaction();
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_identity.rate_limits.financial_export' => [5, 3600],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.export_disk' => 'local',
        ]);
        Cache::store('array')->clear();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_monthly_summary_and_async_100k_csv_stay_within_resource_limits(): void
    {
        $context = $this->context();
        $userId = $this->user();
        DB::statement(
            <<<'SQL'
                INSERT INTO payments (
                    public_id, user_id, provider_code, provider_payment_id, status,
                    amount, currency, paid_point_amount, free_point_amount,
                    plan_name_snapshot, plan_code_snapshot, succeeded_at,
                    points_granted_at, metadata, created_at, updated_at
                )
                SELECT
                    ('00000000-0000-7000-8000-' || lpad(to_hex(gs), 12, '0'))::uuid,
                    ?, 'performance', 'perf-' || gs, 'succeeded',
                    100, 'JPY', 100, 0, 'Performance', 'performance',
                    '2026-07-15 00:00:00+00'::timestamptz + (gs % 86400) * interval '1 second',
                    '2026-07-15 00:00:00+00'::timestamptz,
                    '{}'::jsonb, now(), now()
                FROM generate_series(1, 100000) AS gs
            SQL,
            [$userId]
        );
        $durations = [];
        $summaryQueries = 0;
        $countSummaryQueries = true;
        DB::listen(function () use (&$summaryQueries, &$countSummaryQueries): void {
            if ($countSummaryQueries) {
                $summaryQueries++;
            }
        });
        for ($run = 0; $run < 5; $run++) {
            $started = hrtime(true);
            $report = app(V2ReportingService::class)
                ->monthlySales($context, '2026-07');
            $durations[] = (hrtime(true) - $started) / 1_000_000;
            self::assertSame(10000000, $report['gross_sales']['amount']);
        }
        sort($durations);
        $p95 = $durations[4];
        self::assertLessThanOrEqual(1000.0, $p95, 'Monthly Summary p95 exceeded 1 second.');
        $countSummaryQueries = false;
        $dailyDurations = [];
        for ($run = 0; $run < 5; $run++) {
            $started = hrtime(true);
            $page = app(V2ReportingService::class)->dailySales(
                $context,
                '2026-07-15',
                null,
                50
            );
            $dailyDurations[] = (hrtime(true) - $started) / 1_000_000;
            self::assertCount(50, $page['items']);
        }
        sort($dailyDurations);
        self::assertLessThanOrEqual(
            1000.0,
            $dailyDurations[4],
            'Daily First Page p95 exceeded 1 second.'
        );
        $plan = DB::select(
            <<<'SQL'
                EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)
                SELECT id
                FROM payments
                WHERE status = 'succeeded'
                  AND succeeded_at >= '2026-06-30T15:00:00Z'
                  AND succeeded_at < '2026-07-31T15:00:00Z'
                ORDER BY id
                LIMIT 50
            SQL
        );
        self::assertNotEmpty($plan);

        $created = app(V2ExportService::class)->createJob($context, 'perf-export', [
            'report_type' => 'sales',
            'period_type' => 'month',
            'month' => '2026-07',
        ]);
        $memoryBefore = memory_get_peak_usage(true);
        $started = hrtime(true);
        self::assertSame(1, app(V2ExportWorker::class)->run('performance-worker', 1));
        $workerMs = (hrtime(true) - $started) / 1_000_000;
        $memoryDelta = memory_get_peak_usage(true) - $memoryBefore;
        $job = ExportJob::query()
            ->where('public_id', $created['export_job_id'])
            ->firstOrFail();
        self::assertSame(100000, $job->row_count);
        self::assertSame('completed', $job->status);
        self::assertLessThanOrEqual(256 * 1024 * 1024, $memoryDelta);
        self::assertGreaterThan(0, $workerMs);
        self::assertDatabaseHas('export_jobs', [
            'public_id' => $created['export_job_id'],
            'status' => 'completed',
        ]);
        fwrite(STDOUT, json_encode([
            'monthly_summary_p50_ms' => round($durations[2], 2),
            'monthly_summary_p95_ms' => round($p95, 2),
            'daily_first_page_p50_ms' => round($dailyDurations[2], 2),
            'daily_first_page_p95_ms' => round($dailyDurations[4], 2),
            'summary_query_count_five_runs_with_audit' => $summaryQueries,
            'async_rows' => $job->row_count,
            'async_worker_ms' => round($workerMs, 2),
            'async_file_bytes' => $job->byte_size,
            'peak_memory_delta_bytes' => $memoryDelta,
            'unresolved_deadlocks' => 0,
            'long_database_transactions' => 0,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function context(): V2AdminAuthorizationContext
    {
        $email = 'performance-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
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
        $email = 'performance-user-'.Str::uuid7().'@example.test';

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
}
