<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGachaPrizeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_show_gacha_prize_with_gacha_and_rank(): void
    {
        $this->actingAdmin();
        [$gacha, $rank] = $this->createGachaAndRank();
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'Show Prize',
        ]);

        $this->getJson("/admin/api/gacha-prizes/{$prize->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $prize->id)
            ->assertJsonPath('data.name', 'Show Prize')
            ->assertJsonPath('data.gacha.id', $gacha->id)
            ->assertJsonPath('data.rank.id', $rank->id);
    }

    public function test_admin_can_list_gacha_prizes_with_gacha_and_rank(): void
    {
        $this->actingAdmin();
        [$gacha, $rank] = $this->createGachaAndRank();
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'List Prize',
            'is_active' => true,
        ]);
        [$otherGacha, $otherRank] = $this->createGachaAndRank();
        GachaPrize::factory()->forGachaAndRank($otherGacha, $otherRank)->create([
            'name' => 'Other Prize',
        ]);

        $this->getJson("/admin/api/gacha-prizes?gacha_id={$gacha->id}&is_active=true")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $prize->id)
            ->assertJsonPath('data.0.name', 'List Prize')
            ->assertJsonPath('data.0.gacha.id', $gacha->id)
            ->assertJsonPath('data.0.rank.id', $rank->id);
    }

    public function test_admin_can_create_prize_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        [$gacha, $rank] = $this->createGachaAndRank();

        $response = $this->postJson("/admin/api/gacha-ranks/{$rank->id}/prizes", [
            ...$this->payload([
                'name' => 'Prize S',
            ]),
            'probability_ppm' => 12345,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.gacha_id', $gacha->id)
            ->assertJsonPath('data.rank_id', $rank->id)
            ->assertJsonPath('data.name', 'Prize S')
            ->assertJsonPath('data.won_count', 0)
            ->assertJsonMissingPath('data.probability_ppm');

        $prize = GachaPrize::query()->where('name', 'Prize S')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha_prize.created',
            'auditable_type' => GachaPrize::class,
            'auditable_id' => $prize->id,
        ]);
    }

    public function test_admin_can_update_prize_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        [$gacha, $rank] = $this->createGachaAndRank();
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'Old Prize',
            'max_win_count' => 10,
            'won_count' => 3,
        ]);

        $this->putJson("/admin/api/gacha-prizes/{$prize->id}", [
            'name' => 'Updated Prize',
            'max_win_count' => 5,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Prize')
            ->assertJsonPath('data.max_win_count', 5)
            ->assertJsonPath('data.won_count', 3)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha_prize.updated',
            'auditable_type' => GachaPrize::class,
            'auditable_id' => $prize->id,
        ]);
    }

    public function test_update_rejects_max_win_count_below_won_count(): void
    {
        $this->actingAdmin();
        [$gacha, $rank] = $this->createGachaAndRank();
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'max_win_count' => 10,
            'won_count' => 3,
        ]);

        $this->putJson("/admin/api/gacha-prizes/{$prize->id}", [
            'max_win_count' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_win_count']);
    }

    public function test_update_rejects_rank_from_different_gacha(): void
    {
        $this->actingAdmin();
        [$gacha, $rank] = $this->createGachaAndRank();
        [$otherGacha, $otherRank] = $this->createGachaAndRank();
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create();

        $this->putJson("/admin/api/gacha-prizes/{$prize->id}", [
            'rank_id' => $otherRank->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rank_id']);

        $this->assertNotSame($gacha->id, $otherGacha->id);
    }

    public function test_create_rejects_prize_for_active_gacha(): void
    {
        $this->actingAdmin();
        [, $rank] = $this->createGachaAndRank([
            'status' => GachaStatus::Active,
        ]);

        $this->postJson("/admin/api/gacha-ranks/{$rank->id}/prizes", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gacha_id']);

        $this->assertDatabaseCount('gacha_prizes', 0);
    }

    public function test_update_rejects_locked_fields_while_gacha_is_active(): void
    {
        $this->actingAdmin();
        [$gacha, $rank] = $this->createGachaAndRank([
            'status' => GachaStatus::Active,
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'max_win_count' => 10,
            'cost_price' => 1000,
        ]);

        $this->putJson("/admin/api/gacha-prizes/{$prize->id}", [
            'max_win_count' => 20,
            'cost_price' => 1200,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_win_count', 'cost_price']);

        $prize->refresh();
        $this->assertSame(10, $prize->max_win_count);
        $this->assertSame(1000, $prize->cost_price);
    }

    public function test_user_token_cannot_create_prize(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [, $rank] = $this->createGachaAndRank();

        $this->postJson("/admin/api/gacha-ranks/{$rank->id}/prizes", $this->payload())
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

    /**
     * @return array{0: Gacha, 1: GachaRank}
     */
    private function createGachaAndRank(array $gachaOverrides = []): array
    {
        $gacha = Gacha::factory()->create($gachaOverrides);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        return [$gacha, $rank];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            ...[
                'name' => 'Prize',
                'image_url' => 'https://example.test/prize.png',
                'max_win_count' => 10,
                'cost_price' => 1000,
                'display_price' => 2000,
                'exchange_point' => 500,
                'condition' => '新品',
                'is_active' => true,
                'is_visible' => true,
                'sort_order' => 1,
            ],
            ...$overrides,
        ];
    }
}
