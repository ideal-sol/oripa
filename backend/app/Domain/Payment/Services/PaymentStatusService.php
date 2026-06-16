<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\PaymentOperationException;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentStatusService
{
    public function markRefunded(Payment $payment, ?string $reason = null): Payment
    {
        return $this->mark($payment, PaymentStatus::Refunded, 'refunded_at', $reason);
    }

    public function markChargeback(Payment $payment, ?string $reason = null): Payment
    {
        return $this->mark($payment, PaymentStatus::Chargeback, 'chargeback_at', $reason, suspendUser: true);
    }

    private function mark(
        Payment $payment,
        PaymentStatus $status,
        string $timestampColumn,
        ?string $reason,
        bool $suspendUser = false,
    ): Payment {
        return DB::transaction(function () use ($payment, $status, $timestampColumn, $reason, $suspendUser): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->with('user')
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Succeeded && $lockedPayment->status !== $status) {
                throw new PaymentOperationException('Only succeeded payments can be updated to this status.');
            }

            if ($lockedPayment->status === $status) {
                return $lockedPayment->refresh()->load('user');
            }

            $metadata = $lockedPayment->metadata ?? [];
            $metadata['admin_status_change'] = [
                'status' => $status->value,
                'reason' => $reason,
                'recorded_at' => now()->toIso8601String(),
                'point_reversal' => 'pending_manual_or_followup_process',
            ];

            $lockedPayment->forceFill([
                'status' => $status,
                $timestampColumn => now(),
                'metadata' => $metadata,
            ])->save();

            if ($suspendUser && $lockedPayment->user) {
                $lockedPayment->user->forceFill([
                    'status' => 'suspended',
                ])->save();
            }

            return $lockedPayment->refresh()->load('user');
        });
    }
}
