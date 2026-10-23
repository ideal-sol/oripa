<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankAccountingService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZMemberRankActivationFeasibilityTest extends TestCase
{
    use MemberRankAccountingFixture;

    public function test_naive_atomic_accounting_and_settlement_expose_the_audit_user_deadlock(): void
    {
        if (getenv('V2_ACTIVATION_DEADLOCK_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable activation deadlock database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_activation_synthetic', DB::connection()->getDatabaseName());
        self::assertNull(DB::selectOne('SELECT inet_server_addr() AS address')->address);
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        self::assertSame(0, DB::table('payments')->count());
        self::assertTrue(function_exists('pcntl_fork'));
        $this->configureRankFixture();
        $this->rankClock('2026-10-22T00:00:00Z');
        $first = $this->rankUser();
        $second = $this->rankUser();
        $firstPayment = $this->settleRankPayment($first, 10000);
        $secondPayment = $this->settleRankPayment($second, 10000);
        [, $event] = $this->rankPayment($second, 10000);
        $directory = sys_get_temp_dir().'/activation-deadlock-'.Str::uuid7();
        mkdir($directory, 0700);
        DB::disconnect();
        $processId = pcntl_fork();
        self::assertNotSame(-1, $processId);
        if ($processId === 0) {
            DB::reconnect();
            DB::statement("SET statement_timeout = '15s'");
            DB::statement("SET lock_timeout = '10s'");
            file_put_contents($directory.'/pid', (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
            $deadline = microtime(true) + 15;
            while (! file_exists($directory.'/start') && microtime(true) < $deadline) {
                usleep(10000);
            }
            $deadlocks = 0;
            $started = hrtime(true);
            try {
                if (! file_exists($directory.'/start')) {
                    throw new \RuntimeException('Synthetic start barrier timed out.');
                }
                app(V2PointTransactionRunner::class)->run(function () use ($event, &$deadlocks): void {
                    try {
                        app(V2PaymentService::class)->confirmSucceeded($event->id);
                    } catch (\Throwable $exception) {
                        if ($this->hasDeadlock($exception)) {
                            $deadlocks++;
                        }
                        throw $exception;
                    }
                });
                $result = ['status' => 'success', 'deadlocks' => $deadlocks];
            } catch (\Throwable $exception) {
                $result = ['status' => 'error', 'sqlstate' => (string) $exception->getCode(), 'deadlocks' => $deadlocks];
            }
            $result['elapsed_ms'] = (hrtime(true) - $started) / 1e6;
            file_put_contents($directory.'/result', json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            exit(0);
        }
        $parentDeadlocks = 0;
        try {
            DB::reconnect();
            DB::statement("SET lock_timeout = '10s'");
            DB::beginTransaction();
            $this->enableRankFixture('2026-10-22T00:00:00Z');
            app(V2MemberRankAccountingService::class)->accountGrantedPayment($firstPayment->id);
            file_put_contents($directory.'/start', 'start');
            $blocked = false;
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                if (file_exists($directory.'/pid')) {
                    $row = DB::selectOne('SELECT wait_event_type, wait_event FROM pg_stat_activity WHERE pid = ?', [(int) file_get_contents($directory.'/pid')]);
                    if ($row !== null && $row->wait_event_type === 'Lock' && $row->wait_event === 'advisory') {
                        $blocked = true;
                        break;
                    }
                }
                usleep(10000);
                DB::select('SELECT pg_stat_clear_snapshot()');
            }
            self::assertTrue($blocked, 'The real settlement must own User B and wait for the audit chain.');
            try {
                app(V2MemberRankAccountingService::class)->accountGrantedPayment($secondPayment->id);
            } catch (\Throwable $exception) {
                self::assertTrue($this->hasDeadlock($exception));
                $parentDeadlocks++;
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            pcntl_waitpid($processId, $status);
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
        $child = json_decode(file_get_contents($directory.'/result'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('success', $child['status']);
        self::assertGreaterThanOrEqual(1, $parentDeadlocks + $child['deadlocks']);
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'member_rank_promotion')->count());
        self::assertSame(3, DB::table('payment_point_grants')->count());
        fwrite(STDOUT, "\nMEMBERRANK_ACTIVATION_DEADLOCK ".json_encode([
            'parent_deadlocks' => $parentDeadlocks, 'settlement' => $child, 'activation' => 'ROLLED_BACK_OFF',
            'protocol' => 'negative_control_not_option_a',
        ], JSON_THROW_ON_ERROR)."\n");
        unlink($directory.'/pid');
        unlink($directory.'/start');
        unlink($directory.'/result');
        rmdir($directory);
    }

    public function test_early_on_negative_control_is_not_a_late_on_activation_protocol(): void
    {
        if (getenv('V2_ACTIVATION_FEASIBILITY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable activation feasibility database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_activation_synthetic', DB::connection()->getDatabaseName());
        self::assertNull(DB::selectOne('SELECT inet_server_addr() AS address')->address);
        self::assertFalse((bool) DB::table('member_rank_settings')->value('enabled'));
        self::assertSame(0, DB::table('payments')->count());
        $requested = getenv('V2_ACTIVATION_PROBE_COUNT');
        self::assertContains($requested, ['0', '10', '100', '1000', '10000', '100000']);
        $count = (int) $requested;
        $distribution = getenv('V2_ACTIVATION_PROBE_USERS') ?: '10';
        self::assertContains($distribution, ['1', '10']);
        $this->configureRankFixture();
        $this->rankClock('2026-10-22T00:00:00Z');
        config(['database.connections.activation_observer' => config('database.connections.pgsql')]);
        $observer = DB::connection('activation_observer');
        self::assertNotSame(DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, $observer->selectOne('SELECT pg_backend_pid() AS pid')->pid);
        DB::statement("SET statement_timeout = '30s'");
        DB::statement("SET lock_timeout = '2s'");
        $metrics = ['protocol' => 'early_on_negative_control_not_option_a', 'requested_payments' => $count,
            'requested_users' => (int) $distribution, 'fixture_kind' => 'service_seed_and_constraint_checked_bulk_clones',
            'completed_fixture_payments' => 0, 'capacity_verdict' => 'NOT_PROVEN'];
        try {
            $started = hrtime(true);
            $users = [];
            for ($index = 0; $index < max(1, min((int) $distribution, $count)); $index++) {
                $users[] = $this->rankUser();
            }
            $payments = [];
            $planId = null;
            for ($index = 0; $index < min($count, count($users)); $index++) {
                [$payment, $event] = $this->rankPayment($users[$index % count($users)], 10000, planId: $planId);
                $planId = $payment->point_purchase_plan_id;
                app(V2PaymentService::class)->confirmSucceeded($event->id);
                $payments[] = $payment->id;
                $metrics['completed_fixture_payments']++;
            }
            if ($count > count($payments)) {
                $payments = $this->expandSyntheticFixture($payments, $count);
            }
            $metrics['completed_fixture_payments'] = count($payments);
            self::assertSame($count, DB::table('payment_point_grants')->count());
            self::assertSame($count * 10000, (int) DB::table('wallets')->sum('paid_balance'));
            self::assertSame($count * 10000, (int) DB::table('point_lots')->where('point_type', 'paid')->sum('remaining_amount'));
            self::assertSame($count * 10000, (int) DB::table('point_ledger_entries')->where('point_type', 'paid')->sum('amount_delta'));
            $metrics['fixture_seconds'] = (hrtime(true) - $started) / 1e9;
            $metrics['users'] = count($users);
            $writerEvent = null;
            if ($count > 0) {
                [, $writerEvent] = $this->rankPayment($users[0], 10000, planId: $planId);
            }
            $baseline = $this->businessSnapshot($observer);
            $accounting = app(V2MemberRankAccountingService::class);
            DB::transaction(function () use ($payments, $accounting): void {
                foreach (array_slice($payments, 0, 10) as $paymentId) {
                    $accounting->accountGrantedPayment($paymentId);
                }
            });
            self::assertSame($baseline, $this->businessSnapshot($observer));
            $metrics['ordinary_off_accounting'] = 'NO_EFFECT';
            $metrics['ordinary_off_calls'] = min($count, 10);

            $started = hrtime(true);
            DB::beginTransaction();
            $this->enableRankFixture('2026-10-22T00:00:00Z');
            foreach (array_slice($payments, 0, 10) as $paymentId) {
                $accounting->accountGrantedPayment($paymentId);
            }
            self::assertSame($baseline, $this->businessSnapshot($observer));
            $rollbackStarted = hrtime(true);
            DB::rollBack();
            $metrics['explicit_rollback_ms'] = (hrtime(true) - $rollbackStarted) / 1e6;
            $metrics['rollback_probe_tx_ms'] = (hrtime(true) - $started) / 1e6;
            self::assertSame($baseline, $this->businessSnapshot($observer));
            $metrics['independent_connection_uncommitted_visibility'] = 'OFF_BASELINE';
            $metrics['partial_reward_rollback'] = 'BASELINE_RESTORED';

            if ($count > 0) {
                try {
                    DB::transaction(function () use ($accounting, $payments): void {
                        $this->enableRankFixture('2026-10-22T00:00:00Z');
                        $accounting->accountGrantedPayment($payments[0]);
                        DB::table('member_rank_month_totals')->increment('points');
                    });
                    self::fail('Deferred total inconsistency must fail the actual commit.');
                } catch (\PDOException $exception) {
                    self::assertSame('P0001', $exception->errorInfo[0]);
                }
                self::assertSame($baseline, $this->businessSnapshot($observer));
                $metrics['deferred_commit_failure'] = 'BASELINE_RESTORED';
                try {
                    DB::transaction(function () use ($accounting, $payments): void {
                        $this->enableRankFixture('2026-10-22T00:00:00Z');
                        $accounting->accountGrantedPayment($payments[0]);
                        DB::statement("SET LOCAL statement_timeout = '10ms'");
                        DB::select('SELECT pg_sleep(0.1)');
                    });
                    self::fail('Injected statement timeout must abort the transaction.');
                } catch (\Illuminate\Database\QueryException $exception) {
                    self::assertSame('57014', $exception->errorInfo[0]);
                }
                self::assertSame($baseline, $this->businessSnapshot($observer));
                $metrics['statement_timeout_after_reward'] = 'BASELINE_RESTORED';
                DB::beginTransaction();
                $this->enableRankFixture('2026-10-22T00:00:00Z');
                $accounting->accountGrantedPayment($payments[0]);
                DB::purge();
                self::assertSame($baseline, $this->businessSnapshot($observer));
                self::assertSame(0, DB::transactionLevel());
                $metrics['client_disconnect_after_reward'] = 'BASELINE_RESTORED';
                DB::statement("SET statement_timeout = '30s'");
                DB::statement("SET lock_timeout = '2s'");
            }

            $beforeWal = DB::selectOne('SELECT pg_current_wal_insert_lsn()::text AS position')->position;
            $beforeSize = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes;
            $started = hrtime(true);
            DB::beginTransaction();
            $this->enableRankFixture('2026-10-22T00:00:00Z');
            $processed = 0;
            foreach ($payments as $paymentId) {
                if ((hrtime(true) - $started) / 1e9 > 300) {
                    $rollbackStarted = hrtime(true);
                    DB::rollBack();
                    $metrics['budget_rollback_ms'] = (hrtime(true) - $rollbackStarted) / 1e6;
                    $metrics['stop_reason'] = 'negative_control_tx_budget_300_seconds';
                    $metrics['processed_before_rollback'] = $processed;
                    $metrics['total_negative_control_tx_ms'] = (hrtime(true) - $started) / 1e6;
                    $metrics['wal_insert_bytes'] = (int) DB::selectOne('SELECT pg_wal_lsn_diff(pg_current_wal_insert_lsn(), ?::pg_lsn) AS bytes', [$beforeWal])->bytes;
                    $metrics['database_growth_bytes'] = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes - $beforeSize;
                    self::assertSame($baseline, $this->businessSnapshot($observer));
                    self::markTestIncomplete('Atomic transaction budget exceeded; capacity remains unproved.');
                }
                $accounting->accountGrantedPayment($paymentId);
                $processed++;
                if ($processed === 1) {
                    $metrics['ordinary_writers'] = $this->measureBlockedWriters($observer, $users[0]->id, $writerEvent->id, $payments[0]);
                }
            }
            self::assertSame($baseline, $this->businessSnapshot($observer));
            $metrics['locks_before_commit'] = (int) DB::selectOne('SELECT count(*) AS count FROM pg_locks WHERE pid = pg_backend_pid()')->count;
            if ($count > 0) {
                self::assertFalse((bool) $observer->selectOne("SELECT pg_try_advisory_xact_lock(hashtextextended('v2_audit_chain', 0)) AS acquired")->acquired);
                $metrics['global_audit_chain_unavailable_to_other_writer'] = true;
                $observer->statement("SET lock_timeout = '100ms'");
                $waitStarted = hrtime(true);
                try {
                    $observer->table('users')->where('id', $users[0]->id)->update(['updated_at' => now()]);
                    self::fail('An independent writer must not pass an owned User row lock.');
                } catch (\Illuminate\Database\QueryException $exception) {
                    self::assertSame('55P03', $exception->errorInfo[0]);
                    $metrics['independent_writer_wait_ms'] = (hrtime(true) - $waitStarted) / 1e6;
                    $metrics['independent_writer_sqlstate'] = '55P03';
                }
            }
            $this->rankClock('2026-11-01T00:00:00Z');
            $commitStarted = hrtime(true);
            DB::commit();
            $metrics['commit_ms'] = (hrtime(true) - $commitStarted) / 1e6;
            $metrics['total_negative_control_tx_ms'] = (hrtime(true) - $started) / 1e6;
            self::assertTrue((bool) $observer->table('member_rank_settings')->value('enabled'));
            self::assertSame($count, $observer->table('member_rank_point_entries')->count());
            self::assertSame($count * 10000, (int) $observer->table('member_rank_month_totals')->sum('points'));
            self::assertSame(0, $observer->table('member_rank_purchase_bonuses')->count());
            self::assertSame($count > 0 ? count($users) : 0, $observer->table('member_rank_changes')->where('from_level', 1)->count());
            $activatedAt = CarbonImmutable::parse($observer->table('member_rank_settings')->value('activated_at'));
            self::assertSame('2026-10', $activatedAt->setTimezone('Asia/Tokyo')->format('Y-m'));
            self::assertSame('2026-11', CarbonImmutable::now()->setTimezone('Asia/Tokyo')->format('Y-m'));
            $metrics['injected_publication_month_mismatch'] = 'REPRODUCED';
            try {
                DB::table('member_rank_settings')->where('id', 1)->update(['activated_at' => CarbonImmutable::now()]);
                self::fail('The immutable activation timestamp must not be repairable after early ON.');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertSame('P0001', $exception->errorInfo[0]);
                $metrics['timestamp_repair'] = 'REJECTED_BY_EXISTING_TRIGGER';
            }
            $metrics['wal_insert_bytes'] = (int) DB::selectOne('SELECT pg_wal_lsn_diff(pg_current_wal_insert_lsn(), ?::pg_lsn) AS bytes', [$beforeWal])->bytes;
            $metrics['database_growth_bytes'] = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes - $beforeSize;
            $metrics['stop_reason'] = 'negative_control_completed_not_activation_accepted';
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $metrics['php_peak_memory_bytes'] = memory_get_peak_usage(true);
            fwrite(STDOUT, "\nMEMBERRANK_ACTIVATION_FEASIBILITY ".json_encode($metrics, JSON_THROW_ON_ERROR)."\n");
            DB::disconnect('activation_observer');
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    private function hasDeadlock(\Throwable $exception): bool
    {
        do {
            if (($exception instanceof \PDOException && ($exception->errorInfo[0] ?? null) === '40P01')
                || (string) $exception->getCode() === '40P01') {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception !== null);
        return false;
    }

    private function expandSyntheticFixture(array $paymentIds, int $count): array
    {
        $templates = [];
        foreach ($paymentIds as $paymentId) {
            $payment = (array) DB::table('payments')->where('id', $paymentId)->sole();
            $grant = (array) DB::table('payment_point_grants')->where('payment_id', $paymentId)->sole();
            $operation = (array) DB::table('point_operations')->where('id', $grant['point_operation_id'])->sole();
            $lot = (array) DB::table('point_lots')->where('grant_operation_id', $operation['id'])->sole();
            $ledger = (array) DB::table('point_ledger_entries')->where('point_operation_id', $operation['id'])->sole();
            unset($grant['id'], $ledger['id']);
            $templates[] = compact('payment', 'grant', 'operation', 'lot', 'ledger');
        }
        $balances = DB::table('wallets')->pluck('paid_balance', 'user_id')->all();
        while (count($paymentIds) < $count) {
            $batchSize = min(200, $count - count($paymentIds));
            DB::transaction(function () use (&$paymentIds, &$balances, $templates, $batchSize): void {
                $identifiers = [];
                foreach (['payments', 'point_operations', 'point_lots'] as $table) {
                    $identifiers[$table] = array_column(DB::select("SELECT nextval(pg_get_serial_sequence(?, 'id')) AS id FROM generate_series(1, ?)", [$table, $batchSize]), 'id');
                }
                $rows = ['payments' => [], 'point_operations' => [], 'point_lots' => [], 'point_ledger_entries' => [], 'payment_point_grants' => []];
                $additions = [];
                for ($index = 0; $index < $batchSize; $index++) {
                    $template = $templates[count($paymentIds) % count($templates)];
                    $paymentId = $identifiers['payments'][$index];
                    $operationId = $identifiers['point_operations'][$index];
                    $lotId = $identifiers['point_lots'][$index];
                    $userId = $template['payment']['user_id'];
                    $balances[$userId] += 10000;
                    $additions[$userId] = ($additions[$userId] ?? 0) + 1;
                    $rows['payments'][] = [...$template['payment'], 'id' => $paymentId, 'public_id' => (string) Str::uuid7(),
                        'provider_payment_id' => 'synthetic-activation-'.$paymentId, 'idempotency_record_id' => null];
                    $rows['point_operations'][] = [...$template['operation'], 'id' => $operationId, 'public_id' => (string) Str::uuid7(),
                        'source_id' => $paymentId, 'business_key' => 'payment.grant:'.$paymentId];
                    $rows['point_lots'][] = [...$template['lot'], 'id' => $lotId, 'grant_operation_id' => $operationId];
                    $rows['point_ledger_entries'][] = [...$template['ledger'], 'point_operation_id' => $operationId,
                        'point_lot_id' => $lotId, 'wallet_balance_after' => $balances[$userId]];
                    $rows['payment_point_grants'][] = [...$template['grant'], 'payment_id' => $paymentId, 'point_operation_id' => $operationId];
                    $paymentIds[] = $paymentId;
                }
                foreach ($rows as $table => $values) {
                    DB::table($table)->insert($values);
                }
                foreach ($additions as $userId => $added) {
                    DB::table('wallets')->where('user_id', $userId)->update([
                        'paid_balance' => $balances[$userId], 'lock_version' => DB::raw('lock_version + '.$added),
                    ]);
                }
            });
        }
        return $paymentIds;
    }

    private function measureBlockedWriters(\Illuminate\Database\Connection $observer, int $userId, int $eventId, int $paymentId): array
    {
        $observer->statement("SET lock_timeout = '100ms'");
        $observer->statement("SET statement_timeout = '2s'");
        $default = DB::getDefaultConnection();
        $results = [];
        DB::setDefaultConnection('activation_observer');
        try {
            $writers = [
                'payment_confirmation' => fn () => app(V2PaymentService::class)->confirmSucceeded($eventId),
                'free_point_grant' => fn () => app(V2PointService::class)->grantFree($userId, 1, (string) Str::uuid7()),
                'refund_reservation' => fn () => app(V2PaymentService::class)->reserveFullRefund($paymentId, (string) Str::uuid7()),
            ];
            foreach ($writers as $name => $writer) {
                $started = hrtime(true);
                try {
                    $writer();
                    self::fail('A real independent writer must wait for the negative-control transaction.');
                } catch (\Illuminate\Database\QueryException $exception) {
                    self::assertSame('55P03', $exception->errorInfo[0]);
                    self::assertSame(0, $observer->transactionLevel());
                    $results[$name] = ['elapsed_ms' => (hrtime(true) - $started) / 1e6, 'sqlstate' => '55P03',
                        'wait_is_censored_at_timeout' => true];
                }
            }
        } finally {
            DB::setDefaultConnection($default);
        }
        return $results;
    }

    private function businessSnapshot(\Illuminate\Database\Connection $connection): array
    {
        $counts = [];
        foreach (['member_rank_states', 'member_rank_point_entries', 'member_rank_month_totals',
            'member_rank_changes', 'member_rank_achievements', 'member_rank_entitlements',
            'point_operations', 'point_lots', 'point_ledger_entries', 'member_rank_purchase_bonuses', 'audit_logs'] as $table) {
            $counts[$table] = $connection->table($table)->count();
        }
        return ['counts' => $counts,
            'settings' => (array) $connection->table('member_rank_settings')->first(),
            'wallets' => $connection->table('wallets')->orderBy('user_id')->get()->map(fn (object $wallet): array => (array) $wallet)->all(),
        ];
    }
}
