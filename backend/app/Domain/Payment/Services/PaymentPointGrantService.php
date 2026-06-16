<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Services\PointLotService;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentPointGrantService
{
    public function __construct(private readonly PointLotService $pointLotService)
    {
    }

    public function markSucceeded(Payment $payment, ?string $webhookEventId = null): Payment
    {
        return DB::transaction(function () use ($payment, $webhookEventId): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status === PaymentStatus::Succeeded) {
                return $lockedPayment->refresh();
            }

            $lockedPayment->forceFill([
                'status' => PaymentStatus::Succeeded,
                'webhook_event_id' => $webhookEventId ?? $lockedPayment->webhook_event_id,
                'paid_at' => $lockedPayment->paid_at ?? now(),
            ])->save();

            if ($lockedPayment->paid_point_amount > 0) {
                $this->pointLotService->grantPaid(
                    user: $lockedPayment->user,
                    amount: $lockedPayment->paid_point_amount,
                    sourceType: PointLotSourceType::Purchase,
                    sourceId: $lockedPayment->id,
                    description: 'Payment purchase points.',
                );
            }

            if ($lockedPayment->free_point_amount > 0) {
                $this->pointLotService->grantFree(
                    user: $lockedPayment->user,
                    amount: $lockedPayment->free_point_amount,
                    expireAt: now()->addDays((int) config('oripa.free_point_expiration_days', 180)),
                    sourceType: PointLotSourceType::Purchase,
                    sourceId: $lockedPayment->id,
                    ledgerType: PointLedgerType::Grant,
                    relatedType: 'payment',
                    relatedId: $lockedPayment->id,
                    description: 'Payment bonus points.',
                );
            }

            return $lockedPayment->refresh();
        });
    }
}
