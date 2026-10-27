<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2TechcoinKickbackService
{
    public function process(array $input): void
    {
        $id = V2TechcoinIdentity::paymentId($input['cookie'] ?? null);
        if ($id === null) return;
        $view = DB::table('payments')->where('public_id', $id)->where('provider_code', 'techcoin')->first();
        if ($view === null) return;
        app(V2PointTransactionRunner::class)->run(function () use ($view, $input): void {
            $order = $input['order_cpt'] ?? null;
            $validOrder = is_string($order) && preg_match('/\A[A-Za-z0-9_-]{1,191}\z/', $order);
            $reference = $validOrder ? $order : $input['cookie'];
            // Order reference is serialized before user/payment locks, including across different members.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['techcoin.reference:'.$reference]);
            $user = DB::table('users')->where('id', $view->user_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $view->id)->lockForUpdate()->firstOrFail();
            $match = $this->match($input, $payment, $user, (bool) $validOrder);
            if ($match !== 'ok') {
                $this->review((int) $payment->id, $match);
                return;
            }
            if (($payment->provider_payment_id !== null && $payment->provider_payment_id !== $reference)
                || DB::table('payments')->where('provider_code', 'techcoin')->where('provider_payment_id', $reference)
                    ->where('id', '<>', $payment->id)->exists()) {
                $this->review((int) $payment->id, 'duplicate_charge');
                return;
            }
            $success = (string) $input['rel'] === '1';
            // Fingerprint only canonical financial inputs. Optional sendpoint never changes event identity.
            $normalized = json_encode(['payment' => $payment->public_id, 'reference' => $reference,
                'result' => $success ? 'success' : 'failure', 'amount' => (int) $payment->amount], JSON_THROW_ON_ERROR);
            $eventId = hash('sha256', $normalized);
            if (DB::table('payment_provider_events')->where('provider_code', 'techcoin')->where('external_event_id', $eventId)->exists()) {
                return;
            }
            if (in_array($payment->status, ['succeeded', 'failed', 'canceled', 'expired'], true)) {
                if ($success) $this->review((int) $payment->id, 'expired');
                return;
            }
            if ($payment->expires_at === null || CarbonImmutable::parse($payment->expires_at)->lessThanOrEqualTo(now())) {
                if ($success) $this->review((int) $payment->id, 'expired');
                return;
            }
            $classification = DB::table('payment_commercial_classifications')->where('payment_id', $payment->id)->first();
            if ($classification === null) {
                $this->review((int) $payment->id, 'mode_mismatch');
                return;
            }
            DB::table('payments')->where('id', $payment->id)->update([
                'provider_payment_id' => $reference, 'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            $payments = app(V2PaymentService::class);
            // There is no signed provider occurrence time. Common authority uses received_at for Techcoin only.
            $event = $payments->recordVerifiedProviderEvent('techcoin', $eventId,
                $success ? 'payment.succeeded' : 'payment.failed', $normalized, paymentId: (int) $payment->id);
            app(V2PaymentProgressService::class)->append((int) $payment->id, 'provider_notified', [
                'result' => $success ? 'success' : 'error', 'match' => 'ok', 'amount' => (int) $payment->amount,
            ]);
            $payments->applyVerifiedStatus((int) $event->id, $success ? 'succeeded' : 'failed', $success ? '1' : '0');
        });
    }

    public function recordProcessingFailure(array $input): void
    {
        $id = V2TechcoinIdentity::paymentId($input['cookie'] ?? null);
        if ($id === null) return;
        DB::transaction(function () use ($id): void {
            $payment = DB::table('payments')->where('public_id', $id)->where('provider_code', 'techcoin')->lockForUpdate()->first();
            if ($payment !== null) $this->review((int) $payment->id, 'source_mismatch', 'reconciliation_failed');
        });
    }

    private function match(array $input, object $payment, object $user, bool $validOrder): string
    {
        if ($payment->payment_method !== 'netridecash' || $payment->currency !== 'JPY') return 'source_mismatch';
        if (! is_string($input['sendid'] ?? null) || ! preg_match('/\A[a-z0-9]{1,30}\z/', $input['sendid'])
            || ! hash_equals(V2TechcoinIdentity::sendid($user->public_id), $input['sendid'])) return 'member_mismatch';
        if (! $this->scalar($input['payment_code'] ?? null) || (string) $input['payment_code'] !== '10055'
            || config('v2_techcoin.payment_code') !== '10055') return 'source_mismatch';
        $price = $input['price'] ?? null;
        if (! $this->scalar($price) || ! preg_match('/\A[1-9][0-9]{0,5}\z/', (string) $price)
            || (string) $price !== (string) $payment->amount) return 'amount_mismatch';
        if (! $this->scalar($input['rel'] ?? null) || ! in_array((string) $input['rel'], ['0', '1'], true)
            || (array_key_exists('order_cpt', $input) && ! $validOrder)
            || (isset($input['currency']) && $input['currency'] !== 'JPY')
            || (array_key_exists('sendpoint', $input) && (! is_string($input['sendpoint'])
                || strlen($input['sendpoint']) > 150 || preg_match('/[\x00-\x1f\x7f]/', $input['sendpoint'])))) return 'source_mismatch';
        return 'ok';
    }

    private function scalar(mixed $value): bool
    {
        return is_string($value) || is_int($value);
    }

    private function review(int $paymentId, string $match, string $reason = 'reconciliation_failed'): void
    {
        // Bounded reasons only; no raw body, sender IP, sendpoint, email or path token.
        $detail = json_encode(['match' => $match, 'reason' => $reason], JSON_THROW_ON_ERROR);
        if (! DB::table('payment_progress_events')->where('payment_id', $paymentId)->where('source', 'server')
            ->whereRaw('detail = ?::jsonb', [$detail])->exists()) {
            app(V2PaymentProgressService::class)->append($paymentId, 'provider_notified', ['match' => $match, 'reason' => $reason]);
        }
        $type = 'payment.review.'.$match;
        app(V2PaymentService::class)->recordVerifiedProviderEvent('techcoin',
            hash('sha256', 'techcoin.review:'.$paymentId.':'.$match), $type, $detail, paymentId: $paymentId);
    }
}
