<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2UnivapayWebhookService
{
    public const TOPIC = 'payment.univapay';

    public function receive(array $body): void
    {
        $eventId = $body['id'] ?? null;
        $kind = $body['event'] ?? null;
        $known = in_array($kind, ['charge_finished','charge_updated','refund_finished','cancel_finished'], true);
        $chargeId = $known && is_array($body['data'] ?? null)
            ? ($kind === 'refund_finished' || $kind === 'cancel_finished' ? ($body['data']['charge_id'] ?? null) : ($body['data']['id'] ?? null)) : null;
        $validId = is_string($eventId) && Str::isUuid($eventId);
        $validCharge = is_string($chargeId) && Str::isUuid($chargeId);
        $kind = $known && $validCharge ? $kind : 'ignored';
        // Even malformed/unknown input gets a minimal receipt; no raw body is stored.
        $eventId = $validId ? $eventId : (string) Str::uuid7();
        DB::transaction(function () use ($eventId, $chargeId, $kind): void {
            DB::statement("SET LOCAL statement_timeout = '1500ms'");
            DB::statement("SET LOCAL lock_timeout = '500ms'");
            try {
                $receipt = app(V2PaymentService::class)->recordVerifiedProviderEvent('univapay', 'receipt:'.$eventId,
                    'univapay.'.$kind, json_encode(['charge_id' => $kind === 'ignored' ? null : $chargeId], JSON_THROW_ON_ERROR));
            } catch (V2PaymentException $e) {
                if ($e->getMessage() !== 'PROVIDER_EVENT_ID_REUSED') throw $e;
                return; // Preserve the first authenticated envelope; never replace its charge association.
            }
            if ($kind === 'ignored') return;
            app(V2OutboxService::class)->enqueue(self::TOPIC, 'payment', null, 'univapay.lookup',
                ['event_id' => $eventId, 'charge_id' => $chargeId], 'univapay.receipt:'.$eventId);
        }, 3);
    }

    public function work(int $limit = 20): int
    {
        $outbox = app(V2OutboxService::class);
        $worker = 'univapay:'.Str::uuid();
        $messages = $outbox->claim($worker, $limit, 60, [self::TOPIC], ['univapay.lookup']);
        foreach ($messages as $message) {
            try {
                $receipt = DB::table('payment_provider_events')->where('provider_code', 'univapay')
                    ->where('external_event_id', 'receipt:'.$message->payload['event_id'])->firstOrFail();
                $ok = app(V2UnivapayPaymentService::class)->reconcile($message->payload['charge_id'], reversal:
                    in_array($receipt->event_type, ['univapay.refund_finished','univapay.cancel_finished'], true));
                if (! $ok) {
                    $outbox->retry($message->public_id, $worker, 'provider_unavailable', 60);
                    continue;
                }
                // Provider receipts are immutable. Delivery state belongs to the existing Outbox.
                $outbox->markDelivered($message->public_id, $worker);
            } catch (\Throwable) {
                // A crash keeps the lease recoverable; routine failures retry only this topic, never purchase mail.
                $outbox->retry($message->public_id, $worker, 'reconciliation_failed', 60);
            }
        }

        return $messages->count();
    }
}
