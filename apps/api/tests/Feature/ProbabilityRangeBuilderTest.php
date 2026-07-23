<?php

namespace Tests\Feature;

use App\Domain\Probability\Services\ProbabilityRangeBuilder;
use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ProbabilityRangeBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_ranges_and_picks_entries_at_boundaries(): void
    {
        [$gacha, $prize, $admin] = $this->createGachaFixture();

        $version = app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 100_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 900_000],
                ],
            ],
        ], $admin);

        $stage = $version->stages()->firstOrFail();
        $range = app(ProbabilityRangeBuilder::class)->build($stage);

        $this->assertSame(1_000_000, $range->totalPpm());
        $this->assertCount(2, $range->entries);
        $this->assertTrue($range->pick(0)->isPrize());
        $this->assertTrue($range->pick(99_999)->isPrize());
        $this->assertTrue($range->pick(100_000)->isMinimumGuarantee);
        $this->assertTrue($range->pick(999_999)->isMinimumGuarantee);
    }

    public function test_it_absorbs_sold_out_and_inactive_prize_ppm_into_minimum_guarantee(): void
    {
        [$gacha, $activePrize, $admin, $rank] = $this->createGachaFixture();
        $soldOutPrize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'max_win_count' => 1,
            'won_count' => 1,
        ]);
        $inactivePrize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'is_active' => false,
        ]);

        $version = app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $soldOutPrize->id, 'probability_ppm' => 100_000],
                    ['prize_id' => $activePrize->id, 'probability_ppm' => 200_000],
                    ['prize_id' => $inactivePrize->id, 'probability_ppm' => 50_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 650_000],
                ],
            ],
        ], $admin);

        $stage = $version->stages()->firstOrFail();
        $range = app(ProbabilityRangeBuilder::class)->build($stage);

        $this->assertSame(1_000_000, $range->totalPpm());
        $this->assertCount(2, $range->entries);

        $prizeEntry = $range->entries[0];
        $minimumGuaranteeEntry = $range->entries[1];

        $this->assertSame($activePrize->id, $prizeEntry->prizeId);
        $this->assertSame(200_000, $prizeEntry->probabilityPpm);
        $this->assertTrue($minimumGuaranteeEntry->isMinimumGuarantee);
        $this->assertSame(800_000, $minimumGuaranteeEntry->probabilityPpm);
        $this->assertSame($activePrize->id, $range->pick(199_999)->prizeId);
        $this->assertTrue($range->pick(200_000)->isMinimumGuarantee);
    }

    public function test_it_throws_when_stage_has_no_minimum_guarantee_row(): void
    {
        [$gacha, $prize] = $this->createGachaFixture();

        $version = GachaProbabilityVersion::query()->create([
            'gacha_id' => $gacha->id,
            'version_number' => 1,
            'status' => ProbabilityVersionStatus::Draft,
            'snapshot_hash' => hash('sha256', 'missing-minimum-guarantee'),
        ]);
        $stage = $version->stages()->create([
            'stage_key' => 'stage_1',
            'name' => 'Stage 1',
            'condition_type' => 'sold_count',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
        ]);
        $stage->probabilities()->create([
            'prize_id' => $prize->id,
            'is_minimum_guarantee' => false,
            'probability_ppm' => 1_000_000,
        ]);

        $this->expectException(LogicException::class);

        app(ProbabilityRangeBuilder::class)->build($stage);
    }

    public function test_probability_range_rejects_random_values_outside_ppm_bounds(): void
    {
        [$gacha, $prize, $admin] = $this->createGachaFixture();

        $version = app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 100_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 900_000],
                ],
            ],
        ], $admin);

        $range = app(ProbabilityRangeBuilder::class)->build($version->stages()->firstOrFail());

        $this->expectException(LogicException::class);

        $range->pick(1_000_000);
    }

    /**
     * @return array{0: Gacha, 1: GachaPrize, 2: AdminUser, 3: GachaRank}
     */
    private function createGachaFixture(): array
    {
        $admin = AdminUser::factory()->create();
        $gacha = Gacha::factory()->create(['total_count' => 10000]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'max_win_count' => 10,
            'won_count' => 0,
            'is_active' => true,
        ]);

        return [$gacha, $prize, $admin, $rank];
    }
}
