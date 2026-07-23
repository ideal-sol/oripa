<?php

namespace Tests\Unit;

use App\Domain\Payment\Enums\PaymentReversalPointBucket;
use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentReversalType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PointReversalService;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalPointEntry;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PointReversalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reverses_unused_payment_origin_points_for_normal_refund(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 1000, freePointAmount: 200);
        $wallet = $this->createWallet($user, paid: 1000, free: 200);
        $paidLot = $this->createLot($user, PointType::Paid, 1000, 1000, sourceId: $payment->id);
        $freeLot = $this->createLot($user, PointType::Free, 200, 200, sourceId: $payment->id);
        $reversal = $this->createReversal($payment, PaymentReversalType::Refund);

        $result = app(PointReversalService::class)->reverseForRefund($reversal);

        $this->assertSame(1000, $result['paid_reversed_amount']);
        $this->assertSame(200, $result['free_reversed_amount']);
        $this->assertSame(0, $result['shortfall_paid_amount']);
        $this->assertSame(0, $result['shortfall_free_amount']);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(0, $wallet->free_balance);
        $this->assertSame(0, $paidLot->refresh()->remaining_amount);
        $this->assertSame(0, $freeLot->refresh()->remaining_amount);
        $this->assertSame(2, PointLedger::query()->where('related_type', 'payment_reversal')->where('related_id', $reversal->id)->count());
        $this->assertSame(2, PaymentReversalPointEntry::query()->where('payment_reversal_id', $reversal->id)->where('shortfall_amount', 0)->count());
    }

    public function test_normal_refund_does_not_change_lots_from_other_payments(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 1000, freePointAmount: 200);
        $otherPayment = $this->createPayment($user, paidPointAmount: 700, freePointAmount: 300);
        $wallet = $this->createWallet($user, paid: 1700, free: 500);
        $targetPaidLot = $this->createLot($user, PointType::Paid, 1000, 1000, sourceId: $payment->id);
        $targetFreeLot = $this->createLot($user, PointType::Free, 200, 200, sourceId: $payment->id);
        $otherPaidLot = $this->createLot($user, PointType::Paid, 700, 700, sourceId: $otherPayment->id);
        $otherFreeLot = $this->createLot($user, PointType::Free, 300, 300, sourceId: $otherPayment->id);
        $campaignLot = $this->createLot($user, PointType::Free, 150, 150, sourceId: null, sourceType: PointLotSourceType::Campaign);
        $reversal = $this->createReversal($payment, PaymentReversalType::Refund);

        app(PointReversalService::class)->reverseForRefund($reversal);

        $this->assertSame(0, $targetPaidLot->refresh()->remaining_amount);
        $this->assertSame(0, $targetFreeLot->refresh()->remaining_amount);
        $this->assertSame(700, $otherPaidLot->refresh()->remaining_amount);
        $this->assertSame(300, $otherFreeLot->refresh()->remaining_amount);
        $this->assertSame(150, $campaignLot->refresh()->remaining_amount);
        $this->assertSame(700, $wallet->refresh()->paid_balance);
        $this->assertSame(300, $wallet->free_balance);
    }

    public function test_it_rejects_normal_refund_when_payment_origin_points_were_used(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 1000, freePointAmount: 0);
        $this->createWallet($user, paid: 999, free: 0);
        $this->createLot($user, PointType::Paid, 1000, 999, sourceId: $payment->id);
        $reversal = $this->createReversal($payment, PaymentReversalType::Refund);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payment-origin points have already been used.');

        app(PointReversalService::class)->reverseForRefund($reversal);
    }

    public function test_chargeback_reverses_in_confirmed_order_without_using_paid_for_free_bonus(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 500, freePointAmount: 200);
        $wallet = $this->createWallet($user, paid: 300, free: 450);
        $paidLot = $this->createLot($user, PointType::Paid, 300, 300, grantedAt: now()->subDays(3));
        $freeSoon = $this->createLot($user, PointType::Free, 250, 250, grantedAt: now()->subDays(2), expireAt: now()->addDays(3));
        $freeLater = $this->createLot($user, PointType::Free, 200, 200, grantedAt: now()->subDay(), expireAt: now()->addDays(30));
        $reversal = $this->createReversal($payment, PaymentReversalType::Chargeback);

        $result = app(PointReversalService::class)->reverseForChargeback($reversal);

        $this->assertSame(300, $result['paid_reversed_amount']);
        $this->assertSame(400, $result['free_reversed_amount']);
        $this->assertSame(0, $result['shortfall_paid_amount']);
        $this->assertSame(0, $result['shortfall_free_amount']);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(50, $wallet->free_balance);
        $this->assertSame(0, $paidLot->refresh()->remaining_amount);
        $this->assertSame(0, $freeSoon->refresh()->remaining_amount);
        $this->assertSame(50, $freeLater->refresh()->remaining_amount);

        $entries = PaymentReversalPointEntry::query()
            ->where('payment_reversal_id', $reversal->id)
            ->orderBy('id')
            ->get(['bucket', 'point_type', 'amount', 'shortfall_amount'])
            ->map(fn (PaymentReversalPointEntry $entry): array => [
                'bucket' => $entry->bucket->value,
                'point_type' => $entry->point_type->value,
                'amount' => (int) $entry->amount,
                'shortfall_amount' => (int) $entry->shortfall_amount,
            ])
            ->all();

        $this->assertSame([
            [
                'bucket' => PaymentReversalPointBucket::PaidPurchaseFromPaid->value,
                'point_type' => PointType::Paid->value,
                'amount' => 300,
                'shortfall_amount' => 0,
            ],
            [
                'bucket' => PaymentReversalPointBucket::FreeBonusFromFree->value,
                'point_type' => PointType::Free->value,
                'amount' => 200,
                'shortfall_amount' => 0,
            ],
            [
                'bucket' => PaymentReversalPointBucket::PaidPurchaseShortfallFromFree->value,
                'point_type' => PointType::Free->value,
                'amount' => 50,
                'shortfall_amount' => 0,
            ],
            [
                'bucket' => PaymentReversalPointBucket::PaidPurchaseShortfallFromFree->value,
                'point_type' => PointType::Free->value,
                'amount' => 150,
                'shortfall_amount' => 0,
            ],
        ], $entries);
    }

    public function test_chargeback_uses_paid_fifo_and_free_expiration_order_across_multiple_lots(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 150, freePointAmount: 150);
        $this->createWallet($user, paid: 200, free: 200);
        $paidOlder = $this->createLot($user, PointType::Paid, 100, 100, grantedAt: now()->subDays(10));
        $paidNewer = $this->createLot($user, PointType::Paid, 100, 100, grantedAt: now()->subDay());
        $freeSooner = $this->createLot($user, PointType::Free, 100, 100, grantedAt: now()->subDay(), expireAt: now()->addDays(3));
        $freeLater = $this->createLot($user, PointType::Free, 100, 100, grantedAt: now()->subDays(5), expireAt: now()->addDays(30));
        $reversal = $this->createReversal($payment, PaymentReversalType::Chargeback);

        app(PointReversalService::class)->reverseForChargeback($reversal);

        $this->assertSame(0, $paidOlder->refresh()->remaining_amount);
        $this->assertSame(50, $paidNewer->refresh()->remaining_amount);
        $this->assertSame(0, $freeSooner->refresh()->remaining_amount);
        $this->assertSame(50, $freeLater->refresh()->remaining_amount);

        $entryLotIds = PaymentReversalPointEntry::query()
            ->where('payment_reversal_id', $reversal->id)
            ->whereNotNull('point_lot_id')
            ->orderBy('id')
            ->pluck('point_lot_id')
            ->all();

        $this->assertSame([
            $paidOlder->id,
            $paidNewer->id,
            $freeSooner->id,
            $freeLater->id,
        ], $entryLotIds);
    }

    public function test_chargeback_excludes_expired_free_lots_from_reversal(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 0, freePointAmount: 300);
        $wallet = $this->createWallet($user, paid: 0, free: 600);
        $expiredFreeLot = $this->createLot($user, PointType::Free, 500, 500, expireAt: now()->subDay());
        $validFreeLot = $this->createLot($user, PointType::Free, 100, 100, expireAt: now()->addDay());
        $reversal = $this->createReversal($payment, PaymentReversalType::Chargeback);

        $result = app(PointReversalService::class)->reverseForChargeback($reversal);

        $this->assertSame(0, $result['paid_reversed_amount']);
        $this->assertSame(100, $result['free_reversed_amount']);
        $this->assertSame(0, $result['shortfall_paid_amount']);
        $this->assertSame(200, $result['shortfall_free_amount']);
        $this->assertSame(500, $expiredFreeLot->refresh()->remaining_amount);
        $this->assertSame(0, $validFreeLot->refresh()->remaining_amount);
        $this->assertSame(500, $wallet->refresh()->free_balance);
        $this->assertSame(1, PointLedger::query()->where('related_type', 'payment_reversal')->where('related_id', $reversal->id)->count());
        $this->assertSame(200, PaymentReversalPointEntry::query()->where('bucket', PaymentReversalPointBucket::Shortfall->value)->sum('shortfall_amount'));
    }

    public function test_chargeback_records_shortfall_without_creating_ledgers_for_shortfall(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 500, freePointAmount: 200);
        $wallet = $this->createWallet($user, paid: 100, free: 150);
        $paidLot = $this->createLot($user, PointType::Paid, 100, 100);
        $freeLot = $this->createLot($user, PointType::Free, 150, 150);
        $reversal = $this->createReversal($payment, PaymentReversalType::Chargeback);

        $result = app(PointReversalService::class)->reverseForChargeback($reversal);

        $this->assertSame(100, $result['paid_reversed_amount']);
        $this->assertSame(150, $result['free_reversed_amount']);
        $this->assertSame(400, $result['shortfall_paid_amount']);
        $this->assertSame(50, $result['shortfall_free_amount']);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(0, $wallet->free_balance);
        $this->assertSame(0, $paidLot->refresh()->remaining_amount);
        $this->assertSame(0, $freeLot->refresh()->remaining_amount);
        $this->assertSame(2, PointLedger::query()->where('ledger_type', PointLedgerType::Cancel->value)->count());
        $this->assertSame(2, PaymentReversalPointEntry::query()->where('bucket', PaymentReversalPointBucket::Shortfall->value)->count());
        $this->assertSame(0, PaymentReversalPointEntry::query()->where('bucket', PaymentReversalPointBucket::Shortfall->value)->sum('amount'));
        $this->assertSame(450, PaymentReversalPointEntry::query()->where('bucket', PaymentReversalPointBucket::Shortfall->value)->sum('shortfall_amount'));
    }

    private function createPayment(User $user, int $paidPointAmount, int $freePointAmount): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'payment-'.$user->id.'-'.$paidPointAmount.'-'.$freePointAmount,
            'status' => PaymentStatus::Succeeded,
            'amount' => 1000,
            'paid_point_amount' => $paidPointAmount,
            'free_point_amount' => $freePointAmount,
            'currency' => 'JPY',
            'paid_at' => now(),
        ]);
    }

    private function createReversal(Payment $payment, PaymentReversalType $type): PaymentReversal
    {
        return PaymentReversal::query()->create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'type' => $type,
            'status' => PaymentReversalStatus::Pending,
            'payment_amount' => $payment->amount,
            'paid_point_amount' => $payment->paid_point_amount,
            'free_point_amount' => $payment->free_point_amount,
            'occurred_at' => now(),
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

    private function createLot(
        User $user,
        PointType $pointType,
        int $grantedAmount,
        int $remainingAmount,
        ?int $sourceId = null,
        ?\DateTimeInterface $grantedAt = null,
        ?\DateTimeInterface $expireAt = null,
        PointLotSourceType $sourceType = PointLotSourceType::Purchase,
    ): PointLot {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $grantedAmount,
            'remaining_amount' => $remainingAmount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'granted_at' => $grantedAt ?? now()->subDay(),
            'expire_at' => $pointType === PointType::Free ? ($expireAt ?? now()->addMonth()) : null,
        ]);
    }
}
