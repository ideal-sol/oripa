<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\ProbabilityMode;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaCategory;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\GachaTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GachaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_active_and_sold_out_gachas(): void
    {
        $tag = GachaTag::factory()->create([
            'name' => '高還元',
            'slug' => 'high-return',
            'sort_order' => 1,
        ]);
        $hiddenTag = GachaTag::factory()->create([
            'name' => '非表示タグ',
            'slug' => 'hidden-tag',
            'sort_order' => 2,
            'is_active' => false,
        ]);
        $category = GachaCategory::factory()->create([
            'name' => 'Pokemon',
            'slug' => 'pokemon',
            'description' => 'Public category description.',
        ]);
        $active = Gacha::factory()->create([
            'category_id' => $category->id,
            'title' => 'Active Gacha',
            'status' => GachaStatus::Active,
            'sold_count' => 3,
            'total_count' => 10,
        ]);
        $active->tags()->sync([$tag->id, $hiddenTag->id]);
        $soldOut = Gacha::factory()->create([
            'title' => 'Sold Out Gacha',
            'status' => GachaStatus::SoldOut,
            'sold_count' => 10,
            'total_count' => 10,
            'start_at' => now()->addMinute(),
        ]);
        Gacha::factory()->create([
            'title' => 'Draft Gacha',
            'status' => GachaStatus::Draft,
        ]);

        $response = $this->getJson('/api/gachas');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.title', 'Active Gacha')
            ->assertJsonPath('data.0.category.description', 'Public category description.')
            ->assertJsonPath('data.0.remaining_count', 7)
            ->assertJsonPath('data.0.tags.0.id', $tag->id)
            ->assertJsonPath('data.0.tags.0.slug', 'high-return')
            ->assertJsonMissing(['slug' => 'hidden-tag'])
            ->assertJsonPath('data.1.id', $soldOut->id)
            ->assertJsonPath('data.1.status', GachaStatus::SoldOut->value)
            ->assertJsonPath('data.1.remaining_count', 0)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'title',
                    'slug',
                    'category',
                    'tags',
                    'price',
                    'total_count',
                    'sold_count',
                    'remaining_count',
                    'minimum_guarantee',
                    'status',
                ]],
                'links',
                'meta',
            ]);
    }

    public function test_public_tag_index_returns_active_tags_in_sort_order(): void
    {
        $second = GachaTag::factory()->create([
            'name' => '限定',
            'slug' => 'limited',
            'sort_order' => 20,
        ]);
        $first = GachaTag::factory()->create([
            'name' => '高還元',
            'slug' => 'high-return',
            'sort_order' => 10,
        ]);
        GachaTag::factory()->create([
            'name' => '非表示',
            'slug' => 'hidden',
            'sort_order' => 1,
            'is_active' => false,
        ]);

        $this->getJson('/api/gacha-tags')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.slug', 'high-return')
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.slug', 'limited')
            ->assertJsonMissing(['slug' => 'hidden']);
    }

    public function test_show_rejects_sold_out_gacha(): void
    {
        $gacha = Gacha::factory()->create([
            'status' => GachaStatus::SoldOut,
            'sold_count' => 10,
            'total_count' => 10,
        ]);

        $this->getJson("/api/gachas/{$gacha->id}")
            ->assertNotFound();
    }

    public function test_show_returns_ranks_prizes_and_stage_probabilities(): void
    {
        [$gacha, $rankS, $rankA, $prizeS, $prizeA] = $this->createPublishedTwoStageGacha();
        $tag = GachaTag::factory()->create([
            'name' => '初心者向け',
            'slug' => 'beginner',
        ]);
        $hiddenTag = GachaTag::factory()->create([
            'name' => '非表示タグ',
            'slug' => 'hidden-tag',
            'is_active' => false,
        ]);
        $gacha->tags()->sync([$tag->id, $hiddenTag->id]);

        $response = $this->getJson("/api/gachas/{$gacha->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $gacha->id)
            ->assertJsonPath('data.category.description', 'Apple category description.')
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonPath('data.tags.0.slug', 'beginner')
            ->assertJsonMissing(['slug' => 'hidden-tag'])
            ->assertJsonPath('data.probability_mode', ProbabilityMode::SoldCountStage->value)
            ->assertJsonPath('data.remaining_count', 5001)
            ->assertJsonPath('data.current_stage.stage_key', 'stage_1')
            ->assertJsonPath('data.next_stage.stage_key', 'stage_2')
            ->assertJsonPath('data.minimum_guarantee.stage_ppm.stage_1', 970000)
            ->assertJsonPath('data.minimum_guarantee.stage_ppm.stage_2', 930000)
            ->assertJsonCount(2, 'data.stages')
            ->assertJsonCount(2, 'data.ranks')
            ->assertJsonPath('data.ranks.0.id', $rankS->id)
            ->assertJsonPath('data.ranks.0.result_image_url', 'https://example.test/images/s-result.png')
            ->assertJsonPath('data.ranks.0.stage_total_ppm.stage_1', 10000)
            ->assertJsonPath('data.ranks.0.stage_total_ppm.stage_2', 20000)
            ->assertJsonPath('data.ranks.0.prizes.0.id', $prizeS->id)
            ->assertJsonPath('data.ranks.0.prizes.0.ppm.stage_1', 10000)
            ->assertJsonPath('data.ranks.0.prizes.0.ppm.stage_2', 20000)
            ->assertJsonPath('data.ranks.1.id', $rankA->id)
            ->assertJsonPath('data.ranks.1.stage_total_ppm.stage_1', 20000)
            ->assertJsonPath('data.ranks.1.stage_total_ppm.stage_2', 50000)
            ->assertJsonPath('data.ranks.1.prizes.0.id', $prizeA->id);
    }

    /**
     * @return array{0: Gacha, 1: GachaRank, 2: GachaRank, 3: GachaPrize, 4: GachaPrize}
     */
    private function createPublishedTwoStageGacha(): array
    {
        $category = GachaCategory::factory()->create([
            'name' => 'Apple',
            'slug' => 'apple',
            'description' => 'Apple category description.',
        ]);
        $gacha = Gacha::factory()->create([
            'category_id' => $category->id,
            'status' => GachaStatus::Active,
            'probability_mode' => ProbabilityMode::SoldCountStage,
            'sold_count' => 4999,
            'total_count' => 10000,
            'minimum_guarantee_value' => 10,
        ]);
        $rankS = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'result_image_url' => 'https://example.test/images/s-result.png',
            'sort_order' => 1,
        ]);
        $rankA = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'A',
            'sort_order' => 2,
        ]);
        $prizeS = GachaPrize::factory()->forGachaAndRank($gacha, $rankS)->create([
            'name' => 'Prize S',
            'sort_order' => 1,
        ]);
        $prizeA = GachaPrize::factory()->forGachaAndRank($gacha, $rankA)->create([
            'name' => 'Prize A',
            'sort_order' => 1,
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => 9999,
                'sort_order' => 1,
                'probabilities' => [
                    ['prize_id' => $prizeS->id, 'probability_ppm' => 10_000],
                    ['prize_id' => $prizeA->id, 'probability_ppm' => 20_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 970_000],
                ],
            ],
            [
                'stage_key' => 'stage_2',
                'name' => 'Stage 2',
                'min_draw_number' => 10000,
                'max_draw_number' => null,
                'sort_order' => 2,
                'probabilities' => [
                    ['prize_id' => $prizeS->id, 'probability_ppm' => 20_000],
                    ['prize_id' => $prizeA->id, 'probability_ppm' => 50_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 930_000],
                ],
            ],
        ], AdminUser::factory()->create());

        return [$gacha->refresh(), $rankS, $rankA, $prizeS, $prizeA];
    }
}
