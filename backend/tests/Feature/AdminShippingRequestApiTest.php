<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
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

class AdminShippingRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_shipping_requests(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['email' => 'shipping-user@example.test']);
        $shippingRequest = $this->createShippingRequest($user, ShippingRequestStatus::Requested);
        $this->createShippingRequest(User::factory()->create(), ShippingRequestStatus::Packing);

        $this->getJson('/admin/api/shipping-requests?status=requested')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shippingRequest->id)
            ->assertJsonPath('data.0.status', 'requested')
            ->assertJsonPath('data.0.user.email', 'shipping-user@example.test')
            ->assertJsonPath('data.0.items_count', 1);
    }

    public function test_admin_can_show_shipping_request(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $shippingRequest = $this->createShippingRequest($user, ShippingRequestStatus::Requested);

        $this->getJson("/admin/api/shipping-requests/{$shippingRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $shippingRequest->id)
            ->assertJsonPath('data.items.0.user_prize.status', 'shipping_requested')
            ->assertJsonPath('data.items.0.user_prize.gacha.title', 'Admin Shipping Gacha')
            ->assertJsonPath('data.items.0.user_prize.prize.name', 'Admin Shipping Prize');
    }

    public function test_user_token_cannot_access_admin_shipping_requests(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/shipping-requests')->assertForbidden();
    }

    public function test_admin_can_mark_shipping_request_as_shipped_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $user = User::factory()->create();
        $shippingRequest = $this->createShippingRequest($user, ShippingRequestStatus::Packing);
        $userPrizeId = $shippingRequest->items()->firstOrFail()->user_prize_id;

        $this->putJson("/admin/api/shipping-requests/{$shippingRequest->id}", [
            'status' => ShippingRequestStatus::Shipped->value,
            'tracking_number' => 'TRACK-123',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.tracking_number', 'TRACK-123');

        $this->assertDatabaseHas('shipping_requests', [
            'id' => $shippingRequest->id,
            'status' => 'shipped',
            'tracking_number' => 'TRACK-123',
        ]);
        $this->assertDatabaseHas('user_prizes', [
            'id' => $userPrizeId,
            'status' => 'shipped',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.shipping_request.updated',
            'auditable_type' => ShippingRequest::class,
            'auditable_id' => $shippingRequest->id,
        ]);
        $this->assertDatabaseHas('shipping_request_histories', [
            'shipping_request_id' => $shippingRequest->id,
            'admin_user_id' => $admin->id,
            'from_status' => 'packing',
            'to_status' => 'shipped',
            'tracking_number' => 'TRACK-123',
        ]);
    }

    public function test_admin_can_mark_shipped_request_as_delivered(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $shippingRequest = $this->createShippingRequest($user, ShippingRequestStatus::Shipped);
        $userPrizeId = $shippingRequest->items()->firstOrFail()->user_prize_id;

        $this->putJson("/admin/api/shipping-requests/{$shippingRequest->id}", [
            'status' => ShippingRequestStatus::Delivered->value,
            'note' => 'Delivered by carrier.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.tracking_number', 'TRACK-OLD');

        $this->assertDatabaseHas('shipping_requests', [
            'id' => $shippingRequest->id,
            'status' => 'delivered',
            'tracking_number' => 'TRACK-OLD',
        ]);
        $this->assertDatabaseHas('user_prizes', [
            'id' => $userPrizeId,
            'status' => 'shipped',
        ]);
        $this->assertDatabaseHas('shipping_request_histories', [
            'shipping_request_id' => $shippingRequest->id,
            'from_status' => 'shipped',
            'to_status' => 'delivered',
            'tracking_number' => 'TRACK-OLD',
            'note' => 'Delivered by carrier.',
        ]);
    }

    public function test_admin_cannot_skip_shipping_status_transition(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $shippingRequest = $this->createShippingRequest($user, ShippingRequestStatus::Requested);

        $this->putJson("/admin/api/shipping-requests/{$shippingRequest->id}", [
            'status' => ShippingRequestStatus::Shipped->value,
            'tracking_number' => 'TRACK-SKIP',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('shipping_requests', [
            'id' => $shippingRequest->id,
            'status' => 'requested',
            'tracking_number' => null,
        ]);
    }

    public function test_admin_cannot_cancel_already_shipped_request(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $shippingRequest = $this->createShippingRequest($user, ShippingRequestStatus::Shipped);
        $userPrizeId = $shippingRequest->items()->firstOrFail()->user_prize_id;

        $this->putJson("/admin/api/shipping-requests/{$shippingRequest->id}", [
            'status' => ShippingRequestStatus::Canceled->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('shipping_requests', [
            'id' => $shippingRequest->id,
            'status' => 'shipped',
        ]);
        $this->assertDatabaseHas('user_prizes', [
            'id' => $userPrizeId,
            'status' => 'shipped',
        ]);
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

    private function createShippingRequest(User $user, ShippingRequestStatus $status): ShippingRequest
    {
        [$gacha, $prize] = $this->createPrizeFixture();
        $userPrize = $this->createUserPrize(
            user: $user,
            gacha: $gacha,
            prize: $prize,
            status: $status === ShippingRequestStatus::Shipped ? UserPrizeStatus::Shipped : UserPrizeStatus::ShippingRequested,
        );

        $shippingRequest = ShippingRequest::query()->create([
            'user_id' => $user->id,
            'status' => $status,
            'recipient_name' => '山田 太郎',
            'postal_code' => '100-0001',
            'prefecture' => '東京都',
            'city' => '千代田区',
            'address_line1' => '千代田1-1',
            'address_line2' => null,
            'phone_number' => '09012345678',
            'tracking_number' => $status === ShippingRequestStatus::Shipped ? 'TRACK-OLD' : null,
            'requested_at' => now()->subDay(),
            'shipped_at' => $status === ShippingRequestStatus::Shipped ? now() : null,
        ]);

        ShippingItem::query()->create([
            'shipping_request_id' => $shippingRequest->id,
            'user_prize_id' => $userPrize->id,
        ]);

        return $shippingRequest->refresh()->load('items');
    }

    /**
     * @return array{0: Gacha, 1: GachaPrize}
     */
    private function createPrizeFixture(): array
    {
        $gacha = Gacha::factory()->create([
            'title' => 'Admin Shipping Gacha',
            'slug' => 'admin-shipping-gacha-'.fake()->uuid(),
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'display_name' => 'S Rank',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'Admin Shipping Prize',
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

        return [$gacha->refresh(), $prize];
    }

    private function createUserPrize(User $user, Gacha $gacha, GachaPrize $prize, UserPrizeStatus $status): UserPrize
    {
        $version = $gacha->currentProbabilityVersion()->with('stages')->firstOrFail();
        $stage = $version->stages->first();
        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'admin-shipping-'.$user->id.'-'.microtime(true),
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
            'acquired_at' => now()->subDay(),
            'storage_expire_at' => now()->addDays(30),
        ]);
    }
}
