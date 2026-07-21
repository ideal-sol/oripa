<?php

namespace Tests\Unit;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use App\Domain\Gacha\Exceptions\DrawException;
use App\Domain\Gacha\Services\QaDrawResolver;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\QaDrawPlan;
use App\Models\QaTestUserMode;
use App\Models\RankAsset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaDrawResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_returns_inactive_selection_without_active_qa_mode(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $resolver = app(QaDrawResolver::class);
        [$user, $gacha] = $this->fixtures();

        $this->assertFalse($resolver->resolve($user, $gacha, 1, false)->active);

        $disabled = $this->createMode($user, [
            'is_enabled' => false,
            'disabled_at' => CarbonImmutable::now('Asia/Tokyo'),
        ]);
        $this->assertFalse($resolver->resolve($user, $gacha, 1, false)->active);

        $disabled->delete();
        $this->createMode($user, [
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->subHours(2),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->subHour(),
        ]);
        $this->assertFalse($resolver->resolve($user, $gacha, 1, false)->active);
    }

    public function test_active_qa_mode_without_active_plan_throws(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $resolver = app(QaDrawResolver::class);
        [$user, $gacha] = $this->fixtures();
        $this->createMode($user);

        $this->expectException(DrawException::class);
        $this->expectExceptionMessage('no active QA draw plan');

        $resolver->resolve($user, $gacha, 1, false);
    }

    public function test_remaining_item_shortage_throws(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $resolver = app(QaDrawResolver::class);
        [$user, $gacha, $prize] = $this->fixtures();
        $this->createMode($user);
        $this->createPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $prize->id,
            'quantity' => 2,
            'consumed_count' => 1,
        ]]);

        $this->expectException(DrawException::class);
        $this->expectExceptionMessage('not have enough remaining');

        $resolver->resolve($user, $gacha, 2, false);
    }

    public function test_it_expands_quantity_and_consumed_count_in_order(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $resolver = app(QaDrawResolver::class);
        [$user, $gacha, $firstPrize, $imageAsset, $videoAsset] = $this->fixtures();
        $secondPrize = $this->createPrize($gacha, 'Second prize');
        $mode = $this->createMode($user);
        $plan = $this->createPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $firstPrize->id,
            'quantity' => 3,
            'consumed_count' => 1,
            'rank_image_asset_id' => $imageAsset->id,
            'draw_video_asset_id' => $videoAsset->id,
        ], [
            'sort_order' => 2,
            'gacha_prize_id' => $secondPrize->id,
            'quantity' => 1,
            'consumed_count' => 0,
        ]]);

        $selection = $resolver->resolve($user, $gacha, 3, false);

        $this->assertTrue($selection->active);
        $this->assertSame($mode->id, $selection->modeId());
        $this->assertSame($plan->id, $selection->planId());
        $this->assertCount(3, $selection->items);
        $this->assertSame($firstPrize->id, $selection->items[0]->prize->id);
        $this->assertSame($firstPrize->id, $selection->items[1]->prize->id);
        $this->assertSame($secondPrize->id, $selection->items[2]->prize->id);
        $this->assertSame($imageAsset->url, $selection->items[0]->fixedRankImageUrl());
        $this->assertSame($videoAsset->url, $selection->items[0]->fixedDrawVideoUrl());
    }

    public function test_invalid_prize_configuration_throws(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $resolver = app(QaDrawResolver::class);
        [$user, $gacha] = $this->fixtures();
        [$otherUser, $otherGacha, $otherPrize] = $this->fixtures();
        $this->createMode($user);
        $this->createPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $otherPrize->id,
            'quantity' => 1,
            'consumed_count' => 0,
        ]]);

        $this->expectException(DrawException::class);
        $this->expectExceptionMessage('does not belong');

        $resolver->resolve($user, $gacha, 1, false);
    }

    public function test_inactive_prize_throws(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $resolver = app(QaDrawResolver::class);
        [$user, $gacha, $prize] = $this->fixtures();
        $prize->forceFill(['is_active' => false])->save();
        $this->createMode($user);
        $this->createPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $prize->id,
            'quantity' => 1,
            'consumed_count' => 0,
        ]]);

        $this->expectException(DrawException::class);
        $this->expectExceptionMessage('not active');

        $resolver->resolve($user, $gacha, 1, false);
    }

    public function test_inventory_shortage_throws(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $resolver = app(QaDrawResolver::class);
        [$user, $gacha, $prize] = $this->fixtures();
        $prize->forceFill(['max_win_count' => 2, 'won_count' => 1])->save();
        $this->createMode($user);
        $this->createPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $prize->id,
            'quantity' => 2,
            'consumed_count' => 0,
        ]]);

        $this->expectException(DrawException::class);
        $this->expectExceptionMessage('inventory is insufficient');

        $resolver->resolve($user, $gacha, 2, false);
    }

    public function test_wrong_or_inactive_asset_type_throws(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        $resolver = app(QaDrawResolver::class);
        [$user, $gacha, $prize, $imageAsset, $videoAsset] = $this->fixtures();
        $this->createMode($user);
        $this->createPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $prize->id,
            'quantity' => 1,
            'consumed_count' => 0,
            'rank_image_asset_id' => $videoAsset->id,
        ]]);

        try {
            $resolver->resolve($user, $gacha, 1, false);
            $this->fail('Expected image asset exception.');
        } catch (DrawException $exception) {
            $this->assertStringContainsString('active image asset', $exception->getMessage());
        }

        $user2 = User::factory()->create();
        $this->createMode($user2);
        $inactiveVideo = RankAsset::query()->create([
            'title' => 'Inactive video',
            'asset_type' => 'video',
            'url' => 'https://example.test/inactive.mp4',
            'is_active' => false,
        ]);
        $this->createPlan($user2, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $prize->id,
            'quantity' => 1,
            'consumed_count' => 0,
            'draw_video_asset_id' => $inactiveVideo->id,
        ]]);

        $this->expectException(DrawException::class);
        $this->expectExceptionMessage('active video asset');

        $resolver->resolve($user2, $gacha, 1, false);
    }

    private function createMode(User $user, array $attributes = []): QaTestUserMode
    {
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);

        return QaTestUserMode::query()->create(array_merge([
            'user_id' => $user->id,
            'is_enabled' => true,
            'reason' => 'QA resolver test',
            'starts_at' => null,
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHour(),
            'enabled_by_admin_user_id' => $owner->id,
        ], $attributes));
    }

    private function createPlan(User $user, Gacha $gacha, array $items): QaDrawPlan
    {
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        $plan = QaDrawPlan::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'status' => QaDrawPlanStatus::Active,
            'title' => 'QA resolver plan',
            'reason' => 'QA resolver test',
            'starts_at' => null,
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHour(),
            'created_by_admin_user_id' => $owner->id,
            'updated_by_admin_user_id' => $owner->id,
        ]);

        foreach ($items as $item) {
            $plan->items()->create($item);
        }

        return $plan;
    }

    private function fixtures(): array
    {
        $user = User::factory()->create();
        $gacha = Gacha::factory()->create();
        $prize = $this->createPrize($gacha, 'First prize');
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

    private function createPrize(Gacha $gacha, string $name): GachaPrize
    {
        $rank = GachaRank::factory()->create(['gacha_id' => $gacha->id]);

        return GachaPrize::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_id' => $rank->id,
            'name' => $name,
            'max_win_count' => 10,
            'won_count' => 0,
            'is_active' => true,
        ]);
    }
}
