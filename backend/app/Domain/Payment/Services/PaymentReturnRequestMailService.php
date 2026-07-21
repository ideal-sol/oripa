<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentReversalPrizeActionType;
use App\Mail\ChargebackReturnRequestMail;
use App\Models\PaymentReversal;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PaymentReturnRequestMailService
{
    public function sendForReversal(PaymentReversal $paymentReversal): array
    {
        $reversal = PaymentReversal::query()
            ->with('user', 'payment', 'prizeActions.userPrize.prize', 'prizeActions.shippingItem')
            ->findOrFail($paymentReversal->id);

        $returnActions = $reversal->prizeActions
            ->where('action_type', PaymentReversalPrizeActionType::ReturnRequested);

        $targetActions = $returnActions
            ->whereNull('mail_sent_at')
            ->values();

        if ($targetActions->isEmpty()) {
            return [
                'attempted' => false,
                'sent' => false,
                'sent_count' => 0,
                'failed_count' => 0,
                'skipped_count' => $returnActions->count(),
                'message' => $returnActions->isEmpty()
                    ? 'No return requested prize actions.'
                    : 'All return request mails have already been sent.',
            ];
        }

        $attemptedAt = now();

        try {
            Mail::to($reversal->user->email, $reversal->user->name)
                ->send(new ChargebackReturnRequestMail($reversal, $targetActions));

            foreach ($targetActions as $action) {
                $action->forceFill([
                    'mail_sent_at' => $attemptedAt,
                    'mail_last_attempted_at' => $attemptedAt,
                    'mail_last_error' => null,
                ])->save();
            }

            return [
                'attempted' => true,
                'sent' => true,
                'sent_count' => $targetActions->count(),
                'failed_count' => 0,
                'skipped_count' => $returnActions->count() - $targetActions->count(),
                'message' => 'Return request mail sent.',
            ];
        } catch (Throwable $throwable) {
            foreach ($targetActions as $action) {
                $action->forceFill([
                    'mail_last_attempted_at' => $attemptedAt,
                    'mail_last_error' => mb_substr($throwable->getMessage(), 0, 2000),
                ])->save();
            }

            return [
                'attempted' => true,
                'sent' => false,
                'sent_count' => 0,
                'failed_count' => $targetActions->count(),
                'skipped_count' => $returnActions->count() - $targetActions->count(),
                'message' => 'Return request mail failed.',
                'error' => $throwable->getMessage(),
            ];
        }
    }
}
