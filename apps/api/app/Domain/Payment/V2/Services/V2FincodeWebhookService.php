<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Payment\V2\Exceptions\V2FincodeException;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class V2FincodeWebhookService
{
    private const PAY_TYPES = [
        'Card' => 'credit_card',
        'Paypay' => 'paypay',
        'Konbini' => 'konbini',
        'Virtualaccount' => 'virtual_account',
    ];

    private const EVENTS = [
        'payments.card.regist',
        'payments.card.exec',
        'payments.card.capture',
        'payments.card.cancel',
        'payments.card.secure2.authenticate',
        'payments.card.secure2.result',
        'payments.card.secure',
        'payments.paypay.regist',
        'payments.paypay.exec',
        'payments.paypay.capture',
        'payments.paypay.cancel',
        'payments.paypay.complete',
        'payments.konbini.regist',
        'payments.konbini.exec',
        'payments.konbini.cancel',
        'payments.konbini.complete',
        'payments.konbini.complete.stub',
        'payments.konbini.expired.update.batch',
        'payments.virtualaccount.regist',
        'payments.virtualaccount.exec',
        'payments.virtualaccount.cancel',
        'payments.virtualaccount.complete',
        'payments.virtualaccount.complete.stub',
    ];

    public function __construct(
        private readonly V2FincodeClient $client,
        private readonly V2PaymentService $payments,
        private readonly V2FincodeCanonicalStatusClassifier $statusClassifier
    ) {
    }

    /** @return array{status: string} */
    public function process(string $rawPayload, ?string $signature): array
    {
        $this->verifySignature($signature);
        if ($rawPayload === '' || strlen($rawPayload) > 262_144) {
            throw new V2FincodeException('FINCODE_WEBHOOK_INVALID', 422, 'The webhook payload is invalid.');
        }
        try {
            $payload = json_decode($rawPayload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new V2FincodeException('FINCODE_WEBHOOK_INVALID', 422, 'The webhook payload is invalid.');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new V2FincodeException('FINCODE_WEBHOOK_INVALID', 422, 'The webhook payload is invalid.');
        }
        $eventName = $payload['event'] ?? null;
        if (! is_string($eventName) || $eventName === '') {
            throw new V2FincodeException('FINCODE_WEBHOOK_INVALID', 422, 'The webhook event is invalid.');
        }
        if (! in_array($eventName, self::EVENTS, true)) {
            return ['status' => 'ignored'];
        }
        $payType = $payload['pay_type'] ?? null;
        $method = is_string($payType) ? (self::PAY_TYPES[$payType] ?? null) : null;
        $orderId = $payload['order_id'] ?? $payload['id'] ?? null;
        if (
            $method === null
            || ! is_string($orderId)
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderId)
        ) {
            throw new V2FincodeException('FINCODE_WEBHOOK_INVALID', 422, 'The webhook payment reference is invalid.');
        }
        $payment = DB::table('payments')
            ->where('provider_code', 'fincode')
            ->where('provider_payment_id', $orderId)
            ->first();
        if ($payment === null || $payment->payment_method !== $method) {
            throw new V2FincodeException('FINCODE_PAYMENT_NOT_FOUND', 404, 'The webhook payment was not found.');
        }

        $canonical = $this->client->retrievePayment($orderId, $payType);

        return $this->applyCanonical(
            $canonical,
            $payment,
            $orderId,
            $payType,
            $eventName,
            $rawPayload,
            $payload
        );
    }

    /** @return array{status: string} */
    public function reconcile(object $payment): array
    {
        $payType = array_search($payment->payment_method, self::PAY_TYPES, true);
        if (
            ! is_string($payType)
            || $payment->provider_code !== 'fincode'
            || ! is_string($payment->provider_payment_id)
            || $payment->provider_payment_id === ''
        ) {
            throw new V2FincodeException(
                'FINCODE_PAYMENT_REFERENCE_INVALID',
                422,
                'The payment reference is invalid.'
            );
        }
        $canonical = $this->client->retrievePayment($payment->provider_payment_id, $payType);
        $evidence = [];
        $evidenceFields = [
            'id',
            'order_id',
            'pay_type',
            'status',
            'amount',
            'tax',
            'billing_total_amount',
            'error_code',
            'transaction_date',
            'payment_date',
            'process_date',
        ];
        foreach ($evidenceFields as $field) {
            if (array_key_exists($field, $canonical)) {
                $evidence[$field] = $canonical[$field];
            }
        }
        $rawEvidence = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->applyCanonical(
            $canonical,
            $payment,
            $payment->provider_payment_id,
            $payType,
            'payments.reconciled',
            $rawEvidence,
            $evidence
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $eventPayload
     * @return array{status: string}
     */
    private function applyCanonical(
        array $canonical,
        object $payment,
        string $orderId,
        string $payType,
        string $eventName,
        string $rawEvidence,
        array $eventPayload
    ): array
    {
        $this->validateCanonicalPayment($canonical, $payment, $orderId, $payType);
        $classification = $this->statusClassifier->classify($canonical, $payType);
        $providerStatus = $classification['provider_status'];
        $platformStatus = $classification['platform_status'];
        $terminalFailureCode = $classification['terminal_failure_code'];
        $occurredAt = $this->occurredAt($canonical, $eventPayload, $platformStatus === 'succeeded');
        $eventId = hash('sha256', implode('|', [
            'fincode',
            $eventName,
            $orderId,
            $providerStatus,
            $terminalFailureCode ?? '',
            $occurredAt?->toIso8601String() ?? '',
            hash('sha256', $rawEvidence),
        ]));
        $event = $this->payments->recordVerifiedProviderEvent(
            'fincode',
            $eventId,
            $platformStatus === 'succeeded' ? 'payment.succeeded' : 'payment.status_changed',
            $rawEvidence,
            [],
            $payment->id,
            null,
            $occurredAt
        );

        try {
            $this->payments->applyVerifiedStatus($event->id, $platformStatus, $providerStatus);
        } catch (V2PaymentException $exception) {
            if (! in_array($exception->getMessage(), [
                'PAYMENT_STATUS_TRANSITION_INVALID',
                'PAYMENT_TERMINAL_STATE',
            ], true)) {
                throw $exception;
            }
            return ['status' => 'ignored'];
        }
        $attemptUpdate = [
            'status' => match ($platformStatus) {
                'succeeded' => 'completed',
                'processing' => 'pending',
                'requires_action' => 'requires_action',
                default => 'failed',
            },
            'updated_at' => now(),
        ];
        if ($terminalFailureCode !== null) {
            $attemptUpdate['last_error_code'] = $terminalFailureCode;
        }
        DB::table('fincode_payment_attempts')
            ->where('payment_id', $payment->id)
            ->update($attemptUpdate);

        return ['status' => 'processed'];
    }

    private function verifySignature(?string $actual): void
    {
        $expected = config('v2_fincode.webhook_signature');
        if (
            ! is_string($expected)
            || $expected === ''
            || ! is_string($actual)
            || ! hash_equals($expected, $actual)
        ) {
            throw new V2FincodeException(
                'FINCODE_WEBHOOK_AUTHENTICITY_INVALID',
                401,
                'The webhook authenticity check failed.'
            );
        }
    }

    /** @param array<string, mixed> $canonical */
    private function validateCanonicalPayment(
        array $canonical,
        object $payment,
        string $orderId,
        string $payType
    ): void {
        $canonicalId = $canonical['id'] ?? $canonical['order_id'] ?? null;
        $status = $canonical['status'] ?? null;
        $canonicalPayType = $canonical['pay_type'] ?? null;
        if (
            $canonicalId !== $orderId
            || $canonicalPayType !== $payType
            || ! is_string($status)
        ) {
            throw new V2FincodeException('FINCODE_CANONICAL_RESPONSE_INVALID', 503, 'The canonical payment response is invalid.', true);
        }
        $amount = $this->integerAmount($canonical['billing_total_amount'] ?? null);
        if ($amount === null) {
            $baseAmount = $this->integerAmount($canonical['amount'] ?? null);
            $tax = $this->integerAmount($canonical['tax'] ?? 0);
            $amount = $baseAmount === null || $tax === null ? null : $baseAmount + $tax;
        }
        if ($amount === null || $amount !== (int) $payment->amount) {
            throw new V2FincodeException('FINCODE_PAYMENT_AMOUNT_MISMATCH', 409, 'The canonical payment amount does not match.');
        }
    }

    private function integerAmount(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $payload
     */
    private function occurredAt(array $canonical, array $payload, bool $required): ?CarbonImmutable
    {
        foreach (['transaction_date', 'payment_date', 'process_date'] as $field) {
            $value = $canonical[$field] ?? $payload[$field] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            foreach (['Y/m/d H:i:s.v', 'Y/m/d H:i:s'] as $format) {
                try {
                    $date = CarbonImmutable::createFromFormat($format, $value, 'Asia/Tokyo');
                } catch (Throwable) {
                    $date = false;
                }
                if ($date !== false) {
                    return $date->utc()->startOfSecond();
                }
            }
        }
        if ($required) {
            throw new V2FincodeException(
                'FINCODE_OCCURRED_AT_REQUIRED',
                503,
                'The canonical payment timestamp is unavailable.',
                true
            );
        }

        return null;
    }
}
