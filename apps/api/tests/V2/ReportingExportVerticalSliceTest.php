<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Domain\Reporting\Services\V2CsvWriter;
use App\Domain\Reporting\Services\V2ExportRowSource;
use App\Domain\Reporting\Services\V2ExportService;
use App\Domain\Reporting\Services\V2ExportWorker;
use App\Domain\Reporting\Services\V2ReportingService;
use App\Domain\Reporting\ValueObjects\V2ExportDefinition;
use App\Models\V2\Admin;
use App\Models\V2\ExportJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportingExportVerticalSliceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-31T15:30:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_identity.rate_limits.financial_export' => [5, 3600],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.business_timezone' => 'Asia/Tokyo',
            'v2_reporting.export_disk' => 'local',
            'v2_reporting.streaming_max_rows' => 10000,
            'v2_reporting.async_row_threshold' => 10001,
        ]);
        Cache::store('array')->clear();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_sales_use_succeeded_event_dates_and_jst_boundary(): void
    {
        $context = $this->context(V2AdminRole::Owner);
        $user = $this->user();
        $july = $this->payment($user, '2026-07-31T14:59:59Z', 1000);
        $august = $this->payment($user, '2026-07-31T15:00:00Z', 2000);
        $this->adjustment($july, 'refund', '2026-07-31T15:10:00Z', 1000);
        $this->adjustment($august, 'chargeback', '2026-08-01T01:00:00Z', 2000);
        self::assertSame(
            1,
            DB::table('payments')
                ->where('status', 'succeeded')
                ->where('succeeded_at', '>=', '2026-06-30T15:00:00Z')
                ->where('succeeded_at', '<', '2026-07-31T15:00:00Z')
                ->count()
        );

        $julyReport = app(V2ReportingService::class)
            ->monthlySales($context, '2026-07');
        self::assertSame(1000, $julyReport['gross_sales']['amount']);
        self::assertSame(0, $julyReport['refunds']['amount']);
        self::assertSame(1000, $julyReport['net_sales_amount']);

        $augustReport = app(V2ReportingService::class)
            ->monthlySales($context, '2026-08');
        self::assertSame(2000, $augustReport['gross_sales']['amount']);
        self::assertSame(1000, $augustReport['refunds']['amount']);
        self::assertSame(2000, $augustReport['chargebacks']['amount']);
        self::assertSame(-1000, $augustReport['net_sales_amount']);
        self::assertSame(
            'operational_event_aggregation_not_accounting_recognition',
            $augustReport['basis']
        );
    }

    public function test_point_report_uses_immutable_ledger_not_wallet_balance(): void
    {
        $context = $this->context(V2AdminRole::Admin);
        $user = $this->user();
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $user,
            'paid_balance' => 999999,
            'free_balance' => 999999,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operationId = DB::table('point_operations')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user,
            'operation_type' => 'draw_spend',
            'business_key' => 'report-test-'.Str::uuid7(),
            'source_type' => 'draw',
            'source_id' => null,
            'actor_type' => 'user',
            'actor_id' => $user,
            'is_qa' => false,
            'qa_draw_execution_id' => null,
            'occurred_at' => '2026-07-31T15:20:00Z',
            'business_date' => '2026-08-01',
            'metadata' => '{}',
            'created_at' => now(),
        ]);
        DB::table('point_ledger_entries')->insert([
            'point_operation_id' => $operationId,
            'sequence_no' => 1,
            'user_id' => $user,
            'wallet_id' => $walletId,
            'point_lot_id' => null,
            'point_type' => 'free',
            'entry_type' => 'spend',
            'amount_delta' => -125,
            'wallet_balance_after' => 999999,
            'lot_remaining_after' => null,
            'occurred_at' => '2026-07-31T15:20:00Z',
            'business_date' => '2026-08-01',
            'created_at' => now(),
        ]);

        $report = app(V2ReportingService::class)->pointSummary($context, '2026-08');
        self::assertSame(125, $report['entries'][0]['amount']);
        self::assertSame('free', $report['entries'][0]['point_type']);
        self::assertSame('spend', $report['entries'][0]['entry_type']);
    }

    public function test_csv_has_stable_header_utf8_bom_and_formula_protection(): void
    {
        $drawDefinition = V2ExportDefinition::from([
            'report_type' => 'draw_results',
            'period_type' => 'month',
            'month' => '2026-08',
            'qa_filter' => 'all',
        ]);
        self::assertContains(
            'is_qa_draw',
            app(V2ExportRowSource::class)->headers($drawDefinition)
        );

        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        $count = app(V2CsvWriter::class)->write(
            $stream,
            ['name', 'note'],
            [
                ['name' => '=1+1', 'note' => "line1\nline2"],
                ['name' => '+SUM(A1)', 'note' => '日本語'],
            ]
        );
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        self::assertSame(2, $count);
        self::assertStringStartsWith("\xEF\xBB\xBFname,note", $csv);
        self::assertStringContainsString("'=1+1", $csv);
        self::assertStringContainsString("'+SUM(A1)", $csv);
        self::assertStringContainsString('"line1', $csv);
    }

    public function test_export_job_is_idempotent_and_worker_persists_private_checksum(): void
    {
        $context = $this->context(V2AdminRole::Owner);
        $request = [
            'report_type' => 'sales',
            'period_type' => 'month',
            'month' => '2026-08',
            'qa_filter' => 'all',
        ];
        $service = app(V2ExportService::class);
        $created = $service->createJob($context, 'export-key-1', $request);
        $replay = $service->createJob($context, 'export-key-1', $request);
        self::assertFalse($created['idempotent_replay']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($created['export_job_id'], $replay['export_job_id']);
        self::assertSame(1, ExportJob::query()->count());
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => $created['export_job_id'],
            'event_type' => 'reporting.export.requested',
        ]);

        self::assertSame(1, app(V2ExportWorker::class)->run('test-worker', 1));
        $job = ExportJob::query()->where('public_id', $created['export_job_id'])->firstOrFail();
        self::assertSame('completed', $job->status);
        self::assertSame(0, $job->row_count);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $job->sha256);
        self::assertStringStartsWith('v2/private/exports/', $job->private_object_key);
        Storage::disk('local')->assertExists($job->private_object_key);
    }

    public function test_export_key_conflict_and_worker_lease_recovery_are_fail_closed(): void
    {
        $context = $this->context(V2AdminRole::Owner);
        $service = app(V2ExportService::class);
        $base = [
            'report_type' => 'sales',
            'period_type' => 'month',
            'month' => '2026-08',
        ];
        $service->createJob($context, 'conflict-key', $base);
        try {
            $service->createJob(
                $context,
                'conflict-key',
                [...$base, 'month' => '2026-09']
            );
            self::fail('A reused Idempotency-Key must conflict.');
        } catch (\App\Domain\Point\Exceptions\V2PointException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->getMessage());
        }

        $job = ExportJob::query()->firstOrFail();
        $worker = app(V2ExportWorker::class);
        self::assertCount(1, $worker->claim('first-worker', 1));
        DB::table('export_jobs')->where('id', $job->id)->update([
            'lease_expires_at' => now()->subSecond(),
        ]);
        self::assertCount(1, $worker->claim('second-worker', 1));
        self::assertSame(
            'second-worker',
            ExportJob::query()->findOrFail($job->id)->locked_by
        );
    }

    public function test_reporting_permissions_and_fresh_mfa_fail_closed(): void
    {
        $operator = $this->context(V2AdminRole::Operator);
        try {
            app(V2ReportingService::class)->monthlySales($operator, '2026-08');
            self::fail('Operator must not access Financial Reporting.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }

        $stale = $this->context(V2AdminRole::Owner, now()->subMinutes(5));
        try {
            app(V2ExportService::class)->createJob($stale, 'stale-key', [
                'report_type' => 'sales',
                'period_type' => 'month',
                'month' => '2026-08',
            ]);
            self::fail('Financial Export requires Fresh MFA.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('FRESH_AUTHENTICATION_REQUIRED', $exception->errorCode);
        }
    }

    public function test_financial_export_rate_limit_is_fail_closed_and_audited(): void
    {
        $context = $this->context(V2AdminRole::Owner);
        $service = app(V2ExportService::class);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $service->createJob($context, 'rate-key-'.$attempt, [
                'report_type' => 'sales',
                'period_type' => 'month',
                'month' => '2026-08',
            ]);
        }

        try {
            $service->createJob($context, 'rate-key-6', [
                'report_type' => 'sales',
                'period_type' => 'month',
                'month' => '2026-08',
            ]);
            self::fail('Financial Export must stop after five requests per hour.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('RATE_LIMITED', $exception->errorCode);
            self::assertSame(429, $exception->status);
        }
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'report.export.rate_limited',
            'outcome' => 'failure',
            'reason_code' => 'rate_limited',
        ]);
    }

    public function test_snapshot_is_ledger_cutoff_resource_and_unknown_date_is_404(): void
    {
        $context = $this->context(V2AdminRole::Admin);
        DB::table('point_balance_snapshots')->insert([
            'snapshot_date' => '2026-03-31',
            'source_cutoff_at' => '2026-03-31T15:00:00Z',
            'calculation_method' => 'ledger_cutoff',
            'opening_paid_balance' => 0,
            'opening_free_balance' => 0,
            'granted_paid_amount' => 10,
            'granted_free_amount' => 20,
            'consumed_paid_amount' => 0,
            'consumed_free_amount' => 0,
            'expired_free_amount' => 0,
            'reversed_paid_amount' => 0,
            'reversed_free_amount' => 0,
            'closing_paid_balance' => 10,
            'closing_free_balance' => 20,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'user_count' => 1,
            'open_lot_count' => 2,
            'is_base_date' => true,
            'generated_at' => now(),
            'generation_run_id' => (string) Str::uuid7(),
            'checksum' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $snapshot = app(V2ReportingService::class)->snapshot($context, '2026-03-31');
        self::assertTrue($snapshot['is_base_date']);
        self::assertSame(10, $snapshot['closing_paid_balance']);

        $this->expectException(V2ReportingException::class);
        app(V2ReportingService::class)->snapshot($context, '2026-03-30');
    }

    private function context(
        V2AdminRole $role,
        ?\DateTimeInterface $verifiedAt = null
    ): V2AdminAuthorizationContext {
        $email = 'report-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => $verifiedAt ?? now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now()->subHour(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(7),
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
        $email = 'report-user-'.Str::uuid7().'@example.test';

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

    private function payment(int $userId, string $succeededAt, int $amount): int
    {
        return DB::table('payments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'point_purchase_plan_id' => null,
            'provider_code' => 'test',
            'provider_payment_id' => (string) Str::uuid7(),
            'status' => 'succeeded',
            'amount' => $amount,
            'currency' => 'JPY',
            'paid_point_amount' => $amount,
            'free_point_amount' => 0,
            'plan_name_snapshot' => 'Test Plan',
            'plan_code_snapshot' => 'test-plan',
            'idempotency_record_id' => null,
            'succeeded_at' => $succeededAt,
            'points_granted_at' => $succeededAt,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function adjustment(
        int $paymentId,
        string $type,
        string $succeededAt,
        int $amount
    ): int {
        return DB::table('payment_adjustments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => $paymentId,
            'parent_adjustment_id' => null,
            'type' => $type,
            'status' => 'succeeded',
            'amount' => $amount,
            'currency' => 'JPY',
            'requested_at' => $succeededAt,
            'succeeded_at' => $succeededAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
