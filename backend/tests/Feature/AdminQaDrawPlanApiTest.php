<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\QaDrawPlan;
use App\Models\RankAsset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminQaDrawPlanApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_create_list_show_update_pause_activate_and_disable_plan(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $owner = $this->actingAdmin(AdminRole::Owner);
        [$user, $gacha, $prize, $imageAsset, $videoAsset] = $this->fixtures();

        $create = $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", [
            'gacha_id' => $gacha->id,
            'status' => QaDrawPlanStatus::Active->value,
            'title' => 'QA API plan',
            'reason' => 'QA API test',
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->toIso8601String(),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHours(2)->toIso8601String(),
            'items' => [[
                'sort_order' => 1,
                'gacha_prize_id' => $prize->id,
                'quantity' => 2,
                'rank_image_asset_id' => $imageAsset->id,
                'draw_video_asset_id' => $videoAsset->id,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.gacha_id', $gacha->id)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.items.0.gacha_prize_id', $prize->id);

        $planId = $create->json('data.id');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_draw_plan.created',
            'admin_user_id' => $owner->id,
            'user_id' => $user->id,
            'auditable_id' => $planId,
        ]);

        $this->getJson("/admin/api/users/{$user->id}/qa-draw-plans")
            ->assertOk()
            ->assertJsonPath('data.0.id', $planId);

        $this->getJson("/admin/api/qa-draw-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.id', $planId)
            ->assertJsonPath('data.items.0.remaining_count', 2);

        $this->putJson("/admin/api/qa-draw-plans/{$planId}", [
            'status' => QaDrawPlanStatus::Paused->value,
            'title' => 'Updated QA API plan',
            'reason' => 'Updated QA API test',
            'items' => [[
                'sort_order' => 2,
                'gacha_prize_id' => $prize->id,
                'quantity' => 3,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $planId)
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.items.0.sort_order', 2);

        $this->postJson("/admin/api/qa-draw-plans/{$planId}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->postJson("/admin/api/qa-draw-plans/{$planId}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->deleteJson("/admin/api/qa-draw-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->assertSame(1, QaDrawPlan::query()->whereKey($planId)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_draw_plan.disabled',
            'auditable_id' => $planId,
        ]);
    }

    public function test_active_plan_is_unique_and_completed_plan_cannot_be_activated(): void
    {
        $this->actingAdmin(AdminRole::Owner);
        [$user, $gacha, $prize] = $this->fixtures();

        $first = $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", $this->payload($gacha, $prize))
            ->assertOk()
            ->json('data.id');

        $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", $this->payload($gacha, $prize))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gacha_id');

        QaDrawPlan::query()->whereKey($first)->update(['status' => QaDrawPlanStatus::Completed]);

        $this->postJson("/admin/api/qa-draw-plans/{$first}/activate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_expired_or_consumed_active_plan_is_completed_before_new_active_plan(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $this->actingAdmin(AdminRole::Owner);
        [$user, $gacha, $prize] = $this->fixtures();

        $expired = $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", array_merge($this->payload($gacha, $prize), [
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addMinute()->toIso8601String(),
        ]))
            ->assertOk()
            ->json('data.id');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:02:00', 'Asia/Tokyo'));

        $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", $this->payload($gacha, $prize))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('qa_draw_plans', [
            'id' => $expired,
            'status' => QaDrawPlanStatus::Completed->value,
        ]);
    }

    public function test_validation_errors_for_items_prize_gacha_and_asset_types(): void
    {
        $this->actingAdmin(AdminRole::Owner);
        [$user, $gacha, $prize, $imageAsset, $videoAsset] = $this->fixtures();
        [$otherUser, $otherGacha, $otherPrize] = $this->fixtures();

        $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", array_replace_recursive($this->payload($gacha, $prize), [
            'items' => [[
                'sort_order' => 1,
                'gacha_prize_id' => $prize->id,
                'quantity' => 1,
            ], [
                'sort_order' => 1,
                'gacha_prize_id' => $prize->id,
                'quantity' => 1,
            ]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", $this->payload($gacha, $otherPrize))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.gacha_prize_id');

        $this->postJson("/admin/api/users/{$otherUser->id}/qa-draw-plans", array_replace_recursive($this->payload($otherGacha, $otherPrize), [
            'items' => [[
                'sort_order' => 1,
                'gacha_prize_id' => $otherPrize->id,
                'quantity' => 1,
                'rank_image_asset_id' => $videoAsset->id,
            ]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.rank_image_asset_id');

        $this->postJson("/admin/api/users/{$otherUser->id}/qa-draw-plans", array_replace_recursive($this->payload($otherGacha, $otherPrize), [
            'items' => [[
                'sort_order' => 1,
                'gacha_prize_id' => $otherPrize->id,
                'quantity' => 1,
                'draw_video_asset_id' => $imageAsset->id,
            ]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.draw_video_asset_id');
    }

    public function test_admin_operator_and_unauthenticated_requests_are_rejected(): void
    {
        [$user, $gacha, $prize] = $this->fixtures();

        $this->actingAdmin(AdminRole::Admin);
        $this->getJson("/admin/api/users/{$user->id}/qa-draw-plans")->assertForbidden();
        $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", $this->payload($gacha, $prize))->assertForbidden();

        $this->actingAdmin(AdminRole::Operator);
        $this->getJson("/admin/api/users/{$user->id}/qa-draw-plans")->assertForbidden();
        $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", $this->payload($gacha, $prize))->assertForbidden();

        auth()->forgetGuards();

        $this->getJson("/admin/api/users/{$user->id}/qa-draw-plans")->assertUnauthorized();
        $this->postJson("/admin/api/users/{$user->id}/qa-draw-plans", $this->payload($gacha, $prize))->assertUnauthorized();
    }

    private function actingAdmin(AdminRole $role): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        return $admin;
    }

    private function payload(Gacha $gacha, GachaPrize $prize): array
    {
        return [
            'gacha_id' => $gacha->id,
            'status' => QaDrawPlanStatus::Active->value,
            'title' => 'QA plan',
            'reason' => 'QA指定排出',
            'items' => [[
                'sort_order' => 1,
                'gacha_prize_id' => $prize->id,
                'quantity' => 1,
            ]],
        ];
    }

    private function fixtures(): array
    {
        $user = User::factory()->create();
        $gacha = Gacha::factory()->create();
        $rank = GachaRank::factory()->create(['gacha_id' => $gacha->id]);
        $prize = GachaPrize::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_id' => $rank->id,
        ]);
        $imageAsset = RankAsset::query()->create([
            'title' => 'QA image',
            'asset_type' => 'image',
            'url' => 'https://example.test/qa-image.png',
            'is_active' => true,
        ]);
        $videoAsset = RankAsset::query()->create([
            'title' => 'QA video',
            'asset_type' => 'video',
            'url' => 'https://example.test/qa-video.mp4',
            'is_active' => true,
        ]);

        return [$user, $gacha, $prize, $imageAsset, $videoAsset];
    }
}
