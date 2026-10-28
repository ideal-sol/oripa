<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\OutboxMessage;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2PaidyReconciliationService
{
    private const TOPIC = 'payment.paidy';
    private const LEASE = 180;

    public function enqueuePayment(object $payment): OutboxMessage
    {
        return app(V2OutboxService::class)->enqueue(self::TOPIC, 'payment', $payment->public_id, 'paidy.payment',
            ['payment_id' => $payment->public_id], 'paidy.payment:'.$payment->public_id, now()->addMinute());
    }

    public function enqueueLookup(string $id, ?string $expected = null): OutboxMessage
    {
        $message = app(V2OutboxService::class)->enqueue(self::TOPIC, 'payment', $expected, 'paidy.lookup',
            ['paidy_payment_id' => $id, 'payment_id' => $expected], $this->lookupKey($id, $expected));
        // A later refund/close notification must wake a previously completed lookup.
        DB::table('outbox_messages')->where('id', $message->id)->where('status', 'delivered')
            ->update(['status' => 'pending', 'available_at' => V2DatabaseTimestamp::format(now()), 'delivered_at' => null]);
        return $message;
    }

    public function enqueueNotification(string $id, string $fingerprint): OutboxMessage
    {
        // A distinct durable event cannot be lost while another lookup is in flight.
        return app(V2OutboxService::class)->enqueue(self::TOPIC, 'payment', null, 'paidy.lookup',
            ['paidy_payment_id' => $id, 'payment_id' => null], 'paidy.notification:'.$fingerprint);
    }

    public function runLookup(string $id, ?string $expected): void
    {
        $worker = 'paidy-callback-'.Str::uuid();
        $message = DB::transaction(function () use ($id, $expected, $worker): ?OutboxMessage {
            $message = OutboxMessage::query()->where('deduplication_key', $this->lookupKey($id, $expected))->lockForUpdate()->firstOrFail();
            if ($message->status === 'processing' && $message->lease_expires_at?->isFuture()) return null;
            if (! in_array($message->status, ['pending', 'processing'], true)) return null;
            $message->forceFill(['status' => 'processing', 'attempts' => $message->attempts + 1, 'locked_by' => $worker,
                'locked_at' => now(), 'lease_expires_at' => now()->addSeconds(self::LEASE)])->save();
            return $message;
        }, 3);
        if ($message !== null) $this->process($message, $worker);
    }

    public function work(int $limit = 20): int
    {
        if (config('v2_paidy.worker_enabled') !== true) throw new \LogicException('Paidy worker is disabled.');
        $worker = 'paidy-worker-'.Str::uuid();
        // Claim one at a time so the last item cannot lose its lease in a batch.
        $count = 0;
        for ($i = 0; $i < $limit; $i++) {
            $message = app(V2OutboxService::class)->claim($worker, 1, self::LEASE, [self::TOPIC], ['paidy.lookup', 'paidy.payment'])->first();
            if ($message === null) break;
            $this->process($message, $worker); $count++;
        }
        return $count;
    }

    private function process(OutboxMessage $message, string $worker): void
    {
        $outbox = app(V2OutboxService::class);
        try {
            $expected = $message->payload['payment_id'] ?? null;
            $id = $message->payload['paidy_payment_id'] ?? null;
            if ($id === null && $expected !== null) {
                $payment = DB::table('payments')->where('public_id', $expected)->where('provider_code', 'paidy')->first();
                $id = $payment?->provider_payment_id;
                if ($id === null && $payment !== null) {
                    if ($payment->status === 'requires_action' && CarbonImmutable::parse($payment->expires_at)->isPast()) {
                        $this->apply($payment, 'expired');
                        $outbox->markDelivered($message->public_id, $worker);
                        return;
                    }
                    $outbox->retry($message->public_id, $worker, 'paidy_reference_pending', $this->delay($message->attempts));
                    return;
                }
            }
            if (! V2PaidyClient::validId($id)) {
                $outbox->markDelivered($message->public_id, $worker); return;
            }
            $complete = $this->reconcile($id, $expected, $message, $worker);
            if ($complete) $outbox->markDelivered($message->public_id, $worker);
            else $outbox->retry($message->public_id, $worker, 'paidy_reconciliation_pending', $this->delay($message->attempts));
        } catch (\Throwable $exception) {
            // Neither HTTP payloads nor exception messages may enter durable logs.
            try {
                $query = DB::table('payments')->where('provider_code', 'paidy');
                $payment = isset($message->payload['payment_id'])
                    ? $query->where('public_id', $message->payload['payment_id'])->first()
                    : $query->where('provider_payment_id', $message->payload['paidy_payment_id'] ?? '')->first();
                if ($payment !== null) $this->review($payment);
            } catch (\Throwable) {
                // Database failure is still retried through the durable job.
            }
            $outbox->retry($message->public_id, $worker, 'paidy_reconciliation_failed', $this->delay($message->attempts));
        }
    }

    private function reconcile(string $id, ?string $expected, OutboxMessage $message, string $worker): bool
    {
        $response = app(V2PaidyClient::class)->request($id);
        $body = $response['body'];
        $reference = $expected ?? ($body['order']['order_ref'] ?? null);
        $payment = is_string($reference) && Str::isUuid($reference)
            ? DB::table('payments')->where('public_id', $reference)->where('provider_code', 'paidy')->first() : null;
        if ($payment === null) return $body !== null;
        $classification = DB::table('payment_commercial_classifications')->where('payment_id', $payment->id)->value('classification');
        if (! in_array($classification, ['test', 'commercial'], true)
            || ($classification === 'test') !== (config('v2_paidy.mode') === 'test')) {
            $this->review($payment, 'mode_mismatch'); return false;
        }
        $result = app(V2PaidyResponseClassifier::class)->classify($body, $payment, $id, $classification === 'test');
        $this->progress($payment, 'lookup', $response['http_status']);
        if ($result['match'] !== 'ok') {
            $this->review($payment, $result['match']);
            return $this->closeIfSafe($payment, $id, $result, $message, $worker);
        }
        if ($body === null || $result['state'] === 'uncertain') {
            $this->processing($payment); $this->review($payment); return false;
        }
        $associated = DB::transaction(function () use ($payment, $id): bool {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['paidy:'.$id]);
            $fresh = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            if (($fresh->provider_payment_id !== null && $fresh->provider_payment_id !== $id)
                || DB::table('payments')->where('provider_code', 'paidy')->where('provider_payment_id', $id)->where('id', '<>', $fresh->id)->exists()) return false;
            DB::table('payments')->where('id', $fresh->id)->update(['provider_payment_id' => $id]);
            return true;
        }, 3);
        if (! $associated) {
            $this->review($payment, 'duplicate_charge'); return false;
        }
        if ($expected === null) app(V2PaymentProgressService::class)->append((int) $payment->id, 'provider_notified');
        if ($result['state'] === 'authorized') {
            $payment = DB::table('payments')->where('id', $payment->id)->firstOrFail();
            $attempted = $this->captureAttempted($payment);
            if (in_array($payment->status, ['expired', 'failed', 'canceled'], true)
                || CarbonImmutable::parse($payment->expires_at)->isPast()) {
                // An uncertain Capture may still be in flight. Never cancel it.
                if ($attempted || $payment->status === 'processing') { $this->review($payment, 'expired'); return false; }
                return $this->closeIfSafe($payment, $id, $result, $message, $worker);
            }
            if ($payment->status === 'succeeded') { $this->review($payment); return true; }
            if (! $this->claimCapture($payment, $message, $worker)) {
                $this->processing($payment); $this->review($payment); return false;
            }
            $this->processing($payment);
            $capture = app(V2PaidyClient::class)->request($id, 'capture');
            $this->progress($payment, 'capture', $capture['http_status']);
            if ($capture['authorization_expired']) { $this->apply($payment, 'failed'); return true; }
            $result = app(V2PaidyResponseClassifier::class)->classify($capture['body'], $payment, $id, $classification === 'test');
            if ($result['state'] !== 'captured' || $result['match'] !== 'ok') {
                // Includes forbidden, any other 4xx, 5xx, timeout and malformed 2xx.
                $retrieved = app(V2PaidyClient::class)->request($id);
                $this->progress($payment, 'lookup', $retrieved['http_status']);
                $result = app(V2PaidyResponseClassifier::class)->classify($retrieved['body'], $payment, $id, $classification === 'test');
            }
        }
        return $this->finish($payment, $result);
    }

    private function finish(object $payment, array $result): bool
    {
        if ($result['match'] !== 'ok') { $this->review($payment, $result['match']); return false; }
        if ($result['state'] === 'captured') {
            try {
                return DB::transaction(function () use ($payment, $result): bool {
                    // Keep the common user -> payment money lock order.
                    DB::table('users')->where('id', $payment->user_id)->lockForUpdate()->firstOrFail();
                    $fresh = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->firstOrFail();
                    if (! DB::table('account_closures')->where('user_id', $fresh->user_id)->exists() && (in_array($fresh->status, ['expired', 'failed', 'canceled'], true)
                        || ($fresh->status !== 'succeeded' && ($result['occurred_at']->greaterThanOrEqualTo(CarbonImmutable::parse($fresh->expires_at))
                            || ($fresh->status !== 'processing' && CarbonImmutable::parse($fresh->expires_at)->isPast()))))) {
                        $this->review($fresh, 'expired'); return true;
                    }
                    $event = app(V2PaymentService::class)->recordVerifiedProviderEvent('paidy', 'captured:'.$fresh->public_id,
                        'payment.succeeded', '{}', [], (int) $fresh->id, providerOccurredAt: $result['occurred_at']);
                    app(V2PaymentService::class)->confirmSucceeded((int) $event->id, 'closed');
                    return true;
                }, 3);
            } catch (\Throwable) {
                $this->review($payment, 'ok', 'capture_grant_failed'); return false;
            }
        }
        if ($result['state'] === 'failed') {
            $fresh = DB::table('payments')->where('id', $payment->id)->firstOrFail();
            if ($fresh->status === 'succeeded') $this->review($fresh, 'ok', 'provider_reversed');
            elseif (! in_array($fresh->status, ['failed', 'canceled', 'expired'], true)) $this->apply($fresh, 'failed');
            return true;
        }
        if ($result['state'] === 'reversed') { $this->review($payment, 'ok', 'provider_reversed'); return true; }
        $this->processing($payment); $this->review($payment); return false;
    }

    private function closeIfSafe(object $payment, string $id, array $result, OutboxMessage $message, string $worker): bool
    {
        // A valid uncaptured authorization may belong to an entirely different
        // order, including when amount_mismatch masks member_mismatch.
        if (! $result['order_matches'] || ! $result['uncaptured'] || $this->captureAttempted($payment)
            || DB::table('payments')->where('provider_code', 'paidy')->where('provider_payment_id', $id)->where('id', '<>', $payment->id)->exists()) return false;
        // Close and Capture share the same permanent per-payment operation exclusion.
        if (! $this->claimCapture($payment, $message, $worker, 'cancel')) return false;
        $response = app(V2PaidyClient::class)->request($id, 'cancel');
        $this->progress($payment, 'cancel', $response['http_status']);
        $body = $response['body'];
        if (($body['id'] ?? null) === $id && is_string($body['status'] ?? null) && strtolower($body['status']) === 'closed'
            && V2PaidyResponseClassifier::yen($body['amount'] ?? null) !== null && is_string($body['currency'] ?? null)
            && is_bool($body['test'] ?? null) && ($body['order']['order_ref'] ?? null) === $payment->public_id
            && ($body['captures'] ?? null) === [] && ($body['refunds'] ?? null) === []) {
            $fresh = DB::table('payments')->where('id', $payment->id)->firstOrFail();
            if (! in_array($fresh->status, ['succeeded', 'failed', 'canceled', 'expired'], true)) $this->apply($fresh, 'failed');
            return true;
        }
        $this->review($payment); return false;
    }

    private function claimCapture(object $payment, OutboxMessage $message, string $worker, string $operation = 'capture'): bool
    {
        return DB::transaction(function () use ($payment, $message, $worker, $operation): bool {
            $lease = OutboxMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($lease->status !== 'processing' || $lease->locked_by !== $worker || ! $lease->lease_expires_at?->isFuture()) return false;
            $fresh = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            if ($this->captureAttempted($fresh) || $fresh->status === 'succeeded') return false;
            if ($operation === 'capture' && (in_array($fresh->status, ['expired', 'failed', 'canceled'], true) || CarbonImmutable::parse($fresh->expires_at)->isPast())) return false;
            // Unique Outbox key is a permanent write-ahead operation claim. Even
            // after lease recovery an ambiguous money request is never resent.
            $claim = app(V2OutboxService::class)->enqueue('payment.paidy.operation', 'payment', $fresh->public_id, 'paidy.'.$operation.'.claimed',
                ['payment_id' => $fresh->public_id, 'operation' => $operation], 'paidy.operation:'.$fresh->public_id);
            DB::table('outbox_messages')->where('id', $claim->id)->update(['status' => 'delivered', 'delivered_at' => V2DatabaseTimestamp::format(now())]);
            return true;
        }, 3);
    }

    private function captureAttempted(object $payment): bool
    {
        return DB::table('outbox_messages')->where('deduplication_key', 'paidy.operation:'.$payment->public_id)->exists();
    }

    private function processing(object $payment): void
    {
        $fresh = DB::table('payments')->where('id', $payment->id)->firstOrFail();
        if (in_array($fresh->status, ['created', 'requires_action'], true)) $this->apply($fresh, 'processing');
    }

    private function apply(object $payment, string $status): void
    {
        $event = app(V2PaymentService::class)->recordVerifiedProviderEvent('paidy', $status.':'.$payment->public_id,
            'payment.'.$status, '{}', [], (int) $payment->id);
        app(V2PaymentService::class)->applyVerifiedStatus((int) $event->id, $status, $status);
    }

    private function review(object $payment, string $match = 'ok', string $reason = 'reconciliation_failed'): void
    {
        app(V2PaymentProgressService::class)->append((int) $payment->id, 'provider_api_called', ['match' => $match, 'reason' => $reason]);
    }

    private function progress(object $payment, string $operation, ?int $status): void
    {
        app(V2PaymentProgressService::class)->append((int) $payment->id, 'provider_api_called', array_filter([
            'operation' => $operation, 'http_status' => $status,
        ], static fn ($v) => $v !== null));
    }

    private function lookupKey(string $id, ?string $expected): string
    {
        return 'paidy.lookup:'.hash('sha256', $id.':'.($expected ?? 'webhook'));
    }

    private function delay(int $attempts): int
    {
        return match (true) { $attempts <= 1 => 60, $attempts === 2 => 300, $attempts === 3 => 900, default => 3600 };
    }
}
