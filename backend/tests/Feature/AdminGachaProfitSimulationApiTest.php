<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGachaProfitSimulationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_profit_simulation(): void
    {
        $admin = $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'price' => 500,
            'total_count' => 100,
            'sold_count' => 20,
            'minimum_guarantee_cost' => 10,
            'target_margin' => 30,
        ]);
        $rank = GachaRank::factory()->create(['gacha_id' => $gacha->id]);
        $firstPrize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'cost_price' => 1000,
            'max_win_count' => 5,
            'won_count' => 2,
        ]);
        $secondPrize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'cost_price' => 200,
            'max_win_count' => 10,
            'won_count' => 1,
        ]);
        app(ProbabilityVersionPublisher::class)->publish($gacha, [[
            'stage_key' => 'stage_1',
            'name' => 'Default',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
            'probabilities' => [
                ['prize_id' => $firstPrize->id, 'probability_ppm' => 100_000],
                ['prize_id' => $secondPrize->id, 'probability_ppm' => 200_000],
                ['is_minimum_guarantee' => true, 'probability_ppm' => 700_000],
            ],
        ]], $admin);

        $this->getJson("/admin/api/gachas/{$gacha->id}/profit-simulation")
            ->assertOk()
            ->assertJsonPath('data.sales.total_sales', 50000)
            ->assertJsonPath('data.sales.sold_sales', 10000)
            ->assertJsonPath('data.costs.prize_inventory_cost', 7000)
            ->assertJsonPath('data.costs.prize_awarded_cost', 2200)
            ->assertJsonPath('data.costs.minimum_guarantee_max_cost', 1000)
            ->assertJsonPath('data.costs.max_cost', 8000)
            ->assertJsonPath('data.profit.projected_profit', 42000)
            ->assertJsonPath('data.profit.projected_margin_rate', 84)
            ->assertJsonPath('data.profit.target_profit', 15000)
            ->assertJsonPath('data.profit.meets_target', true)
            ->assertJsonPath('data.expected.available', true)
            ->assertJsonPath('data.expected.expected_cost_per_draw', 147)
            ->assertJsonPath('data.expected.expected_total_cost', 14700)
            ->assertJsonPath('data.expected.expected_profit', 35300)
            ->assertJsonPath('data.expected.expected_margin_rate', 70.6)
            ->assertJsonPath('data.expected.stages.0.draw_count', 100);
    }

    public function test_user_token_cannot_get_profit_simulation(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $gacha = Gacha::factory()->create();

        $this->getJson("/admin/api/gachas/{$gacha->id}/profit-simulation")
            ->assertForbidden();
    }

    private function actingAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        return $admin;
    }
}
