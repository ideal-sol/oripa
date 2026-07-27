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

class BulkDrawPerformanceTest extends TestCase
{
    use DatabaseMigrations;

    private const API_TIMEOUT_MS = 60_000;

    public function test_bulk_draw_100_and_1000_meet_the_synchronous_performance_budget(): void
    {
        $activeMetrics = null;
        DB::listen(function (QueryExecuted $query) use (&$activeMetrics): void {
            if ($activeMetrics === null) {
                return;
            }

            $activeMetrics['query_count']++;
            $activeMetrics['query_time_ms'] += $query->time;
        });

        $report = [];

        foreach ([100, 1_000] as $drawCount) {
            $samples = [];

            for ($iteration = 1; $iteration <= 5; $iteration++) {
                [$user, $gacha, $prizes] = $this->createRepresentativeFixture($drawCount);
                $memoryBefore = memory_get_usage(true);
                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }
                $beforeCounts = $this->recordCounts();
                $activeMetrics = ['query_count' => 0, 'query_time_ms' => 0.0];
                $startedAt = hrtime(true);

                $drawRequest = app(DrawService::class)->draw(
                    $user,
                    $gacha,
                    $drawCount,
                    "performance-{$drawCount}-{$iteration}",
                );
                $drawRequest->bulkSummary = app(BulkDrawSummaryService::class)->build($drawRequest);
                $response = (new BulkDrawRequestResource($drawRequest))->resolve(Request::create('/'));
                $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
                $afterCounts = $this->recordCounts();
                $createdRecords = [];

                foreach ($afterCounts as $table => $afterCount) {
                    $createdRecords[$table] = $afterCount - $beforeCounts[$table];
                }

                $sample = [
                    'iteration' => $iteration,
                    'elapsed_ms' => round($elapsedMs, 3),
                    'transaction_ms' => (int) $drawRequest->processing_duration_ms,
                    'query_count' => $activeMetrics['query_count'],
                    'query_time_ms' => round($activeMetrics['query_time_ms'], 3),
                    'lock_wait_ms' => 0,
                    'peak_memory_delta_bytes' => max(0, memory_get_peak_usage(true) - $memoryBefore),
                    'response_bytes' => strlen(json_encode($response, JSON_THROW_ON_ERROR)),
                    'created_records' => $createdRecords,
                    'point_delta' => $drawCount - (int) $user->wallet->refresh()->paid_balance,
                    'sold_count_delta' => (int) $gacha->refresh()->sold_count,
                    'prize_inventory_delta' => $prizes->sum(fn (GachaPrize $prize): int => (int) $prize->refresh()->won_count),
                ];
                $activeMetrics = null;

                $this->assertSame($drawCount, $sample['created_records']['draw_results']);
                $this->assertSame($drawCount, $sample['point_delta']);
                $this->assertSame($drawCount, $sample['sold_count_delta']);
                $this->assertSame($drawCount, $sample['created_records']['user_prizes'] + $sample['created_records']['point_lots']);
                $this->assertLessThan(self::API_TIMEOUT_MS / 2, $elapsedMs);

                $samples[] = $sample;
            }

            $elapsed = array_column($samples, 'elapsed_ms');
            sort($elapsed);
            $report[(string) $drawCount] = [
                'p50_ms' => $elapsed[2],
                'p95_ms' => $elapsed[4],
                'api_timeout_ms' => self::API_TIMEOUT_MS,
                'target_ms' => self::API_TIMEOUT_MS / 2,
                'samples' => $samples,
            ];
        }

        fwrite(STDOUT, 'BULK_DRAW_PERFORMANCE='.json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * @return array{0: User, 1: Gacha, 2: \Illuminate\Support\Collection<int, GachaPrize>}
     */
    private function createRepresentativeFixture(int $drawCount): array
    {
        $user = User::factory()->create();
        $gacha = Gacha::factory()->create([
            'price' => 1,
            'total_count' => $drawCount,
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
                'max_win_count' => $drawCount,
                'won_count' => 0,
                'is_active' => true,
            ]);
            $prizes->push($prize);
            $probabilities[] = ['prize_id' => $prize->id, 'probability_ppm' => 100_000];
        }

        $probabilities[] = ['is_minimum_guarantee' => true, 'probability_ppm' => 600_000];
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => $drawCount,
            'free_balance' => 0,
        ]);
        PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => PointType::Paid,
            'granted_amount' => $drawCount,
            'remaining_amount' => $drawCount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => null,
            'granted_at' => now()->subDay(),
            'expire_at' => null,
        ]);
        app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => $probabilities,
            ],
        ], AdminUser::factory()->create());

        return [$user, $gacha, $prizes];
    }

    /**
     * @return array<string, int>
     */
    private function recordCounts(): array
    {
        return [
            'draw_results' => DrawResult::query()->count(),
            'user_prizes' => UserPrize::query()->count(),
            'point_lots' => PointLot::query()->count(),
        ];
    }
}
