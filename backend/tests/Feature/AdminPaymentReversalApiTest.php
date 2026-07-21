<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionStatus;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentReversalType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Shipping\Enums\ShippingRequestStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaProbabilityVersionStage;
use App\Models\GachaRank;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalPrizeAction;
use App\Models\ShippingItem;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\UserPrize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentReversalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_show_payment_reversals(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment, PaymentReversalType::Chargeback);

        $this->getJson('/admin/api/payment-reversals?type=chargeback')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reversal->id)
            ->assertJsonPath('data.0.type', 'chargeback')
            ->assertJsonPath('data.0.payment.id', $payment->id);

        $this->getJson("/admin/api/payment-reversals/{$reversal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $reversal->id)
            ->assertJsonPath('data.payment.id', $payment->id);
    }

    public function test_admin_can_filter_payment_reversals_by_occurred_date_range(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();

        $firstPayment = $this->createPayment($user);
        $first = $this->createReversal($firstPayment, PaymentReversalType::Refund, '2026-06-10 12:00:00');

        $secondPayment = $this->createPayment($user);
        $second = $this->createReversal($secondPayment, PaymentReversalType::Chargeback, '2026-06-20 09:00:00');

        $thirdPayment = $this->createPayment($user);
        $this->createReversal($thirdPayment, PaymentReversalType::Chargeback, '2026-07-01 00:00:00');

        $this->getJson('/admin/api/payment-reversals?date_from=2026-06-01&date_to=2026-06-30&per_page=100')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_payment_reversal_date_filter_rejects_invalid_range(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/api/payment-reversals?date_from=2026-06-30&date_to=2026-06-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_admin_can_release_holds(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment, PaymentReversalType::Chargeback);
        $prize = $this->createUserPrize($user, UserPrizeStatus::Held);
        $action = PaymentReversalPrizeAction::query()->create([
            'payment_reversal_id' => $reversal->id,
            'user_prize_id' => $prize->id,
            'shipping_item_id' => null,
            'action_type' => PaymentReversalPrizeActionType::Hold,
            'previous_user_prize_status' => UserPrizeStatus::Stored->value,
            'previous_shipping_item_status' => null,
            'status' => PaymentReversalPrizeActionStatus::Pending,
        ]);

        $this->postJson("/admin/api/payment-reversals/{$reversal->id}/release-holds", [
            'note' => 'Reviewed.',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $reversal->id);

        $this->assertSame(UserPrizeStatus::Stored, $prize->refresh()->status);
        $this->assertSame(PaymentReversalPrizeActionStatus::Released, $action->refresh()->status);
        $this->assertSame('Reviewed.', $action->note);
    }

    public function test_admin_can_mark_return_requested_action_returned(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment, PaymentReversalType::Chargeback);
        $prize = $this->createUserPrize($user, UserPrizeStatus::ShippingRequested);
        $item = $this->createShippingItem($user, $prize, ShippingRequestStatus::ReturnRequested);
        $action = PaymentReversalPrizeAction::query()->create([
            'payment_reversal_id' => $reversal->id,
            'user_prize_id' => $prize->id,
            'shipping_item_id' => $item->id,
            'action_type' => PaymentReversalPrizeActionType::ReturnRequested,
            'previous_user_prize_status' => UserPrizeStatus::ShippingRequested->value,
            'previous_shipping_item_status' => ShippingRequestStatus::Shipped->value,
            'status' => PaymentReversalPrizeActionStatus::Pending,
        ]);

        $this->postJson("/admin/api/payment-reversal-prize-actions/{$action->id}/mark-returned", [
            'note' => 'Returned by customer.',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $action->id)
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(ShippingRequestStatus::Returned, $item->refresh()->status);
        $this->assertSame(PaymentReversalPrizeActionStatus::Completed, $action->refresh()->status);
        $this->assertSame('Returned by customer.', $action->note);
    }

    public function test_invalid_mark_returned_action_returns_422(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment, PaymentReversalType::Chargeback);
        $prize = $this->createUserPrize($user, UserPrizeStatus::Held);
        $action = PaymentReversalPrizeAction::query()->create([
            'payment_reversal_id' => $reversal->id,
            'user_prize_id' => $prize->id,
            'shipping_item_id' => null,
            'action_type' => PaymentReversalPrizeActionType::Hold,
            'previous_user_prize_status' => UserPrizeStatus::Stored->value,
            'status' => PaymentReversalPrizeActionStatus::Pending,
        ]);

        $this->postJson("/admin/api/payment-reversal-prize-actions/{$action->id}/mark-returned")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
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

    private function createPayment(User $user): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'reversal-payment-'.$user->id.'-'.uniqid(),
            'status' => PaymentStatus::Chargeback,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 0,
            'currency' => 'JPY',
            'paid_at' => now()->subDay(),
            'chargeback_at' => now(),
        ]);
    }

    private function createReversal(Payment $payment, PaymentReversalType $type, ?string $occurredAt = null): PaymentReversal
    {
        return PaymentReversal::query()->create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'type' => $type,
            'status' => PaymentReversalStatus::Completed,
            'payment_amount' => $payment->amount,
            'paid_point_amount' => $payment->paid_point_amount,
            'free_point_amount' => $payment->free_point_amount,
            'paid_reversed_amount' => 1000,
            'free_reversed_amount' => 0,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    private function createUserPrize(User $user, UserPrizeStatus $status): UserPrize
    {
        $gacha = Gacha::factory()->create();
        $rank = GachaRank::factory()->for($gacha)->create();
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create();
        $version = GachaProbabilityVersion::query()->create([
            'gacha_id' => $gacha->id,
            'version_number' => 1,
            'status' => 'draft',
        ]);
        $stage = GachaProbabilityVersionStage::query()->create([
            'probability_version_id' => $version->id,
            'stage_key' => 'default',
            'name' => 'Default',
            'condition_type' => 'sold_count',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
        ]);
        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'reversal-api-'.uniqid(),
            'status' => DrawRequestStatus::Completed,
            'consumed_point_total' => 100,
        ]);
        $drawResult = DrawResult::query()->create([
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

        return UserPrize::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $drawResult->id,
            'status' => $status,
            'acquired_at' => now(),
            'storage_expire_at' => now()->addMonth(),
            'converted_point' => null,
        ]);
    }

    private function createShippingItem(User $user, UserPrize $userPrize, ShippingRequestStatus $status): ShippingItem
    {
        $request = ShippingRequest::query()->create([
            'user_id' => $user->id,
            'status' => ShippingRequestStatus::Requested,
            'recipient_name' => 'Test User',
            'postal_code' => '1000001',
            'prefecture' => 'Tokyo',
            'city' => 'Chiyoda',
            'address_line1' => '1-1',
            'address_line2' => null,
            'phone_number' => '0312345678',
            'requested_at' => now(),
        ]);

        return ShippingItem::query()->create([
            'shipping_request_id' => $request->id,
            'user_prize_id' => $userPrize->id,
            'status' => $status,
        ]);
    }
}
