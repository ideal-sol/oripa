<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2PaymentService
{
    private const TERMINAL_PAYMENT_STATUSES = ['succeeded', 'failed', 'canceled', 'expired'];
    private const PAYMENT_TRANSITIONS = [
        'created' => ['requires_action', 'processing', 'succeeded', 'failed', 'canceled', 'expired'],
        'requires_action' => ['processing', 'succeeded', 'failed', 'canceled', 'expired'],
        'processing' => ['succeeded', 'failed', 'canceled', 'expired'],
    ];

    public function __construct(
        private readonly V2PointTransactionRunner $transactions,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
        private readonly V2OutboxService $outbox,
        private readonly V2PointPurchaseEligibilityService $purchaseEligibility
    ) {
    }

    public function assertRuntimeSafe(): void
    {
        if (app()->environment('production') && config('v2_payment.mock_driver') === true) {
            throw new V2PaymentException('Mock payment is prohibited in production.');
        }
    }

    public function createPayment(
        int $userId,
        int $planId,
        string $providerCode,
        ?string $providerPaymentId,
        string $idempotencyKey
    ): object {
        $this->assertRuntimeSafe();
        $this->assertCode($providerCode, 64);
        $user = User::query()->findOrFail($userId);

        return $this->transactions->run(function () use (
            $user,
            $planId,
            $providerCode,
            $providerPaymentId,
            $idempotencyKey
        ): object {
            $claim = $this->idempotency->claim(
                'payment.create',
                'user',
                $user->public_id,
                $idempotencyKey,
                [
                    'plan_id' => $planId,
                    'provider_code' => $providerCode,
                    'provider_payment_id' => $providerPaymentId,
                ]
            );
            if ($claim->replay) {
                return DB::table('payments')
                    ->where('public_id', $claim->record->resource_public_id)
                    ->firstOrFail();
            }
            $plan = DB::table('point_purchase_plans')
                ->where('id', $planId)
                ->where('status', 'published')
                ->where(fn ($query) => $query->whereNull('available_from')
                    ->orWhere('available_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('available_until')
                    ->orWhere('available_until', '>', now()))
                ->lockForUpdate()
                ->first();
            if ($plan === null) {
                throw new V2PaymentException('PURCHASE_PLAN_NOT_AVAILABLE');
            }
            $this->purchaseEligibility->assertEligible($user, $plan);
            $now = now()->startOfSecond();
            $publicId = (string) Str::uuid7();
            $paymentId = DB::table('payments')->insertGetId([
                'public_id' => $publicId,
                'user_id' => $user->id,
                'point_purchase_plan_id' => $plan->id,
                'provider_code' => $providerCode,
                'provider_payment_id' => $providerPaymentId,
                'status' => 'created',
                'amount' => $plan->amount,
                'currency' => 'JPY',
                'paid_point_amount' => $plan->paid_point_amount,
                'free_point_amount' => $plan->free_point_amount,
                'plan_name_snapshot' => $plan->name,
                'plan_code_snapshot' => $plan->code,
                'idempotency_record_id' => $claim->record->id,
                'metadata' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->paymentHistory($paymentId, null, 'created', 'user', null);
            $this->idempotency->complete($claim->record, 'payment', $publicId);

            return DB::table('payments')->where('id', $paymentId)->firstOrFail();
        });
    }

    /**
     * @param array<string, string> $headers
     */
    public function recordVerifiedProviderEvent(
        string $providerCode,
        string $externalEventId,
        string $eventType,
        string $rawPayload,
        array $headers = [],
        ?int $paymentId = null,
        ?int $adjustmentId = null,
        ?\DateTimeInterface $providerOccurredAt = null
    ): object {
        foreach (array_keys($headers) as $key) {
            if (preg_match('/authorization|cookie|secret|token|signature/i', $key)) {
                unset($headers[$key]);
            }
        }
        try {
            DB::table('payment_provider_events')->insert([
                'provider_code' => $providerCode,
                'external_event_id' => $externalEventId,
                'event_type' => $eventType,
                'payment_id' => $paymentId,
                'payment_adjustment_id' => $adjustmentId,
                'signature_verified_at' => now()->startOfSecond(),
                'provider_occurred_at' => $providerOccurredAt,
                'received_at' => now()->startOfSecond(),
                'payload_hash' => hash('sha256', $rawPayload),
                'payload_ciphertext' => Crypt::encryptString($rawPayload),
                'headers_redacted' => json_encode((object) $headers, JSON_THROW_ON_ERROR),
                'processing_status' => 'received',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
        }

        $event = DB::table('payment_provider_events')
            ->where('provider_code', $providerCode)
            ->where('external_event_id', $externalEventId)
            ->firstOrFail();
        if (
            $event->event_type !== $eventType
            || (int) ($event->payment_id ?? 0) !== (int) ($paymentId ?? 0)
            || (int) ($event->payment_adjustment_id ?? 0) !== (int) ($adjustmentId ?? 0)
            || ! hash_equals($event->payload_hash, hash('sha256', $rawPayload))
        ) {
            throw new V2PaymentException('PROVIDER_EVENT_ID_REUSED');
        }

        return $event;
    }

    /**
     * Provider通信を開始する前にTransaction外で永続化する境界。
     *
     * @param array<string, mixed> $requestRedacted
     */
    public function beginProviderRefundOperation(
        int $paymentId,
        int $adjustmentId,
        string $providerIdempotencyKey,
        array $requestRedacted
    ): object {
        if (DB::transactionLevel() !== 0) {
            throw new V2PaymentException('PROVIDER_CALL_TRANSACTION_PROHIBITED');
        }
        $this->assertRedactedPayload($requestRedacted);
        $keyHash = hash('sha256', $providerIdempotencyKey);
        $requestHash = hash(
            'sha256',
            json_encode($requestRedacted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
        DB::table('payment_provider_operations')->insertOrIgnore([
            'public_id' => (string) Str::uuid7(),
            'operation_type' => 'refund',
            'payment_id' => $paymentId,
            'payment_adjustment_id' => $adjustmentId,
            'provider_idempotency_key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'request_redacted' => json_encode(
                (object) $requestRedacted,
                JSON_THROW_ON_ERROR
            ),
            'status' => 'pending',
            'attempt_count' => 0,
            'timed_out' => false,
            'outcome_uncertain' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operation = DB::table('payment_provider_operations')
            ->where('operation_type', 'refund')
            ->where('provider_idempotency_key_hash', $keyHash)
            ->firstOrFail();
        if (
            (int) $operation->payment_id !== $paymentId
            || (int) ($operation->payment_adjustment_id ?? 0) !== $adjustmentId
            || ! hash_equals($operation->request_hash, $requestHash)
        ) {
            throw new V2PaymentException('PROVIDER_OPERATION_KEY_REUSED');
        }

        return $operation;
    }

    /**
     * @param array<string, mixed>|null $responseRedacted
     */
    public function resolveProviderOperation(
        int $operationId,
        string $result,
        ?array $responseRedacted = null
    ): object {
        if (DB::transactionLevel() !== 0) {
            throw new V2PaymentException('PROVIDER_CALL_TRANSACTION_PROHIBITED');
        }
        if (! in_array($result, ['succeeded', 'failed', 'uncertain'], true)) {
            throw new V2PaymentException('PROVIDER_OPERATION_RESULT_INVALID');
        }
        if ($responseRedacted !== null) {
            $this->assertRedactedPayload($responseRedacted);
        }
        $operation = DB::table('payment_provider_operations')
            ->where('id', $operationId)->firstOrFail();
        if (in_array($operation->status, ['succeeded', 'failed'], true)) {
            if ($operation->status !== $result) {
                throw new V2PaymentException('PROVIDER_OPERATION_TERMINAL_STATE');
            }

            return $operation;
        }
        DB::table('payment_provider_operations')->where('id', $operationId)->update([
            'status' => $result,
            'response_redacted' => $responseRedacted === null
                ? null
                : json_encode((object) $responseRedacted, JSON_THROW_ON_ERROR),
            'attempt_count' => DB::raw('attempt_count + 1'),
            'timed_out' => $result === 'uncertain',
            'outcome_uncertain' => $result === 'uncertain',
            'completed_at' => $result === 'uncertain' ? null : now()->startOfSecond(),
            'updated_at' => now(),
        ]);

        return DB::table('payment_provider_operations')
            ->where('id', $operationId)->firstOrFail();
    }

    public function applyVerifiedStatus(int $providerEventId, string $status): object
    {
        if ($status === 'succeeded') {
            $this->confirmSucceeded($providerEventId);

            return DB::table('payments')
                ->where('id', DB::table('payment_provider_events')
                    ->where('id', $providerEventId)->value('payment_id'))
                ->firstOrFail();
        }
        if (! in_array($status, ['requires_action', 'processing', 'failed', 'canceled', 'expired'], true)) {
            throw new V2PaymentException('PAYMENT_STATUS_INVALID');
        }

        return $this->transactions->run(function () use ($providerEventId, $status): object {
            $event = DB::table('payment_provider_events')
                ->where('id', $providerEventId)->lockForUpdate()->firstOrFail();
            if ($event->signature_verified_at === null || $event->payment_id === null) {
                throw new V2PaymentException('VERIFIED_SERVER_EVENT_REQUIRED');
            }
            $payment = DB::table('payments')
                ->where('id', $event->payment_id)->lockForUpdate()->firstOrFail();
            if ($payment->status === $status) {
                return $payment;
            }
            if (! in_array($status, self::PAYMENT_TRANSITIONS[$payment->status] ?? [], true)) {
                throw new V2PaymentException('PAYMENT_STATUS_TRANSITION_INVALID');
            }
            $transitioned = $this->transitionPayment(
                $payment,
                $status,
                'provider_event',
                $event->id
            );
            $this->providerAttempt($event->id, 'success');
            $this->audit->record('payment.status_changed', [
                'target_type' => 'payment',
                'target_public_id' => $payment->public_id,
                'metadata' => [
                    'from_status' => $payment->status,
                    'to_status' => $status,
                    'provider_code' => $payment->provider_code,
                ],
            ]);

            return $transitioned;
        });
    }

    public function confirmSucceeded(int $providerEventId): object
    {
        return $this->transactions->run(function () use ($providerEventId): object {
            $event = DB::table('payment_provider_events')
                ->where('id', $providerEventId)->lockForUpdate()->firstOrFail();
            if ($event->signature_verified_at === null || $event->payment_id === null) {
                throw new V2PaymentException('VERIFIED_SERVER_EVENT_REQUIRED');
            }
            $payment = DB::table('payments')
                ->where('id', $event->payment_id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('payment_point_grants')
                ->where('payment_id', $payment->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if (in_array($payment->status, self::TERMINAL_PAYMENT_STATUSES, true)) {
                throw new V2PaymentException('PAYMENT_TERMINAL_STATE');
            }
            if (! in_array('succeeded', self::PAYMENT_TRANSITIONS[$payment->status] ?? [], true)) {
                throw new V2PaymentException('PAYMENT_STATUS_TRANSITION_INVALID');
            }
            $plan = DB::table('point_purchase_plans')
                ->where('id', $payment->point_purchase_plan_id)
                ->lockForUpdate()
                ->firstOrFail();
            $user = User::query()->findOrFail($payment->user_id);
            $this->purchaseEligibility->assertEligible($user, $plan, (int) $payment->id);
            $this->transitionPayment($payment, 'succeeded', 'provider_event', $event->id);
            $grant = $this->grantPaymentPoints($payment);
            DB::table('payments')->where('id', $payment->id)->update([
                'points_granted_at' => now()->startOfSecond(),
                'updated_at' => now(),
            ]);
            $this->providerAttempt($event->id, 'success');
            $this->audit->record('payment.succeeded', [
                'target_type' => 'payment',
                'target_public_id' => $payment->public_id,
                'metadata' => [
                    'provider_code' => $payment->provider_code,
                    'amount' => (int) $payment->amount,
                    'currency' => $payment->currency,
                    'point_operation_public_id' => $grant->operation_public_id,
                ],
            ]);
            $this->outbox->enqueue(
                'payment.lifecycle',
                'payment',
                $payment->public_id,
                'payment.succeeded',
                ['payment_public_id' => $payment->public_id],
                'payment.succeeded:'.$payment->public_id
            );

            return DB::table('payment_point_grants')
                ->where('payment_id', $payment->id)->firstOrFail();
        });
    }

    public function reserveFullRefund(
        int $paymentId,
        string $idempotencyKey,
        ?int $requestedByAdminId = null
    ): object {
        $paymentView = DB::table('payments')->where('id', $paymentId)->firstOrFail();
        $user = User::query()->findOrFail($paymentView->user_id);

        return $this->transactions->run(function () use (
            $paymentId,
            $idempotencyKey,
            $requestedByAdminId,
            $user
        ): object {
            $claim = $this->idempotency->claim(
                'payment.refund',
                'admin',
                $user->public_id,
                $idempotencyKey,
                ['payment_id' => $paymentId, 'mode' => 'full']
            );
            if ($claim->replay) {
                return DB::table('payment_adjustments')
                    ->where('public_id', $claim->record->resource_public_id)->firstOrFail();
            }
            $payment = DB::table('payments')->where('id', $paymentId)
                ->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'succeeded') {
                throw new V2PaymentException('REFUND_PAYMENT_NOT_SUCCEEDED');
            }
            if (DB::table('payment_adjustments')->where('payment_id', $paymentId)
                ->whereIn('type', ['refund', 'chargeback'])
                ->where('status', 'succeeded')->exists()) {
                throw new V2PaymentException('REFUND_ALREADY_COMPLETED');
            }
            $wallet = $this->lockWallet($payment->user_id);
            $lots = $this->paymentLots($payment->id);
            if ($lots->isEmpty()) {
                throw new V2PaymentException('REFUND_POINT_GRANT_NOT_FOUND');
            }
            foreach ($lots as $lot) {
                if (
                    (int) $lot->remaining_amount !== (int) $lot->granted_amount
                    || (int) $lot->reserved_amount !== 0
                    || ($lot->point_type === 'free' && CarbonImmutable::parse($lot->expire_at)->isPast())
                ) {
                    throw new V2PaymentException('REFUND_POINTS_NOT_FULLY_UNUSED');
                }
            }
            $adjustment = $this->createAdjustment(
                $payment,
                'refund',
                'requested',
                $requestedByAdminId,
                null,
                null
            );
            $this->adjustmentHistory($adjustment->id, null, 'requested', 'admin');
            $paidReserved = 0;
            $freeReserved = 0;
            foreach ($lots as $lot) {
                DB::table('point_lot_reservations')->insert([
                    'point_lot_id' => $lot->id,
                    'payment_adjustment_id' => $adjustment->id,
                    'amount' => $lot->granted_amount,
                    'status' => 'active',
                    'reserved_at' => now()->startOfSecond(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('point_lots')->where('id', $lot->id)->update([
                    'reserved_amount' => $lot->granted_amount,
                    'updated_at' => now(),
                ]);
                if ($lot->point_type === 'paid') {
                    $paidReserved += (int) $lot->granted_amount;
                } else {
                    $freeReserved += (int) $lot->granted_amount;
                }
            }
            DB::table('wallets')->where('id', $wallet->id)->update([
                'paid_reserved_balance' => (int) $wallet->paid_reserved_balance + $paidReserved,
                'free_reserved_balance' => (int) $wallet->free_reserved_balance + $freeReserved,
                'lock_version' => (int) $wallet->lock_version + 1,
                'updated_at' => now(),
            ]);
            $this->transitionAdjustment($adjustment, 'points_reserved', 'system');
            $this->outbox->enqueue(
                'payment.provider',
                'payment_adjustment',
                $adjustment->public_id,
                'payment.refund.requested',
                [
                    'payment_public_id' => $payment->public_id,
                    'adjustment_public_id' => $adjustment->public_id,
                ],
                'payment.refund:'.$adjustment->public_id
            );
            $this->idempotency->complete(
                $claim->record,
                'payment_adjustment',
                $adjustment->public_id
            );
            $this->auditAdjustment($payment, $adjustment, 'payment.refund_reserved');

            return DB::table('payment_adjustments')->where('id', $adjustment->id)->firstOrFail();
        });
    }

    public function resolveRefund(int $adjustmentId, string $result): object
    {
        if (! in_array($result, ['succeeded', 'failed', 'uncertain'], true)) {
            throw new V2PaymentException('REFUND_RESULT_INVALID');
        }

        return $this->transactions->run(function () use ($adjustmentId, $result): object {
            $adjustment = DB::table('payment_adjustments')->where('id', $adjustmentId)
                ->lockForUpdate()->firstOrFail();
            if (in_array($adjustment->status, ['succeeded', 'failed', 'canceled'], true)) {
                $expected = $result === 'succeeded' ? 'succeeded' : ($result === 'failed' ? 'failed' : null);
                if ($expected !== $adjustment->status) {
                    throw new V2PaymentException('REFUND_TERMINAL_STATE');
                }

                return $adjustment;
            }
            $payment = DB::table('payments')->where('id', $adjustment->payment_id)
                ->lockForUpdate()->firstOrFail();
            $wallet = $this->lockWallet($payment->user_id);
            $reservations = DB::table('point_lot_reservations')
                ->where('payment_adjustment_id', $adjustment->id)
                ->where('status', 'active')->orderBy('point_lot_id')->lockForUpdate()->get();
            if ($result === 'uncertain') {
                return $this->transitionAdjustment($adjustment, 'processing', 'provider');
            }
            if ($result === 'failed') {
                $this->releaseReservations($wallet, $reservations);
                $failed = $this->transitionAdjustment($adjustment, 'failed', 'provider');
                $this->auditAdjustment($payment, $failed, 'payment.refund_failed');

                return $failed;
            }

            $operation = $this->newPointOperation(
                $payment,
                'refund_reversal',
                'payment_adjustment',
                'payment.refund:'.$adjustment->id
            );
            $sequence = 1;
            $paid = 0;
            $free = 0;
            $runningPaidBalance = (int) $wallet->paid_balance;
            $runningFreeBalance = (int) $wallet->free_balance;
            foreach ($reservations as $reservation) {
                $lot = DB::table('point_lots')->where('id', $reservation->point_lot_id)
                    ->lockForUpdate()->firstOrFail();
                $amount = (int) $reservation->amount;
                DB::table('point_lots')->where('id', $lot->id)->update([
                    'reserved_amount' => (int) $lot->reserved_amount - $amount,
                    'updated_at' => now(),
                ]);
                $lot->reserved_amount = (int) $lot->reserved_amount - $amount;
                $this->debitLot(
                    $operation,
                    $wallet,
                    $lot,
                    $amount,
                    $sequence++,
                    $runningPaidBalance,
                    $runningFreeBalance
                );
                DB::table('point_lot_reservations')->where('id', $reservation->id)->update([
                    'status' => 'consumed',
                    'consumed_at' => now()->startOfSecond(),
                    'updated_at' => now(),
                ]);
                $lot->point_type === 'paid' ? $paid += $amount : $free += $amount;
            }
            $this->updateWalletAfterDebit($wallet, $paid, $free, $paid, $free);
            DB::table('payment_adjustment_point_operations')->insert([
                'payment_adjustment_id' => $adjustment->id,
                'point_operation_id' => $operation->id,
                'role' => 'reserve_consume',
                'created_at' => now(),
            ]);
            DB::table('payment_adjustment_point_impacts')->insert([
                'payment_adjustment_id' => $adjustment->id,
                'required_paid_amount' => $payment->paid_point_amount,
                'required_free_amount' => $payment->free_point_amount,
                'reversed_paid_from_paid' => $paid,
                'reversed_free_from_free' => $free,
                'reversed_paid_shortage_from_free' => 0,
                'shortfall_paid_amount' => 0,
                'shortfall_free_amount' => 0,
                'completed_at' => now(),
                'created_at' => now(),
            ]);
            $resolved = $this->transitionAdjustment($adjustment, 'succeeded', 'provider');
            $this->auditAdjustment($payment, $resolved, 'payment.refund_succeeded');
            $this->outbox->enqueue(
                'payment.lifecycle',
                'payment_adjustment',
                $resolved->public_id,
                'payment.refund.succeeded',
                [
                    'payment_public_id' => $payment->public_id,
                    'adjustment_public_id' => $resolved->public_id,
                ],
                'payment.refund.succeeded:'.$resolved->public_id
            );

            return $resolved;
        });
    }

    public function processChargeback(int $providerEventId): object
    {
        return $this->transactions->run(function () use ($providerEventId): object {
            $event = DB::table('payment_provider_events')->where('id', $providerEventId)
                ->lockForUpdate()->firstOrFail();
            if ($event->signature_verified_at === null || $event->payment_id === null) {
                throw new V2PaymentException('VERIFIED_SERVER_EVENT_REQUIRED');
            }
            $payment = DB::table('payments')->where('id', $event->payment_id)
                ->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'succeeded') {
                throw new V2PaymentException('CHARGEBACK_PAYMENT_NOT_SUCCEEDED');
            }
            $existing = DB::table('payment_adjustments')
                ->where('source_provider_event_id', $event->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $adjustment = $this->createAdjustment(
                $payment,
                'chargeback',
                'processing',
                null,
                $event->id,
                null
            );
            $this->adjustmentHistory(
                $adjustment->id,
                null,
                'processing',
                'provider_event',
                $event->id
            );
            $wallet = $this->lockWallet($payment->user_id);
            $operation = $this->newPointOperation(
                $payment,
                'chargeback_reversal',
                'payment_adjustment',
                'payment.chargeback:'.$adjustment->id
            );
            $originLots = $this->paymentLots($payment->id)->keyBy('id');
            $allPaid = DB::table('point_lots')->where('user_id', $payment->user_id)
                ->where('point_type', 'paid')->whereColumn('remaining_amount', '>', 'reserved_amount')
                ->orderBy('granted_at')->orderBy('id')->lockForUpdate()->get();
            $allFree = DB::table('point_lots')->where('user_id', $payment->user_id)
                ->where('point_type', 'free')->whereColumn('remaining_amount', '>', 'reserved_amount')
                ->orderBy('expire_at')->orderBy('granted_at')->orderBy('id')->lockForUpdate()->get();
            $sequence = 1;
            $paidNeed = (int) $payment->paid_point_amount;
            $freeNeed = (int) $payment->free_point_amount;
            $runningPaidBalance = (int) $wallet->paid_balance;
            $runningFreeBalance = (int) $wallet->free_balance;
            $paidFromPaid = $this->consumeChargebackLots(
                $operation, $wallet, $this->orderedOriginFirst($allPaid, $originLots),
                $paidNeed, $sequence, $runningPaidBalance, $runningFreeBalance
            );
            $freeFromFree = $this->consumeChargebackLots(
                $operation, $wallet, $this->orderedOriginFirst($allFree, $originLots),
                $freeNeed, $sequence, $runningPaidBalance, $runningFreeBalance
            );
            $paidShortageFromFree = $this->consumeChargebackLots(
                $operation, $wallet, $allFree, $paidNeed, $sequence,
                $runningPaidBalance, $runningFreeBalance
            );
            $paidDebited = $paidFromPaid;
            $freeDebited = $freeFromFree + $paidShortageFromFree;
            $this->updateWalletAfterDebit($wallet, $paidDebited, $freeDebited, 0, 0);
            DB::table('payment_adjustment_point_operations')->insert([
                'payment_adjustment_id' => $adjustment->id,
                'point_operation_id' => $operation->id,
                'role' => 'reversal',
                'created_at' => now(),
            ]);
            DB::table('payment_adjustment_point_impacts')->insert([
                'payment_adjustment_id' => $adjustment->id,
                'required_paid_amount' => $payment->paid_point_amount,
                'required_free_amount' => $payment->free_point_amount,
                'reversed_paid_from_paid' => $paidFromPaid,
                'reversed_free_from_free' => $freeFromFree,
                'reversed_paid_shortage_from_free' => $paidShortageFromFree,
                'shortfall_paid_amount' => $paidNeed,
                'shortfall_free_amount' => $freeNeed,
                'completed_at' => now(),
                'created_at' => now(),
            ]);
            $resolved = $this->transitionAdjustment($adjustment, 'succeeded', 'provider_event');
            $this->providerAttempt($event->id, 'success');
            $this->auditAdjustment($payment, $resolved, 'payment.chargeback_processed');
            $this->outbox->enqueue(
                'payment.lifecycle',
                'payment_adjustment',
                $resolved->public_id,
                'payment.chargeback.processed',
                [
                    'payment_public_id' => $payment->public_id,
                    'adjustment_public_id' => $resolved->public_id,
                ],
                'payment.chargeback.processed:'.$resolved->public_id
            );

            return $resolved;
        });
    }

    public function recordChargebackReversal(int $providerEventId, int $parentAdjustmentId): object
    {
        return $this->transactions->run(function () use ($providerEventId, $parentAdjustmentId): object {
            $event = DB::table('payment_provider_events')->where('id', $providerEventId)
                ->lockForUpdate()->firstOrFail();
            if ($event->signature_verified_at === null) {
                throw new V2PaymentException('VERIFIED_SERVER_EVENT_REQUIRED');
            }
            $parent = DB::table('payment_adjustments')->where('id', $parentAdjustmentId)
                ->where('type', 'chargeback')->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $parent->payment_id)
                ->lockForUpdate()->firstOrFail();
            $existing = DB::table('payment_adjustments')
                ->where('source_provider_event_id', $event->id)->first();
            if ($existing !== null) {
                if (
                    $existing->type !== 'chargeback_reversal'
                    || (int) $existing->parent_adjustment_id !== $parent->id
                ) {
                    throw new V2PaymentException('PROVIDER_EVENT_ID_REUSED');
                }

                return $existing;
            }
            $adjustment = $this->createAdjustment(
                $payment,
                'chargeback_reversal',
                'manual_review',
                null,
                $event->id,
                $parent->id
            );
            $this->adjustmentHistory(
                $adjustment->id,
                null,
                'manual_review',
                'provider_event',
                $event->id
            );
            $this->auditAdjustment($payment, $adjustment, 'payment.chargeback_reversal_review');

            return $adjustment;
        });
    }

    private function grantPaymentPoints(object $payment): object
    {
        $wallet = $this->lockWallet($payment->user_id);
        $operation = $this->newPointOperation(
            $payment,
            'payment_grant',
            'payment',
            'payment.grant:'.$payment->id
        );
        $sequence = 1;
        $paid = (int) $payment->paid_point_amount;
        $free = (int) $payment->free_point_amount;
        if ($paid > 0) {
            $this->grantLot($operation, $wallet, 'paid', $paid, null, $sequence++);
        }
        if ($free > 0) {
            $expiryDays = filter_var(
                config('v2_payment.purchase_bonus_expiry_days'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($expiryDays === false) {
                throw new V2PaymentException('PAYMENT_BONUS_EXPIRY_NOT_CONFIGURED');
            }
            $this->grantLot(
                $operation,
                $wallet,
                'free',
                $free,
                now()->addDays($expiryDays)->startOfSecond(),
                $sequence++
            );
        }
        DB::table('wallets')->where('id', $wallet->id)->update([
            'paid_balance' => (int) $wallet->paid_balance + $paid,
            'free_balance' => (int) $wallet->free_balance + $free,
            'lock_version' => (int) $wallet->lock_version + 1,
            'updated_at' => now(),
        ]);
        DB::table('payment_point_grants')->insert([
            'payment_id' => $payment->id,
            'point_operation_id' => $operation->id,
            'granted_at' => now()->startOfSecond(),
        ]);

        return (object) ['operation_public_id' => $operation->public_id];
    }

    private function grantLot(
        object $operation,
        object $wallet,
        string $type,
        int $amount,
        ?\DateTimeInterface $expiry,
        int $sequence
    ): void {
        $lotId = DB::table('point_lots')->insertGetId([
            'user_id' => $operation->user_id,
            'grant_operation_id' => $operation->id,
            'point_type' => $type,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'reserved_amount' => 0,
            'granted_at' => $operation->occurred_at,
            'expire_at' => $expiry,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = (int) $wallet->{$type.'_balance'};
        DB::table('point_ledger_entries')->insert([
            'point_operation_id' => $operation->id,
            'sequence_no' => $sequence,
            'user_id' => $operation->user_id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => $lotId,
            'point_type' => $type,
            'entry_type' => 'grant',
            'amount_delta' => $amount,
            'wallet_balance_after' => $before + $amount,
            'lot_remaining_after' => $amount,
            'occurred_at' => $operation->occurred_at,
            'business_date' => $operation->business_date,
            'created_at' => now(),
        ]);
    }

    private function consumeChargebackLots(
        object $operation,
        object $wallet,
        Collection $lots,
        int &$remaining,
        int &$sequence,
        int &$paidBalance,
        int &$freeBalance
    ): int {
        $consumed = 0;
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $fresh = DB::table('point_lots')->where('id', $lot->id)->firstOrFail();
            $available = (int) $fresh->remaining_amount - (int) $fresh->reserved_amount;
            $amount = min($remaining, max(0, $available));
            if ($amount === 0) {
                continue;
            }
            $this->debitLot(
                $operation,
                $wallet,
                $fresh,
                $amount,
                $sequence++,
                $paidBalance,
                $freeBalance
            );
            $remaining -= $amount;
            $consumed += $amount;
        }

        return $consumed;
    }

    private function orderedOriginFirst(Collection $lots, Collection $originLots): Collection
    {
        return $lots->filter(fn (object $lot): bool => $originLots->has($lot->id))
            ->concat($lots->reject(fn (object $lot): bool => $originLots->has($lot->id)))
            ->values();
    }

    private function debitLot(
        object $operation,
        object $wallet,
        object $lot,
        int $amount,
        int $sequence,
        int &$paidBalance,
        int &$freeBalance
    ): void {
        $remaining = (int) $lot->remaining_amount - $amount;
        if ($remaining < 0) {
            throw new V2PaymentException('POINT_LOT_NEGATIVE');
        }
        DB::table('point_lots')->where('id', $lot->id)->update([
            'remaining_amount' => $remaining,
            'updated_at' => now(),
        ]);
        if ($lot->point_type === 'paid') {
            $paidBalance -= $amount;
            $balanceAfter = $paidBalance;
        } else {
            $freeBalance -= $amount;
            $balanceAfter = $freeBalance;
        }
        if ($balanceAfter < 0) {
            throw new V2PaymentException('POINT_WALLET_NEGATIVE');
        }
        DB::table('point_ledger_entries')->insert([
            'point_operation_id' => $operation->id,
            'sequence_no' => $sequence,
            'user_id' => $operation->user_id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => $lot->id,
            'point_type' => $lot->point_type,
            'entry_type' => 'reverse',
            'amount_delta' => -$amount,
            'wallet_balance_after' => $balanceAfter,
            'lot_remaining_after' => $remaining,
            'occurred_at' => $operation->occurred_at,
            'business_date' => $operation->business_date,
            'created_at' => now(),
        ]);
    }

    private function updateWalletAfterDebit(
        object $wallet,
        int $paid,
        int $free,
        int $paidReserved,
        int $freeReserved
    ): void {
        $paidBalance = (int) $wallet->paid_balance - $paid;
        $freeBalance = (int) $wallet->free_balance - $free;
        $paidReservedBalance = (int) $wallet->paid_reserved_balance - $paidReserved;
        $freeReservedBalance = (int) $wallet->free_reserved_balance - $freeReserved;
        if (
            $paidBalance < 0 || $freeBalance < 0
            || $paidReservedBalance < 0 || $freeReservedBalance < 0
        ) {
            throw new V2PaymentException('POINT_WALLET_NEGATIVE');
        }
        DB::table('wallets')->where('id', $wallet->id)->update([
            'paid_balance' => $paidBalance,
            'free_balance' => $freeBalance,
            'paid_reserved_balance' => $paidReservedBalance,
            'free_reserved_balance' => $freeReservedBalance,
            'lock_version' => (int) $wallet->lock_version + 1,
            'updated_at' => now(),
        ]);
    }

    private function releaseReservations(object $wallet, Collection $reservations): void
    {
        $paid = 0;
        $free = 0;
        foreach ($reservations as $reservation) {
            $lot = DB::table('point_lots')->where('id', $reservation->point_lot_id)
                ->lockForUpdate()->firstOrFail();
            DB::table('point_lots')->where('id', $lot->id)->update([
                'reserved_amount' => (int) $lot->reserved_amount - (int) $reservation->amount,
                'updated_at' => now(),
            ]);
            DB::table('point_lot_reservations')->where('id', $reservation->id)->update([
                'status' => 'released',
                'released_at' => now()->startOfSecond(),
                'updated_at' => now(),
            ]);
            $lot->point_type === 'paid'
                ? $paid += (int) $reservation->amount
                : $free += (int) $reservation->amount;
        }
        DB::table('wallets')->where('id', $wallet->id)->update([
            'paid_reserved_balance' => (int) $wallet->paid_reserved_balance - $paid,
            'free_reserved_balance' => (int) $wallet->free_reserved_balance - $free,
            'lock_version' => (int) $wallet->lock_version + 1,
            'updated_at' => now(),
        ]);
    }

    private function paymentLots(int $paymentId): Collection
    {
        $grant = DB::table('payment_point_grants')->where('payment_id', $paymentId)->first();
        if ($grant === null) {
            return collect();
        }

        return DB::table('point_lots')->where('grant_operation_id', $grant->point_operation_id)
            ->orderBy('id')->lockForUpdate()->get();
    }

    private function lockWallet(int $userId): object
    {
        DB::table('wallets')->insertOrIgnore([
            'user_id' => $userId,
            'paid_balance' => 0,
            'free_balance' => 0,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    private function newPointOperation(
        object $payment,
        string $type,
        string $source,
        string $businessKey
    ): object {
        $now = now()->startOfSecond();
        $id = DB::table('point_operations')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $payment->user_id,
            'operation_type' => $type,
            'business_key' => $businessKey,
            'source_type' => $source,
            'source_id' => $payment->id,
            'actor_type' => 'system',
            'is_qa' => false,
            'occurred_at' => $now,
            'business_date' => CarbonImmutable::parse($now)
                ->setTimezone('Asia/Tokyo')->toDateString(),
            'metadata' => '{}',
            'created_at' => $now,
        ]);

        return DB::table('point_operations')->where('id', $id)->firstOrFail();
    }

    private function transitionPayment(
        object $payment,
        string $to,
        string $source,
        ?int $eventId
    ): object {
        $updates = ['status' => $to, 'updated_at' => now()];
        $updates[$to.'_at'] = now()->startOfSecond();
        DB::table('payments')->where('id', $payment->id)->update($updates);
        $this->paymentHistory($payment->id, $payment->status, $to, $source, $eventId);

        return DB::table('payments')->where('id', $payment->id)->firstOrFail();
    }

    private function paymentHistory(
        int $paymentId,
        ?string $from,
        string $to,
        string $source,
        ?int $eventId
    ): void {
        DB::table('payment_status_histories')->insert([
            'payment_id' => $paymentId,
            'from_status' => $from,
            'to_status' => $to,
            'transition_source' => $source,
            'provider_event_id' => $eventId,
            'actor_type' => $source === 'user' ? 'user' : 'system',
            'occurred_at' => now()->startOfSecond(),
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    private function createAdjustment(
        object $payment,
        string $type,
        string $status,
        ?int $adminId,
        ?int $eventId,
        ?int $parentId
    ): object {
        $id = DB::table('payment_adjustments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => $payment->id,
            'parent_adjustment_id' => $parentId,
            'type' => $type,
            'status' => $status,
            'amount' => $payment->amount,
            'currency' => 'JPY',
            'requested_by_admin_id' => $adminId,
            'source_provider_event_id' => $eventId,
            'requested_at' => now()->startOfSecond(),
            'manual_review_at' => $status === 'manual_review' ? now()->startOfSecond() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('payment_adjustments')->where('id', $id)->firstOrFail();
    }

    private function transitionAdjustment(
        object $adjustment,
        string $to,
        string $source
    ): object {
        $updates = ['status' => $to, 'updated_at' => now()];
        if ($to === 'succeeded') {
            $updates['succeeded_at'] = now()->startOfSecond();
        } elseif ($to === 'failed') {
            $updates['failed_at'] = now()->startOfSecond();
        }
        DB::table('payment_adjustments')->where('id', $adjustment->id)->update($updates);
        $this->adjustmentHistory($adjustment->id, $adjustment->status, $to, $source);

        return DB::table('payment_adjustments')->where('id', $adjustment->id)->firstOrFail();
    }

    private function adjustmentHistory(
        int $adjustmentId,
        ?string $from,
        string $to,
        string $source,
        ?int $eventId = null
    ): void {
        DB::table('payment_adjustment_status_histories')->insert([
            'payment_adjustment_id' => $adjustmentId,
            'from_status' => $from,
            'to_status' => $to,
            'transition_source' => $source,
            'provider_event_id' => $eventId,
            'actor_type' => $source === 'admin' ? 'admin' : 'system',
            'occurred_at' => now()->startOfSecond(),
            'created_at' => now(),
        ]);
    }

    private function providerAttempt(int $eventId, string $outcome): void
    {
        $attempt = DB::table('payment_provider_event_attempts')
            ->where('payment_provider_event_id', $eventId)->count() + 1;
        DB::table('payment_provider_event_attempts')->insert([
            'payment_provider_event_id' => $eventId,
            'attempt_no' => $attempt,
            'worker_id' => 'v2-payment-domain',
            'started_at' => now()->startOfSecond(),
            'completed_at' => now()->startOfSecond(),
            'outcome' => $outcome,
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    private function auditAdjustment(object $payment, object $adjustment, string $action): void
    {
        $this->audit->record($action, [
            'target_type' => 'payment_adjustment',
            'target_public_id' => $adjustment->public_id,
            'metadata' => [
                'payment_public_id' => $payment->public_id,
                'adjustment_type' => $adjustment->type,
                'adjustment_status' => $adjustment->status,
                'amount' => (int) $adjustment->amount,
                'currency' => $adjustment->currency,
            ],
        ]);
    }

    private function assertCode(string $value, int $max): void
    {
        if ($value === '' || strlen($value) > $max || ! preg_match('/\A[a-z0-9._-]+\z/', $value)) {
            throw new V2PaymentException('PAYMENT_CODE_INVALID');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertRedactedPayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (preg_match(
                '/password|secret|token|authorization|cookie|session|pan|cvv|cvc|pin|track/i',
                (string) $key
            )) {
                throw new V2PaymentException('SENSITIVE_PROVIDER_DATA_PROHIBITED');
            }
            if (is_array($value)) {
                $this->assertRedactedPayload($value);
            }
        }
    }
}
