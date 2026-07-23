<?php

namespace Database\Factories;

use App\Models\GachaTag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GachaTag> */
class GachaTagFactory extends Factory
{
    protected $model = GachaTag::class;

    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
