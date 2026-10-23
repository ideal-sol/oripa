<?php

namespace Tests\V2;

use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PreRegistrationService;
use App\Domain\MemberRank\Services\V2MemberRankAccountingService;
use App\Domain\MemberRank\Services\V2MemberRankPaymentAdjustmentService;
use App\Domain\MemberRank\Services\V2MemberRankPrelaunchInspection;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use App\Support\V2DatabaseTimestamp;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankGachaFixture;
use Tests\TestCase;

final class ZMemberRankPrelaunchInspectionTest extends TestCase
{
    use MemberRankGachaFixture;

    public function test_read_only_inspection_protects_applicants_but_cannot_authorize_cutover(): void
    {
        if (getenv('V2_PRELAUNCH_INSPECTION_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable activation database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_activation_synthetic', DB::connection()->getDatabaseName());
        self::assertNull(DB::selectOne('SELECT inet_server_addr() AS address')->address);
        self::assertSame(0, DB::table('users')->count());
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        $rewardAmount = getenv('V2_PRELAUNCH_BONUS_AMOUNT') ?: '500';
        self::assertContains($rewardAmount, ['500', '750']);
        $this->configureRankFixture();
        $this->rankClock('2026-10-22T00:15:00Z');
        $inspection = app(V2MemberRankPrelaunchInspection::class);
        $empty = $inspection->inspect();
        self::assertTrue($empty['read_only']);
        self::assertSame('repeatable read', $empty['isolation']);
        self::assertFalse($empty['activation_authorized']);
        self::assertSame([], $empty['data_holds']);
        self::assertSame('OBSERVATION_ONLY', $empty['status']);
        self::assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
        $connectionName = DB::getDefaultConnection();
        config(['database.connections.prelaunch_writer' => config('database.connections.'.$connectionName)]);
        $writer = DB::connection('prelaunch_writer');
        self::assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
            $writer->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        try {
            $first = $this->applicant('2026-10-21T01:00:00Z');
            $second = $this->applicant('2026-10-21T02:00:00Z');
            $oldTestUser = $this->rankUser();
            $oldPayment = $this->settleRankPayment($oldTestUser, 10000);
            $beforeRewards = $this->preservedRows();
            $report = $inspection->inspect();
            self::assertSame($beforeRewards, $this->preservedRows());
            self::assertSame(3, $report['counts']['users']);
            self::assertSame(2, $report['counts']['protected_applicants']);
            self::assertSame(1, $report['counts']['paid_grants']);
            self::assertSame(0, $report['counts']['protected_paid_grants']);
            self::assertSame(0, $report['counts']['invalid_paid_origins']);
            self::assertFalse($report['activation_authorized']);

            $admin = $this->rankAdmin();
            DB::table('pre_registration_settings')->where('id', 1)->update([
                'is_enabled' => false, 'cutoff_at' => '2026-10-22T00:00:00Z', 'point_amount' => (int) $rewardAmount,
            ]);
            $revision = (int) DB::table('pre_registration_settings')->value('revision');
            $reward = app(V2PreRegistrationService::class)->activate($admin, (string) Str::uuid7(), ['expected_revision' => $revision]);
            self::assertSame(2, $reward['data']['summary']['activated']);
            self::assertSame(2 * (int) $rewardAmount, (int) DB::table('pre_registration_grants')->sum('point_amount'));
            foreach ([$first, $second] as $user) {
                self::assertNotEquals($user->created_at, $user->fresh()->created_at);
                self::assertEquals($user->pre_registered_at, $user->fresh()->pre_registered_at);
                self::assertEquals($user->first_sms_verified_at, $user->fresh()->first_sms_verified_at);
                self::assertEquals($user->email_verified_at, $user->fresh()->email_verified_at);
                self::assertEquals($user->first_registration_qualified_at, $user->fresh()->first_registration_qualified_at);
                self::assertSame((int) $rewardAmount, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
            }
            $before = $this->preservedRows();
            $report = $inspection->inspect();
            self::assertSame($before, $this->preservedRows());
            self::assertSame(2, $report['counts']['protected_applicants']);
            self::assertSame(2, $report['counts']['protected_preregistration_grants']);
            self::assertSame(0, $report['counts']['protected_other_point_operations']);
            self::assertSame(0, array_sum($report['rank_rows']));
            self::assertFalse($report['activation_authorized']);

            $committed = false;
            DB::listen(function (QueryExecuted $query) use (&$committed, $first, $connectionName): void {
                if ($committed || $query->connectionName !== $connectionName
                    || ! str_contains($query->sql, 'from "member_rank_settings"')) {
                    return;
                }
                $committed = true;
                DB::setDefaultConnection('prelaunch_writer');
                try {
                    $this->settleRankPayment($first->fresh(), 10000);
                } finally {
                    DB::setDefaultConnection($connectionName);
                }
            });
            $stale = $inspection->inspect();
            self::assertTrue($committed);
            self::assertSame(0, $stale['counts']['protected_paid_grants']);
            self::assertFalse($stale['activation_authorized']);
            $fresh = $inspection->inspect();
            self::assertSame(1, $fresh['counts']['protected_paid_grants']);
            self::assertContains('PROTECTED_PAID_GRANTS', $fresh['data_holds']);
            self::assertContains('PROTECTED_SUCCEEDED_PAYMENTS', $fresh['data_holds']);
            self::assertContains('PROTECTED_OTHER_POINT_OPERATIONS', $fresh['data_holds']);
            self::assertSame(0, $fresh['counts']['invalid_paid_origins']);

            $this->rankPayment($second->fresh(), 10000);
            $pending = $inspection->inspect();
            self::assertSame(1, $pending['counts']['pending_payments']);
            self::assertContains('PENDING_PAYMENTS', $pending['data_holds']);
            self::assertContains('PENDING_PROVIDER_EVENTS', $pending['data_holds']);

            $this->importRankGachas();
            $gacha = $this->rankGacha('login_daily');
            $draw = $this->rankDraw($second->fresh(), $gacha);
            self::assertSame(0, $draw['point_cost_total']);
            $drawReport = $inspection->inspect();
            self::assertSame(1, $drawReport['counts']['protected_draw_requests']);
            self::assertContains('PROTECTED_DRAW_REQUESTS', $drawReport['data_holds']);
            $before = $this->preservedRows();
            $inspection->inspect();
            self::assertSame($before, $this->preservedRows());

            DB::beginTransaction();
            try {
                $inspection->inspect();
                self::fail('Inspection must not join an existing writable transaction.');
            } catch (\LogicException $exception) {
                self::assertSame('Prelaunch inspection requires its own PostgreSQL read-only snapshot.', $exception->getMessage());
            } finally {
                DB::rollBack();
            }
            $this->assertNaiveCutoverCounterexamples($oldPayment);
            self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
            self::assertSame(0, DB::table('member_rank_point_entries')->count());
            $this->assertQuiescentInspection($oldTestUser);
            $this->assertPriorSnapshotCounterexample($oldTestUser);
            fwrite(STDOUT, "\nPRELAUNCH_INSPECTION: protected rewards, bounded lock probes and prior-snapshot counterexample verified; activation remains HOLD.\n");
        } finally {
            DB::setDefaultConnection($connectionName);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('prelaunch_writer');
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    private function assertQuiescentInspection(User $user): void
    {
        $inspection = app(V2MemberRankPrelaunchInspection::class);
        $writer = DB::connection('prelaunch_writer');
        $connectionName = DB::getDefaultConnection();
        $before = $this->preservedRows();
        $tables = ['member_rank_settings', ...V2MemberRankPrelaunchInspection::FINANCIAL_TABLES];
        $started = hrtime(true);
        foreach ($tables as $table) {
            $writer->beginTransaction();
            try {
                $writer->statement('LOCK TABLE '.$table.' IN ROW EXCLUSIVE MODE');
                try {
                    $inspection->inspectQuiescent();
                    self::fail('An admitted writer must prevent the quiescent inspection: '.$table);
                } catch (\RuntimeException $exception) {
                    self::assertSame('PRELAUNCH_INSPECTION_BUSY', $exception->getMessage());
                    self::assertInstanceOf(QueryException::class, $exception->getPrevious());
                    self::assertSame('55P03', $exception->getPrevious()->errorInfo[0]);
                }
                self::assertSame(0, DB::transactionLevel());
            } finally {
                $writer->rollBack();
            }
        }
        $writer->beginTransaction();
        try {
            $writer->table('member_rank_settings')->first();
            try {
                $inspection->inspectQuiescent();
                self::fail('An existing OFF reader must prevent the settings fence.');
            } catch (\RuntimeException $exception) {
                self::assertSame('PRELAUNCH_INSPECTION_BUSY', $exception->getMessage());
            }
        } finally {
            $writer->rollBack();
        }

        $armed = true;
        $measurements = [];
        DB::listen(function (QueryExecuted $query) use (&$armed, &$measurements, $writer, $user, $connectionName): void {
            if (! $armed || $query->connectionName !== $connectionName
                || ! str_contains($query->sql, 'from "member_rank_settings"')) {
                return;
            }
            $armed = false;
            $writer->beginTransaction();
            try {
                $writer->table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
                self::assertSame(1, $writer->table('users')->where('id', $user->id)->update(['updated_at' => now()]));
            } finally {
                $writer->rollBack();
            }
            $writer->beginTransaction();
            $writer->statement("SET LOCAL lock_timeout = '100ms'");
            DB::setDefaultConnection('prelaunch_writer');
            $started = hrtime(true);
            try {
                $this->settleRankPayment($user, 10000);
                self::fail('A new payment writer must not pass the financial fence.');
            } catch (QueryException $exception) {
                self::assertSame('55P03', $exception->errorInfo[0]);
                $measurements['writer_call_elapsed_ms'] = (hrtime(true) - $started) / 1_000_000;
            } finally {
                $rollback = hrtime(true);
                while ($writer->transactionLevel() > 0) {
                    $writer->rollBack();
                }
                $measurements['writer_rollback_ms'] = (hrtime(true) - $rollback) / 1_000_000;
                DB::setDefaultConnection($connectionName);
            }
        });
        $report = $inspection->inspectQuiescent();
        self::assertFalse($armed);
        self::assertSame('QUIESCENT_OBSERVATION_ONLY', $report['status']);
        self::assertSame('read committed', $report['isolation']);
        self::assertFalse($report['read_only']);
        self::assertFalse($report['activation_authorized']);
        self::assertTrue($report['fence_released_before_return']);
        self::assertSame($tables, $report['fenced_tables']);
        self::assertSame($before, $this->preservedRows());

        $injectFailure = true;
        DB::listen(function (QueryExecuted $query) use (&$injectFailure, $connectionName): void {
            if ($injectFailure && $query->connectionName === $connectionName
                && str_contains($query->sql, 'from "member_rank_settings"')) {
                $injectFailure = false;
                throw new \RuntimeException('synthetic inspection failure');
            }
        });
        try {
            $inspection->inspectQuiescent();
            self::fail('Injected failure must abort the inspection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('synthetic inspection failure', $exception->getMessage());
        }
        self::assertSame(0, DB::transactionLevel());
        $writer->beginTransaction();
        try {
            $writer->statement('LOCK TABLE '.implode(', ', $tables).' IN ACCESS EXCLUSIVE MODE NOWAIT');
        } finally {
            $writer->rollBack();
        }
        self::assertSame($before, $this->preservedRows());
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        fwrite(STDOUT, "\nPRELAUNCH_FENCE_PROBE ".json_encode([
            'tables' => count($tables), 'probe_total_ms' => (hrtime(true) - $started) / 1_000_000,
            ...$measurements, 'diagnostic_lock_timeout_ms' => 100, 'acceptance_threshold' => null,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    private function assertPriorSnapshotCounterexample(User $user): void
    {
        $connectionName = DB::getDefaultConnection();
        $writer = DB::connection('prelaunch_writer');
        $writer->beginTransaction();
        $writer->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $writer->selectOne('SELECT pg_current_snapshot() AS snapshot');
        $publishFixture = true;
        DB::listen(function (QueryExecuted $query) use (&$publishFixture, $connectionName): void {
            if ($publishFixture && $query->connectionName === $connectionName
                && str_contains($query->sql, 'from "member_rank_settings"')) {
                $publishFixture = false;
                $this->enableRankFixture('2026-10-22T00:15:00Z');
            }
        });
        try {
            $inspection = app(V2MemberRankPrelaunchInspection::class)->inspectQuiescent();
            self::assertFalse($inspection['activation_authorized']);
            self::assertTrue((bool) DB::table('member_rank_settings')->value('enabled'));
            self::assertFalse((bool) $writer->table('member_rank_settings')->value('enabled'));
            DB::setDefaultConnection('prelaunch_writer');
            $payment = $this->settleRankPayment($user, 10000);
            $writer->commit();
            DB::setDefaultConnection($connectionName);
            self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
            self::assertSame(0, DB::table('member_rank_point_entries')->where('payment_id', $payment->id)->count());
            try {
                app(V2MemberRankPaymentAdjustmentService::class)->context($payment);
                self::fail('The post-publication origin missing under a stale OFF snapshot must fail closed.');
            } catch (V2PaymentException $exception) {
                self::assertSame('PAYMENT_RANK_ADJUSTMENT_NOT_READY', $exception->getMessage());
            }
        } finally {
            DB::setDefaultConnection($connectionName);
            while ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
        }
    }

    private function assertNaiveCutoverCounterexamples(object $payment): void
    {
        self::assertSame(0, (int) DB::table('information_schema.columns')->where('table_schema', 'public')
            ->where('table_name', 'payment_point_grants')->where('column_name', 'granted_at')->sole()->datetime_precision);
        self::assertSame(6, (int) DB::table('information_schema.columns')->where('table_schema', 'public')
            ->where('table_name', 'member_rank_settings')->where('column_name', 'activated_at')->sole()->datetime_precision);
        self::assertSame(V2DatabaseTimestamp::format(CarbonImmutable::parse('2026-10-22T00:15:00.100000Z')),
            V2DatabaseTimestamp::format(CarbonImmutable::parse('2026-10-22T00:15:00.900000Z')));
        DB::beginTransaction();
        try {
            $this->enableRankFixture('2026-10-22T00:15:01Z');
            try {
                app(V2MemberRankPaymentAdjustmentService::class)->context($payment);
                self::fail('Unmodified M8 must reject the unaccounted same-month pre-cutover origin.');
            } catch (V2PaymentException $exception) {
                self::assertSame('PAYMENT_RANK_ADJUSTMENT_NOT_READY', $exception->getMessage());
            }
            app(V2MemberRankAccountingService::class)->accountGrantedPayment($payment->id);
            self::assertSame(1, DB::table('member_rank_point_entries')->where('payment_id', $payment->id)->count());
            self::assertSame(10000, (int) DB::table('member_rank_month_totals')->where('user_id', $payment->user_id)->sum('points'));
        } finally {
            DB::rollBack();
        }
    }

    private function applicant(string $registeredAt): User
    {
        $origin = CarbonImmutable::parse($registeredAt);
        $email = 'prelaunch-'.Str::uuid7().'@example.test';
        $user = new User([
            'email_display' => $email, 'email_normalized' => $email,
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid synthetic password'), 'state' => 'active',
            'pre_registered_at' => $origin, 'email_verified_at' => $origin->addSecond(),
            'first_sms_verified_at' => $origin->addSeconds(2), 'first_registration_qualified_at' => $origin->addSecond(),
        ]);
        $user->forceFill(['created_at' => $origin])->save();
        $phone = '+8190'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT);
        UserPhoneNumber::query()->create([
            'user_id' => $user->id, 'phone_ciphertext' => Crypt::encryptString($phone),
            'phone_hmac' => app(V2IdentityCorrelation::class)->hash($phone), 'verified_at' => $origin->addSeconds(2),
        ]);

        return $user;
    }

    private function preservedRows(): array
    {
        $result = [];
        foreach (['users', 'user_phone_numbers', 'pre_registration_settings', 'pre_registration_targets',
            'pre_registration_grants', 'wallets', 'point_lots', 'point_operations', 'point_ledger_entries',
            'payments', 'payment_point_grants', 'draw_requests', 'draw_results', 'audit_logs'] as $table) {
            $rows = DB::table($table)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = hash('sha256', implode("\n", $rows));
        }

        return $result;
    }
}
