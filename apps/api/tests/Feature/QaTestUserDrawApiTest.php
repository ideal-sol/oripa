<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\QaDrawExecution;
use App\Models\QaDrawPlan;
use App\Models\QaTestUserMode;
use App\Models\RankAsset;
use App\Models\User;
use App\Models\UserPrize;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaTestUserDrawApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_non_qa_user_uses_existing_normal_draw_flow(): void
    {
        [$user, $gacha, $prize] = $this->drawableFixture();
        $this->createWalletWithPaidLot($user, 200);
        $this->publishPointBackStage($gacha, $prize);
        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 1,
            'idempotency_key' => 'normal-non-qa',
        ])
            ->assertCreated()
            ->assertJsonPath('data.results.0.result_type', DrawResultType::PointBack->value);

        $this->assertSame(1, $gacha->refresh()->sold_count);
        $this->assertSame(0, QaDrawExecution::query()->count());
    }

    public function test_disabled_or_expired_qa_mode_uses_existing_normal_draw_flow(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        [$user, $gacha, $prize] = $this->drawableFixture();
        $this->createWalletWithPaidLot($user, 200);
        $this->publishPointBackStage($gacha, $prize);
        $this->createQaMode($user, [
            'is_enabled' => false,
            'disabled_at' => CarbonImmutable::now('Asia/Tokyo'),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 1,
            'idempotency_key' => 'disabled-qa-mode',
        ])
            ->assertCreated()
            ->assertJsonPath('data.results.0.result_type', DrawResultType::PointBack->value);

        $user2 = User::factory()->create();
        $this->createWalletWithPaidLot($user2, 100);
        $this->createQaMode($user2, [
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->subHours(2),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->subHour(),
        ]);
        Sanctum::actingAs($user2);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 1,
            'idempotency_key' => 'expired-qa-mode',
        ])
            ->assertCreated()
            ->assertJsonPath('data.results.0.result_type', DrawResultType::PointBack->value);
    }

    public function test_active_qa_mode_without_plan_fails_before_point_consumption(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        [$user, $gacha, $prize] = $this->drawableFixture();
        $this->createWalletWithPaidLot($user, 100);
        $this->publishPointBackStage($gacha, $prize);
        $this->createQaMode($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 1,
            'idempotency_key' => 'qa-no-plan',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draw');

        $this->assertSame(100, $user->wallet->refresh()->paid_balance);
        $this->assertSame(0, $gacha->refresh()->sold_count);
        $this->assertSame(0, $prize->refresh()->won_count);
        $this->assertSame(0, PointLedger::query()->count());
    }

    public function test_qa_draw_outputs_configured_prizes_in_order_and_records_execution(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        [$user, $gacha, $firstPrize, $rank] = $this->drawableFixture();
        $secondPrize = $this->createPrize($gacha, 'Second QA Prize');
        $this->createWalletWithPaidLot($user, 500);
        $this->publishPointBackStage($gacha, $firstPrize);
        $mode = $this->createQaMode($user);
        $imageAsset = $this->createAsset('image', 'https://example.test/fixed-image.png');
        $videoAsset = $this->createAsset('video', 'https://example.test/fixed-video.mp4');
        $plan = $this->createQaPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $firstPrize->id,
            'quantity' => 2,
            'consumed_count' => 0,
            'rank_image_asset_id' => $imageAsset->id,
            'draw_video_asset_id' => $videoAsset->id,
        ], [
            'sort_order' => 2,
            'gacha_prize_id' => $secondPrize->id,
            'quantity' => 1,
            'consumed_count' => 0,
        ]]);
        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 3,
            'idempotency_key' => 'qa-configured-prizes',
        ])
            ->assertCreated()
            ->assertJsonPath('data.draw_count', 3)
            ->assertJsonPath('data.results.0.result_type', DrawResultType::Prize->value)
            ->assertJsonPath('data.results.0.prize_id', $firstPrize->id)
            ->assertJsonPath('data.results.0.selected_rank_image_url', $imageAsset->url)
            ->assertJsonPath('data.results.0.selected_draw_video_url', $videoAsset->url)
            ->assertJsonPath('data.results.1.prize_id', $firstPrize->id)
            ->assertJsonPath('data.results.2.prize_id', $secondPrize->id);

        $this->assertSame(3, $gacha->refresh()->sold_count);
        $this->assertSame(2, $firstPrize->refresh()->won_count);
        $this->assertSame(1, $secondPrize->refresh()->won_count);
        $this->assertSame(200, $user->wallet->refresh()->paid_balance);
        $this->assertSame(3, UserPrize::query()->where('user_id', $user->id)->count());
        $this->assertSame(QaDrawPlanStatus::Completed, $plan->refresh()->status);
        $this->assertSame([2, 1], $plan->items()->orderBy('sort_order')->pluck('consumed_count')->all());

        $execution = QaDrawExecution::query()->firstOrFail();
        $this->assertSame($mode->id, $execution->qa_test_user_mode_id);
        $this->assertSame($plan->id, $execution->qa_draw_plan_id);
        $this->assertSame($user->id, $execution->user_id);
        $this->assertSame($gacha->id, $execution->gacha_id);
        $this->assertSame(3, $execution->draw_count);
        $this->assertCount(3, $execution->metadata['items']);

        $this->assertDatabaseHas('draw_requests', [
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'is_qa_draw' => true,
            'qa_test_user_mode_id' => $mode->id,
            'qa_draw_plan_id' => $plan->id,
        ]);
        $this->assertDatabaseHas('draw_results', [
            'prize_id' => $firstPrize->id,
            'is_qa_draw' => true,
        ]);
    }

    public function test_same_idempotency_key_does_not_double_consume_qa_plan(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        [$user, $gacha, $prize] = $this->drawableFixture();
        $this->createWalletWithPaidLot($user, 300);
        $this->publishPointBackStage($gacha, $prize);
        $this->createQaMode($user);
        $plan = $this->createQaPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $prize->id,
            'quantity' => 2,
            'consumed_count' => 0,
        ]]);
        Sanctum::actingAs($user);

        $payload = [
            'draw_count' => 1,
            'idempotency_key' => 'qa-idempotent',
        ];

        $this->postJson("/api/gachas/{$gacha->id}/draw", $payload)->assertCreated();
        $this->postJson("/api/gachas/{$gacha->id}/draw", $payload)->assertCreated();

        $this->assertSame(1, $gacha->refresh()->sold_count);
        $this->assertSame(1, $prize->refresh()->won_count);
        $this->assertSame(1, $plan->items()->first()->consumed_count);
        $this->assertSame(1, QaDrawExecution::query()->count());
    }

    public function test_qa_setting_error_rolls_back_points_inventory_and_consumed_count(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 10:00:00', 'Asia/Tokyo'));
        [$user, $gacha, $prize] = $this->drawableFixture();
        $prize->forceFill(['max_win_count' => 1, 'won_count' => 0])->save();
        $this->createWalletWithPaidLot($user, 300);
        $this->publishPointBackStage($gacha, $prize);
        $this->createQaMode($user);
        $plan = $this->createQaPlan($user, $gacha, [[
            'sort_order' => 1,
            'gacha_prize_id' => $prize->id,
            'quantity' => 2,
            'consumed_count' => 0,
        ]]);
        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 2,
            'idempotency_key' => 'qa-inventory-shortage',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('draw');

        $this->assertSame(300, $user->wallet->refresh()->paid_balance);
        $this->assertSame(0, $gacha->refresh()->sold_count);
        $this->assertSame(0, $prize->refresh()->won_count);
        $this->assertSame(0, $plan->items()->first()->consumed_count);
        $this->assertSame(0, PointLedger::query()->count());
        $this->assertSame(0, QaDrawExecution::query()->count());
    }

    private function drawableFixture(): array
    {
        $user = User::factory()->create();
        $gacha = Gacha::factory()->create([
            'price' => 100,
            'total_count' => 10000,
            'sold_count' => 0,
            'status' => GachaStatus::Active,
            'minimum_guarantee_value' => 10,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'max_win_count' => 10,
            'won_count' => 0,
            'is_active' => true,
        ]);

        return [$user, $gacha, $prize, $rank];
    }

    private function createPrize(Gacha $gacha, string $name): GachaPrize
    {
        $rank = GachaRank::factory()->create(['gacha_id' => $gacha->id]);

        return GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => $name,
            'max_win_count' => 10,
            'won_count' => 0,
            'is_active' => true,
        ]);
    }

    private function createWalletWithPaidLot(User $user, int $amount): void
    {
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => $amount,
            'free_balance' => 0,
        ]);

        PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => PointType::Paid,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => null,
            'granted_at' => now()->subDay(),
            'expire_at' => null,
        ]);
    }

    private function publishPointBackStage(Gacha $gacha, GachaPrize $prize): void
    {
        app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 0],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 1_000_000],
                ],
            ],
        ], AdminUser::factory()->create());
    }

    private function createQaMode(User $user, array $attributes = []): QaTestUserMode
    {
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);

        return QaTestUserMode::query()->create(array_merge([
            'user_id' => $user->id,
            'is_enabled' => true,
            'reason' => 'QA draw integration test',
            'starts_at' => null,
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHour(),
            'enabled_by_admin_user_id' => $owner->id,
        ], $attributes));
    }

    private function createQaPlan(User $user, Gacha $gacha, array $items): QaDrawPlan
    {
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        $plan = QaDrawPlan::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'status' => QaDrawPlanStatus::Active,
            'title' => 'QA draw integration plan',
            'reason' => 'QA draw integration test',
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

    private function createAsset(string $type, string $url): RankAsset
    {
        return RankAsset::query()->create([
            'title' => "QA {$type}",
            'asset_type' => $type,
            'url' => $url,
            'is_active' => true,
        ]);
    }
}
