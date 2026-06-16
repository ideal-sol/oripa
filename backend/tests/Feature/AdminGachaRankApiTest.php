<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGachaRankApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_ranks_with_gacha_and_prize_count(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create(['title' => 'Rank List Gacha']);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);

        $this->getJson("/admin/api/gacha-ranks?gacha_id={$gacha->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rank->id)
            ->assertJsonPath('data.0.gacha.id', $gacha->id)
            ->assertJsonPath('data.0.prizes_count', 0);
    }

    public function test_admin_can_show_rank(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create();
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'A',
            'display_name' => 'A賞',
        ]);

        $this->getJson("/admin/api/gacha-ranks/{$rank->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $rank->id)
            ->assertJsonPath('data.gacha.id', $gacha->id)
            ->assertJsonPath('data.rank_key', 'A')
            ->assertJsonPath('data.display_name', 'A賞');
    }

    public function test_admin_can_create_rank_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $gacha = Gacha::factory()->create();

        $response = $this->postJson("/admin/api/gachas/{$gacha->id}/ranks", $this->payload([
            'rank_key' => 'S',
            'display_name' => 'S賞',
        ]));

        $response
            ->assertCreated()
            ->assertJsonPath('data.gacha_id', $gacha->id)
            ->assertJsonPath('data.rank_key', 'S')
            ->assertJsonPath('data.display_name', 'S賞')
            ->assertJsonPath('data.draw_video_url', 'https://example.test/rank-s.mp4')
            ->assertJsonPath('data.result_image_url', 'https://example.test/rank-s-result.png')
            ->assertJsonPath('data.is_visible', true);

        $rank = GachaRank::query()->where('gacha_id', $gacha->id)->where('rank_key', 'S')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha_rank.created',
            'auditable_type' => GachaRank::class,
            'auditable_id' => $rank->id,
        ]);
    }

    public function test_rank_key_must_be_unique_within_same_gacha(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create();
        GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);

        $this->postJson("/admin/api/gachas/{$gacha->id}/ranks", $this->payload([
            'rank_key' => 'S',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rank_key']);
    }

    public function test_same_rank_key_is_allowed_for_different_gachas(): void
    {
        $this->actingAdmin();
        $firstGacha = Gacha::factory()->create();
        $secondGacha = Gacha::factory()->create();
        GachaRank::factory()->create([
            'gacha_id' => $firstGacha->id,
            'rank_key' => 'S',
        ]);

        $this->postJson("/admin/api/gachas/{$secondGacha->id}/ranks", $this->payload([
            'rank_key' => 'S',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.gacha_id', $secondGacha->id)
            ->assertJsonPath('data.rank_key', 'S');
    }

    public function test_admin_can_update_rank_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $rank = GachaRank::factory()->create([
            'rank_key' => 'A',
            'display_name' => 'A賞',
            'sort_order' => 2,
            'is_visible' => true,
        ]);

        $this->putJson("/admin/api/gacha-ranks/{$rank->id}", [
            'rank_key' => 'S',
            'display_name' => 'S賞',
            'sort_order' => 1,
            'is_visible' => false,
            'draw_video_url' => 'https://example.test/rank-s-edit.mp4',
            'result_image_url' => 'https://example.test/rank-s-result-edit.png',
        ])
            ->assertOk()
            ->assertJsonPath('data.rank_key', 'S')
            ->assertJsonPath('data.display_name', 'S賞')
            ->assertJsonPath('data.sort_order', 1)
            ->assertJsonPath('data.draw_video_url', 'https://example.test/rank-s-edit.mp4')
            ->assertJsonPath('data.result_image_url', 'https://example.test/rank-s-result-edit.png')
            ->assertJsonPath('data.is_visible', false);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha_rank.updated',
            'auditable_type' => GachaRank::class,
            'auditable_id' => $rank->id,
        ]);
    }

    public function test_user_token_cannot_create_rank(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $gacha = Gacha::factory()->create();

        $this->postJson("/admin/api/gachas/{$gacha->id}/ranks", $this->payload())
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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            ...[
                'rank_key' => 'S',
                'display_name' => 'S賞',
                'description' => 'Top rank.',
                'image_url' => 'https://example.test/rank-s.png',
                'draw_video_url' => 'https://example.test/rank-s.mp4',
                'result_image_url' => 'https://example.test/rank-s-result.png',
                'sort_order' => 1,
                'is_visible' => true,
            ],
            ...$overrides,
        ];
    }
}
