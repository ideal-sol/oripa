<?php

namespace Tests\V2;

use App\Domain\MemberRank\Exceptions\V2MemberRankException;
use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\MemberRank\Services\V2MemberRankPromotionWorker;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZMemberRankAdminConcurrencyTest extends TestCase
{
    use MemberRankAccountingFixture;

    public function test_monthly_worker_serializes_refund_transfer_replay_and_closure(): void
    {
        if (getenv('V2_M7_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable M7 concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_m7_synthetic', DB::connection()->getDatabaseName());
        $this->configureRankFixture();
        $this->enableRankFixture();
        config(['mail.default' => 'array']);
        \Illuminate\Support\Facades\Mail::fake();
        try {
            foreach (['refund' => '2026-11-30T15:00:00Z', 'transfer' => '2026-12-31T15:00:00Z', 'closure' => '2027-01-31T15:00:00Z'] as $race => $instant) {
                $this->rankClock($instant);
                $user = $this->rankUser();
                $admin = $this->rankAdmin();
                $payment = $this->settleRankPayment($user, 20000);
                $beforeLevel = (int) DB::table('member_rank_states')->where('user_id', $user->id)->value('level');
                $beforeBonuses = DB::table('member_rank_achievements')->where('user_id', $user->id)->count();
                $payments = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
                $parent = $race === 'refund' ? $payments->write($admin, $payment->public_id, 'refund', ['reason' => 'Synthetic concurrent refund'], (string) Str::uuid7())['data']['id'] : null;
                $key = (string) Str::uuid7();
                $transfer = fn () => app(\App\Domain\MemberRank\Services\V2MemberRankTransferService::class)->write(
                    $admin, $user->public_id,
                    ['status' => 'transferred', 'equivalent_level' => 4, 'points' => 80000, 'expected_revision' => 0, 'reason' => 'Synthetic concurrent transfer'], $key
                );
                $actions = [
                    'worker' => fn () => app(V2MemberRankPromotionWorker::class)->run('cumulative-'.$race, 100),
                ];
                if ($race === 'refund') {
                    $actions['refund'] = fn () => $payments->write($admin, $payment->public_id, 'complete', ['reason' => 'Synthetic concurrent completion', 'provider_refund_confirmed' => true], $key, $parent);
                } else {
                    $actions['transfer'] = $transfer;
                    $actions['transfer-replay'] = $transfer;
                }
                if ($race === 'closure') {
                    $actions['closure'] = fn () => app(\App\Domain\Point\Services\V2PointTransactionRunner::class)->run(function () use ($user): void {
                        $locked = \App\Models\V2\User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                        app(\App\Domain\Identity\Services\V2UserClosureService::class)->closeLocked($locked, 'self', 'normal', (string) Str::uuid7());
                    });
                }
                $children = [];
                DB::disconnect();
                foreach ($actions as $name => $action) {
                    $path = tempnam(sys_get_temp_dir(), 'cumulative-race-');
                    $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                    self::assertIsArray($barrier);
                    $pid = pcntl_fork();
                    self::assertGreaterThanOrEqual(0, $pid);
                    if ($pid === 0) {
                        fclose($barrier[0]); fread($barrier[1], 1); fclose($barrier[1]);
                        DB::reconnect(); DB::statement("SET statement_timeout = '30s'");
                        try { $action(); $result = 'success'; }
                        catch (V2MemberRankException $error) { $result = $error->errorCode; }
                        catch (\Throwable $error) { $result = $error::class.':'.$error->getMessage(); }
                        file_put_contents($path, $result); DB::disconnect(); exit(0);
                    }
                    fclose($barrier[1]);
                    $children[] = [$pid, $path, $name, $barrier[0]];
                }
                foreach ($children as [, , , $barrier]) { fwrite($barrier, '1'); fclose($barrier); }
                foreach ($children as [$pid, $path, $name]) {
                    pcntl_waitpid($pid, $status);
                    self::assertTrue(pcntl_wifexited($status)); self::assertSame(0, pcntl_wexitstatus($status));
                    $result = file_get_contents($path); unlink($path);
                    self::assertContains($result, $race === 'closure' && str_starts_with($name, 'transfer')
                        ? ['success', 'MEMBER_RANK_USER_UNAVAILABLE'] : ['success'], $race.':'.$name);
                }
                DB::reconnect();
                app(V2MemberRankPromotionWorker::class)->run('cumulative-finish-'.$race, 100);
                self::assertSame(0, DB::table('member_rank_promotion_jobs')->where('status', 'failed')->count());
                self::assertSame(0, DB::table('member_rank_compensation_entries')->where('user_id', $user->id)->count());
                self::assertSame($beforeBonuses, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
                self::assertSame(0, DB::table('member_rank_bonus_recoveries')->where('user_id', $user->id)->count());
                self::assertGreaterThanOrEqual($beforeLevel, (int) DB::table('member_rank_states')->where('user_id', $user->id)->value('level'));
                $month = CarbonImmutable::parse($instant)->setTimezone('Asia/Tokyo')->startOfMonth()->toDateString();
                self::assertDatabaseHas('member_rank_promotion_jobs', ['month' => $month, 'status' => 'completed', 'failures' => 0]);
                self::assertLessThanOrEqual(1, DB::table('member_rank_cumulative_events')->where('user_id', $user->id)->where('kind', 'monthly')->where('month', $month)->count());
                $histories = DB::table('member_rank_transfer_histories')->where('user_id', $user->id)->count();
                self::assertLessThanOrEqual(1, $histories);
                if ($race === 'transfer') {
                    self::assertSame(1, DB::table('member_rank_cumulative_events')->where('user_id', $user->id)->where('kind', 'monthly')->where('month', $month)->count());
                    self::assertSame(1, $histories);
                    self::assertSame(100000, app(\App\Domain\MemberRank\Services\V2MemberRankCumulativePoints::class)->total($user->id));
                    self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 4]);
                } elseif ($race === 'refund') {
                    self::assertSame(1, DB::table('member_rank_cumulative_events')->where('user_id', $user->id)->where('kind', 'monthly')->where('month', $month)->count());
                    self::assertSame(0, app(\App\Domain\MemberRank\Services\V2MemberRankCumulativePoints::class)->total($user->id));
                    self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 0, 'paid_reserved_balance' => 0]);
                } else {
                    self::assertSame(1, DB::table('account_closures')->where('user_id', $user->id)->count());
                    self::assertNotNull(DB::table('member_rank_states')->where('user_id', $user->id)->value('frozen_at'));
                    self::assertSame(['paid' => 0, 'free' => 0], app(\App\Domain\Point\Services\V2PointLedgerService::class)->rebuild($user->id));
                }
            }
        } finally {
            DB::purge(); DB::reconnect();
            Carbon::setTestNow(); CarbonImmutable::setTestNow();
        }
    }

    public function test_decrement_duplicate_payment_worker_and_projection_are_serialized_without_lost_updates(): void
    {
        if (getenv('V2_M7_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable M7 concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_m7_synthetic', DB::connection()->getDatabaseName());
        $this->configureRankFixture();
        $this->enableRankFixture();
        $admin = $this->rankAdmin();
        $user = $this->rankUser();
        $other = $this->rankUser();
        $this->seedRankState($other, 7);
        $this->rankClock('2026-10-31T15:00:00Z');
        $admin = $this->rankAdmin();
        $service = app(V2MemberRankAdminService::class);
        $input = ['delta' => 10000, 'reason' => 'concurrency seed'];
        $preview = $service->preview($admin, $user->public_id, 'points', $input);
        $service->write($admin, $user->public_id, 'points', [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        [$payment, $event] = $this->rankPayment($user, 5000);
        $input = ['delta' => -10000, 'reason' => 'concurrent correction'];
        $preview = $service->preview($admin, $user->public_id, 'points', $input);
        $payload = [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']];
        $key = (string) Str::uuid7();
        $processes = [];
        DB::disconnect();
        foreach (['manual', 'manual', 'payment', 'worker', 'read'] as $action) {
            $path = tempnam(sys_get_temp_dir(), 'm7-concurrency-');
            $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($barrier);
            $processId = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $processId);
            if ($processId === 0) {
                fclose($barrier[0]);
                fread($barrier[1], 1);
                fclose($barrier[1]);
                DB::reconnect();
                DB::statement("SET lock_timeout = '10s'");
                try {
                    if ($action === 'manual') {
                        $service->write($admin, $user->public_id, 'points', $payload, $key);
                    } elseif ($action === 'payment') {
                        app(V2PaymentService::class)->confirmSucceeded($event->id);
                    } elseif ($action === 'worker') {
                        app(V2MemberRankPromotionWorker::class)->run('m7-concurrent', 100);
                    } else {
                        app(\App\Domain\MemberRank\Services\V2MemberRankResolver::class)->project($user->id);
                    }
                    $outcome = 'success';
                } catch (V2MemberRankException $exception) {
                    $outcome = $exception->errorCode;
                } catch (\Throwable $exception) {
                    $outcome = get_class($exception).':'.$exception->getCode();
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
        foreach ($processes as [$processId, $path, $action]) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $outcome = file_get_contents($path);
            self::assertContains($outcome, $action === 'manual' ? ['success', 'MEMBER_RANK_REVISION_CONFLICT'] : ['success']);
            unlink($path);
        }
        DB::reconnect();
        if (! DB::table('member_rank_point_entries')->where('user_id', $user->id)->where('applied_delta', -10000)->exists()) {
            $preview = $service->preview($admin, $user->public_id, 'points', $input);
            $service->write($admin, $user->public_id, 'points', [...$input, 'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], $key);
        }
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-11-01', 'points' => 5000]);
        self::assertSame(1, DB::table('member_rank_point_entries')->where('user_id', $user->id)->where('applied_delta', -10000)->count());
        self::assertSame(0, DB::table('member_rank_bonus_recoveries')->where('user_id', $user->id)->count());
        $purchase = DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->sole();
        self::assertSame(2, (int) $purchase->rank_level);
        self::assertSame(25, (int) $purchase->bonus_amount);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 5000, 'free_balance' => 500 + (int) $purchase->bonus_amount]);
        self::assertSame(5000, (int) DB::table('member_rank_point_entries')->where('user_id', $user->id)->sum('applied_delta'));
        self::assertDatabaseHas('member_rank_states', ['user_id' => $other->id, 'level' => 7, 'baseline_month' => '2026-10-01']);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
