<?php

namespace Tests\Unit;

use App\Domain\Payment\Enums\PaymentReversalPointBucket;
use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Shipping\Enums\ShippingRequestStatus;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaProbabilityVersionStage;
use App\Models\GachaRank;
use App\Domain\Payment\Services\ChargebackReversalService;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalPrizeAction;
use App\Models\PaymentReversalPointEntry;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\ShippingItem;
use App\Models\ShippingRequest;
use App\Models\User;
use App\Models\UserPrize;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class ChargebackReversalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_payment_chargeback_suspends_user_and_reverses_current_points(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 500, freePointAmount: 200);
        $wallet = $this->createWallet($user, paid: 300, free: 450);
        $paidLot = $this->createLot($user, PointType::Paid, 300, 300);
        $freeLot = $this->createLot($user, PointType::Free, 450, 450);

        $reversal = app(ChargebackReversalService::class)->chargeback($payment, reason: 'card dispute');

        $this->assertSame(PaymentReversalStatus::Completed, $reversal->status);
        $this->assertSame(PaymentStatus::Chargeback, $payment->refresh()->status);
        $this->assertNotNull($payment->chargeback_at);
        $this->assertSame('suspended', $user->refresh()->status);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(50, $wallet->free_balance);
        $this->assertSame(0, $paidLot->refresh()->remaining_amount);
        $this->assertSame(50, $freeLot->refresh()->remaining_amount);
        $this->assertSame(3, PointLedger::query()->where('related_type', 'payment_reversal')->where('related_id', $reversal->id)->count());
        $this->assertSame(200, PaymentReversalPointEntry::query()->where('payment_reversal_id', $reversal->id)->where('bucket', PaymentReversalPointBucket::PaidPurchaseShortfallFromFree->value)->sum('amount'));
    }

    public function test_it_is_idempotent_after_completed_chargeback(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 100, freePointAmount: 0);
        $this->createWallet($user, paid: 100, free: 0);
        $this->createLot($user, PointType::Paid, 100, 100);

        $first = app(ChargebackReversalService::class)->chargeback($payment);
        $second = app(ChargebackReversalService::class)->chargeback($payment->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PaymentReversal::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, PointLedger::query()->where('related_type', 'payment_reversal')->where('related_id', $first->id)->count());
    }

    public function test_mail_failure_does_not_roll_back_chargeback_processing(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 100, freePointAmount: 0);
        $this->createWallet($user, paid: 100, free: 0);
        $this->createLot($user, PointType::Paid, 100, 100);
        $userPrize = $this->createUserPrize($user, UserPrizeStatus::ShippingRequested);
        $shippingItem = $this->createShippingItem($user, $userPrize, ShippingRequestStatus::Shipped);

        $reversal = app(ChargebackReversalService::class)->chargeback($payment);

        $this->assertSame(PaymentStatus::Chargeback, $payment->refresh()->status);
        $this->assertSame(ShippingRequestStatus::ReturnRequested, $shippingItem->refresh()->status);
        $action = PaymentReversalPrizeAction::query()
            ->where('payment_reversal_id', $reversal->id)
            ->where('action_type', PaymentReversalPrizeActionType::ReturnRequested->value)
            ->firstOrFail();
        $this->assertNull($action->mail_sent_at);
        $this->assertNotNull($action->mail_last_attempted_at);
        $this->assertStringContainsString('smtp down', $action->mail_last_error);
    }

    private function createPayment(User $user, int $paidPointAmount, int $freePointAmount): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'chargeback-payment-'.$user->id.'-'.$paidPointAmount.'-'.$freePointAmount,
            'status' => PaymentStatus::Succeeded,
            'amount' => 1000,
            'paid_point_amount' => $paidPointAmount,
            'free_point_amount' => $freePointAmount,
            'currency' => 'JPY',
            'paid_at' => now(),
        ]);
    }

    private function createWallet(User $user, int $paid, int $free): Wallet
    {
        return Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => $paid,
            'free_balance' => $free,
        ]);
    }

    private function createLot(User $user, PointType $pointType, int $grantedAmount, int $remainingAmount): PointLot
    {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $grantedAmount,
            'remaining_amount' => $remainingAmount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => null,
            'granted_at' => now()->subDay(),
            'expire_at' => $pointType === PointType::Free ? now()->addMonth() : null,
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
            'idempotency_key' => 'chargeback-mail-'.uniqid(),
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
            'tracking_number' => 'TRACK123',
            'shipped_at' => now(),
        ]);
    }
}
