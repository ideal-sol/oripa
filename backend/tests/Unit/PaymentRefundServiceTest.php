<?php

namespace Tests\Unit;

use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentRefundService;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PaymentRefundServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refunds_only_when_payment_origin_points_are_unused(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 1000, freePointAmount: 200);
        $wallet = $this->createWallet($user, paid: 1000, free: 200);
        $paidLot = $this->createLot($user, PointType::Paid, 1000, 1000, sourceId: $payment->id);
        $freeLot = $this->createLot($user, PointType::Free, 200, 200, sourceId: $payment->id);

        $reversal = app(PaymentRefundService::class)->refund($payment, reason: 'customer request');

        $this->assertSame(PaymentReversalStatus::Completed, $reversal->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertNotNull($payment->refunded_at);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(0, $wallet->free_balance);
        $this->assertSame(0, $paidLot->refresh()->remaining_amount);
        $this->assertSame(0, $freeLot->refresh()->remaining_amount);
        $this->assertSame(2, PointLedger::query()->where('related_type', 'payment_reversal')->where('related_id', $reversal->id)->count());
    }

    public function test_it_rejects_refund_when_any_payment_origin_point_was_used(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 1000, freePointAmount: 0);
        $this->createWallet($user, paid: 999, free: 0);
        $this->createLot($user, PointType::Paid, 1000, 999, sourceId: $payment->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payment-origin points have already been used.');

        app(PaymentRefundService::class)->refund($payment);
    }

    public function test_it_is_idempotent_after_completed_refund(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 100, freePointAmount: 0);
        $this->createWallet($user, paid: 100, free: 0);
        $this->createLot($user, PointType::Paid, 100, 100, sourceId: $payment->id);

        $first = app(PaymentRefundService::class)->refund($payment);
        $second = app(PaymentRefundService::class)->refund($payment->refresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PaymentReversal::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, PointLedger::query()->where('related_type', 'payment_reversal')->where('related_id', $first->id)->count());
    }

    private function createPayment(User $user, int $paidPointAmount, int $freePointAmount): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'refund-payment-'.$user->id.'-'.$paidPointAmount.'-'.$freePointAmount,
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

    private function createLot(User $user, PointType $pointType, int $grantedAmount, int $remainingAmount, int $sourceId): PointLot
    {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $grantedAmount,
            'remaining_amount' => $remainingAmount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => $sourceId,
            'granted_at' => now()->subDay(),
            'expire_at' => $pointType === PointType::Free ? now()->addMonth() : null,
        ]);
    }
}
