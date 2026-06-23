<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Services\DrawService;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Point\Exceptions\InsufficientPointsException;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaRank;
use App\Models\PointLot;
use App\Models\RankAsset;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrawServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_draws_point_back_and_grants_minimum_guarantee_free_points(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100, minimumGuaranteeValue: 10);
        $this->createWalletWithPaidLot($user, 200);
        $this->publishSingleStage($gacha, $prize, prizePpm: 0, minimumGuaranteePpm: 1_000_000);

        $request = app(DrawService::class)->draw($user, $gacha, 2, 'draw-point-back');

        $this->assertSame(DrawRequestStatus::Completed, $request->status);
        $this->assertSame(2, $request->results->count());
        $this->assertSame(2, $gacha->refresh()->sold_count);
        $this->assertSame(0, $user->wallet->refresh()->paid_balance);
        $this->assertSame(20, $user->wallet->free_balance);
        $this->assertDatabaseCount('draw_results', 2);
        $this->assertDatabaseCount('user_prizes', 0);
        $this->assertDatabaseHas('draw_results', [
            'draw_sequence_number' => 1,
            'result_type' => DrawResultType::PointBack->value,
            'granted_point' => 10,
        ]);
        $this->assertDatabaseHas('draw_results', [
            'draw_sequence_number' => 2,
            'result_type' => DrawResultType::PointBack->value,
            'granted_point' => 10,
        ]);
        $this->assertSame(2, PointLot::query()->where('point_type', PointType::Free->value)->count());
    }

    public function test_it_draws_prize_updates_inventory_and_creates_user_prize(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100);
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $request = app(DrawService::class)->draw($user, $gacha, 1, 'draw-prize');

        $result = $request->results->first();

        $this->assertSame(DrawResultType::Prize, $result->result_type);
        $this->assertSame($prize->id, $result->prize_id);
        $this->assertSame(1, $prize->refresh()->won_count);
        $this->assertSame(1, $gacha->refresh()->sold_count);
        $this->assertSame(0, $user->wallet->refresh()->paid_balance);
        $this->assertDatabaseHas('user_prizes', [
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $result->id,
            'status' => 'stored',
        ]);
    }

    public function test_it_stores_random_rank_presentation_urls_for_prize_result(): void
    {
        [$user, $gacha, $prize, $rank] = $this->createDrawableFixture(price: 100);
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $imageAssets = [
            RankAsset::query()->create(['title' => 'S image 1', 'asset_type' => 'image', 'url' => 'https://example.test/s-1.png', 'is_active' => true]),
            RankAsset::query()->create(['title' => 'S image 2', 'asset_type' => 'image', 'url' => 'https://example.test/s-2.png', 'is_active' => true]),
        ];
        $videoAssets = [
            RankAsset::query()->create(['title' => 'S video 1', 'asset_type' => 'video', 'url' => 'https://example.test/s-1.mp4', 'is_active' => true]),
            RankAsset::query()->create(['title' => 'S video 2', 'asset_type' => 'video', 'url' => 'https://example.test/s-2.mp4', 'is_active' => true]),
        ];

        foreach ($imageAssets as $index => $asset) {
            $rank->rankImageAssets()->attach($asset->id, ['usage_type' => 'image', 'sort_order' => $index]);
        }

        foreach ($videoAssets as $index => $asset) {
            $rank->drawVideoAssets()->attach($asset->id, ['usage_type' => 'video', 'sort_order' => $index]);
        }

        $request = app(DrawService::class)->draw($user, $gacha, 1, 'draw-prize-presentation');
        $result = $request->results->first();

        $this->assertContains($result->selected_rank_image_url, ['https://example.test/s-1.png', 'https://example.test/s-2.png']);
        $this->assertContains($result->selected_draw_video_url, ['https://example.test/s-1.mp4', 'https://example.test/s-2.mp4']);
        $this->assertDatabaseHas('draw_results', [
            'id' => $result->id,
            'selected_rank_image_url' => $result->selected_rank_image_url,
            'selected_draw_video_url' => $result->selected_draw_video_url,
        ]);
    }

    public function test_it_returns_existing_completed_request_for_same_idempotency_key(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100, minimumGuaranteeValue: 10);
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 0, minimumGuaranteePpm: 1_000_000);

        $first = app(DrawService::class)->draw($user, $gacha, 1, 'same-key');
        $second = app(DrawService::class)->draw($user, $gacha, 1, 'same-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $gacha->refresh()->sold_count);
        $this->assertSame(1, DrawResult::query()->count());
    }

    public function test_it_applies_stage_per_draw_when_multi_draw_crosses_boundary(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100, totalCount: 10000, soldCount: 9998);
        $this->createWalletWithPaidLot($user, 200);
        $version = $this->publishTwoStages($gacha, $prize);
        $stageIds = $version->stages()->orderBy('min_draw_number')->pluck('id')->all();

        app(DrawService::class)->draw($user, $gacha, 2, 'cross-boundary');

        $results = DrawResult::query()->orderBy('draw_sequence_number')->get();

        $this->assertSame([9999, 10000], $results->pluck('draw_sequence_number')->all());
        $this->assertSame([$stageIds[0], $stageIds[1]], $results->pluck('probability_version_stage_id')->all());
        $this->assertSame(10000, $gacha->refresh()->sold_count);
        $this->assertSame(GachaStatus::SoldOut, $gacha->status);
    }

    public function test_it_rolls_back_when_points_are_insufficient(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100);
        $this->createWalletWithPaidLot($user, 99);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $this->expectException(InsufficientPointsException::class);

        try {
            app(DrawService::class)->draw($user, $gacha, 1, 'insufficient');
        } finally {
            $this->assertSame(0, $gacha->refresh()->sold_count);
            $this->assertSame(0, DrawResult::query()->count());
            $this->assertSame(0, $prize->refresh()->won_count);
        }
    }

    /**
     * @return array{0: User, 1: Gacha, 2: GachaPrize, 3: GachaRank}
     */
    private function createDrawableFixture(
        int $price = 100,
        int $minimumGuaranteeValue = 10,
        int $totalCount = 10000,
        int $soldCount = 0,
    ): array {
        $user = User::factory()->create();
        $gacha = Gacha::factory()->create([
            'price' => $price,
            'total_count' => $totalCount,
            'sold_count' => $soldCount,
            'status' => GachaStatus::Active,
            'minimum_guarantee_value' => $minimumGuaranteeValue,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'max_win_count' => 10,
            'won_count' => 0,
            'is_active' => true,
        ]);

        return [$user, $gacha, $prize, $rank];
    }

    private function createWalletWithPaidLot(User $user, int $amount): void
    {
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => $amount,
            'free_balance' => 0,
        ]);

        PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => PointType::Paid,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => null,
            'granted_at' => now()->subDay(),
            'expire_at' => null,
        ]);
    }

    private function publishSingleStage(Gacha $gacha, GachaPrize $prize, int $prizePpm, int $minimumGuaranteePpm): GachaProbabilityVersion
    {
        return app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => $prizePpm],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => $minimumGuaranteePpm],
                ],
            ],
        ], AdminUser::factory()->create());
    }

    private function publishTwoStages(Gacha $gacha, GachaPrize $prize): GachaProbabilityVersion
    {
        return app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => 9999,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 0],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 1_000_000],
                ],
            ],
            [
                'stage_key' => 'stage_2',
                'name' => 'Stage 2',
                'min_draw_number' => 10000,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 0],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 1_000_000],
                ],
            ],
        ], AdminUser::factory()->create());
    }
}
