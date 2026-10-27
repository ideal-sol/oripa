<?php

namespace App\Domain\Payment\V2\Services;

use Illuminate\Support\Facades\DB;

final class V2PaidyWebhookService
{
    public function receive(array $input): void
    {
        $id = V2PaidyClient::validId($input['payment_id'] ?? null) ? $input['payment_id'] : null;
        $status = in_array($input['status'] ?? null, ['authorize_success', 'capture_success', 'close_success', 'refund_success', 'update_success'], true)
            ? $input['status'] : 'unknown';
        // Only minimal correlation keys and a non-reversible receipt fingerprint.
        $fingerprint = hash('sha256', json_encode([$id, $status, is_string($input['timestamp'] ?? null) ? substr($input['timestamp'], 0, 64) : null], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($id, $status, $fingerprint): void {
            DB::statement("SET LOCAL lock_timeout = '2s'");
            DB::statement("SET LOCAL statement_timeout = '3s'");
            // This path authenticates the URL only. The receipt cannot authorize
            // settlement; only payment.succeeded after an exact GET can do so.
            app(V2PaymentService::class)->recordVerifiedProviderEvent('paidy', 'receipt:'.$fingerprint,
                'paidy.notification', json_encode(['paidy_payment_id' => $id, 'status' => $status], JSON_THROW_ON_ERROR));
            if ($id !== null) app(V2PaidyReconciliationService::class)->enqueueNotification($id, $fingerprint);
        }, 3);
    }
}
