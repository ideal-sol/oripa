<?php

namespace App\Domain\Payment\V2\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

final class V2FincodeReconciliationService
{
    public function __construct(private readonly V2FincodeWebhookService $webhooks)
    {
    }

    /** @return array{selected: int, processed: int, failed: int} */
    public function reconcileDue(int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('The reconciliation limit must be between 1 and 1000.');
        }
        if (config('v2_fincode.enabled') !== true) {
            return ['selected' => 0, 'processed' => 0, 'failed' => 0];
        }
        $payments = DB::table('payments')
            ->where('provider_code', 'fincode')
            ->whereIn('payment_method', ['konbini', 'virtual_account'])
            ->whereIn('status', ['requires_action', 'processing'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $processed = 0;
        $failed = 0;
        foreach ($payments as $payment) {
            try {
                $this->webhooks->reconcile($payment);
                $processed++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'selected' => $payments->count(),
            'processed' => $processed,
            'failed' => $failed,
        ];
    }
}
