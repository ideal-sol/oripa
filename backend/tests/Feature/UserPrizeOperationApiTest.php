<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\User;
use App\Models\UserPrize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPrizeOperationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_exchange_stored_prize_for_free_points(): void
    {
        $user = User::factory()->create();
        [$gacha, , $prize] = $this->createPrizeFixture(exchangePoint: 300);
        $userPrize = $this->createUserPrize($user, $gacha, $prize, UserPrizeStatus::Stored);

        Sanctum::actingAs($user);

        $this->postJson("/api/me/prizes/{$userPrize->id}/exchange")
            ->assertOk()
            ->assertJsonPath('data.id', $userPrize->id)
            ->assertJsonPath('data.status', 'converted')
            ->assertJsonPath('data.converted_point', 300);

        $this->assertDatabaseHas('user_prizes', [
            'id' => $userPrize->id,
            'status' => 'converted',
            'converted_point' => 300,
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 0,
            'free_balance' => 300,
        ]);
        $this->assertDatabaseHas('point_ledgers', [
            'user_id' => $user->id,
            'point_type' => PointType::Free->value,
            'ledger_type' => PointLedgerType::Exchange->value,
            'amount' => 300,
            'related_type' => 'user_prize',
            'related_id' => $userPrize->id,
        ]);

        $this->assertDatabaseHas('point_lots', [
            'user_id' => $user->id,
            'point_type' => PointType::Free->value,
            'granted_amount' => 300,
            'remaining_amount' => 300,
        ]);
    }

    public function test_user_cannot_exchange_other_users_prize(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$gacha, , $prize] = $this->createPrizeFixture(exchangePoint: 300);
        $userPrize = $this->createUserPrize($other, $gacha, $prize, UserPrizeStatus::Stored);

        Sanctum::actingAs($user);

        $this->postJson("/api/me/prizes/{$userPrize->id}/exchange")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_prize');

        $this->assertDatabaseHas('user_prizes', [
            'id' => $userPrize->id,
            'status' => 'stored',
            'converted_point' => null,
        ]);
    }

    public function test_user_can_create_shipping_request_for_stored_prizes(): void
    {
        config(['services.discord.admin_webhook_url' => 'https://discord.test/webhook']);
        Http::fake([
            'discord.test/*' => Http::response('', 204),
        ]);
        $user = User::factory()->create();
        [$gacha, , $prize] = $this->createPrizeFixture(exchangePoint: 300);
        $firstPrize = $this->createUserPrize($user, $gacha, $prize, UserPrizeStatus::Stored);
        $secondPrize = $this->createUserPrize($user, $gacha, $prize, UserPrizeStatus::Stored);

        Sanctum::actingAs($user);

        $this->postJson('/api/me/shipping-requests', [
            'user_prize_ids' => [$firstPrize->id, $secondPrize->id],
            'recipient_name' => '山田 太郎',
            'postal_code' => '100-0001',
            'prefecture' => '東京都',
            'city' => '千代田区',
            'address_line1' => '千代田1-1',
            'address_line2' => 'テストビル101',
            'phone_number' => '09012345678',
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'requested')
            ->assertJsonPath('data.0.recipient_name', '山田 太郎')
            ->assertJsonCount(1, 'data.0.items')
            ->assertJsonCount(1, 'data.1.items');

        $this->assertDatabaseHas('shipping_requests', [
            'user_id' => $user->id,
            'status' => 'requested',
            'recipient_name' => '山田 太郎',
        ]);
        $this->assertDatabaseCount('shipping_requests', 2);
        $this->assertDatabaseCount('shipping_items', 2);
        $this->assertDatabaseHas('shipping_items', ['user_prize_id' => $firstPrize->id]);
        $this->assertDatabaseHas('shipping_items', ['user_prize_id' => $secondPrize->id]);
        $this->assertDatabaseHas('user_prizes', [
            'id' => $firstPrize->id,
            'status' => 'shipping_requested',
        ]);
        $this->assertDatabaseHas('user_prizes', [
            'id' => $secondPrize->id,
            'status' => 'shipping_requested',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.test/webhook'
            && str_contains($request['content'], '【新規配送申請】')
            && str_contains($request['content'], '宛名: 山田 太郎')
            && str_contains($request['content'], '景品数: 1件'));
    }

    public function test_shipping_request_rejects_non_stored_or_other_users_prize(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$gacha, , $prize] = $this->createPrizeFixture(exchangePoint: 300);
        $ownPrize = $this->createUserPrize($user, $gacha, $prize, UserPrizeStatus::Converted, convertedPoint: 300);
        $otherPrize = $this->createUserPrize($other, $gacha, $prize, UserPrizeStatus::Stored);

        Sanctum::actingAs($user);

        $this->postJson('/api/me/shipping-requests', [
            'user_prize_ids' => [$ownPrize->id, $otherPrize->id],
            'recipient_name' => '山田 太郎',
            'postal_code' => '100-0001',
            'prefecture' => '東京都',
            'city' => '千代田区',
            'address_line1' => '千代田1-1',
            'phone_number' => '09012345678',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_prize_ids');

        $this->assertDatabaseCount('shipping_requests', 0);
        $this->assertDatabaseCount('shipping_items', 0);
    }

    /**
     * @return array{0: Gacha, 1: GachaRank, 2: GachaPrize}
     */
    private function createPrizeFixture(int $exchangePoint): array
    {
        $gacha = Gacha::factory()->create([
            'title' => 'Operation Gacha',
            'slug' => 'operation-gacha-'.fake()->uuid(),
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'display_name' => 'S Rank',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'Operation Prize',
            'exchange_point' => $exchangePoint,
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

    private function createUserPrize(
        User $user,
        Gacha $gacha,
        GachaPrize $prize,
        UserPrizeStatus $status,
        ?int $convertedPoint = null,
    ): UserPrize {
        $version = $gacha->refresh()->currentProbabilityVersion()->with('stages')->firstOrFail();
        $stage = $version->stages->first();
        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'test-'.$user->id.'-'.$status->value.'-'.microtime(true),
            'status' => DrawRequestStatus::Completed,
            'consumed_point_total' => $gacha->price,
        ]);

        $drawResult = DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => $drawRequest->id,
            'rank_id' => $prize->rank_id,
            'prize_id' => $prize->id,
            'result_type' => DrawResultType::Prize,
            'consumed_point' => $gacha->price,
            'granted_point' => 0,
            'random_value' => 1,
            'probability_version_id' => $version->id,
            'probability_version_stage_id' => $stage->id,
        ]);

        return UserPrize::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $drawResult->id,
            'status' => $status,
            'acquired_at' => now(),
            'storage_expire_at' => now()->addDays(30),
            'converted_point' => $convertedPoint,
        ]);
    }
}
