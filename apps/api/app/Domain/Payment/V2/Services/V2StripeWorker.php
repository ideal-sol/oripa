<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2StripeWorker
{
    private const EVENTS = [
        'checkout.session.completed' => 'session_id', 'checkout.session.expired' => 'session_id',
        'checkout.session.async_payment_succeeded' => 'session_id', 'checkout.session.async_payment_failed' => 'session_id',
        'charge.refunded' => 'charge_id', 'charge.dispute.created' => 'dispute_id', 'charge.dispute.closed' => 'dispute_id',
    ];

    public function __construct(
        private V2OutboxService $outbox,
        private V2StripeClient $client,
        private V2StripePaymentService $checkout,
        private V2StripeReconciliationService $sync,
    ) {}

    public function receive(array $event): void
    {
        $eventId = $event['id'] ?? null;
        if (! is_string($eventId) || ! preg_match('/\Aevt_[a-zA-Z0-9]{1,180}\z/', $eventId)) throw new \UnexpectedValueException();
        $kind = $event['type'] ?? null;
        $field = self::EVENTS[$kind] ?? null;
        $object = $event['data']['object'] ?? [];
        $ids = ['event_id' => $eventId];
        if ($field !== null) {
            $ids[$field] = $object['id'] ?? throw new \UnexpectedValueException();
            foreach (['payment_intent' => 'payment_intent_id', 'charge' => 'charge_id'] as $source => $target) {
                if (isset($object[$source])) $ids[$target] = $object[$source];
            }
        }
        $match = ($event['livemode'] ?? null) === (config('v2_stripe.mode') === 'live');
        DB::transaction(function () use ($eventId, $kind, $field, $match, $ids) {
            $type = ! $field ? 'stripe.ignored' : ($match ? $kind : 'stripe.mode_mismatch.'.$kind);
            app(V2PaymentService::class)->recordVerifiedProviderEvent('stripe', 'receipt:'.$eventId, $type, json_encode($ids, JSON_THROW_ON_ERROR));
            if ($field && $match) {
                $this->outbox->enqueue('payment.stripe', 'payment', null, 'stripe.lookup', ['event_id' => $eventId], 'stripe.receipt:'.$eventId);
            }
        });
    }

    public function work(int $limit): void
    {
        DB::table('payments')->where('provider_code', 'stripe')->where('status', 'created')
            ->where('created_at', '<=', V2DatabaseTimestamp::format(now()->subMinutes(10)))
            ->chunkById(100, function ($payments) {
                foreach ($payments as $payment) $this->checkout->failOverdue($payment);
            });
        $worker = 'stripe:'.Str::uuid();
        foreach ($this->outbox->claim($worker, $limit, 120, ['payment.stripe'], ['stripe.lookup','stripe.expire']) as $message) {
            try {
                if ($message->event_type === 'stripe.expire') {
                    $this->expireQueuedSession($message->aggregate_public_id, $message->payload['object_id']);
                } else {
                    $receipt = DB::table('payment_provider_events')->where('provider_code', 'stripe')
                        ->where('external_event_id', 'receipt:'.$message->payload['event_id'])->firstOrFail();
                    $ids = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($receipt->payload_ciphertext), true, 8, JSON_THROW_ON_ERROR);
                    $ok = isset($ids['session_id']) ? $this->sync->lookup($ids['session_id'], receiptId: $receipt->id)
                        : $this->reversal($receipt, $ids);
                    if (! $ok) throw new \UnexpectedValueException();
                }
                $this->outbox->markDelivered($message->public_id, $worker);
            } catch (\Throwable) {
                $this->outbox->retry($message->public_id, $worker, 'reconciliation_failed', 60);
            }
        }
    }

    private function expireQueuedSession(string $paymentId, string $id): void
    {
        $payment = DB::table('payments')->where('public_id', $paymentId)->where('provider_code', 'stripe')->sole();
        $object = $this->client->session($id);
        $canExpire = $this->checkout->locked($payment, function ($payment, $user, $adopted, $latest) use ($id, $object) {
            $number = filter_var($object['metadata']['oripa-attempt'] ?? null, FILTER_VALIDATE_INT);
            $attempt = $number ? DB::table('stripe_checkout_attempts')->where('payment_id', $payment->id)
                ->where('attempt_no', $number)->first() : null;
            if (($object['id'] ?? null) !== $id || ! $attempt || ! $this->checkout->belongs($payment, $user, $object, $number)) {
                $this->checkout->review($payment);
                return false;
            }
            if (($object['status'] ?? null) !== 'open') return false;
            if ($adopted?->session_id === $id) {
                return $payment->status === 'canceled' && DB::table('account_closures')->where('user_id', $user->id)->exists();
            }
            return $payment->status !== 'created' || $adopted || $latest?->id !== $attempt->id || $attempt->state !== 'pending';
        });
        if ($canExpire) $this->client->expire($id);
    }

    private function reversal(object $receipt, array $ids): bool
    {
        $type = $receipt->event_type;
        $dispute = isset($ids['dispute_id']);
        $object = $dispute ? $this->client->dispute($ids['dispute_id']) : $this->client->charge($ids['charge_id']);
        $intentId = $object['payment_intent'] ?? null;
        $session = is_string($intentId) ? DB::table('stripe_checkout_sessions')->where('payment_intent_id', $intentId)->first() : null;
        if (! $session) {
            $this->checkout->reviewReceipt($receipt->id);
            return true;
        }
        $view = DB::table('payments')->find($session->payment_id);
        return $this->checkout->locked($view, function ($payment, $user, $current) use ($type, $ids, $dispute, $object, $intentId) {
            if ($current?->payment_intent_id !== $intentId || ($object['id'] ?? null) !== ($ids['dispute_id'] ?? $ids['charge_id'])
                || ($object['currency'] ?? null) !== 'jpy' || ($object['livemode'] ?? null) !== $current->livemode
                || (isset($ids['payment_intent_id']) && $ids['payment_intent_id'] !== $intentId)
                || ($dispute && ($object['charge'] ?? null) !== ($ids['charge_id'] ?? null))) {
                $this->checkout->review($payment);
                return true;
            }
            if (DB::table('payment_progress_events')->where('payment_id', $payment->id)->where('detail->event_id', $ids['event_id'])->exists()) return true;
            $detail = ['provider' => 'stripe', 'event_id' => $ids['event_id'], 'payment_intent_id' => $intentId,
                'stripe_charge_id' => $ids['charge_id'], 'amount' => $dispute ? $object['amount'] : $object['amount_refunded']];
            if ($dispute) {
                $detail += ['dispute_id' => $ids['dispute_id'], 'dispute_status' => $object['status'], 'dispute_reason' => $object['reason']];
            } else {
                $detail['refund_ids'] = array_column($object['refunds']['data'] ?? [], 'id');
            }
            app(V2PaymentProgressService::class)->append($payment->id, match ($type) {
                'charge.refunded' => 'provider_reversed', 'charge.dispute.created' => 'dispute_opened', 'charge.dispute.closed' => 'dispute_closed',
            }, $detail);
            return true;
        });
    }

    public function reconcile(): void
    {
        DB::table('payments')->where('provider_code', 'stripe')->where(function ($query) {
            $query->where(fn ($created) => $created->where('status', 'created')->where('created_at', '<=', V2DatabaseTimestamp::format(now()->subMinutes(2))))
                ->orWhere(fn ($open) => $open->whereIn('status', ['requires_action','processing'])->where('created_at', '<=', V2DatabaseTimestamp::format(now()->subMinutes(5))))
                ->orWhere(fn ($done) => $done->whereIn('status', ['succeeded','canceled','failed','expired'])->where('updated_at', '>=', V2DatabaseTimestamp::format(now()->subDay())));
        })->chunkById(100, function ($payments) {
            foreach ($payments as $payment) {
                if ($payment->status === 'created') {
                    try {
                        $this->checkout->ensureSession($payment);
                    } catch (V2FincodeException) {
                    }
                    continue;
                }
                $session = DB::table('stripe_checkout_sessions')->where('payment_id', $payment->id)->first();
                if (! $session) continue;
                if ($session->session_status === 'open' && ($session->cancel_requested_at
                    || CarbonImmutable::parse($session->session_expires_at)->addMinutes(5)->lte(now()))) {
                    $this->sync->expireAndLookup($session->session_id, $payment);
                } else {
                    $this->sync->lookup($session->session_id, $payment);
                }
            }
        });
    }
}
