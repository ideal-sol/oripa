<?php

namespace Database\Factories;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
use App\Domain\Gacha\Enums\ProbabilityMode;
use App\Models\Gacha;
use App\Models\GachaCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Gacha> */
class GachaFactory extends Factory
{
    protected $model = Gacha::class;

    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1000, 9999),
            'category_id' => GachaCategory::factory(),
            'price' => 500,
            'total_count' => 10000,
            'sold_count' => 0,
            'probability_mode' => ProbabilityMode::Single->value,
            'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
            'minimum_guarantee_value' => 10,
            'minimum_guarantee_cost' => 10,
            'status' => GachaStatus::Draft->value,
            'start_at' => now(),
            'end_at' => now()->addMonth(),
            'description' => fake()->paragraph(),
            'caution' => '確率式。最低保証枠があります。',
            'main_image_url' => 'https://example.test/images/gacha.png',
            'target_margin' => 30.00,
        ];
    }
}
