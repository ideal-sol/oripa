<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZMemberRankMonthlyConcurrencyTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('V2_RANK_MONTH_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable rank month concurrency database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_rankmonth_synthetic', DB::connection()->getDatabaseName());
        self::assertTrue(function_exists('pcntl_fork'));
        $this->configureRankFixture();
        $this->enableRankFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_two_workers_two_payments_replays_and_projection_commit_one_history(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 100000);
        [$first, $firstEvent] = $this->rankPayment($user, 50000);
        [, $secondEvent] = $this->rankPayment($user, 50000, planId: $first->point_purchase_plan_id);
        $this->rankClock('2026-11-30T15:00:01Z');
        $outcomes = $this->race([
            fn () => app(V2MemberRankMonthlyWorker::class)->run('worker-one', 10),
            fn () => app(V2MemberRankMonthlyWorker::class)->run('worker-two', 10),
            function () use ($firstEvent): void {
                app(V2PaymentService::class)->confirmSucceeded($firstEvent->id);
                app(V2PaymentService::class)->confirmSucceeded($firstEvent->id);
            },
            function () use ($secondEvent): void {
                app(V2PaymentService::class)->confirmSucceeded($secondEvent->id);
                app(V2PaymentService::class)->confirmSucceeded($secondEvent->id);
            },
            function () use ($user): void {
                for ($iteration = 0; $iteration < 20; $iteration++) {
                    $rank = app(V2MemberRankResolver::class)->project($user->id);
                    if (! in_array($rank['level'], [3, 4], true) || $rank['last_judged_month'] !== '2026-12-01') {
                        throw new \LogicException('Torn projection');
                    }
                }
            },
        ]);
        self::assertCount(5, $outcomes);
        app(V2MemberRankMonthlyWorker::class)->run('finish', 100);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 4, 'baseline_level' => 3, 'last_judged_month' => '2026-12-01']);
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-12-01', 'points' => 100000]);
        self::assertSame(['promotion', 'demotion', 'promotion'], DB::table('member_rank_changes')->where('user_id', $user->id)->orderBy('id')->pluck('reason')->all());
        self::assertSame(3, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        self::assertSame(3, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 200000, 'free_balance' => 8500]);
        self::assertSame(1000, (int) DB::table('member_rank_purchase_bonuses')->where('user_id', $user->id)->sum('bonus_amount'));
        self::assertSame(1, DB::table('member_rank_monthly_jobs')->where('month', '2026-12-01')->count());
    }

    public function test_real_deadlock_retries_preserve_one_judgment_per_user(): void
    {
        $first = $this->rankUser();
        $second = $this->rankUser();
        $this->seedRankState($first, 4);
        $this->seedRankState($second, 4);
        $this->rankClock('2026-10-31T15:00:01Z');
        $barrier = tempnam(sys_get_temp_dir(), 'rank-deadlock-');
        $outcomes = $this->race(array_map(function (array $users) use ($barrier): callable {
            return function () use ($users, $barrier): int {
                $attempt = 0;
                app(V2PointTransactionRunner::class)->run(function () use ($users, $barrier, &$attempt): void {
                    $attempt++;
                    DB::table('users')->where('id', $users[0])->lockForUpdate()->firstOrFail();
                    if ($attempt === 1) {
                        file_put_contents($barrier.'.'.$users[0], 'locked');
                        $deadline = microtime(true) + 5;
                        while (! file_exists($barrier.'.'.$users[1])) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Synthetic barrier timeout');
                            }
                            usleep(1000);
                        }
                    }
                    DB::table('users')->where('id', $users[1])->lockForUpdate()->firstOrFail();
                    foreach ($users as $userId) {
                        app(V2MemberRankResolver::class)->materialize($userId);
                    }
                });

                return $attempt;
            };
        }, [[$first->id, $second->id], [$second->id, $first->id]]));
        self::assertGreaterThanOrEqual(3, array_sum($outcomes));
        self::assertSame(2, DB::table('member_rank_changes')->whereIn('user_id', [$first->id, $second->id])->where('reason', 'demotion')->count());
        foreach ([$first, $second] as $user) {
            self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 3, 'last_judged_month' => '2026-11-01']);
            unlink($barrier.'.'.$user->id);
        }
        unlink($barrier);
    }

    public function test_month_end_grant_commits_before_waiting_monthly_judgment(): void
    {
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        [$payment, $event] = $this->rankPayment($user, 70000);
        $barrier = tempnam(sys_get_temp_dir(), 'rank-boundary-');
        $outcomes = $this->race([
            function () use ($event, $barrier): void {
                DB::listen(function ($query) use ($barrier): void {
                    if (str_contains($query->sql, 'insert into "payment_point_grants"')) {
                        file_put_contents($barrier, 'grant-recorded');
                        usleep(400000);
                    }
                });
                app(V2PaymentService::class)->confirmSucceeded($event->id);
            },
            function () use ($user, $barrier): void {
                $deadline = microtime(true) + 5;
                while (file_get_contents($barrier) !== 'grant-recorded') {
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('Synthetic grant barrier timeout');
                    }
                    usleep(1000);
                }
                $this->rankClock('2026-10-31T15:00:01Z');
                app(V2PointTransactionRunner::class)->run(fn () => app(V2MemberRankResolver::class)->materialize($user->id));
            },
        ]);
        self::assertCount(2, $outcomes);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 4, 'last_judged_month' => '2026-11-01']);
        self::assertDatabaseHas('member_rank_point_entries', ['payment_id' => $payment->id, 'month' => '2026-10-01', 'applied_delta' => 70000]);
        self::assertSame(0, DB::table('member_rank_changes')->where('user_id', $user->id)->where('reason', 'demotion')->count());
        unlink($barrier);
    }

    public function test_artisan_loop_sigterm_releases_lease_and_next_command_resumes(): void
    {
        foreach ([4, 5] as $level) {
            $this->seedRankState($this->rankUser(), $level);
        }
        $this->rankClock('2027-01-31T15:00:01Z');
        $outcomes = $this->race([function (): int {
            \Illuminate\Console\Signals::resolveAvailabilityUsing(fn (): bool => extension_loaded('pcntl'));
            $sent = false;
            DB::listen(function ($query) use (&$sent): void {
                if (! $sent && str_contains($query->sql, 'update "member_rank_states"')) {
                    $sent = true;
                    posix_kill(getmypid(), SIGTERM);
                    pcntl_signal_dispatch();
                }
            });

            return \Illuminate\Support\Facades\Artisan::call('v2:member-rank:work', [
                '--worker' => 'signal-test', '--limit' => '1', '--loop' => true,
            ]);
        }]);
        self::assertSame([0], $outcomes);
        $worker = app(V2MemberRankMonthlyWorker::class);
        self::assertSame('pending', $worker->progress()['job']->status);
        self::assertNull($worker->progress()['job']->lease_owner);
        self::assertSame(1, (int) $worker->progress()['job']->processed_count);
        self::assertGreaterThan(0, $worker->progress()['remaining']);
        $this->artisan('v2:member-rank:work', ['--worker' => 'resume-test', '--limit' => '100'])->assertSuccessful();
        self::assertSame(0, $worker->progress()['remaining']);
    }

    private function race(array $operations): array
    {
        $children = [];
        $startAt = microtime(true) + 0.25;
        DB::disconnect();
        foreach ($operations as $operation) {
            $path = tempnam(sys_get_temp_dir(), 'rank-month-race-');
            $processId = pcntl_fork();
            self::assertNotSame(-1, $processId);
            if ($processId === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1000);
                }
                DB::reconnect();
                DB::statement("SET lock_timeout = '10s'");
                try {
                    $result = ['status' => 'success', 'value' => $operation()];
                } catch (\Throwable $exception) {
                    $result = ['status' => get_class($exception).':'.$exception->getCode()];
                }
                file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR));
                DB::disconnect();
                exit(0);
            }
            $children[] = [$processId, $path];
        }
        $values = [];
        foreach ($children as [$processId, $path]) {
            pcntl_waitpid($processId, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('success', $result['status']);
            $values[] = $result['value'];
            unlink($path);
        }
        DB::reconnect();

        return $values;
    }
}
