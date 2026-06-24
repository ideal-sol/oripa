<?php

namespace Tests\Feature;

use App\Models\PointPurchasePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointPurchasePlanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_index_returns_only_currently_available_plans(): void
    {
        PointPurchasePlan::query()->delete();

        $available = PointPurchasePlan::query()->create([
            'name' => 'Available',
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'sort_order' => 2,
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $unlimited = PointPurchasePlan::query()->create([
            'name' => 'Unlimited',
            'amount' => 3000,
            'paid_point_amount' => 3000,
            'free_point_amount' => 300,
            'sort_order' => 1,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ]);
        PointPurchasePlan::query()->create([
            'name' => 'Future',
            'amount' => 5000,
            'paid_point_amount' => 5000,
            'free_point_amount' => 500,
            'sort_order' => 3,
            'is_active' => true,
            'starts_at' => now()->addDay(),
            'ends_at' => null,
        ]);
        PointPurchasePlan::query()->create([
            'name' => 'Expired',
            'amount' => 10000,
            'paid_point_amount' => 10000,
            'free_point_amount' => 1000,
            'sort_order' => 4,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => now()->subDay(),
        ]);

        $this->getJson('/api/point-purchase-plans')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $unlimited->id)
            ->assertJsonPath('data.0.starts_at', null)
            ->assertJsonPath('data.0.ends_at', null)
            ->assertJsonPath('data.1.id', $available->id)
            ->assertJsonMissing(['name' => 'Future'])
            ->assertJsonMissing(['name' => 'Expired']);
    }
}
