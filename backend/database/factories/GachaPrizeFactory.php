<?php

namespace Database\Factories;

use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GachaPrize> */
class GachaPrizeFactory extends Factory
{
    protected $model = GachaPrize::class;

    public function definition(): array
    {
        return [
            'gacha_id' => Gacha::factory(),
            'rank_id' => GachaRank::factory(),
            'name' => fake()->words(2, true),
            'image_url' => 'https://example.test/images/prize.png',
            'max_win_count' => 10,
            'won_count' => 0,
            'cost_price' => fake()->numberBetween(1000, 50000),
            'display_price' => fake()->numberBetween(2000, 60000),
            'exchange_point' => fake()->numberBetween(100, 10000),
            'condition' => '新品',
            'is_active' => true,
            'is_visible' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function forGachaAndRank(Gacha $gacha, GachaRank $rank): self
    {
        return $this->state([
            'gacha_id' => $gacha->id,
            'rank_id' => $rank->id,
        ]);
    }
}
