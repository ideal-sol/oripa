<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Exceptions\PaymentWebhookException;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentWebhookService
{
    public function __construct(private readonly PaymentPointGrantService $paymentPointGrantService)
    {
    }
    public function handle(array $payload): array
    {
        $eventId = (string) $payload['event_id'];

        $existingPayment = Payment::query()
            ->where('webhook_event_id', $eventId)
            ->first();

        if ($existingPayment) {
            return [
                'payment' => $existingPayment,
                'duplicate' => true,
            ];
        }

        $payment = Payment::query()
            ->where('provider', (string) ($payload['provider'] ?? 'mock'))
            ->where('provider_payment_id', (string) $payload['provider_payment_id'])
            ->first();

        if (! $payment) {
            throw new PaymentWebhookException('Payment was not found.');
        }

        $type = (string) $payload['type'];

        return match ($type) {
            'payment.succeeded' => [
                'payment' => $this->paymentPointGrantService->markSucceeded($payment, $eventId),
                'duplicate' => false,
            ],
            'payment.failed' => [
                'payment' => $this->markTerminal($payment, PaymentStatus::Failed, $eventId),
                'duplicate' => false,
            ],
            'payment.canceled' => [
                'payment' => $this->markTerminal($payment, PaymentStatus::Canceled, $eventId),
                'duplicate' => false,
            ],
            default => throw new PaymentWebhookException('Unsupported webhook type.'),
        };
    }

    private function markTerminal(Payment $payment, PaymentStatus $status, string $eventId): Payment
    {
        return DB::transaction(function () use ($payment, $status, $eventId): Payment {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPayment->status === PaymentStatus::Succeeded) {
                throw new PaymentWebhookException('Succeeded payment cannot be marked as failed or canceled.');
            }

            if (in_array($lockedPayment->status, [PaymentStatus::Failed, PaymentStatus::Canceled], true)) {
                return $lockedPayment->refresh();
            }

            $lockedPayment->forceFill([
                'status' => $status,
                'webhook_event_id' => $eventId,
            ])->save();

            return $lockedPayment->refresh();
        });
    }
}
