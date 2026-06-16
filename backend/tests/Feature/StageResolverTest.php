<?php

namespace Tests\Feature;

use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use App\Domain\Probability\Exceptions\ProbabilityStageNotFoundException;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Domain\Probability\Services\StageResolver;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_stage_by_draw_sequence_number_boundaries(): void
    {
        [$gacha, $prize, $admin] = $this->createGachaFixture();

        $version = app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => 9999,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 1_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 999_000],
                ],
            ],
            [
                'stage_key' => 'stage_2',
                'name' => 'Stage 2',
                'min_draw_number' => 10000,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 2_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 998_000],
                ],
            ],
        ], $admin);

        $resolver = app(StageResolver::class);

        $this->assertSame('stage_1', $resolver->resolve($version->id, 1)->stage_key);
        $this->assertSame('stage_1', $resolver->resolve($version->id, 9999)->stage_key);
        $this->assertSame('stage_2', $resolver->resolve($version->id, 10000)->stage_key);
        $this->assertSame('stage_2', $resolver->resolve($version->id, 10001)->stage_key);
    }

    public function test_it_rejects_draw_sequence_numbers_less_than_one(): void
    {
        $this->expectException(ProbabilityStageNotFoundException::class);

        app(StageResolver::class)->resolve(1, 0);
    }

    public function test_it_ignores_draft_probability_versions(): void
    {
        [$gacha] = $this->createGachaFixture();

        $version = GachaProbabilityVersion::query()->create([
            'gacha_id' => $gacha->id,
            'version_number' => 1,
            'status' => ProbabilityVersionStatus::Draft,
            'snapshot_hash' => hash('sha256', 'draft'),
        ]);

        $version->stages()->create([
            'stage_key' => 'stage_1',
            'name' => 'Draft Stage',
            'condition_type' => 'sold_count',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
        ]);

        $this->expectException(ProbabilityStageNotFoundException::class);

        app(StageResolver::class)->resolve($version->id, 1);
    }

    public function test_it_throws_when_no_published_stage_covers_the_sequence(): void
    {
        [$gacha, $prize, $admin] = $this->createGachaFixture();

        $version = app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => 10000,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 1_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 999_000],
                ],
            ],
        ], $admin);

        $this->expectException(ProbabilityStageNotFoundException::class);

        app(StageResolver::class)->resolve($version->id, 10001);
    }

    /**
     * @return array{0: Gacha, 1: GachaPrize, 2: AdminUser}
     */
    private function createGachaFixture(): array
    {
        $admin = AdminUser::factory()->create();
        $gacha = Gacha::factory()->create(['total_count' => 10000]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create();

        return [$gacha, $prize, $admin];
    }
}
