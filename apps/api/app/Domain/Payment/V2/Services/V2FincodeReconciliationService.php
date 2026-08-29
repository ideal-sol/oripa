<?php

namespace App\Domain\Payment\V2\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

final class V2FincodeReconciliationService
{
    public function __construct(
        private readonly V2FincodeWebhookService $webhooks,
        private readonly V2FincodeCardService $cards
    ) {
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

    /** @return array{selected: int, processed: int, failed: int, expired: int} */
    public function reconcileCardRegistrations(int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('The reconciliation limit must be between 1 and 1000.');
        }
        if (config('v2_fincode.enabled') !== true) {
            return ['selected' => 0, 'processed' => 0, 'failed' => 0, 'expired' => 0];
        }
        $expired = $this->cards->expireDue($limit);
        $registrations = DB::table('fincode_card_registration_intents')
            ->where('flow_type', 'three_d_secure_2')
            ->whereIn('status', ['requires_action', 'pending'])
            ->whereNotNull('provider_payment_method_id')
            ->where('expires_at', '>', now())
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $processed = 0;
        $failed = 0;
        foreach ($registrations as $registration) {
            try {
                $this->cards->reconcilePending((int) $registration->id);
                $processed++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'selected' => $registrations->count(),
            'processed' => $processed,
            'failed' => $failed,
            'expired' => $expired,
        ];
    }
}
