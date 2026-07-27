<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Services\BulkDrawSummaryService;
use App\Domain\Gacha\Services\DrawService;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Http\Resources\BulkDrawRequestResource;
use App\Models\AdminUser;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\PointLot;
use App\Models\User;
use App\Models\UserPrize;
use App\Models\Wallet;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkDrawConcentratedPerformanceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_same_gacha_concentrated_draws_meet_integrity_and_latency_targets(): void
    {
        $report = [];

        foreach ([5, 10, 20] as $userCount) {
            [$users, $gacha, $prizes] = $this->createFixture($userCount);
            $evidenceDirectory = storage_path("framework/testing/bulk-draw-a2-{$userCount}-".bin2hex(random_bytes(4)));
            mkdir($evidenceDirectory, 0700, true);
            $children = [];
            $statuses = [];
            $lockSamples = [];
            $peakConnections = 0;
            $sampleIntervalUs = 10_000;

            foreach ($users as $index => $user) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->fail('Unable to fork concentrated draw process.');
                }

                if ($pid === 0) {
                    try {
                        DB::disconnect();
                        DB::reconnect();
                        $backendPid = (int) DB::scalar('select pg_backend_pid()');
                        file_put_contents("{$evidenceDirectory}/{$index}.pid", (string) $backendPid);
                        $metrics = [
                            'select' => 0,
                            'for_update' => 0,
                            'insert' => 0,
                            'update' => 0,
                            'other' => 0,
                            'query_count' => 0,
                            'query_time_ms' => 0.0,
                        ];
                        $active = true;
                        DB::listen(function (QueryExecuted $query) use (&$metrics, &$active): void {
                            if (! $active) {
                                return;
                            }

                            $sql = strtoupper(ltrim($query->sql));
                            $metrics['query_count']++;
                            $metrics['query_time_ms'] += $query->time;

                            if (str_contains($sql, 'FOR UPDATE')) {
                                $metrics['for_update']++;
                            } elseif (str_starts_with($sql, 'SELECT')) {
                                $metrics['select']++;
                            } elseif (str_starts_with($sql, 'INSERT')) {
                                $metrics['insert']++;
                            } elseif (str_starts_with($sql, 'UPDATE')) {
                                $metrics['update']++;
                            } else {
                                $metrics['other']++;
                            }
                        });

                        if (function_exists('memory_reset_peak_usage')) {
                            memory_reset_peak_usage();
                        }
                        $memoryBefore = memory_get_usage(true);
                        $startedAt = microtime(true);
                        $drawRequest = app(DrawService::class)->draw(
                            User::query()->findOrFail($user->id),
                            Gacha::query()->findOrFail($gacha->id),
                            1_000,
                            "concentrated-{$userCount}-{$index}",
                        );
                        $completedAt = microtime(true);
                        $active = false;
                        $drawRequest->bulkSummary = app(BulkDrawSummaryService::class)->build($drawRequest);
                        $response = (new BulkDrawRequestResource($drawRequest))->resolve(Request::create('/'));
                        file_put_contents("{$evidenceDirectory}/{$index}.json", json_encode([
                            'index' => $index,
                            'backend_pid' => $backendPid,
                            'started_at' => gmdate('c', (int) $startedAt),
                            'completed_at' => gmdate('c', (int) $completedAt),
                            'elapsed_ms' => round(($completedAt - $startedAt) * 1_000, 3),
                            'transaction_ms' => (int) $drawRequest->processing_duration_ms,
                            'queries' => $metrics,
                            'peak_memory_delta_bytes' => max(0, memory_get_peak_usage(true) - $memoryBefore),
                            'response_bytes' => strlen(json_encode($response, JSON_THROW_ON_ERROR)),
                        ], JSON_THROW_ON_ERROR));
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents(
                            "{$evidenceDirectory}/{$index}.error",
                            $exception::class.': '.$exception->getMessage(),
                        );
                        exit(1);
                    }
                }

                $children[$pid] = $index;
            }

            while ($children !== []) {
                $backendPids = [];

                foreach (range(0, $userCount - 1) as $index) {
                    $pidFile = "{$evidenceDirectory}/{$index}.pid";

                    if (is_file($pidFile)) {
                        $backendPids[$index] = (int) file_get_contents($pidFile);
                    }
                }

                if ($backendPids !== []) {
                    $activity = DB::table('pg_stat_activity')
                        ->select(['pid', 'wait_event_type'])
                        ->whereIn('pid', array_values($backendPids))
                        ->get()
                        ->keyBy('pid');

                    foreach ($backendPids as $index => $backendPid) {
                        if ($activity->get($backendPid)?->wait_event_type === 'Lock') {
                            $lockSamples[$index] = ($lockSamples[$index] ?? 0) + 1;
                        }
                    }
                }

                $peakConnections = max(
                    $peakConnections,
                    (int) DB::table('pg_stat_activity')->where('datname', DB::getDatabaseName())->count(),
                );

                foreach (array_keys($children) as $pid) {
                    $result = pcntl_waitpid($pid, $status, WNOHANG);

                    if ($result === $pid) {
                        $statuses[$children[$pid]] = pcntl_wexitstatus($status);
                        unset($children[$pid]);
                    }
                }

                usleep($sampleIntervalUs);
            }

            DB::disconnect();
            DB::reconnect();
            $requests = [];

            foreach (range(0, $userCount - 1) as $index) {
                $this->assertSame(
                    0,
                    $statuses[$index] ?? null,
                    is_file("{$evidenceDirectory}/{$index}.error")
                        ? file_get_contents("{$evidenceDirectory}/{$index}.error")
                        : "Child {$index} failed.",
                );
                $metrics = json_decode(
                    file_get_contents("{$evidenceDirectory}/{$index}.json"),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                $metrics['lock_wait_ms'] = round(($lockSamples[$index] ?? 0) * $sampleIntervalUs / 1_000, 3);
                $this->assertLessThanOrEqual(100, $metrics['queries']['query_count']);
                $this->assertLessThanOrEqual(20, $metrics['queries']['insert']);
                $this->assertGreaterThan(0, $metrics['queries']['for_update']);
                $this->assertLessThan(128 * 1024 * 1024, $metrics['peak_memory_delta_bytes']);
                $requests[] = $metrics;
            }

            usort($requests, fn (array $left, array $right): int => $left['index'] <=> $right['index']);
            $elapsed = array_column($requests, 'elapsed_ms');
            sort($elapsed);
            $totalDraws = $userCount * 1_000;
            $historyCount = DrawResult::query()->where('gacha_id', $gacha->id)->count();
            $userPrizeCount = UserPrize::query()->where('gacha_id', $gacha->id)->count();
            $pointLotCount = PointLot::query()
                ->whereIn('user_id', $users->pluck('id'))
                ->where('source_type', PointLotSourceType::MinimumGuarantee)
                ->count();
            $pointDelta = $users->sum(
                fn (User $user): int => 1_000 - (int) $user->wallet->refresh()->paid_balance,
            );
            $inventoryDelta = $prizes->sum(
                fn (GachaPrize $prize): int => (int) $prize->refresh()->won_count,
            );
            $distinctSequences = DrawResult::query()
                ->where('gacha_id', $gacha->id)
                ->distinct()
                ->count('draw_sequence_number');

            $this->assertSame($totalDraws, $historyCount);
            $this->assertSame($totalDraws, $userPrizeCount + $pointLotCount);
            $this->assertSame($totalDraws, $pointDelta);
            $this->assertSame($totalDraws, (int) $gacha->refresh()->sold_count);
            $this->assertSame($totalDraws, $distinctSequences);
            $this->assertSame($userPrizeCount, $inventoryDelta);

            $report[(string) $userCount] = [
                'p50_ms' => $elapsed[(int) floor(($userCount - 1) * 0.50)],
                'p95_ms' => $elapsed[(int) ceil($userCount * 0.95) - 1],
                'first_completed_ms' => min($elapsed),
                'last_completed_ms' => max($elapsed),
                'peak_connections' => $peakConnections,
                'deadlock_or_serialization_failures' => array_sum(
                    array_map(fn (int $status): int => $status === 0 ? 0 : 1, $statuses),
                ),
                'created' => [
                    'draw_results' => $historyCount,
                    'user_prizes' => $userPrizeCount,
                    'point_lots' => $pointLotCount,
                ],
                'requests' => $requests,
            ];

            if ($userCount === 10) {
                $this->assertLessThanOrEqual(20_000, max($elapsed));
            }

            foreach (glob("{$evidenceDirectory}/*") ?: [] as $path) {
                unlink($path);
            }
            rmdir($evidenceDirectory);
        }

        fwrite(STDOUT, 'BULK_DRAW_CONCENTRATED='.json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function createFixture(int $userCount): array
    {
        $drawCapacity = $userCount * 1_000;
        $users = User::factory()->count($userCount)->create();
        $gacha = Gacha::factory()->create([
            'price' => 1,
            'total_count' => $drawCapacity,
            'sold_count' => 0,
            'status' => GachaStatus::Active,
            'minimum_guarantee_value' => 1,
        ]);
        $prizes = collect();
        $probabilities = [];

        foreach (['SS', 'S', 'A', 'B'] as $sortOrder => $rankKey) {
            $rank = GachaRank::factory()->create([
                'gacha_id' => $gacha->id,
                'rank_key' => $rankKey,
                'display_name' => $rankKey,
                'sort_order' => $sortOrder,
            ]);
            $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
                'max_win_count' => $drawCapacity,
                'won_count' => 0,
                'is_active' => true,
            ]);
            $prizes->push($prize);
            $probabilities[] = ['prize_id' => $prize->id, 'probability_ppm' => 100_000];
        }

        foreach ($users as $user) {
            Wallet::query()->create([
                'user_id' => $user->id,
                'paid_balance' => 1_000,
                'free_balance' => 0,
            ]);
            PointLot::query()->create([
                'user_id' => $user->id,
                'point_type' => PointType::Paid,
                'granted_amount' => 1_000,
                'remaining_amount' => 1_000,
                'source_type' => PointLotSourceType::Purchase,
                'source_id' => null,
                'granted_at' => now()->subDay(),
                'expire_at' => null,
            ]);
        }

        $probabilities[] = ['is_minimum_guarantee' => true, 'probability_ppm' => 600_000];
        app(ProbabilityVersionPublisher::class)->publish($gacha, [[
            'stage_key' => 'stage_1',
            'name' => 'Stage 1',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'probabilities' => $probabilities,
        ]], AdminUser::factory()->create());

        return [$users, $gacha, $prizes];
    }
}
