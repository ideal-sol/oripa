<?php

namespace App\Domain\Payment\Services;

use App\Domain\Notification\Services\DiscordNotificationService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Services\PointLotService;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentPointGrantService
{
    public function __construct(
        private readonly PointLotService $pointLotService,
        private readonly DiscordNotificationService $discordNotification,
    )
    {
    }

    public function markSucceeded(Payment $payment, ?string $webhookEventId = null): Payment
    {
        $shouldNotify = false;

        $succeededPayment = DB::transaction(function () use ($payment, $webhookEventId, &$shouldNotify): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 決済Webhookや手動成功処理が再送されても、ポイントを二重付与しない。
            if ($lockedPayment->status === PaymentStatus::Succeeded) {
                return $lockedPayment->refresh();
            }

            $shouldNotify = true;
            $lockedPayment->forceFill([
                'status' => PaymentStatus::Succeeded,
                'webhook_event_id' => $webhookEventId ?? $lockedPayment->webhook_event_id,
                'paid_at' => $lockedPayment->paid_at ?? now(),
            ])->save();

            if ($lockedPayment->paid_point_amount > 0) {
                // 購入分は有償ポイントとして期限なしで付与する。
                $this->pointLotService->grantPaid(
                    user: $lockedPayment->user,
                    amount: $lockedPayment->paid_point_amount,
                    sourceType: PointLotSourceType::Purchase,
                    sourceId: $lockedPayment->id,
                    description: 'Payment purchase points.',
                );
            }

            if ($lockedPayment->free_point_amount > 0) {
                // ボーナス分は無償ポイントとして期限付きで付与する。
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

        if ($shouldNotify) {
            $this->discordNotification->notifyPaymentSucceeded($succeededPayment);
        }

        return $succeededPayment;
    }
}
