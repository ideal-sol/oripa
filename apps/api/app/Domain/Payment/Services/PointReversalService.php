<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentReversalPointBucket;
use App\Domain\Payment\Enums\PaymentReversalType;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Models\PaymentReversal;
use App\Models\PaymentReversalPointEntry;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PointReversalService
{
    public function __construct(private readonly RefundEligibilityService $refundEligibilityService)
    {
    }

    public function reverseForRefund(PaymentReversal $reversal): array
    {
        return DB::transaction(function () use ($reversal): array {
            $reversal = $this->lockReversal($reversal);
            $this->assertNotAlreadyReversed($reversal);

            if ($reversal->type !== PaymentReversalType::Refund) {
                throw new RuntimeException('Payment reversal type must be refund.');
            }

            $payment = $reversal->payment()->lockForUpdate()->firstOrFail();
            $eligibility = $this->refundEligibilityService->check($payment);

            if (! $eligibility['eligible']) {
                throw new RuntimeException($eligibility['reason']);
            }

            $wallet = $this->lockWallet((int) $reversal->user_id);
            $summary = $this->emptySummary();

            foreach ($this->refundLots($payment) as $lot) {
                $bucket = $lot->point_type === PointType::Paid
                    ? PaymentReversalPointBucket::PaidPurchaseFromPaid
                    : PaymentReversalPointBucket::FreeBonusFromFree;

                $this->deductFromLot(
                    reversal: $reversal,
                    wallet: $wallet,
                    lot: $lot,
                    amount: (int) $lot->remaining_amount,
                    bucket: $bucket,
                    description: 'Payment refund point cancellation.',
                    summary: $summary,
                );
            }

            return $this->persistSummary($reversal, $summary);
        });
    }

    public function reverseForChargeback(PaymentReversal $reversal): array
    {
        return DB::transaction(function () use ($reversal): array {
            $reversal = $this->lockReversal($reversal);
            $this->assertNotAlreadyReversed($reversal);

            if ($reversal->type !== PaymentReversalType::Chargeback) {
                throw new RuntimeException('Payment reversal type must be chargeback.');
            }

            $wallet = $this->lockWallet((int) $reversal->user_id);
            $summary = $this->emptySummary();

            $remainingPaidPurchase = (int) $reversal->paid_point_amount;
            $remainingFreeBonus = (int) $reversal->free_point_amount;

            $remainingPaidPurchase = $this->deductFromLotsByType(
                reversal: $reversal,
                wallet: $wallet,
                pointType: PointType::Paid,
                targetAmount: $remainingPaidPurchase,
                bucket: PaymentReversalPointBucket::PaidPurchaseFromPaid,
                description: 'Chargeback paid purchase cancellation from paid points.',
                summary: $summary,
            );

            $remainingFreeBonus = $this->deductFromLotsByType(
                reversal: $reversal,
                wallet: $wallet,
                pointType: PointType::Free,
                targetAmount: $remainingFreeBonus,
                bucket: PaymentReversalPointBucket::FreeBonusFromFree,
                description: 'Chargeback free bonus cancellation from free points.',
                summary: $summary,
            );

            $remainingPaidPurchase = $this->deductFromLotsByType(
                reversal: $reversal,
                wallet: $wallet,
                pointType: PointType::Free,
                targetAmount: $remainingPaidPurchase,
                bucket: PaymentReversalPointBucket::PaidPurchaseShortfallFromFree,
                description: 'Chargeback paid purchase shortfall cancellation from free points.',
                summary: $summary,
            );

            if ($remainingPaidPurchase > 0) {
                $this->recordShortfall($reversal, PointType::Paid, $remainingPaidPurchase);
                $summary['shortfall_paid_amount'] += $remainingPaidPurchase;
            }

            if ($remainingFreeBonus > 0) {
                $this->recordShortfall($reversal, PointType::Free, $remainingFreeBonus);
                $summary['shortfall_free_amount'] += $remainingFreeBonus;
            }

            return $this->persistSummary($reversal, $summary);
        });
    }

    private function lockReversal(PaymentReversal $reversal): PaymentReversal
    {
        return PaymentReversal::query()->whereKey($reversal->id)->lockForUpdate()->firstOrFail();
    }

    private function lockWallet(int $userId): Wallet
    {
        return Wallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    private function assertNotAlreadyReversed(PaymentReversal $reversal): void
    {
        if ($reversal->pointEntries()->exists()) {
            throw new RuntimeException('Payment reversal points have already been processed.');
        }
    }

    private function refundLots($payment)
    {
        return $this->refundEligibilityService
            ->paymentPurchaseLots($payment)
            ->where('remaining_amount', '>', 0)
            ->lockForUpdate()
            ->get();
    }

    private function deductFromLotsByType(
        PaymentReversal $reversal,
        Wallet $wallet,
        PointType $pointType,
        int $targetAmount,
        PaymentReversalPointBucket $bucket,
        string $description,
        array &$summary,
    ): int {
        $remaining = $targetAmount;

        if ($remaining <= 0) {
            return 0;
        }

        foreach ($this->reversibleLots($reversal, $pointType) as $lot) {
            $amount = min($remaining, (int) $lot->remaining_amount);

            if ($amount <= 0) {
                continue;
            }

            $this->deductFromLot($reversal, $wallet, $lot, $amount, $bucket, $description, $summary);
            $remaining -= $amount;

            if ($remaining === 0) {
                return 0;
            }
        }

        return $remaining;
    }

    private function reversibleLots(PaymentReversal $reversal, PointType $pointType)
    {
        $query = PointLot::query()
            ->where('user_id', $reversal->user_id)
            ->where('point_type', $pointType->value)
            ->where('remaining_amount', '>', 0)
            ->lockForUpdate();

        if ($pointType === PointType::Free) {
            $query
                ->where('expire_at', '>', now())
                ->orderBy('expire_at')
                ->orderBy('granted_at')
                ->orderBy('id');
        } else {
            $query
                ->orderBy('granted_at')
                ->orderBy('id');
        }

        return $query->get();
    }

    private function deductFromLot(
        PaymentReversal $reversal,
        Wallet $wallet,
        PointLot $lot,
        int $amount,
        PaymentReversalPointBucket $bucket,
        string $description,
        array &$summary,
    ): void {
        if ($amount <= 0) {
            return;
        }

        $balanceColumn = $lot->point_type === PointType::Paid ? 'paid_balance' : 'free_balance';

        if ((int) $lot->remaining_amount < $amount || (int) $wallet->{$balanceColumn} < $amount) {
            throw new RuntimeException('Point reversal would make lot or wallet balance negative.');
        }

        $lot->forceFill([
            'remaining_amount' => (int) $lot->remaining_amount - $amount,
        ])->save();

        $wallet->forceFill([
            $balanceColumn => (int) $wallet->{$balanceColumn} - $amount,
        ])->save();

        $ledger = PointLedger::query()->create([
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => $lot->id,
            'point_type' => $lot->point_type,
            'ledger_type' => PointLedgerType::Cancel,
            'amount' => -$amount,
            'balance_after' => $wallet->{$balanceColumn},
            'related_type' => 'payment_reversal',
            'related_id' => $reversal->id,
            'description' => $description,
        ]);

        PaymentReversalPointEntry::query()->create([
            'payment_reversal_id' => $reversal->id,
            'payment_id' => $reversal->payment_id,
            'user_id' => $reversal->user_id,
            'point_lot_id' => $lot->id,
            'point_ledger_id' => $ledger->id,
            'point_type' => $lot->point_type,
            'bucket' => $bucket,
            'amount' => $amount,
            'shortfall_amount' => 0,
            'created_at' => now(),
        ]);

        if ($bucket === PaymentReversalPointBucket::PaidPurchaseFromPaid) {
            $summary['paid_reversed_amount'] += $amount;
        } else {
            $summary['free_reversed_amount'] += $amount;
        }
    }

    private function recordShortfall(PaymentReversal $reversal, PointType $pointType, int $amount): void
    {
        PaymentReversalPointEntry::query()->create([
            'payment_reversal_id' => $reversal->id,
            'payment_id' => $reversal->payment_id,
            'user_id' => $reversal->user_id,
            'point_lot_id' => null,
            'point_ledger_id' => null,
            'point_type' => $pointType,
            'bucket' => PaymentReversalPointBucket::Shortfall,
            'amount' => 0,
            'shortfall_amount' => $amount,
            'created_at' => now(),
        ]);
    }

    private function persistSummary(PaymentReversal $reversal, array $summary): array
    {
        $reversal->forceFill($summary)->save();

        return $summary + [
            'payment_reversal_id' => $reversal->id,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'paid_reversed_amount' => 0,
            'free_reversed_amount' => 0,
            'shortfall_paid_amount' => 0,
            'shortfall_free_amount' => 0,
        ];
    }
}
