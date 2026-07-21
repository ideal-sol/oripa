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

class PaymentRefundService
{
    public function __construct(private readonly PointReversalService $pointReversalService)
    {
    }

    public function refund(Payment $payment, ?AdminUser $adminUser = null, ?string $reason = null): PaymentReversal
    {
        return DB::transaction(function () use ($payment, $adminUser, $reason): PaymentReversal {
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status === PaymentStatus::Refunded) {
                $existing = $lockedPayment->reversal()->first();

                if ($existing && $existing->status === PaymentReversalStatus::Completed) {
                    return $existing->refresh();
                }
            }

            if ($lockedPayment->status !== PaymentStatus::Succeeded) {
                throw new RuntimeException('Only succeeded payments can be refunded.');
            }

            $reversal = PaymentReversal::query()->firstOrCreate(
                ['payment_id' => $lockedPayment->id],
                [
                    'user_id' => $lockedPayment->user_id,
                    'admin_user_id' => $adminUser?->id,
                    'type' => PaymentReversalType::Refund,
                    'status' => PaymentReversalStatus::Pending,
                    'reason' => $reason,
                    'payment_amount' => $lockedPayment->amount,
                    'paid_point_amount' => $lockedPayment->paid_point_amount,
                    'free_point_amount' => $lockedPayment->free_point_amount,
                    'occurred_at' => now(),
                    'metadata' => [],
                ],
            );

            if ($reversal->type !== PaymentReversalType::Refund) {
                throw new RuntimeException('Payment already has a non-refund reversal.');
            }

            if ($reversal->status === PaymentReversalStatus::Completed) {
                return $reversal->refresh();
            }

            $pointSummary = $this->pointReversalService->reverseForRefund($reversal);
            $occurredAt = $reversal->occurred_at ?? now();
            $metadata = $lockedPayment->metadata ?? [];
            $metadata['refund_reversal'] = [
                'payment_reversal_id' => $reversal->id,
                'reason' => $reason,
                'recorded_at' => now()->toIso8601String(),
                'point_summary' => $pointSummary,
            ];

            $lockedPayment->forceFill([
                'status' => PaymentStatus::Refunded,
                'refunded_at' => $occurredAt,
                'metadata' => $metadata,
            ])->save();

            $reversal->forceFill([
                'status' => PaymentReversalStatus::Completed,
                'admin_user_id' => $adminUser?->id ?? $reversal->admin_user_id,
                'reason' => $reason ?? $reversal->reason,
                'occurred_at' => $occurredAt,
                'metadata' => [
                    'point_summary' => $pointSummary,
                ],
            ])->save();

            return $reversal->refresh()->load('payment', 'user', 'pointEntries');
        });
    }
}
