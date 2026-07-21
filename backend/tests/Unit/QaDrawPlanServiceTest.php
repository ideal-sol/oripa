<?php

namespace Tests\Unit;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use App\Domain\Gacha\Services\QaDrawPlanService;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\QaDrawPlan;
use App\Models\RankAsset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QaDrawPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_creates_updates_pauses_and_disables_plan_with_audit_logs(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $service = app(QaDrawPlanService::class);
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        [$user, $gacha, $prize, $imageAsset, $videoAsset] = $this->fixtures();

        $plan = $service->create($user, $owner, [
            'gacha_id' => $gacha->id,
            'status' => QaDrawPlanStatus::Active->value,
            'title' => 'QA plan',
            'reason' => 'QA指定排出',
            'starts_at' => null,
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHours(2)->toIso8601String(),
            'items' => [[
                'sort_order' => 1,
                'gacha_prize_id' => $prize->id,
                'quantity' => 2,
                'rank_image_asset_id' => $imageAsset->id,
                'draw_video_asset_id' => $videoAsset->id,
            ]],
        ]);

        $this->assertSame(QaDrawPlanStatus::Active, $plan->status);
        $this->assertSame(1, $plan->items()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_draw_plan.created',
            'admin_user_id' => $owner->id,
            'user_id' => $user->id,
            'auditable_type' => QaDrawPlan::class,
            'auditable_id' => $plan->id,
        ]);

        $updated = $service->update($plan, $owner, [
            'status' => QaDrawPlanStatus::Paused->value,
            'title' => 'Updated QA plan',
            'reason' => 'QA指定排出更新',
            'items' => [[
                'sort_order' => 2,
                'gacha_prize_id' => $prize->id,
                'quantity' => 3,
            ]],
        ]);

        $this->assertSame($plan->id, $updated->id);
        $this->assertSame(QaDrawPlanStatus::Paused, $updated->status);
        $this->assertSame('Updated QA plan', $updated->title);
        $this->assertSame(1, $updated->items()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_draw_plan.updated',
            'auditable_id' => $plan->id,
        ]);

        $paused = $service->pause($plan, $owner);
        $this->assertSame(QaDrawPlanStatus::Paused, $paused->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_draw_plan.paused',
            'auditable_id' => $plan->id,
        ]);

        $disabled = $service->disable($plan, $owner);
        $this->assertSame(QaDrawPlanStatus::Disabled, $disabled->status);
        $this->assertSame(1, QaDrawPlan::query()->whereKey($plan->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_draw_plan.disabled',
            'auditable_id' => $plan->id,
        ]);
    }

    public function test_it_rejects_duplicate_active_plan_until_existing_active_is_completed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $service = app(QaDrawPlanService::class);
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        [$user, $gacha, $prize] = $this->fixtures();

        $active = $service->create($user, $owner, $this->payload($gacha, $prize));

        $this->expectException(ValidationException::class);
        try {
            $service->create($user, $owner, $this->payload($gacha, $prize));
        } finally {
            $active->items()->update(['consumed_count' => 1]);
            $newPlan = $service->create($user, $owner, $this->payload($gacha, $prize));

            $this->assertSame(QaDrawPlanStatus::Completed, $active->refresh()->status);
            $this->assertSame(QaDrawPlanStatus::Active, $newPlan->status);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'admin.qa_draw_plan.completed',
                'auditable_id' => $active->id,
            ]);
        }
    }

    public function test_it_validates_prize_gacha_and_asset_types(): void
    {
        $service = app(QaDrawPlanService::class);
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        [$user, $gacha, $prize, $imageAsset, $videoAsset] = $this->fixtures();
        [$otherUser, $otherGacha, $otherPrize] = $this->fixtures();

        try {
            $service->create($user, $owner, $this->payload($gacha, $otherPrize));
            $this->fail('Expected gacha prize validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.gacha_prize_id', $exception->errors());
        }

        try {
            $service->create($otherUser, $owner, array_replace_recursive($this->payload($otherGacha, $otherPrize), [
                'items' => [[
                    'sort_order' => 1,
                    'gacha_prize_id' => $otherPrize->id,
                    'quantity' => 1,
                    'rank_image_asset_id' => $videoAsset->id,
                ]],
            ]));
            $this->fail('Expected image asset validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.rank_image_asset_id', $exception->errors());
        }

        try {
            $service->create($otherUser, $owner, array_replace_recursive($this->payload($otherGacha, $otherPrize), [
                'items' => [[
                    'sort_order' => 1,
                    'gacha_prize_id' => $otherPrize->id,
                    'quantity' => 1,
                    'draw_video_asset_id' => $imageAsset->id,
                ]],
            ]));
            $this->fail('Expected video asset validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.draw_video_asset_id', $exception->errors());
        }
    }

    public function test_completed_plan_cannot_be_activated_again(): void
    {
        $service = app(QaDrawPlanService::class);
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        [$user, $gacha, $prize] = $this->fixtures();
        $plan = $service->create($user, $owner, array_merge($this->payload($gacha, $prize), [
            'status' => QaDrawPlanStatus::Paused->value,
        ]));
        $plan->forceFill(['status' => QaDrawPlanStatus::Completed])->save();

        $this->expectException(ValidationException::class);

        $service->activate($plan, $owner);
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
