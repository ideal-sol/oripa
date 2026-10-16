<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class ZMemberRankMonthlyPerformanceTest extends TestCase
{
    use MemberRankAccountingFixture;

    public function test_ten_thousand_users_commit_in_bounded_resumable_batches(): void
    {
        if (getenv('V2_RANK_MONTH_PERFORMANCE_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated disposable rank month performance database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_rankmonth_synthetic', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::table('member_rank_states')->count());
        $this->configureRankFixture();
        $this->enableRankFixture();
        $template = $this->rankUser()->getAttributes();
        unset($template['id']);
        $started = hrtime(true);
        for ($batch = 0; $batch < 20; $batch++) {
            $users = [];
            for ($offset = 0; $offset < 500; $offset++) {
                $email = 'monthly-load-'.($batch * 500 + $offset).'@example.test';
                $users[] = [...$template, 'public_id' => (string) Str::uuid7(), 'email_display' => $email, 'email_normalized' => $email,
                    'referral_code' => 'LP'.str_pad((string) ($batch * 500 + $offset), 10, '0', STR_PAD_LEFT)];
            }
            DB::table('users')->insert($users);
        }
        $userIds = DB::table('users')->where('email_normalized', 'like', 'monthly-load-%@example.test')->orderBy('id')->pluck('id')->all();
        foreach (array_chunk($userIds, 500) as $chunk) {
            DB::table('member_rank_states')->insert(array_map(fn (int $userId): array => [
                'user_id' => $userId, 'level' => 2 + ($userId % 6), 'baseline_month' => '2026-10-01',
                'baseline_level' => 2 + ($userId % 6), 'skip_count' => $userId % 7 === 0 ? 1 : 0,
                'created_at' => now(), 'updated_at' => now(),
            ], $chunk));
        }
        $fixtureSeconds = (hrtime(true) - $started) / 1e9;
        $this->rankClock('2026-10-31T15:00:01Z');
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void { $queryCount++; });
        $durations = [];
        $processed = 0;
        $started = hrtime(true);
        do {
            $batchStart = hrtime(true);
            $worker = app(V2MemberRankMonthlyWorker::class);
            $count = $worker->run('synthetic-load-'.count($durations), 100);
            $durations[] = (hrtime(true) - $batchStart) / 1e6;
            $processed += $count;
        } while ($count > 0);
        $seconds = (hrtime(true) - $started) / 1e9;
        $measuredQueries = $queryCount;
        self::assertSame(10000, $processed);
        self::assertSame(0, $worker->progress()['remaining']);
        self::assertSame('completed', $worker->progress()['job']->status);
        self::assertSame(10000, DB::table('member_rank_states')->where('last_judged_month', '2026-11-01')->count());
        self::assertSame(0, DB::table('member_rank_states')->where('skip_count', '>', 0)->count());
        self::assertSame(0, DB::table('member_rank_point_entries')->count());
        self::assertSame(0, DB::table('member_rank_achievements')->count());
        sort($durations);
        $result = [
            'users' => $processed, 'seconds' => $seconds, 'users_per_second' => $processed / $seconds,
            'batch_size' => 100, 'batches' => count($durations), 'batch_p50_ms' => $durations[(int) floor(count($durations) * .5)],
            'batch_p95_ms' => $durations[(int) floor(count($durations) * .95)], 'concurrent_workers' => 1,
            'peak_memory_bytes' => memory_get_peak_usage(true), 'queries' => $measuredQueries,
            'fixture_seconds_excluded' => $fixtureSeconds, 'outer_transaction' => false,
            'new_worker_each_batch' => true, 'target_600_seconds' => $seconds <= 600 ? 'PASS' : 'OPEN',
        ];
        fwrite(STDOUT, "\nMEMBER_RANK_MONTHLY_PERFORMANCE ".json_encode($result, JSON_THROW_ON_ERROR)."\n");
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
