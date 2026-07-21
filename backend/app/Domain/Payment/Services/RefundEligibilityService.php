<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Models\Payment;
use App\Models\PointLot;

class RefundEligibilityService
{
    public function check(Payment $payment): array
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            return [
                'eligible' => false,
                'reason' => 'Only succeeded payments can be refunded.',
                'lots' => collect(),
                'used_amount' => 0,
                'refundable_amount' => 0,
            ];
        }

        $lots = $this->paymentPurchaseLots($payment)->get();

        if ($lots->isEmpty()) {
            return [
                'eligible' => false,
                'reason' => 'Payment-origin point lots were not found.',
                'lots' => $lots,
                'used_amount' => 0,
                'refundable_amount' => 0,
            ];
        }

        $usedAmount = $lots->sum(fn (PointLot $lot): int => (int) $lot->granted_amount - (int) $lot->remaining_amount);

        if ($usedAmount > 0) {
            return [
                'eligible' => false,
                'reason' => 'Payment-origin points have already been used.',
                'lots' => $lots,
                'used_amount' => $usedAmount,
                'refundable_amount' => 0,
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'lots' => $lots,
            'used_amount' => 0,
            'refundable_amount' => $lots->sum('remaining_amount'),
        ];
    }

    public function paymentPurchaseLots(Payment $payment)
    {
        return PointLot::query()
            ->where('user_id', $payment->user_id)
            ->where('source_type', PointLotSourceType::Purchase->value)
            ->where('source_id', $payment->id)
            ->orderBy('point_type')
            ->orderBy('granted_at')
            ->orderBy('id');
    }
}
