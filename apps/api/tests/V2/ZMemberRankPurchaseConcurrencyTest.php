<?php

namespace Tests\V2;

use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZMemberRankPurchaseConcurrencyTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('V2_M4_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable M4 concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_m4_synthetic', DB::connection()->getDatabaseName());
        $this->configureRankFixture();
        $this->enableRankFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function lockedTables(): array
    {
        return [['users'], ['point_purchase_plans'], ['wallets']];
    }

    #[DataProvider('lockedTables')]
    public function test_actual_lock_wait_uses_saved_grant_time_across_jst_month(string $table): void
    {
        $user = $this->rankUser();
        $this->seedRankState($user, 3);
        [$payment, $event] = $this->rankPayment($user, 5000);
        DB::table('wallets')->insertOrIgnore(['user_id' => $user->id, 'paid_balance' => 0, 'free_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $settings = app(V2MemberRankSettingsService::class);
        $current = DB::table('member_rank_settings')->where('id', 1)->first();
        $ranks = $settings->effectiveAt(CarbonImmutable::now())['ranks'];
        $ranks[1]['purchase_bonus_rate_bp'] = 100;
        $settings->update($this->rankAdmin(), (string) Str::uuid7(), ['expected_revision' => (int) $current->revision, 'enabled' => true, 'default_skip_count' => 1, 'ranks' => $ranks]);
        $clock = tempnam(sys_get_temp_dir(), 'm4-clock-');
        $result = tempnam(sys_get_temp_dir(), 'm4-result-');
        file_put_contents($clock, '2026-10-31T14:59:59Z');
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($barrier);
        DB::disconnect();
        $processId = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $processId);
        if ($processId === 0) {
            fclose($barrier[0]);
            fread($barrier[1], 1);
            fclose($barrier[1]);
            DB::reconnect();
            DB::statement("SET application_name = 'm4-boundary-child'");
            DB::statement("SET lock_timeout = '15s'");
            Carbon::setTestNow(fn () => CarbonImmutable::parse(trim(file_get_contents($clock))));
            CarbonImmutable::setTestNow(fn () => CarbonImmutable::parse(trim(file_get_contents($clock))));
            try {
                app(V2PaymentService::class)->confirmSucceeded($event->id);
                file_put_contents($result, 'success');
            } catch (\Throwable $error) {
                file_put_contents($result, get_class($error).':'.$error->getMessage());
            }
            DB::disconnect();
            exit(0);
        }
        fclose($barrier[1]);
        DB::reconnect();
        DB::beginTransaction();
        $column = $table === 'wallets' ? 'user_id' : 'id';
        $identifier = $table === 'point_purchase_plans' ? $payment->point_purchase_plan_id : $user->id;
        DB::table($table)->where($column, $identifier)->lockForUpdate()->firstOrFail();
        fwrite($barrier[0], '1');
        fclose($barrier[0]);
        $blocked = false;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $blocked = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = 'm4-boundary-child' AND wait_event_type = 'Lock') AS blocked")->blocked;
            if ($blocked) {
                break;
            }
            usleep(50000);
        }
        file_put_contents($clock, '2026-10-31T15:00:01Z');
        DB::commit();
        pcntl_waitpid($processId, $status);
        self::assertTrue($blocked, 'Must observe a real PostgreSQL lock wait.');
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame('success', file_get_contents($result));
        unlink($clock);
        unlink($result);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'rank_level' => 2, 'rate_basis_points' => 100, 'bonus_amount' => 50, 'month' => '2026-11-01']);
        $grant = DB::table('payment_point_grants')->where('payment_id', $payment->id)->sole();
        self::assertSame('2026-10-31T15:00:01Z', CarbonImmutable::parse($grant->granted_at)->utc()->toIso8601ZuluString());
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-11-01', 'points' => 5000]);
    }

    public function test_real_postgres_deadlock_retries_then_commits_each_bonus_once(): void
    {
        $users = [$this->rankUser(), $this->rankUser()];
        $events = [];
        foreach ($users as $user) {
            $this->seedRankState($user, 2);
            [, $event] = $this->rankPayment($user, 5000);
            $events[] = $event;
        }
        $marker = tempnam(sys_get_temp_dir(), 'm4-deadlock-');
        $children = [];
        DB::disconnect();
        foreach ([0, 1] as $index) {
            $processId = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $processId);
            if ($processId === 0) {
                DB::reconnect();
                $attempts = 0;
                try {
                    app(\App\Domain\Point\Services\V2PointTransactionRunner::class)->run(function () use ($users, $events, $index, $marker, &$attempts): void {
                        $attempts++;
                        DB::table('users')->where('id', $users[$index]->id)->lockForUpdate()->firstOrFail();
                        if ($attempts === 1) {
                            file_put_contents($marker.'.locked'.$index, '1');
                            $deadline = microtime(true) + 15;
                            while (! file_exists($marker.'.locked'.(1 - $index))) {
                                if (microtime(true) > $deadline) {
                                    throw new \RuntimeException('Synthetic deadlock barrier timeout');
                                }
                                usleep(1000);
                            }
                        }
                        DB::table('users')->where('id', $users[1 - $index]->id)->lockForUpdate()->firstOrFail();
                        app(V2PaymentService::class)->confirmSucceeded($events[$index]->id);
                    });
                    $result = ['attempts' => $attempts, 'status' => 'success'];
                } catch (\Throwable $error) {
                    $result = ['attempts' => $attempts, 'status' => get_class($error).':'.$error->getMessage()];
                }
                file_put_contents($marker.'.result'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit(0);
            }
            $children[] = $processId;
        }
        $attempts = 0;
        foreach ($children as $index => $processId) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($marker.'.result'.$index), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('success', $result['status']);
            $attempts += $result['attempts'];
            unlink($marker.'.result'.$index);
            unlink($marker.'.locked'.$index);
        }
        unlink($marker);
        self::assertGreaterThanOrEqual(3, $attempts);
        DB::reconnect();
        foreach ($users as $user) {
            self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 5000, 'free_balance' => 25]);
            self::assertSame(1, DB::table('member_rank_purchase_bonuses')->where('user_id', $user->id)->count());
            self::assertSame(1, DB::table('point_operations')->where('user_id', $user->id)->where('source_type', 'member_rank_purchase')->count());
        }
    }

    public function test_two_payments_replays_worker_manual_rank_and_rank_points_serialize(): void
    {
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['skip_count' => 1]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $bronzeRate = app(V2MemberRankSettingsService::class)->effectiveAt(CarbonImmutable::now())['ranks'][1]['purchase_bonus_rate_bp'];
        $admin = $this->rankAdmin();
        [$first, $firstEvent] = $this->rankPayment($user, 5000);
        [$second, $secondEvent] = $this->rankPayment($user, 5000);
        $service = app(V2MemberRankAdminService::class);
        $inputs = ['rank' => ['rank' => 'gold', 'kind' => 'other', 'reason' => 'M4 race', 'skip_count' => 1, 'grant_bonus' => false],
            'points' => ['delta' => 1, 'reason' => 'M4 race']];
        $payloads = [];
        foreach ($inputs as $action => $input) {
            $preview = $service->preview($admin, $user->public_id, $action, $input);
            $payloads[$action] = [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']];
        }
        $keys = ['rank' => (string) Str::uuid7(), 'points' => (string) Str::uuid7()];
        $processes = [];
        DB::disconnect();
        foreach (['first', 'first', 'second', 'second', 'worker', 'rank', 'points'] as $action) {
            $path = tempnam(sys_get_temp_dir(), 'm4-race-');
            $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($barrier);
            $processId = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $processId);
            if ($processId === 0) {
                fclose($barrier[0]);
                fread($barrier[1], 1);
                fclose($barrier[1]);
                DB::reconnect();
                DB::statement("SET lock_timeout = '15s'");
                try {
                    if (isset($inputs[$action])) {
                        $service->write($admin, $user->public_id, $action, $payloads[$action], $keys[$action]);
                    } elseif ($action === 'worker') {
                        app(V2MemberRankMonthlyWorker::class)->run('m4-race', 100);
                    } else {
                        app(V2PaymentService::class)->confirmSucceeded($action === 'first' ? $firstEvent->id : $secondEvent->id);
                    }
                    $outcome = 'success';
                } catch (V2MemberRankException $error) {
                    $outcome = $error->errorCode;
                } catch (\Throwable $error) {
                    $outcome = get_class($error).':'.$error->getMessage();
                }
                file_put_contents($path, $outcome);
                DB::disconnect();
                exit(0);
            }
            fclose($barrier[1]);
            $processes[] = [$processId, $path, $action, $barrier[0]];
        }
        foreach ($processes as [, , , $barrier]) {
            fwrite($barrier, '1');
            fclose($barrier);
        }
        $retry = [];
        foreach ($processes as [$processId, $path, $action]) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $outcome = file_get_contents($path);
            self::assertContains($outcome, isset($inputs[$action]) ? ['success', 'MEMBER_RANK_REVISION_CONFLICT'] : ['success']);
            if ($outcome !== 'success') {
                $retry[] = $action;
            }
            unlink($path);
        }
        DB::reconnect();
        foreach ($retry as $action) {
            $preview = $service->preview($admin, $user->public_id, $action, $inputs[$action]);
            $service->write($admin, $user->public_id, $action, [...$inputs[$action], 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], $keys[$action]);
        }
        $snapshots = DB::table('member_rank_purchase_bonuses')->where('user_id', $user->id)->get();
        self::assertCount(2, $snapshots);
        foreach ($snapshots as $snapshot) {
            self::assertContains((int) $snapshot->rank_level, [2, 4]);
            self::assertSame((int) $snapshot->rank_level === 2 ? intdiv(5000 * $bronzeRate, 10000) : 75, (int) $snapshot->bonus_amount);
        }
        self::assertSame(2, DB::table('point_operations')->where('user_id', $user->id)->where('source_type', 'member_rank_purchase')->count());
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-11-01', 'points' => 10001]);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'baseline_month' => '2026-11-01']);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 10000, 'free_balance' => 500 + $snapshots->sum('bonus_amount')]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->sole();
        foreach (['paid', 'free'] as $type) {
            self::assertSame((int) $wallet->{$type.'_balance'}, (int) DB::table('point_lots')->where('user_id', $user->id)->where('point_type', $type)->sum('remaining_amount'));
            self::assertSame((int) $wallet->{$type.'_balance'}, (int) DB::table('point_ledger_entries')->where('user_id', $user->id)->where('point_type', $type)->sum('amount_delta'));
        }
    }
}
