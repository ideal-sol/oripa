<?php

namespace Database\Factories;

use App\Models\TopBanner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TopBanner> */
class TopBannerFactory extends Factory
{
    protected $model = TopBanner::class;

    public function definition(): array
    {
        return [
            'image_url' => fake()->imageUrl(1200, 480),
            'link_url' => fake()->url(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
