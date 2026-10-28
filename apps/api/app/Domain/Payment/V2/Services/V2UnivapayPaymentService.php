<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Models\V2\User;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2UnivapayPaymentService
{
    public function __construct(
        private readonly V2PaymentService $payments,
        private readonly V2UnivapayClient $client,
        private readonly V2PointTransactionRunner $transactions,
        private readonly V2PaymentProgressService $progress,
    ) {}

    public function start(User $user, string $productId, string $key): array
    {
        app(V2UnivapayConfiguration::class)->assertConfigured();
        $plan = DB::table('point_purchase_plans')->where('public_id', $productId)->first();
        if ($plan === null) throw new V2FincodeException('POINT_PRODUCT_NOT_FOUND', 404, 'The point product was not found.');
        try {
            $payment = $this->transactions->run(function () use ($user, $plan, $key): object {
                $payment = $this->payments->createPayment((int) $user->id, (int) $plan->id, 'univapay', null, $key, 'credit_card', true);
                // Recheck the locked snapshot, including a price change after discovery.
                if ((int) $payment->amount > 100000) throw new V2FincodeException('PAYMENT_AMOUNT_TOO_LARGE', 422, 'The payment amount exceeds the provider limit.');
                if ($payment->status === 'created') {
                    $this->payments->applyProviderStartResult((int) $payment->id, 'PENDING', 'requires_action', CarbonImmutable::parse($payment->created_at)->addMinutes(60));
                }

                return $payment;
            });
        } catch (V2PaymentException|V2PointException $exception) {
            $code = in_array($exception->getMessage(), ['IDEMPOTENCY_KEY_REUSED', 'IDEMPOTENCY_REQUEST_IN_PROGRESS'], true)
                ? $exception->getMessage() : 'PAYMENT_START_REJECTED';
            throw new V2FincodeException($code, 409, 'The payment could not be started.');
        }

        return app(V2FincodePaymentService::class)->show($user, $payment->public_id);
    }

    public function action(object $payment): ?array
    {
        if ($payment->status !== 'requires_action' || $payment->expires_at === null
            || CarbonImmutable::parse($payment->expires_at)->lessThanOrEqualTo(now())) return null;
        $user = User::query()->findOrFail($payment->user_id);
        if ($user->email_verified_at === null) return null;
        $update = ['metadata' => ['oripa-payment-id' => $payment->public_id, 'oripa-member' => $user->public_id], 'email' => $user->email_display];
        $phone = DB::table('user_phone_numbers')->where('user_id', $user->id)->whereNotNull('verified_at')->whereNull('revoked_at')->first();
        if ($phone !== null) {
            $number = Crypt::decryptString($phone->phone_ciphertext);
            if (preg_match('/\A\+81([0-9]{9,10})\z/', $number, $match)) {
                $update['phoneNumber'] = ['countryCode' => '+81', 'localNumber' => '0'.$match[1]];
            }
        }

        return ['type' => 'univapay_inline', 'amount' => (int) $payment->amount, 'update' => $update,
            'success_url' => app(V2FincodeReturnUrl::class)->storefrontNormalForPayment($payment->public_id)];
    }

    public function report(User $user, string $paymentId, array $input): array
    {
        $payment = Str::isUuid($paymentId) ? DB::table('payments')->where('public_id', $paymentId)->where('user_id', $user->id)->first() : null;
        if ($payment === null) throw new V2FincodeException('PAYMENT_NOT_FOUND', 404, 'The payment was not found.');
        if ($payment->provider_code !== 'univapay') throw new V2FincodeException('PAYMENT_PROVIDER_MISMATCH', 409, 'The payment provider does not support this operation.');
        if (array_keys($input) !== ['charge_id'] || ! is_string($input['charge_id']) || ! Str::isUuid($input['charge_id'])) {
            throw new V2FincodeException('PAYMENT_REQUEST_INVALID', 422, 'The charge identifier is invalid.');
        }
        $this->progress->append((int) $payment->id, 'charge_reported', ['charge_id' => $input['charge_id']]);
        $this->reconcile($input['charge_id'], (int) $payment->id, true);

        return app(V2FincodePaymentService::class)->show($user, $paymentId);
    }

    /** All external HTTP happens before acquiring domain locks. False means retryable uncertainty. */
    public function reconcile(string $chargeId, ?int $expectedPaymentId = null, bool $polling = false, bool $reversal = false): bool
    {
        $charge = $this->client->charge($chargeId, $polling);
        if ($charge === null) {
            if ($expectedPaymentId !== null) $this->progress->append($expectedPaymentId, 'provider_api_called', ['operation' => 'lookup', 'reason' => 'timeout']);
            return false;
        }
        $publicId = $charge['metadata']['oripa-payment-id'] ?? null;
        $payment = $expectedPaymentId !== null
            ? DB::table('payments')->where('id', $expectedPaymentId)->first()
            : (is_string($publicId) && Str::isUuid($publicId) ? DB::table('payments')->where('public_id', $publicId)->first() : null);
        if ($payment === null || $payment->provider_code !== 'univapay') return true;

        $this->transactions->run(function () use ($payment, $charge, $chargeId, $reversal): void {
            // A charge is serialized across payments as well as within a payment; the unique index remains the last guard.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['univapay:'.$chargeId]);
            $user = User::query()->whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            $match = $this->match($payment, $user, $charge, $chargeId);
            if ($match !== 'ok') { $this->review($payment, $match, $chargeId); return; }
            $other = DB::table('payments')->where('provider_code', 'univapay')->where('provider_payment_id', $chargeId)->where('id', '!=', $payment->id)->exists();
            if ($other || ($payment->provider_payment_id !== null && $payment->provider_payment_id !== $chargeId)) {
                $this->review($payment, 'duplicate_charge', $chargeId); return;
            }
            DB::table('payments')->where('id', $payment->id)->update(['provider_payment_id' => $chargeId]);
            if ($reversal) { $this->review($payment, 'ok', $chargeId, 'provider_reversed'); return; }
            $status = $charge['status'] ?? null;
            if (! in_array($status, ['successful','pending','authorized','awaiting','failed','error','canceled'], true)) {
                $this->review($payment, 'source_mismatch', $chargeId); return;
            }
            if (in_array($status, ['pending','authorized','awaiting'], true)) {
                DB::table('payments')->where('id', $payment->id)->update(['provider_status' => strtoupper($status)]);
                $this->progress->append((int) $payment->id, 'provider_api_called', ['operation' => 'lookup', 'match' => 'ok']);
                return;
            }
            // An already granted identical charge is a replay, including after the deadline.
            if ($status === 'successful' && $payment->status === 'succeeded') return;
            $closureSuccess = $status === 'successful' && DB::table('account_closures')->where('user_id', $payment->user_id)->exists();
            if (! $closureSuccess && in_array($payment->status, ['succeeded','failed','canceled','expired'], true)) {
                if ($status === 'successful' || ($status === 'canceled' && $payment->status === 'succeeded')) {
                    $this->review($payment, $status === 'successful' ? 'expired' : 'ok', $chargeId, $status === 'canceled' ? 'provider_reversed' : 'reconciliation_failed');
                }
                return;
            }
            if (! $closureSuccess && $status === 'successful' && ($payment->expires_at === null || CarbonImmutable::parse($payment->expires_at)->lessThanOrEqualTo(now()))) {
                $this->review($payment, 'expired', $chargeId); return;
            }
            $occurred = $this->occurred($charge['created_on'] ?? null);
            if ($status === 'successful' && ($occurred === null || $occurred->lessThan(CarbonImmutable::parse($payment->created_at)->startOfSecond()))) {
                $this->review($payment, 'source_mismatch', $chargeId); return;
            }
            $target = match ($status) { 'successful' => 'succeeded', 'canceled' => 'canceled', default => 'failed' };
            // Store only a normalized enum/ID envelope; provider error message and PII are never retained.
            $normalized = json_encode(['charge_id' => $chargeId, 'status' => $status], JSON_THROW_ON_ERROR);
            $event = $this->payments->recordVerifiedProviderEvent('univapay', 'lookup:'.$chargeId.':'.$status, 'payment.'.$target,
                $normalized, [], (int) $payment->id, providerOccurredAt: $occurred);
            $detail = ['operation' => 'lookup', 'match' => 'ok', 'charge_id' => $chargeId];
            $errorCode = $charge['error']['code'] ?? null;
            if (is_int($errorCode) && $errorCode >= 0 && $errorCode <= 999999) $detail['error_code'] = $errorCode;
            $this->progress->append((int) $payment->id, 'provider_api_called', $detail);
            $this->payments->applyVerifiedStatus((int) $event->id, $target, strtoupper($status));
        });

        return true;
    }

    private function match(object $payment, User $user, array $charge, string $id): string
    {
        if (($charge['id'] ?? null) !== $id || ($charge['store_id'] ?? null) !== config('v2_univapay.store_id')
            || ($charge['transaction_token_type'] ?? null) !== 'one_time'
            || ! is_string($charge['requested_currency'] ?? null) || strtoupper($charge['requested_currency']) !== 'JPY'
            || (($charge['status'] ?? null) === 'successful' && (! is_string($charge['charged_currency'] ?? null) || strtoupper($charge['charged_currency']) !== 'JPY'))) return 'source_mismatch';
        $classification = DB::table('payment_commercial_classifications')->where('payment_id', $payment->id)->value('classification');
        $mode = $classification === 'commercial' ? 'live' : ($classification === 'test' ? 'test' : null);
        if ($mode === null || ($charge['mode'] ?? null) !== $mode || $mode !== config('v2_univapay.mode')) return 'mode_mismatch';
        if (($charge['metadata']['oripa-payment-id'] ?? null) !== $payment->public_id
            || ($charge['metadata']['oripa-member'] ?? null) !== $user->public_id) return 'member_mismatch';
        if (! is_int($charge['requested_amount'] ?? null) || $charge['requested_amount'] !== (int) $payment->amount
            || (($charge['status'] ?? null) === 'successful' && (! is_int($charge['charged_amount'] ?? null) || $charge['charged_amount'] !== (int) $payment->amount))) return 'amount_mismatch';

        return 'ok';
    }

    private function occurred(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})\z/', $value)) return null;
        try { $date = CarbonImmutable::parse($value)->utc()->startOfSecond(); }
        catch (\Throwable) { return null; }

        return $date->greaterThan(now()) ? null : $date;
    }

    private function review(object $payment, string $match, string $chargeId, string $reason = 'reconciliation_failed'): void
    {
        $this->progress->append((int) $payment->id, 'provider_api_called', ['operation' => 'lookup', 'match' => $match, 'charge_id' => $chargeId, 'reason' => $reason]);
    }

    public function reconcileDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Invalid reconciliation limit.');
        $rows = DB::table('payments')->where('provider_code', 'univapay')
            ->whereIn('status', ['requires_action','processing','expired','failed','canceled'])
            ->where('created_at', '<=', V2DatabaseTimestamp::format(now()->subMinutes(5)))
            ->where('expires_at', '>=', V2DatabaseTimestamp::format(now()->subHours(24)))
            ->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        foreach ($rows as $payment) {
            $ids = $payment->provider_payment_id === null ? $this->client->candidates($payment) : [$payment->provider_payment_id];
            foreach ($ids ?? [] as $id) $this->reconcile($id, (int) $payment->id);
            $this->transactions->run(function () use ($payment): void {
                DB::table('users')->where('id', $payment->user_id)->lockForUpdate()->firstOrFail();
                $current = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->firstOrFail();
                if (in_array($current->status, ['requires_action','processing'], true) && CarbonImmutable::parse($current->expires_at)->lessThanOrEqualTo(now())) {
                    $event = $this->payments->recordVerifiedProviderEvent('univapay', 'expiry:'.$current->public_id, 'payment.expired', '{}', [], (int) $current->id);
                    $this->payments->applyVerifiedStatus((int) $event->id, 'expired');
                }
                DB::table('payments')->where('id', $current->id)->update(['updated_at' => V2DatabaseTimestamp::format(now())]);
            });
        }

        return $rows->count();
    }
}
