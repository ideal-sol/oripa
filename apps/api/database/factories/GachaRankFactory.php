<?php

namespace Database\Factories;

use App\Models\Gacha;
use App\Models\GachaRank;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GachaRank> */
class GachaRankFactory extends Factory
{
    protected $model = GachaRank::class;

    public function definition(): array
    {
        $rank = fake()->randomElement(['SS', 'S', 'A', 'B', 'C']);

        return [
            'gacha_id' => Gacha::factory(),
            'rank_key' => $rank.'-'.fake()->unique()->numberBetween(1, 999999),
            'display_name' => 'ランク'.$rank,
            'description' => fake()->sentence(),
            'image_url' => 'https://example.test/images/rank.png',
            'draw_video_url' => 'https://example.test/videos/rank.mp4',
            'result_image_url' => 'https://example.test/images/rank-result.png',
            'sort_order' => fake()->numberBetween(0, 100),
            'is_visible' => true,
        ];
    }
}
