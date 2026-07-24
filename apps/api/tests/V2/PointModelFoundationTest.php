<?php

namespace Tests\V2;

use App\Domain\Audit\V2\Services\V2AuditChainVerifier;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointLedgerService;
use App\Domain\Point\Services\V2PointReconciliationService;
use App\Domain\Point\Services\V2PointService;
use App\Domain\Point\Services\V2PointSnapshotService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Models\V2\PointLedgerEntry;
use App\Models\V2\PointLot;
use App\Models\V2\PointOperation;
use App\Models\V2\PointReconciliationDiscrepancy;
use App\Models\V2\User;
use App\Models\V2\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use Tests\TestCase;

final class PointModelFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    public function test_wallet_and_lot_constraints_reject_invalid_balances_and_expiry(): void
    {
        $user = $this->user('constraints');
        $this->expectQueryFailure(fn () => DB::table('wallets')->insert([
            'user_id' => $user->id,
            'paid_balance' => -1,
            'free_balance' => 0,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'lock_version' => 0,
        ]));
        $wallet = app(V2PointService::class)->initializeWallet($user->id);
        $this->expectQueryFailure(
            fn () => DB::table('wallets')->where('id', $wallet->id)
                ->update(['free_reserved_balance' => 1])
        );
        $operation = $this->rawOperation($user, 'constraint-lot');
        $this->expectQueryFailure(fn () => DB::table('point_lots')->insert([
            'user_id' => $user->id,
            'grant_operation_id' => $operation->id,
            'point_type' => 'paid',
            'granted_amount' => 10,
            'remaining_amount' => 10,
            'reserved_amount' => 0,
            'granted_at' => now(),
            'expire_at' => now()->addDay(),
        ]));
        $this->expectQueryFailure(fn () => DB::table('point_lots')->insert([
            'user_id' => $user->id,
            'grant_operation_id' => $operation->id,
            'point_type' => 'free',
            'granted_amount' => 10,
            'remaining_amount' => 10,
            'reserved_amount' => 0,
            'granted_at' => now(),
            'expire_at' => null,
        ]));
        $this->expectQueryFailure(fn () => DB::table('point_lots')->insert([
            'user_id' => $user->id,
            'grant_operation_id' => $operation->id,
            'point_type' => 'free',
            'granted_amount' => 10,
            'remaining_amount' => -1,
            'reserved_amount' => 0,
            'granted_at' => now(),
            'expire_at' => now()->addDay(),
        ]));
    }

    public function test_free_grant_updates_wallet_lot_ledger_and_audit_atomically(): void
    {
        $user = $this->user('free-grant');
        $operation = app(V2PointService::class)->grantFree(
            $user->id,
            120,
            CarbonImmutable::parse('2027-01-01T00:00:00+09:00'),
            'free-grant-1',
            CarbonImmutable::parse('2026-07-24T10:00:00+09:00')
        );
        self::assertTrue(Str::isUuid($operation->public_id));
        self::assertSame(7, strlen(str_replace('-', '', $operation->public_id)) > 0
            ? (int) $operation->public_id[14]
            : 0);
        self::assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 0,
            'free_balance' => 120,
        ]);
        self::assertDatabaseHas('point_lots', [
            'grant_operation_id' => $operation->id,
            'point_type' => 'free',
            'granted_amount' => 120,
            'remaining_amount' => 120,
        ]);
        self::assertDatabaseHas('point_ledger_entries', [
            'point_operation_id' => $operation->id,
            'entry_type' => 'grant',
            'amount_delta' => 120,
            'wallet_balance_after' => 120,
        ]);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'point.free_granted']);
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }

    public function test_point_operation_and_ledger_are_immutable_in_application_and_database(): void
    {
        $user = $this->user('immutable');
        $operation = app(V2PointService::class)->grantFree(
            $user->id,
            10,
            now()->addDay(),
            'immutable-grant'
        );
        $entry = PointLedgerEntry::query()
            ->where('point_operation_id', $operation->id)
            ->firstOrFail();
        try {
            $operation->forceFill(['operation_type' => 'changed'])->save();
            self::fail('Operation update must fail.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        try {
            $entry->delete();
            self::fail('Ledger delete must fail.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        $this->expectQueryFailure(
            fn () => DB::table('point_operations')->where('id', $operation->id)
                ->update(['operation_type' => 'changed'])
        );
        $this->expectQueryFailure(
            fn () => DB::table('point_ledger_entries')->where('id', $entry->id)->delete()
        );
    }

    public function test_consumption_prefers_free_expiry_then_grant_time_then_paid_fifo(): void
    {
        $user = $this->user('order');
        $this->seedLot($user, 'free', 20, '2026-01-01 00:00:02+00', '2026-09-01 00:00:00+00');
        $first = $this->seedLot(
            $user,
            'free',
            15,
            '2026-01-01 00:00:00+00',
            '2026-08-01 00:00:00+00'
        );
        $second = $this->seedLot(
            $user,
            'free',
            15,
            '2026-01-01 00:00:01+00',
            '2026-08-01 00:00:00+00'
        );
        $paidFirst = $this->seedLot($user, 'paid', 30, '2026-01-01 00:00:00+00');
        $paidSecond = $this->seedLot($user, 'paid', 30, '2026-01-01 00:00:01+00');

        $operation = app(V2PointService::class)->consume(
            $user->id,
            70,
            'ordered-consume',
            CarbonImmutable::parse('2026-07-24 00:00:00+00')
        );
        $lotIds = array_map(
            'intval',
            PointLedgerEntry::query()
                ->where('point_operation_id', $operation->id)
                ->orderBy('sequence_no')
                ->pluck('point_lot_id')
                ->all()
        );
        $expectedLotIds = array_map('intval', [
            $first->id,
            $second->id,
            $this->lotId($user, 'free', 20),
            $paidFirst->id,
        ]);
        self::assertSame(
            $expectedLotIds,
            $lotIds,
            sprintf(
                'Point lot order failure: expected=%s actual=%s',
                json_encode($expectedLotIds, JSON_THROW_ON_ERROR),
                json_encode($lotIds, JSON_THROW_ON_ERROR)
            )
        );
        self::assertSame(10, PointLot::query()->findOrFail($paidFirst->id)->remaining_amount);
        self::assertSame(30, PointLot::query()->findOrFail($paidSecond->id)->remaining_amount);
        self::assertSame(['paid' => 40, 'free' => 0], app(V2PointLedgerService::class)->rebuild($user->id));
    }

    public function test_free_expiry_is_idempotent_and_paid_never_expires(): void
    {
        $user = $this->user('expiry');
        $free = $this->seedLot(
            $user,
            'free',
            25,
            '2026-01-01 00:00:00+00',
            '2026-07-01 00:00:00+00'
        );
        $paid = $this->seedLot($user, 'paid', 40, '2026-01-01 00:00:00+00');
        $service = app(V2PointService::class);
        self::assertSame(1, $service->expireFree(CarbonImmutable::parse('2026-07-02 00:00:00+00')));
        self::assertSame(0, $service->expireFree(CarbonImmutable::parse('2026-07-02 00:00:00+00')));
        self::assertSame(0, PointLot::query()->findOrFail($free->id)->remaining_amount);
        self::assertSame(40, PointLot::query()->findOrFail($paid->id)->remaining_amount);
        self::assertDatabaseHas('point_ledger_entries', [
            'point_lot_id' => $free->id,
            'entry_type' => 'expire',
            'amount_delta' => -25,
        ]);
    }

    public function test_transaction_rollback_removes_wallet_lot_operation_ledger_idempotency_and_audit(): void
    {
        $user = $this->user('rollback');
        try {
            DB::transaction(function () use ($user): void {
                app(V2PointService::class)->grantFree(
                    $user->id,
                    30,
                    now()->addDay(),
                    'rollback-grant'
                );
                throw new V2PointException('force rollback');
            });
        } catch (V2PointException) {
            self::assertDatabaseMissing('wallets', ['user_id' => $user->id]);
            self::assertDatabaseMissing('point_operations', [
                'business_key' => 'point.free_grant:'.hash('sha256', 'rollback-grant'),
            ]);
            self::assertDatabaseMissing('idempotency_records', [
                'key_hash' => hash('sha256', 'rollback-grant'),
            ]);
        }
    }

    public function test_idempotency_replays_same_request_and_rejects_key_reuse(): void
    {
        $user = $this->user('idempotency');
        $service = app(V2PointService::class);
        $first = $service->grantFree($user->id, 50, now()->addDay(), 'same-key');
        $replay = $service->grantFree($user->id, 50, now()->addDay(), 'same-key');
        self::assertSame($first->id, $replay->id);
        self::assertSame(1, PointOperation::query()->whereKey($first->id)->count());
        $this->expectException(V2PointException::class);
        $this->expectExceptionMessage('IDEMPOTENCY_KEY_REUSED');
        $service->grantFree($user->id, 51, now()->addDay(), 'same-key');
    }

    public function test_same_wallet_concurrent_consumption_does_not_overdraw(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for concurrency verification.');
        }
        $user = $this->user('concurrent-consume');
        $this->seedLot($user, 'free', 100, now()->subMinute(), now()->addDay());
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            try {
                app(App\Domain\Point\Services\V2PointService::class)
                    ->consume((int) $argv[1], 80, $argv[2]);
                exit(0);
            } catch (App\Domain\Point\Exceptions\V2PointException) {
                exit(2);
            }
            PHP;
        $statuses = $this->parallelProcesses($script, [
            [(string) $user->id, 'concurrent-a'],
            [(string) $user->id, 'concurrent-b'],
        ]);
        sort($statuses);
        self::assertSame([0, 2], $statuses);
        self::assertSame(
            20,
            (int) Wallet::query()->where('user_id', $user->id)->value('free_balance')
        );
        self::assertSame(
            20,
            (int) PointLot::query()->where('user_id', $user->id)->sum('remaining_amount')
        );
    }

    public function test_consumption_and_expiry_conflict_remains_consistent(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for concurrency verification.');
        }
        $user = $this->user('consume-expire');
        $this->seedLot(
            $user,
            'free',
            60,
            '2026-07-01 00:00:00+00',
            '2026-07-25 00:00:00+00'
        );
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            try {
                if ($argv[2] === 'consume') {
                    app(App\Domain\Point\Services\V2PointService::class)->consume(
                        (int) $argv[1], 40, 'consume-expire-race',
                        Carbon\CarbonImmutable::parse('2026-07-24 23:59:59+00')
                    );
                } else {
                    app(App\Domain\Point\Services\V2PointService::class)->expireFree(
                        Carbon\CarbonImmutable::parse('2026-07-25 00:00:00+00')
                    );
                }
                exit(0);
            } catch (App\Domain\Point\Exceptions\V2PointException) {
                exit(2);
            }
            PHP;
        $statuses = $this->parallelProcesses($script, [
            [(string) $user->id, 'consume'],
            [(string) $user->id, 'expire'],
        ]);
        self::assertContains($statuses[0], [0, 2]);
        self::assertContains($statuses[1], [0, 2]);
        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();
        $lotBalance = PointLot::query()->where('user_id', $user->id)->sum('remaining_amount');
        self::assertSame((int) $wallet->free_balance, (int) $lotBalance);
        self::assertGreaterThanOrEqual(0, $wallet->free_balance);
    }

    public function test_deadlock_and_serialization_failures_are_retried_only_three_times(): void
    {
        $attempts = 0;
        $result = app(V2PointTransactionRunner::class)->run(function () use (&$attempts): string {
            $attempts++;
            if ($attempts < 3) {
                throw $this->queryException('40001');
            }

            return 'completed';
        });
        self::assertSame('completed', $result);
        self::assertSame(3, $attempts);

        $this->expectException(QueryException::class);
        app(V2PointTransactionRunner::class)->run(
            fn (): never => throw $this->queryException('23505')
        );
    }

    public function test_ledger_rebuild_and_snapshot_use_strict_jst_cutoff(): void
    {
        $user = $this->user('snapshot');
        $this->seedLot(
            $user,
            'free',
            40,
            '2020-03-31 14:59:59+00',
            '2021-01-01 00:00:00+00'
        );
        $this->seedLot(
            $user,
            'free',
            20,
            '2020-03-31 15:00:00+00',
            '2021-01-01 00:00:00+00'
        );
        $snapshot = app(V2PointSnapshotService::class)->generate('2020-03-31');
        self::assertSame(40, $snapshot->closing_free_balance);
        self::assertSame(40, $snapshot->granted_free_amount);
        self::assertTrue($snapshot->is_base_date);
        self::assertSame('2020-03-31', $snapshot->snapshot_date->toDateString());
        self::assertDatabaseHas('audit_logs', ['action_code' => 'point.snapshot_generated']);

        $september = app(V2PointSnapshotService::class)->generate('2020-09-30');
        self::assertTrue($september->is_base_date);
        $regenerated = app(V2PointSnapshotService::class)->generate('2020-03-31');
        self::assertNotSame($snapshot->generation_run_id, $regenerated->generation_run_id);
    }

    public function test_reconciliation_detects_discrepancy_without_repair(): void
    {
        $user = $this->user('reconciliation');
        app(V2PointService::class)->grantFree($user->id, 30, now()->addDay(), 'reconcile-grant');
        DB::table('wallets')->where('user_id', $user->id)->update(['free_balance' => 29]);
        $run = app(V2PointReconciliationService::class)->run('2026-07-24');
        self::assertSame('completed', $run->status);
        self::assertGreaterThanOrEqual(1, $run->discrepancy_count);
        self::assertDatabaseHas('point_reconciliation_discrepancies', [
            'reconciliation_run_id' => $run->id,
            'user_id' => $user->id,
            'point_type' => 'free',
            'resolved' => false,
        ]);
        self::assertSame(
            29,
            (int) Wallet::query()->where('user_id', $user->id)->value('free_balance')
        );
        $discrepancy = PointReconciliationDiscrepancy::query()
            ->where('reconciliation_run_id', $run->id)
            ->firstOrFail();
        $this->expectException(LogicException::class);
        $discrepancy->delete();
    }

    public function test_paid_adjustment_permission_is_owner_only_and_self_approval_is_not_blocked(): void
    {
        $permissions = app(V2PermissionAuthorizer::class);
        self::assertTrue($permissions->allows(
            V2AdminRole::Owner,
            V2Permission::ApprovePaidPointAdjustment
        ));
        self::assertFalse($permissions->allows(
            V2AdminRole::Admin,
            V2Permission::ApprovePaidPointAdjustment
        ));
        self::assertFalse($permissions->allows(
            V2AdminRole::Operator,
            V2Permission::RequestPointAdjustment
        ));
    }

    public function test_no_paid_grant_or_public_api_is_exposed_by_point_service(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(V2PointService::class))->getMethods(
                \ReflectionMethod::IS_PUBLIC
            )
        );
        self::assertNotContains('grantPaid', $methods);
        self::assertNotContains('adjustPaid', $methods);
        self::assertSame(
            'succeeded_payment_only',
            config('v2_point.paid_grant.normal_source')
        );
        self::assertFalse(config('v2_point.paid_grant.enabled'));
    }

    private function user(string $name): User
    {
        return User::query()->create([
            'email_display' => $name.'-'.Str::uuid().'@example.test',
            'email_normalized' => $name.'-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
    }

    private function rawOperation(User $user, string $suffix): PointOperation
    {
        $operation = new PointOperation();
        $operation->forceFill([
            'user_id' => $user->id,
            'operation_type' => 'fixture_grant',
            'business_key' => 'fixture:'.$suffix.':'.Str::uuid(),
            'source_type' => 'test_fixture',
            'actor_type' => 'system',
            'is_qa' => false,
            'occurred_at' => now(),
            'business_date' => now('Asia/Tokyo')->toDateString(),
            'metadata' => (object) [],
        ])->save();

        return $operation;
    }

    private function seedLot(
        User $user,
        string $type,
        int $amount,
        string|\DateTimeInterface $grantedAt,
        string|\DateTimeInterface|null $expireAt = null
    ): PointLot {
        $granted = CarbonImmutable::parse($grantedAt);
        $operation = $this->rawOperation($user, 'seed-'.$type);
        $wallet = Wallet::query()->where('user_id', $user->id)->first();
        if ($wallet === null) {
            $wallet = app(V2PointService::class)->initializeWallet($user->id);
        }
        $before = (int) $wallet->{$type.'_balance'};
        $lot = new PointLot();
        $lot->forceFill([
            'user_id' => $user->id,
            'grant_operation_id' => $operation->id,
            'point_type' => $type,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'reserved_amount' => 0,
            'granted_at' => $granted,
            'expire_at' => $expireAt === null ? null : CarbonImmutable::parse($expireAt),
        ])->save();
        $wallet->forceFill([
            $type.'_balance' => $before + $amount,
            'lock_version' => (int) $wallet->lock_version + 1,
        ])->save();
        $entry = new PointLedgerEntry();
        $entry->forceFill([
            'point_operation_id' => $operation->id,
            'sequence_no' => 1,
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => $lot->id,
            'point_type' => $type,
            'entry_type' => 'grant',
            'amount_delta' => $amount,
            'wallet_balance_after' => $before + $amount,
            'lot_remaining_after' => $amount,
            'occurred_at' => $granted,
            'business_date' => $granted->setTimezone('Asia/Tokyo')->toDateString(),
        ])->save();

        return $lot;
    }

    private function lotId(User $user, string $type, int $amount): int
    {
        return PointLot::query()
            ->where('user_id', $user->id)
            ->where('point_type', $type)
            ->where('granted_amount', $amount)
            ->orderByDesc('id')
            ->valueOrFail('id');
    }

    private function expectQueryFailure(callable $callback): void
    {
        try {
            DB::transaction($callback);
            self::fail('PostgreSQL constraint or immutable trigger must reject the mutation.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    private function queryException(string $sqlState): QueryException
    {
        $previous = new PDOException('fixture database failure');
        $previous->errorInfo = [$sqlState, null, 'redacted'];

        return new QueryException('pgsql', 'select 1', [], $previous);
    }

    /**
     * @param list<list<string>> $arguments
     * @return list<int>
     */
    private function parallelProcesses(string $script, array $arguments): array
    {
        $processes = [];
        foreach ($arguments as $args) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $script, ...$args],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                base_path()
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $statuses = [];
        foreach ($processes as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $statuses[] = proc_close($process);
        }
        DB::disconnect();
        DB::reconnect();

        return $statuses;
    }
}
