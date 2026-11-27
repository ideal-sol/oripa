<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ApiConnectionException;

final class V2StripePaymentService
{
    public function __construct(
        private V2StripeClient $client,
        private V2PaymentService $payments,
        private V2PointTransactionRunner $transactions,
        private V2PaymentProgressService $progress,
    ) {}

    public function start(User $user, string $productId, string $key): array
    {
        app(V2StripeConfiguration::class)->assertConfigured();
        $plan = DB::table('point_purchase_plans')->where('public_id', $productId)->first();
        if (! $plan) throw new V2FincodeException('POINT_PRODUCT_NOT_FOUND', 404, 'The point product was not found.');
        try {
            $payment = $this->transactions->run(function () use ($user, $plan, $key) {
                $payment = $this->payments->createPayment($user->id, $plan->id, 'stripe', null, $key, 'credit_card', true);
                $this->locked($payment, function ($current, $lockedUser, $session, $attempt) {
                    if ($current->status === 'created' && ! $attempt && ! $session && ! $this->cutoff($current)) {
                        $this->newAttempt($current, $lockedUser, 1);
                    }
                });
                return $payment;
            });
        } catch (V2PointException|V2PaymentException $error) {
            $code = in_array($error->getMessage(), ['IDEMPOTENCY_KEY_REUSED','IDEMPOTENCY_REQUEST_IN_PROGRESS'], true)
                ? $error->getMessage() : 'PAYMENT_START_REJECTED';
            throw new V2FincodeException($code, 409, 'The payment could not be started.');
        }
        $this->ensureSession($payment);
        return app(V2FincodePaymentService::class)->show($user, $payment->public_id);
    }

    public function locked(object $view, callable $operation): mixed
    {
        return $this->transactions->run(function () use ($view, $operation) {
            $user = User::query()->whereKey($view->user_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $view->id)->where('user_id', $user->id)
                ->where('provider_code', 'stripe')->lockForUpdate()->firstOrFail();
            $session = DB::table('stripe_checkout_sessions')->where('payment_id', $payment->id)->lockForUpdate()->first();
            $attempt = DB::table('stripe_checkout_attempts')->where('payment_id', $payment->id)
                ->orderByDesc('attempt_no')->lockForUpdate()->first();
            return $operation($payment, $user, $session, $attempt);
        });
    }

    private function newAttempt(object $payment, User $user, int $number): object
    {
        $now = CarbonImmutable::now();
        $id = DB::table('stripe_checkout_attempts')->insertGetId([
            'payment_id' => $payment->id, 'attempt_no' => $number, 'state' => 'pending',
            'idempotency_key' => 'oripoke-checkout-'.$payment->public_id.'-'.$number,
            'request_email_ciphertext' => Crypt::encryptString($user->email_display),
            'request_expires_at' => V2DatabaseTimestamp::format($now->addMinutes((int) config('v2_stripe.checkout_expires_minutes'))),
            'request_return_url' => app(V2FincodeReturnUrl::class)->storefrontNormalForPayment($payment->public_id),
            'created_at' => V2DatabaseTimestamp::format($now), 'updated_at' => V2DatabaseTimestamp::format($now),
        ]);
        return DB::table('stripe_checkout_attempts')->find($id);
    }

    private function cutoff(object $payment): bool
    {
        return CarbonImmutable::parse($payment->created_at)->addMinutes(10)->lte(now());
    }

    private function abandon(?object $attempt): void
    {
        if ($attempt && $attempt->state === 'pending') {
            DB::table('stripe_checkout_attempts')->where('id', $attempt->id)->update([
                'state' => 'abandoned', 'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
        }
    }

    public function failOverdue(object $view): void
    {
        $this->locked($view, function ($payment, $user, $session, $attempt) {
            if (! $session && $payment->status === 'created' && $this->cutoff($payment)) {
                $this->abandon($attempt);
                $this->payments->applyProviderStartResult($payment->id, 'START_NOT_COMPLETED', 'failed');
            }
        });
    }

    public function ensureSession(object $view): void
    {
        $request = $this->locked($view, function ($payment, $user, $session, $attempt) {
            if ($payment->status !== 'created' || $session) return null;
            if ($this->cutoff($payment)) {
                $this->abandon($attempt);
                $this->payments->applyProviderStartResult($payment->id, 'START_NOT_COMPLETED', 'failed');
                return null;
            }
            if (! app(V2StripeConfiguration::class)->matchesPaymentMode($payment->id, config('v2_stripe.mode') === 'live')) {
                throw $this->unavailable();
            }
            if (! $attempt || $attempt->state !== 'pending'
                || CarbonImmutable::parse($attempt->request_expires_at)->lt(now()->addMinutes(31))) {
                $this->abandon($attempt);
                if ($attempt && $attempt->attempt_no >= 5) {
                    $this->payments->applyProviderStartResult($payment->id, 'START_ATTEMPTS_EXHAUSTED', 'failed');
                    return null;
                }
                $attempt = $this->newAttempt($payment, $user, ($attempt->attempt_no ?? 0) + 1);
            }
            $metadata = ['oripa-payment-id' => $payment->public_id, 'oripa-member' => $user->public_id];
            return [$attempt, [
                'mode' => 'payment', 'ui_mode' => 'elements', 'payment_method_types' => ['card'],
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => 'jpy', 'unit_amount' => (int) $payment->amount,
                    'product_data' => ['name' => $payment->plan_name_snapshot]]]],
                'customer_email' => Crypt::decryptString($attempt->request_email_ciphertext),
                'client_reference_id' => $payment->public_id, 'locale' => 'ja',
                'metadata' => [...$metadata, 'oripa-attempt' => (string) $attempt->attempt_no],
                'payment_intent_data' => ['metadata' => $metadata],
                'expires_at' => CarbonImmutable::parse($attempt->request_expires_at)->timestamp,
                'return_url' => $attempt->request_return_url,
            ]];
        });
        if ($request === null) return;
        [$sent, $params] = $request;
        try {
            $result = $this->client->create($params, $sent->idempotency_key);
        } catch (ApiErrorException $error) {
            $status = $error->getHttpStatus();
            if ($error instanceof ApiConnectionException || ! $status || $status === 429
                || $error->getStripeCode() === 'idempotency_key_in_use') throw $this->unavailable();
            $retry = $status >= 500 || $error instanceof \Stripe\Exception\IdempotencyException
                || $error->getError()?->param === 'expires_at';
            $current = $this->locked($view, function ($payment, $user, $session, $latest) use ($sent, $error, $retry) {
                if ($payment->status !== 'created' || $session || $latest?->id !== $sent->id || $latest->state !== 'pending') return false;
                $code = $error->getStripeCode();
                DB::table('stripe_checkout_attempts')->where('id', $sent->id)->update(['state' => 'failed',
                    'error_code' => is_string($code) && preg_match('/\A[a-z0-9_]{1,128}\z/', $code) ? $code : null,
                    'updated_at' => V2DatabaseTimestamp::format(now())]);
                if ($error instanceof \Stripe\Exception\IdempotencyException) $this->review($payment);
                if (! $retry) $this->payments->applyProviderStartResult($payment->id, 'START_REJECTED', 'failed');
                return true;
            });
            if (! $current) return;
            throw $retry ? $this->unavailable() : new V2FincodeException('PAYMENT_START_REJECTED', 409, 'The payment was rejected.');
        }
        $matched = $this->locked($view, function ($payment, $user, $session, $latest) use ($sent, $result, $params) {
            $id = $result['id'] ?? null;
            if ($session && $session->session_id === $id) {
                if ($session->livemode === ($result['livemode'] ?? null)
                    && app(V2StripeConfiguration::class)->matchesPaymentMode($payment->id, $session->livemode)) return true;
                $this->review($payment);
                return false;
            }
            if (! is_string($id) || ! preg_match('/\Acs_(live|test)_[a-zA-Z0-9]+\z/', $id)
                || ! $this->belongs($payment, $user, $result, $sent->attempt_no)) {
                $this->review($payment);
                return false;
            }
            if ($payment->status === 'created' && ! $session && $this->cutoff($payment)) {
                $this->abandon($latest);
                $this->payments->applyProviderStartResult($payment->id, 'START_NOT_COMPLETED', 'failed');
                $payment->status = 'failed';
            }
            $now = V2DatabaseTimestamp::format(now());
            if ($payment->status !== 'created' || $session || $latest?->id !== $sent->id || $latest->state !== 'pending') {
                DB::table('stripe_checkout_attempts')->where('id', $sent->id)->update([
                    'state' => 'superseded', 'session_id' => $id, 'updated_at' => $now,
                ]);
                $this->queueExpiry($payment, $id);
                return true;
            }
            if (($result['status'] ?? null) !== 'open' || ($result['payment_status'] ?? null) !== 'unpaid'
                || ! is_string($result['client_secret'] ?? null) || ! str_starts_with($result['client_secret'], $id.'_secret_')
                || ($result['expires_at'] ?? null) !== $params['expires_at']) {
                $this->review($payment);
                return false;
            }
            DB::table('stripe_checkout_sessions')->insert([
                'payment_id' => $payment->id, 'session_id' => $id, 'livemode' => $result['livemode'],
                'client_secret_ciphertext' => Crypt::encryptString($result['client_secret']),
                'session_expires_at' => $sent->request_expires_at, 'session_status' => 'open', 'payment_status' => 'unpaid',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('stripe_checkout_attempts')->where('id', $sent->id)->update([
                'state' => 'created', 'session_id' => $id, 'updated_at' => $now,
            ]);
            $this->payments->applyProviderStartResult($payment->id, 'OPEN', 'requires_action', CarbonImmutable::parse($sent->request_expires_at));
            return true;
        });
        if (! $matched) throw $this->unavailable();
    }

    public function belongs(object $payment, User $user, array $object, int $number): bool
    {
        return ($object['mode'] ?? null) === 'payment'
            && ($object['client_reference_id'] ?? null) === $payment->public_id
            && ($object['metadata']['oripa-payment-id'] ?? null) === $payment->public_id
            && ($object['metadata']['oripa-member'] ?? null) === $user->public_id
            && ($object['metadata']['oripa-attempt'] ?? null) === (string) $number
            && ($object['currency'] ?? null) === 'jpy' && ($object['amount_total'] ?? null) === (int) $payment->amount
            && app(V2StripeConfiguration::class)->matchesPaymentMode($payment->id, $object['livemode'] ?? null);
    }

    public function queueExpiry(object $payment, string $id): void
    {
        app(V2OutboxService::class)->enqueue('payment.stripe', 'payment', $payment->public_id, 'stripe.expire',
            ['object_id' => $id], 'stripe.expire:'.$id);
    }

    public function review(object $payment): void
    {
        $this->progress->append($payment->id, 'provider_api_called', ['operation' => 'lookup', 'match' => 'source_mismatch']);
    }

    public function reviewReceipt(int $receiptId): void
    {
        $receipt = DB::table('payment_provider_events')->where('provider_code', 'stripe')->where('id', $receiptId)->sole();
        $ids = Crypt::decryptString($receipt->payload_ciphertext);
        $eventId = json_decode($ids, true, 8, JSON_THROW_ON_ERROR)['event_id'];
        $this->payments->recordVerifiedProviderEvent('stripe', 'review:'.$eventId, 'payment.review.stripe_reference_unmatched', $ids);
    }

    private function unavailable(): V2FincodeException
    {
        return new V2FincodeException('PAYMENT_PROVIDER_UNAVAILABLE', 503, 'The payment provider is unavailable.', true);
    }
}
