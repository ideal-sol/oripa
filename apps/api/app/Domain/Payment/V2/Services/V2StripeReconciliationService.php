<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;

final class V2StripeReconciliationService
{
    public function __construct(
        private V2StripePaymentService $checkout,
        private V2StripeClient $client,
        private V2PaymentService $payments,
        private V2PaymentProgressService $progress,
    ) {}

    public function lookup(string $id, ?object $expected = null, ?int $receiptId = null): bool
    {
        try {
            $object = $this->client->session($id);
        } catch (ApiErrorException|V2FincodeException) {
            return false;
        }
        $view = $expected;
        $sameId = ($object['id'] ?? null) === $id;
        if (! $view) {
            $local = DB::table('stripe_checkout_sessions')->where('session_id', $id)->first();
            $public = $object['metadata']['oripa-payment-id'] ?? null;
            $view = $local ? DB::table('payments')->find($local->payment_id)
                : ($sameId && is_string($public) && Str::isUuid($public) ? DB::table('payments')->where('public_id', $public)->first() : null);
        }
        if (! $view || $view->provider_code !== 'stripe') {
            if ($receiptId !== null) $this->checkout->reviewReceipt($receiptId);
            return true;
        }
        return $this->checkout->locked($view, function ($payment, $user, $session, $current) use ($object, $id, $sameId) {
            $number = filter_var($object['metadata']['oripa-attempt'] ?? null, FILTER_VALIDATE_INT);
            $attempt = $number ? DB::table('stripe_checkout_attempts')->where('payment_id', $payment->id)
                ->where('attempt_no', $number)->lockForUpdate()->first() : null;
            if (! $sameId || ! $attempt || ! $this->checkout->belongs($payment, $user, $object, $number)
                || ($session?->session_id === $id && $session->livemode !== $object['livemode'])) {
                $this->checkout->review($payment);
                return true;
            }
            $paid = ($object['status'] ?? null) === 'complete' && ($object['payment_status'] ?? null) === 'paid';
            if ($session?->session_id !== $id) {
                if ($paid) $this->checkout->review($payment);
                elseif ($current?->id !== $attempt->id || $attempt->state !== 'pending' || $payment->status !== 'created') {
                    DB::table('stripe_checkout_attempts')->where('id', $attempt->id)
                        ->whereNull('session_id')->update(['session_id' => $id, 'updated_at' => V2DatabaseTimestamp::format(now())]);
                    if (($object['status'] ?? null) === 'open') $this->checkout->queueExpiry($payment, $id);
                }
                return true;
            }
            $status = $object['status'] ?? null;
            $paymentStatus = $object['payment_status'] ?? null;
            $intent = $object['payment_intent'] ?? null;
            $charge = $intent['latest_charge'] ?? null;
            $intentId = $intent['id'] ?? null;
            $terminal = in_array($payment->status, ['failed','succeeded','canceled','expired'], true);
            if ($paid) {
                if (! is_string($intentId) || ! preg_match('/\Api_[a-zA-Z0-9]+\z/', $intentId)
                    || ($intent['amount_received'] ?? null) !== (int) $payment->amount || ($intent['currency'] ?? null) !== 'jpy'
                    || ($intent['livemode'] ?? null) !== $object['livemode'] || ($intent['status'] ?? null) !== 'succeeded'
                    || ($intent['metadata']['oripa-payment-id'] ?? null) !== $payment->public_id
                    || ($intent['metadata']['oripa-member'] ?? null) !== $user->public_id
                    || ! is_int($charge['created'] ?? null) || ($charge['payment_intent'] ?? null) !== $intentId
                    || ($charge['payment_method_details']['type'] ?? null) !== 'card'
                    || ($session->payment_intent_id !== null && $session->payment_intent_id !== $intentId)
                    || DB::table('stripe_checkout_sessions')->where('payment_intent_id', $intentId)->where('id', '<>', $session->id)->exists()) {
                    $this->checkout->review($payment);
                    return true;
                }
                if ($payment->status === 'succeeded') return true;
                $closed = DB::table('account_closures')->where('user_id', $user->id)->exists();
                if (! $closed && ($terminal || $session->session_status === 'expired')) {
                    if (! DB::table('payment_progress_events')->where('payment_id', $payment->id)->where('type', 'paid_after_terminal')
                        ->where('detail->payment_intent_id', $intentId)->exists()) {
                        $this->progress->append($payment->id, 'paid_after_terminal', ['provider' => 'stripe', 'payment_intent_id' => $intentId]);
                    }
                    return true;
                }
            } elseif ($terminal || $session->session_status === 'expired' || $session->payment_status === 'paid'
                || ($session->session_status === 'complete' && $status === 'open')) {
                return true;
            }
            $target = match (true) {
                $paid => 'succeeded',
                $status === 'expired' => $session->cancel_requested_at ? 'canceled' : 'expired',
                $status === 'open' && $paymentStatus === 'unpaid' => 'requires_action',
                $status === 'complete' && $paymentStatus === 'unpaid' => 'processing',
                default => null,
            };
            if (! $target) {
                $this->checkout->review($payment);
                return true;
            }
            $update = ['session_status' => $status, 'payment_status' => $paymentStatus, 'updated_at' => V2DatabaseTimestamp::format(now())];
            if ($paid) {
                $update += ['payment_intent_id' => $intentId, 'actual_method_type' => 'card',
                    'wallet_type' => $charge['payment_method_details']['card']['wallet']['type'] ?? null];
                DB::table('payments')->where('id', $payment->id)->update(['provider_payment_id' => $intentId]);
            }
            DB::table('stripe_checkout_sessions')->where('id', $session->id)->update($update);
            if ($payment->status === $target) return true;
            $event = $this->payments->recordVerifiedProviderEvent('stripe', 'lookup:'.$id.':'.$target, 'payment.'.$target,
                json_encode(['session_id' => $id, 'payment_intent_id' => $intentId], JSON_THROW_ON_ERROR), [], $payment->id,
                providerOccurredAt: $paid ? CarbonImmutable::createFromTimestampUTC($charge['created']) : null);
            $this->payments->applyVerifiedStatus($event->id, $target, strtoupper($status));
            return true;
        });
    }

    public function operation(User $user, string $publicId, string $operation, ?string $key, string $trigger = 'user'): array
    {
        $payment = Str::isUuid($publicId) ? DB::table('payments')->where('user_id', $user->id)->where('public_id', $publicId)->first() : null;
        if (! $payment) throw new V2FincodeException('PAYMENT_NOT_FOUND', 404, 'The payment was not found.');
        if ($payment->provider_code !== 'stripe') throw new V2FincodeException('PAYMENT_PROVIDER_MISMATCH', 409, 'Payment provider mismatch.');
        try {
            $action = $this->checkout->locked($payment, function ($current, $owner, $session, $attempt) use ($operation, $key, $trigger) {
                $claim = $key === null ? null : app(V2PointIdempotencyService::class)->claim('stripe.'.$operation, 'user',
                    $owner->public_id, $key, ['payment' => $current->public_id]);
                if ($claim && ! $claim->replay) app(V2PointIdempotencyService::class)->complete($claim->record, 'payment', $current->public_id);
                if ($operation === 'sync') {
                    return $session && Cache::add('stripe.sync.'.$current->public_id, true, 5) ? ['lookup',$session->session_id] : [];
                }
                if ($operation === 'resume') {
                    if ($session?->cancel_requested_at !== null && in_array($current->status, ['created','requires_action','processing'], true)) {
                        throw new V2FincodeException('PAYMENT_CANCELLATION_PENDING', 409, 'Payment cancellation is pending.');
                    }
                    if ($current->status === 'created') return ['create'];
                    if ($current->status !== 'requires_action' || $session?->session_status !== 'open') {
                        throw new V2FincodeException('PAYMENT_NOT_RESUMABLE', 409, 'The payment cannot be resumed.');
                    }
                    return CarbonImmutable::parse($session->session_expires_at)->lte(now()) ? ['lookup',$session->session_id] : [];
                }
                if (! in_array($current->status, ['created','requires_action'], true)) return [];
                if ($session?->cancel_requested_at === null) {
                    $this->progress->append($current->id, 'cancel_requested', ['provider' => 'stripe', 'trigger' => $trigger]);
                }
                if ($session) {
                    DB::table('stripe_checkout_sessions')->where('id', $session->id)->whereNull('cancel_requested_at')
                        ->update(['cancel_requested_at' => V2DatabaseTimestamp::format(now())]);
                    return ['expire',$session->session_id];
                }
                DB::table('stripe_checkout_attempts')->where('payment_id', $current->id)->where('state', 'pending')
                    ->update(['state' => 'abandoned', 'updated_at' => V2DatabaseTimestamp::format(now())]);
                $this->payments->cancelUnstartedStripe($current);
                return [];
            });
        } catch (V2PointException $error) {
            throw new V2FincodeException($error->getMessage(), 409, 'Payment request conflict.');
        }
        if (($action[0] ?? null) === 'create') $this->checkout->ensureSession($payment);
        if (($action[0] ?? null) === 'expire') $this->expireAndLookup($action[1], $payment);
        if (($action[0] ?? null) === 'lookup') $this->lookup($action[1], $payment);
        return app(V2FincodePaymentService::class)->show($user, $publicId);
    }

    public function expireAndLookup(string $id, object $payment): bool
    {
        try {
            $this->client->expire($id);
        } catch (ApiErrorException|V2FincodeException) {
        }
        return $this->lookup($id, $payment);
    }
}
