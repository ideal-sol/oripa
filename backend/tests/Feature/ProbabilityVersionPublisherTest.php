<?php

namespace Tests\Feature;

use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use App\Domain\Probability\Exceptions\ProbabilityValidationException;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ProbabilityVersionPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_probability_version_and_updates_current_version(): void
    {
        [$gacha, $prizes, $admin] = $this->createGachaWithPrizes();

        $version = app(ProbabilityVersionPublisher::class)->publish(
            $gacha,
            $this->validStages($prizes),
            $admin,
            'Initial publish.'
        );

        $this->assertSame(ProbabilityVersionStatus::Published, $version->status);
        $this->assertSame(1, $version->version_number);
        $this->assertNotNull($version->snapshot_hash);
        $this->assertNotNull($version->published_at);
        $this->assertSame($version->id, $gacha->refresh()->current_probability_version_id);
        $this->assertDatabaseCount('gacha_probability_version_stages', 1);
        $this->assertDatabaseCount('gacha_probability_version_prize_probabilities', 4);
    }

    public function test_it_rejects_stage_total_that_is_not_exactly_one_million_ppm(): void
    {
        [$gacha, $prizes, $admin] = $this->createGachaWithPrizes();
        $stages = $this->validStages($prizes);
        $stages[0]['probabilities'][3]['probability_ppm'] = 888_999;

        $this->expectException(ProbabilityValidationException::class);

        app(ProbabilityVersionPublisher::class)->publish($gacha, $stages, $admin);
    }

    public function test_it_rejects_stage_range_gaps(): void
    {
        [$gacha, $prizes, $admin] = $this->createGachaWithPrizes();
        $stages = $this->validStages($prizes);
        $stages[0]['max_draw_number'] = 100;
        $stages[] = [
            'stage_key' => 'stage_2',
            'name' => 'Stage 2',
            'min_draw_number' => 102,
            'max_draw_number' => null,
            'probabilities' => $stages[0]['probabilities'],
        ];

        $this->expectException(ProbabilityValidationException::class);

        app(ProbabilityVersionPublisher::class)->publish($gacha, $stages, $admin);
    }

    public function test_published_probability_version_is_immutable(): void
    {
        [$gacha, $prizes, $admin] = $this->createGachaWithPrizes();
        $version = app(ProbabilityVersionPublisher::class)->publish($gacha, $this->validStages($prizes), $admin);

        $this->expectException(LogicException::class);

        $version->update(['change_reason' => 'Direct edit must fail.']);
    }

    public function test_published_stage_and_probability_rows_are_immutable(): void
    {
        [$gacha, $prizes, $admin] = $this->createGachaWithPrizes();
        $version = app(ProbabilityVersionPublisher::class)->publish($gacha, $this->validStages($prizes), $admin);
        $stage = $version->stages()->firstOrFail();
        $probability = $stage->probabilities()->where('is_minimum_guarantee', false)->firstOrFail();

        try {
            $stage->update(['name' => 'Direct edit must fail.']);
            $this->fail('Published stage update did not throw.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->expectException(LogicException::class);

        $probability->update(['probability_ppm' => 1]);
    }

    /**
     * @return array{0: Gacha, 1: list<GachaPrize>, 2: AdminUser}
     */
    private function createGachaWithPrizes(): array
    {
        $admin = AdminUser::factory()->create();
        $gacha = Gacha::factory()->create([
            'total_count' => 10000,
            'minimum_guarantee_value' => 10,
        ]);

        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);

        $prizes = [
            GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create(['name' => 'Prize 1']),
            GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create(['name' => 'Prize 2']),
            GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create(['name' => 'Prize 3']),
        ];

        return [$gacha, $prizes, $admin];
    }

    /**
     * @param list<GachaPrize> $prizes
     * @return list<array<string, mixed>>
     */
    private function validStages(array $prizes): array
    {
        return [
            [
                'stage_key' => 'stage_1',
                'name' => 'Default',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prizes[0]->id, 'probability_ppm' => 1_000],
                    ['prize_id' => $prizes[1]->id, 'probability_ppm' => 10_000],
                    ['prize_id' => $prizes[2]->id, 'probability_ppm' => 100_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 889_000],
                ],
            ],
        ];
    }
}
