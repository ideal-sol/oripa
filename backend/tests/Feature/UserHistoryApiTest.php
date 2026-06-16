<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Domain\Shipping\Enums\ShippingRequestStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\ShippingItem;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\UserPrize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_only_own_draw_requests(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$gacha, $rank, $prize] = $this->gachaFixture();
        $own = $this->drawFixture($user, $gacha, $rank, $prize);
        $this->drawFixture($other, $gacha, $rank, $prize);

        Sanctum::actingAs($user);

        $this->getJson('/api/me/draw-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.gacha.title', $gacha->title)
            ->assertJsonPath('data.0.results.0.prize.name', $prize->name);
    }

    public function test_user_cannot_show_other_users_draw_request(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$gacha, $rank, $prize] = $this->gachaFixture();
        $drawRequest = $this->drawFixture($other, $gacha, $rank, $prize);

        Sanctum::actingAs($user);

        $this->getJson("/api/me/draw-requests/{$drawRequest->id}")
            ->assertNotFound();
    }

    public function test_user_can_list_only_own_shipping_requests(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$gacha, $rank, $prize] = $this->gachaFixture();
        $own = $this->shippingFixture($user, $gacha, $rank, $prize);
        $this->shippingFixture($other, $gacha, $rank, $prize);

        Sanctum::actingAs($user);

        $this->getJson('/api/me/shipping-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.status', ShippingRequestStatus::Requested->value)
            ->assertJsonPath('data.0.items.0.user_prize.prize.name', $prize->name);
    }

    public function test_user_cannot_show_other_users_shipping_request(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$gacha, $rank, $prize] = $this->gachaFixture();
        $shippingRequest = $this->shippingFixture($other, $gacha, $rank, $prize);

        Sanctum::actingAs($user);

        $this->getJson("/api/me/shipping-requests/{$shippingRequest->id}")
            ->assertNotFound();
    }

    /**
     * @return array{0: Gacha, 1: GachaRank, 2: GachaPrize}
     */
    private function gachaFixture(): array
    {
        $gacha = Gacha::factory()->create(['title' => 'History Gacha']);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'display_name' => 'S賞',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'History Prize',
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 1_000_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 0],
                ],
            ],
        ], AdminUser::factory()->create());

        return [$gacha, $rank, $prize];
    }

    private function drawFixture(User $user, Gacha $gacha, GachaRank $rank, GachaPrize $prize): DrawRequest
    {
        $version = $gacha->refresh()->currentProbabilityVersion()->with('stages')->firstOrFail();
        $stage = $version->stages->firstOrFail();

        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'history-'.$user->id,
            'status' => DrawRequestStatus::Completed,
            'consumed_point_total' => 100,
        ]);

        DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => $drawRequest->id,
            'rank_id' => $rank->id,
            'prize_id' => $prize->id,
            'result_type' => DrawResultType::Prize,
            'consumed_point' => 100,
            'granted_point' => 0,
            'random_value' => 1,
            'probability_version_id' => $version->id,
            'probability_version_stage_id' => $stage->id,
        ]);

        return $drawRequest;
    }

    private function shippingFixture(User $user, Gacha $gacha, GachaRank $rank, GachaPrize $prize): ShippingRequest
    {
        $drawRequest = $this->drawFixture($user, $gacha, $rank, $prize);
        $drawResult = $drawRequest->results()->firstOrFail();
        $userPrize = UserPrize::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $drawResult->id,
            'status' => UserPrizeStatus::ShippingRequested,
            'acquired_at' => now(),
            'storage_expire_at' => now()->addDays(30),
        ]);

        $shippingRequest = ShippingRequest::query()->create([
            'user_id' => $user->id,
            'status' => ShippingRequestStatus::Requested,
            'recipient_name' => '山田 太郎',
            'postal_code' => '100-0001',
            'prefecture' => '東京都',
            'city' => '千代田区',
            'address_line1' => '千代田1-1',
            'phone_number' => '09012345678',
            'requested_at' => now(),
        ]);

        ShippingItem::query()->create([
            'shipping_request_id' => $shippingRequest->id,
            'user_prize_id' => $userPrize->id,
        ]);

        return $shippingRequest;
    }
}
