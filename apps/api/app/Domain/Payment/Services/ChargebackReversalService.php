<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentReversalStatus;
use App\Domain\Payment\Enums\PaymentReversalType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\PaymentReversal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChargebackReversalService
{
    public function __construct(
        private readonly PointReversalService $pointReversalService,
        private readonly ChargebackPrizeActionService $chargebackPrizeActionService,
        private readonly PaymentReturnRequestMailService $paymentReturnRequestMailService,
    )
    {
    }

    public function chargeback(Payment $payment, ?AdminUser $adminUser = null, ?string $reason = null): PaymentReversal
    {
        $reversal = DB::transaction(function () use ($payment, $adminUser, $reason): PaymentReversal {
            $lockedPayment = Payment::query()
                ->with('user')
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status === PaymentStatus::Chargeback) {
                $existing = $lockedPayment->reversal()->first();

                if ($existing && $existing->status === PaymentReversalStatus::Completed) {
                    return $existing->refresh();
                }
            }

            if ($lockedPayment->status !== PaymentStatus::Succeeded) {
                throw new RuntimeException('Only succeeded payments can be marked as chargeback.');
            }

            $reversal = PaymentReversal::query()->firstOrCreate(
                ['payment_id' => $lockedPayment->id],
                [
                    'user_id' => $lockedPayment->user_id,
                    'admin_user_id' => $adminUser?->id,
                    'type' => PaymentReversalType::Chargeback,
                    'status' => PaymentReversalStatus::Pending,
                    'reason' => $reason,
                    'payment_amount' => $lockedPayment->amount,
                    'paid_point_amount' => $lockedPayment->paid_point_amount,
                    'free_point_amount' => $lockedPayment->free_point_amount,
                    'occurred_at' => now(),
                    'metadata' => [],
                ],
            );

            if ($reversal->type !== PaymentReversalType::Chargeback) {
                throw new RuntimeException('Payment already has a non-chargeback reversal.');
            }

            if ($reversal->status === PaymentReversalStatus::Completed) {
                return $reversal->refresh();
            }

            $pointSummary = $this->pointReversalService->reverseForChargeback($reversal);
            $prizeSummary = $this->chargebackPrizeActionService->apply($reversal);

            $occurredAt = $reversal->occurred_at ?? now();
            $metadata = $lockedPayment->metadata ?? [];
            $metadata['chargeback_reversal'] = [
                'payment_reversal_id' => $reversal->id,
                'reason' => $reason,
                'recorded_at' => now()->toIso8601String(),
                'point_summary' => $pointSummary,
                'prize_summary' => $prizeSummary,
            ];

            $lockedPayment->forceFill([
                'status' => PaymentStatus::Chargeback,
                'chargeback_at' => $occurredAt,
                'metadata' => $metadata,
            ])->save();

            if ($lockedPayment->user) {
                $lockedPayment->user->forceFill([
                    'status' => 'suspended',
                ])->save();
            }

            $reversal->forceFill([
                'status' => PaymentReversalStatus::Completed,
                'admin_user_id' => $adminUser?->id ?? $reversal->admin_user_id,
                'reason' => $reason ?? $reversal->reason,
                'occurred_at' => $occurredAt,
                'metadata' => [
                    'point_summary' => $pointSummary,
                    'prize_summary' => $prizeSummary,
                ],
            ])->save();

            return $reversal->refresh()->load('payment', 'user', 'pointEntries', 'prizeActions');
        });

        $this->paymentReturnRequestMailService->sendForReversal($reversal);

        return $reversal->refresh()->load('payment', 'user', 'pointEntries', 'prizeActions');
    }
}
