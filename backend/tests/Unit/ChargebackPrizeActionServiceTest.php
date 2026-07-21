<?php

namespace Tests\Unit;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentReversalType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\ChargebackPrizeActionService;
use App\Domain\Shipping\Enums\ShippingRequestStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
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
use Tests\TestCase;

class ChargebackPrizeActionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_holds_unshipped_prizes_and_marks_shipped_items_for_return_request(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);

        $storedPrize = $this->createUserPrize($user, UserPrizeStatus::Stored);
        $requestedPrize = $this->createUserPrize($user, UserPrizeStatus::ShippingRequested);
        $requestedItem = $this->createShippingItem($user, $requestedPrize, ShippingRequestStatus::Requested);
        $shippedPrize = $this->createUserPrize($user, UserPrizeStatus::ShippingRequested);
        $shippedItem = $this->createShippingItem($user, $shippedPrize, ShippingRequestStatus::Shipped);
        $convertedPrize = $this->createUserPrize($user, UserPrizeStatus::Converted);

        $summary = app(ChargebackPrizeActionService::class)->apply($reversal);

        $this->assertSame([
            'held_count' => 2,
            'return_requested_count' => 1,
            'no_action_count' => 1,
        ], $summary);
        $this->assertSame(UserPrizeStatus::Held, $storedPrize->refresh()->status);
        $this->assertSame(UserPrizeStatus::Held, $requestedPrize->refresh()->status);
        $this->assertSame(ShippingRequestStatus::Hold, $requestedItem->refresh()->status);
        $this->assertSame(UserPrizeStatus::ShippingRequested, $shippedPrize->refresh()->status);
        $this->assertSame(ShippingRequestStatus::ReturnRequested, $shippedItem->refresh()->status);
        $this->assertSame(UserPrizeStatus::Converted, $convertedPrize->refresh()->status);

        $this->assertSame(2, PaymentReversalPrizeAction::query()->where('action_type', PaymentReversalPrizeActionType::Hold->value)->count());
        $this->assertSame(1, PaymentReversalPrizeAction::query()->where('action_type', PaymentReversalPrizeActionType::ReturnRequested->value)->count());
        $this->assertSame(1, PaymentReversalPrizeAction::query()->where('action_type', PaymentReversalPrizeActionType::NoAction->value)->count());
    }

    public function test_it_is_idempotent_when_prize_actions_already_exist(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $reversal = $this->createReversal($payment);
        $storedPrize = $this->createUserPrize($user, UserPrizeStatus::Stored);

        app(ChargebackPrizeActionService::class)->apply($reversal);
        $summary = app(ChargebackPrizeActionService::class)->apply($reversal);

        $this->assertSame([
            'held_count' => 1,
            'return_requested_count' => 0,
            'no_action_count' => 0,
        ], $summary);
        $this->assertSame(UserPrizeStatus::Held, $storedPrize->refresh()->status);
        $this->assertSame(1, PaymentReversalPrizeAction::query()->where('payment_reversal_id', $reversal->id)->count());
    }

    private function createPayment(User $user): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'payment-'.$user->id.'-prizes',
            'status' => PaymentStatus::Succeeded,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 0,
            'currency' => 'JPY',
            'paid_at' => now(),
        ]);
    }

    private function createReversal(Payment $payment): PaymentReversal
    {
        return PaymentReversal::query()->create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'type' => PaymentReversalType::Chargeback,
            'status' => PaymentReversalStatus::Pending,
            'payment_amount' => $payment->amount,
            'paid_point_amount' => $payment->paid_point_amount,
            'free_point_amount' => $payment->free_point_amount,
            'occurred_at' => now(),
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
            'idempotency_key' => 'idem-'.$user->id.'-'.$status->value.'-'.uniqid(),
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
            'converted_point' => $status === UserPrizeStatus::Converted ? 100 : null,
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
            'tracking_number' => $status === ShippingRequestStatus::Shipped ? 'TRACK123' : null,
            'shipped_at' => $status === ShippingRequestStatus::Shipped ? now() : null,
        ]);
    }
}
