<?php

namespace App\Domain\Payment\V2\Services;

use App\Support\V2DatabaseTimestamp;
use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Mail\Services\V2TemplateMailDeliveryService;
use App\Domain\MemberRank\Services\V2MemberRankPaymentAdjustmentService;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Point\Services\V2CoinExpiryPolicy;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
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
        private readonly V2PointPurchaseEligibilityService $purchaseEligibility,
        private readonly V2CoinExpiryPolicy $expiryPolicy,
        private readonly V2TemplateMailDeliveryService $templateMail,
        private readonly V2PaymentAdjustmentLots $adjustmentLots,
        private readonly V2MemberRankPaymentAdjustmentService $rankAdjustments
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
        string $idempotencyKey,
        ?string $paymentMethod = null,
        bool $requireSmsVerification = false
    ): object {
        $this->assertRuntimeSafe();
        $this->assertCode($providerCode, 64);
        $user = User::query()->findOrFail($userId);
        if ($requireSmsVerification) {
            app(\App\Domain\Identity\Services\V2SmsVerificationService::class)->assertVerified($user);
        }

        return $this->transactions->run(function () use (
            $user,
            $planId,
            $providerCode,
            $providerPaymentId,
            $idempotencyKey,
            $paymentMethod,
            $requireSmsVerification
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
                    'payment_method' => $paymentMethod,
                ]
            );
            if ($claim->replay) {
                return DB::table('payments')
                    ->where('public_id', $claim->record->resource_public_id)
                    ->firstOrFail();
            }
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            app(\App\Domain\MemberRank\Services\V2MemberRankResolver::class)->materialize((int) $user->id);
            if ($requireSmsVerification) {
                app(\App\Domain\Identity\Services\V2SmsVerificationService::class)->assertVerified($user);
            }
            if ($paymentMethod === 'konbini' && DB::table('payments')
                ->where('user_id', $user->id)
                ->where('payment_method', 'konbini')
                ->whereIn('status', ['created', 'requires_action', 'processing'])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', V2DatabaseTimestamp::format(now())))
                ->exists()) {
                throw new V2PaymentException('KONBINI_UNPAID_LIMIT_REACHED');
            }
            $plan = DB::table('point_purchase_plans')
                ->where('id', $planId)
                ->where('status', 'published')
                ->where(fn ($query) => $query->whereNull('available_from')
                    ->orWhere('available_from', '<=', V2DatabaseTimestamp::format(now())))
                ->where(fn ($query) => $query->whereNull('available_until')
                    ->orWhere('available_until', '>', V2DatabaseTimestamp::format(now())))
                ->lockForUpdate()
                ->first();
            if ($plan === null) {
                throw new V2PaymentException('PURCHASE_PLAN_NOT_AVAILABLE');
            }
            $campaigns = DB::table('point_purchase_plan_limited_bonus_campaigns')
                ->where('point_purchase_plan_id', $plan->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $this->purchaseEligibility->assertEligible($user, $plan, $now);
            $publicId = (string) Str::uuid7();
            $paymentId = DB::table('payments')->insertGetId([
                'public_id' => $publicId,
                'user_id' => $user->id,
                'point_purchase_plan_id' => $plan->id,
                'provider_code' => $providerCode,
                'payment_method' => $paymentMethod,
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
                'created_at' => V2DatabaseTimestamp::format($now),
                'updated_at' => V2DatabaseTimestamp::format($now),
            ]);
            foreach ($campaigns as $campaign) {
                DB::table('payment_limited_bonus_snapshots')->insert([
                    'payment_id' => $paymentId,
                    'campaign_public_id' => $campaign->public_id,
                    'is_enabled' => $campaign->is_enabled,
                    'starts_at' => $campaign->starts_at,
                    'ends_at' => $campaign->ends_at,
                    'bonus_point_amount' => $campaign->bonus_point_amount,
                    'snapshotted_at' => V2DatabaseTimestamp::format($now),
                ]);
            }
            $this->paymentHistory($paymentId, null, 'created', 'user', null);
            $this->idempotency->complete($claim->record, 'payment', $publicId);

            return DB::table('payments')->where('id', $paymentId)->firstOrFail();
        });
    }

    public function applyProviderStartResult(
        int $paymentId,
        string $providerStatus,
        string $platformStatus,
        ?\DateTimeInterface $expiresAt = null
    ): object {
        if (! in_array($platformStatus, ['requires_action', 'processing', 'failed'], true)) {
            throw new V2PaymentException('PAYMENT_STATUS_INVALID');
        }

        return $this->transactions->run(function () use (
            $paymentId,
            $providerStatus,
            $platformStatus,
            $expiresAt
        ): object {
            $payment = DB::table('payments')->where('id', $paymentId)
                ->lockForUpdate()->firstOrFail();
            if ($payment->provider_code !== 'fincode') {
                throw new V2PaymentException('PAYMENT_PROVIDER_INVALID');
            }
            DB::table('payments')->where('id', $payment->id)->update([
                'provider_status' => $providerStatus,
                'expires_at' => $expiresAt === null
                    ? $payment->expires_at
                    : CarbonImmutable::parse($expiresAt->format('Y-m-d H:i:s.uP'))
                        ->utc()->startOfSecond()->toIso8601String(),
                'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            if ($payment->status === $platformStatus) {
                return DB::table('payments')->where('id', $payment->id)->firstOrFail();
            }
            if (! in_array($platformStatus, self::PAYMENT_TRANSITIONS[$payment->status] ?? [], true)) {
                throw new V2PaymentException('PAYMENT_STATUS_TRANSITION_INVALID');
            }

            return $this->transitionPayment($payment, $platformStatus, 'provider_api', null);
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
        if ($eventType === 'payment.succeeded' && $providerOccurredAt === null) {
            throw new V2PaymentException('PROVIDER_OCCURRED_AT_REQUIRED');
        }
        foreach (array_keys($headers) as $key) {
            if (preg_match('/authorization|cookie|secret|token|signature/i', $key)) {
                unset($headers[$key]);
            }
        }
        return $this->transactions->run(function () use ($providerCode, $externalEventId, $eventType, $rawPayload, $headers, $paymentId, $adjustmentId, $providerOccurredAt): object {
            $payment = $paymentId === null ? null : DB::table('payments')->where('id', $paymentId)->lockForUpdate()->firstOrFail();
            $ignored = $eventType === 'payment.canceled' && $payment?->status === 'succeeded';
            DB::table('payment_provider_events')->insertOrIgnore([
                    'provider_code' => $providerCode,
                    'external_event_id' => $externalEventId,
                    'event_type' => $eventType,
                    'payment_id' => $paymentId,
                    'payment_adjustment_id' => $adjustmentId,
                    'signature_verified_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
                    'provider_occurred_at' => $providerOccurredAt === null
                        ? null
                        : CarbonImmutable::parse($providerOccurredAt->format('Y-m-d H:i:s.uP'))
                            ->utc()->startOfSecond()->toIso8601String(),
                    'received_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
                    'payload_hash' => hash('sha256', $rawPayload),
                    'payload_ciphertext' => Crypt::encryptString($rawPayload),
                    'headers_redacted' => json_encode((object) $headers, JSON_THROW_ON_ERROR),
                    'processing_status' => $ignored ? 'ignored' : 'received',
                    'created_at' => V2DatabaseTimestamp::format(now()),
                    'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);

            $event = DB::table('payment_provider_events')
                ->where('provider_code', $providerCode)
                ->where('external_event_id', $externalEventId)
                ->firstOrFail();
            if (
                $event->event_type !== $eventType
                || (int) ($event->payment_id ?? 0) !== (int) ($paymentId ?? 0)
                || (int) ($event->payment_adjustment_id ?? 0) !== (int) ($adjustmentId ?? 0)
                || ! hash_equals($event->payload_hash, hash('sha256', $rawPayload))
                || ! $this->sameInstant($event->provider_occurred_at, $providerOccurredAt)
            ) {
                throw new V2PaymentException('PROVIDER_EVENT_ID_REUSED');
            }

            return $event;
        });
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
        if (DB::table('payment_adjustments')->where('id', $adjustmentId)->where('reason_code', 'admin_manual')->exists()) {
            throw new V2PaymentException('MANUAL_ADJUSTMENT_REQUIRED');
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
            'created_at' => V2DatabaseTimestamp::format(now()),
            'updated_at' => V2DatabaseTimestamp::format(now()),
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
            'completed_at' => $result === 'uncertain' ? null : V2DatabaseTimestamp::format(now()->startOfSecond()),
            'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);

        return DB::table('payment_provider_operations')
            ->where('id', $operationId)->firstOrFail();
    }

    public function applyVerifiedStatus(
        int $providerEventId,
        string $status,
        ?string $providerStatus = null
    ): object
    {
        if ($status === 'succeeded') {
            $this->confirmSucceeded($providerEventId, $providerStatus);

            return DB::table('payments')
                ->where('id', DB::table('payment_provider_events')
                    ->where('id', $providerEventId)->value('payment_id'))
                ->firstOrFail();
        }
        if (! in_array($status, ['requires_action', 'processing', 'failed', 'canceled', 'expired'], true)) {
            throw new V2PaymentException('PAYMENT_STATUS_INVALID');
        }

        return $this->transactions->run(function () use (
            $providerEventId,
            $status,
            $providerStatus
        ): object {
            $event = DB::table('payment_provider_events')
                ->where('id', $providerEventId)->lockForUpdate()->firstOrFail();
            if ($event->signature_verified_at === null || $event->payment_id === null) {
                throw new V2PaymentException('VERIFIED_SERVER_EVENT_REQUIRED');
            }
            $payment = DB::table('payments')
                ->where('id', $event->payment_id)->lockForUpdate()->firstOrFail();
            if ($providerStatus !== null) {
                DB::table('payments')->where('id', $payment->id)->update([
                    'provider_status' => $providerStatus,
                    'provider_confirmed_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
                    'updated_at' => V2DatabaseTimestamp::format(now()),
                ]);
            }
            if ($payment->status === $status) {
                return DB::table('payments')->where('id', $payment->id)->firstOrFail();
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

    public function confirmSucceeded(
        int $providerEventId,
        ?string $providerStatus = null
    ): object
    {
        return $this->transactions->run(function () use (
            $providerEventId,
            $providerStatus
        ): object {
            $event = DB::table('payment_provider_events')
                ->where('id', $providerEventId)->lockForUpdate()->firstOrFail();
            if (
                $event->event_type !== 'payment.succeeded'
                || $event->signature_verified_at === null
                || $event->payment_id === null
            ) {
                throw new V2PaymentException('VERIFIED_SERVER_EVENT_REQUIRED');
            }
            if ($event->provider_occurred_at === null) {
                throw new V2PaymentException('PROVIDER_OCCURRED_AT_REQUIRED');
            }
            $succeededAt = CarbonImmutable::parse($event->provider_occurred_at);
            $paymentView = DB::table('payments')->where('id', $event->payment_id)->firstOrFail();
            DB::table('users')->where('id', $paymentView->user_id)->lockForUpdate()->firstOrFail();
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
            $memberRanks = app(\App\Domain\MemberRank\Services\V2MemberRankAccountingService::class);
            $memberRanks->lockUserIfEnabled((int) $payment->user_id);
            $plan = DB::table('point_purchase_plans')
                ->where('id', $payment->point_purchase_plan_id)
                ->lockForUpdate()
                ->firstOrFail();
            $user = User::query()->findOrFail($payment->user_id);
            $this->purchaseEligibility->assertSettlementEligible($user, $plan);
            $limitedBonus = $this->limitedBonusAt((int) $payment->id, $succeededAt);
            $succeededPayment = $this->transitionPayment(
                $payment,
                'succeeded',
                'provider_event',
                $event->id,
                $succeededAt,
                $limitedBonus
            );
            DB::table('payments')->where('id', $payment->id)->update([
                'provider_status' => $providerStatus ?? $payment->provider_status,
                'provider_confirmed_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
                'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            $grant = $this->grantPaymentPoints($succeededPayment);
            DB::table('payments')->where('id', $payment->id)->update([
                'points_granted_at' => V2DatabaseTimestamp::format($grant->granted_at),
                'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            app(\App\Domain\MemberRank\Services\V2MemberRankPurchaseBonusService::class)
                ->grantForPayment((int) $payment->id);
            $memberRanks->accountGrantedPayment((int) $payment->id);
            $this->providerAttempt($event->id, 'success');
            $this->audit->record('payment.succeeded', [
                'target_type' => 'payment',
                'target_public_id' => $payment->public_id,
                'metadata' => [
                    'provider_code' => $payment->provider_code,
                    'amount' => (int) $payment->amount,
                    'currency' => $payment->currency,
                    'limited_bonus_point_amount' => $limitedBonus,
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
            $this->templateMail->schedule(
                'coin_purchase_completed',
                'coin.purchase.completed:'.$payment->public_id,
                'payment',
                $payment->public_id
            );

            return DB::table('payment_point_grants')
                ->where('payment_id', $payment->id)->firstOrFail();
        });
    }

    public function reserveFullRefund(
        int $paymentId,
        string $idempotencyKey,
        ?int $requestedByAdminId = null,
        ?string $manualReason = null
    ): object {
        if ($manualReason !== null && ($requestedByAdminId === null || DB::transactionLevel() === 0)) {
            throw new V2PaymentException('MANUAL_ADJUSTMENT_REQUIRED');
        }
        $paymentView = DB::table('payments')->where('id', $paymentId)->firstOrFail();
        $user = User::query()->findOrFail($paymentView->user_id);

        return $this->transactions->run(function () use (
            $paymentId,
            $idempotencyKey,
            $requestedByAdminId,
            $manualReason,
            $user
        ): object {
            $claim = $manualReason !== null ? null : $this->idempotency->claim(
                'payment.refund',
                $requestedByAdminId === null ? 'system' : 'admin',
                $requestedByAdminId === null ? $user->public_id : DB::table('admins')->where('id', $requestedByAdminId)->value('public_id'),
                $idempotencyKey,
                ['payment_id' => $paymentId, 'mode' => 'full']
            );
            if ($claim?->replay) {
                return DB::table('payment_adjustments')
                    ->where('public_id', $claim->record->resource_public_id)->firstOrFail();
            }
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $paymentId)
                ->lockForUpdate()->firstOrFail();
            $this->assertAdjustmentAvailable($payment);
            $this->rankAdjustments->lock($payment);
            $wallet = $this->lockWallet($payment->user_id);
            $lots = $this->assertRefundEligible($payment, true);
            $reservedAt = now()->startOfSecond();
            $adjustment = $this->createAdjustment(
                $payment,
                'refund',
                'requested',
                $requestedByAdminId,
                null,
                null,
                $manualReason
            );
            $this->adjustmentHistory($adjustment->id, null, 'requested', 'admin', null, $requestedByAdminId);
            $paidReserved = 0;
            $freeReserved = 0;
            foreach ($lots as $lot) {
                DB::table('point_lot_reservations')->insert([
                    'point_lot_id' => $lot->id,
                    'payment_adjustment_id' => $adjustment->id,
                    'amount' => $lot->granted_amount,
                    'status' => 'active',
                    'reserved_at' => V2DatabaseTimestamp::format($reservedAt),
                    'created_at' => V2DatabaseTimestamp::format(now()),
                    'updated_at' => V2DatabaseTimestamp::format(now()),
                ]);
                DB::table('point_lots')->where('id', $lot->id)->update([
                    'reserved_amount' => $lot->granted_amount,
                    'updated_at' => V2DatabaseTimestamp::format(now()),
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
                'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            $adjustment = $this->transitionAdjustment($adjustment, 'points_reserved', $manualReason === null ? 'system' : 'admin', $requestedByAdminId);
            if ($manualReason === null) {
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
            }
            if ($claim !== null) {
                $this->idempotency->complete(
                $claim->record,
                'payment_adjustment',
                $adjustment->public_id
                );
            }
            $this->auditAdjustment($payment, $adjustment, 'payment.refund_reserved', $requestedByAdminId, $manualReason);

            return DB::table('payment_adjustments')->where('id', $adjustment->id)->firstOrFail();
        });
    }

    public function resolveRefund(int $adjustmentId, string $result, ?int $adminId = null, ?string $reason = null): object
    {
        if (! in_array($result, ['succeeded', 'failed', 'uncertain', 'canceled'], true)
            || ($result === 'canceled' && $adminId === null)) {
            throw new V2PaymentException('REFUND_RESULT_INVALID');
        }

        $view = DB::table('payment_adjustments')->where('id', $adjustmentId)->where('type', 'refund')->firstOrFail();
        $paymentView = DB::table('payments')->where('id', $view->payment_id)->firstOrFail();

        return $this->transactions->run(function () use ($adjustmentId, $result, $adminId, $reason, $paymentView): object {
            DB::table('users')->where('id', $paymentView->user_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $paymentView->id)->lockForUpdate()->firstOrFail();
            $adjustment = DB::table('payment_adjustments')->where('id', $adjustmentId)
                ->where('type', 'refund')->lockForUpdate()->firstOrFail();
            $source = $adminId === null ? 'provider' : 'admin';
            if ($adminId === null && $adjustment->reason_code === 'admin_manual') {
                throw new V2PaymentException('MANUAL_ADJUSTMENT_REQUIRED');
            }
            if (in_array($adjustment->status, ['succeeded', 'failed', 'canceled'], true)) {
                $expected = $result === 'uncertain' ? null : $result;
                if ($expected !== $adjustment->status) {
                    throw new V2PaymentException('REFUND_TERMINAL_STATE');
                }

                return $adjustment;
            }
            if ($result === 'succeeded') {
                $this->assertAdjustmentAvailable($payment, (int) $adjustment->id);
                $this->assertRankAdjustmentReady($payment);
                $this->rankAdjustments->lock($payment);
            }
            $wallet = $this->lockWallet($payment->user_id);
            $related = $this->adjustmentLots->inspect($payment, true);
            $reservations = DB::table('point_lot_reservations')
                ->where('payment_adjustment_id', $adjustment->id)
                ->where('status', 'active')->orderBy('point_lot_id')->lockForUpdate()->get();
            $instant = CarbonImmutable::now('UTC')->startOfSecond();
            if ($result === 'uncertain') {
                return $this->transitionAdjustment($adjustment, 'processing', 'provider');
            }
            if (in_array($result, ['failed', 'canceled'], true)) {
                $this->releaseReservations($wallet, $reservations);
                $failed = $this->transitionAdjustment($adjustment, $result, $source, $adminId);
                $this->auditAdjustment($payment, $failed, 'payment.refund_'.$result, $adminId, $reason);

                return $failed;
            }

            if ($reservations->isEmpty()) {
                throw new V2PaymentException('REFUND_RESERVATION_NOT_FOUND');
            }
            if ($reservations->count() !== $related['lots']->count()
                || $reservations->contains(function (object $reservation) use ($related): bool {
                    $lot = $related['lots']->firstWhere('id', $reservation->point_lot_id);

                    return $lot === null || (int) $reservation->amount !== (int) $lot->granted_amount;
                })) {
                throw new V2PaymentException('PAYMENT_RANK_ADJUSTMENT_NOT_READY');
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
                    'updated_at' => V2DatabaseTimestamp::format(now()),
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
                    'consumed_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
                    'updated_at' => V2DatabaseTimestamp::format(now()),
                ]);
                $lot->point_type === 'paid' ? $paid += $amount : $free += $amount;
            }
            $this->updateWalletAfterDebit($wallet, $paid, $free, $paid, $free);
            DB::table('payment_adjustment_point_operations')->insert([
                'payment_adjustment_id' => $adjustment->id,
                'point_operation_id' => $operation->id,
                'role' => 'reserve_consume',
                'created_at' => V2DatabaseTimestamp::format(now()),
            ]);
            DB::table('payment_adjustment_point_impacts')->insert([
                'payment_adjustment_id' => $adjustment->id,
                'required_paid_amount' => $payment->paid_point_amount,
                'required_free_amount' => $related['free'],
                'reversed_paid_from_paid' => $paid,
                'reversed_free_from_free' => $free,
                'reversed_paid_shortage_from_free' => 0,
                'shortfall_paid_amount' => 0,
                'shortfall_free_amount' => 0,
                'completed_at' => V2DatabaseTimestamp::format($instant),
                'created_at' => V2DatabaseTimestamp::format(now()),
            ]);
            $this->rankAdjustments->apply($payment, $adjustment, $this->adjustmentLots->bonusImpact($related, $free), $instant, $adminId);
            $resolved = $this->transitionAdjustment($adjustment, 'succeeded', $source, $adminId, $instant);
            $this->auditAdjustment($payment, $resolved, 'payment.refund_succeeded', $adminId, $reason);
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
            $event = DB::table('payment_provider_events')->where('id', $providerEventId)->lockForUpdate()->firstOrFail();
            if ($event->signature_verified_at === null || $event->payment_id === null || $event->event_type !== 'payment.chargeback') {
                throw new V2PaymentException('VERIFIED_SERVER_EVENT_REQUIRED');
            }
            $view = DB::table('payments')->where('id', $event->payment_id)->firstOrFail();
            DB::table('users')->where('id', $view->user_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $view->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('payment_adjustments')->where('source_provider_event_id', $event->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $resolved = $this->executeChargeback($payment, (int) $event->id);
            $this->providerAttempt($event->id, 'success');

            return $resolved;
        });
    }

    public function recordManualChargeback(int $paymentId, int $adminId, string $reason): object
    {
        return $this->transactions->run(function () use ($paymentId, $adminId, $reason): object {
            $view = DB::table('payments')->where('id', $paymentId)->firstOrFail();
            DB::table('users')->where('id', $view->user_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->firstOrFail();

            return $this->executeChargeback($payment, null, $adminId, $reason);
        });
    }

    private function executeChargeback(object $payment, ?int $eventId, ?int $adminId = null, ?string $reason = null): object
    {
        $this->assertAdjustmentAvailable($payment);
        $this->assertRankAdjustmentReady($payment);
        $this->rankAdjustments->lock($payment);
        $source = $adminId === null ? 'provider_event' : 'admin';
        $adjustment = $this->createAdjustment($payment, 'chargeback', 'processing', $adminId, $eventId, null, $reason);
        $this->adjustmentHistory($adjustment->id, null, 'processing', $source, $eventId, $adminId);
        $wallet = $this->lockWallet($payment->user_id);
        DB::table('point_lots')->where('user_id', $payment->user_id)->orderBy('id')->lockForUpdate()->get();
        $instant = CarbonImmutable::now('UTC')->startOfSecond();
        $operation = $this->newPointOperation(
            $payment,
            'chargeback_reversal',
            'payment_adjustment',
            'payment.chargeback:'.$adjustment->id
        );
        $plan = $this->chargebackPlan($payment, $instant);
        $sequence = 1;
        $runningPaidBalance = (int) $wallet->paid_balance;
        $runningFreeBalance = (int) $wallet->free_balance;
        foreach ($plan['debits'] as $debit) {
            $this->debitLot($operation, $wallet, $debit['lot'], $debit['amount'],
                $sequence++, $runningPaidBalance, $runningFreeBalance);
        }
        $paidFromPaid = $plan['impact']['reversed_paid_from_paid'];
        $freeFromFree = $plan['impact']['reversed_free_from_free'];
        $paidShortageFromFree = $plan['impact']['reversed_paid_shortage_from_free'];
        $paidNeed = $plan['impact']['shortfall_paid_amount'];
        $freeNeed = $plan['impact']['shortfall_free_amount'];
        $paidDebited = $paidFromPaid;
        $freeDebited = $freeFromFree + $paidShortageFromFree;
        $this->updateWalletAfterDebit($wallet, $paidDebited, $freeDebited, 0, 0);
        DB::table('payment_adjustment_point_operations')->insert([
            'payment_adjustment_id' => $adjustment->id,
            'point_operation_id' => $operation->id,
            'role' => 'reversal',
            'created_at' => V2DatabaseTimestamp::format(now()),
        ]);
        DB::table('payment_adjustment_point_impacts')->insert([
            'payment_adjustment_id' => $adjustment->id,
            'required_paid_amount' => $payment->paid_point_amount,
            'required_free_amount' => $plan['impact']['required_free_amount'],
            'reversed_paid_from_paid' => $paidFromPaid,
            'reversed_free_from_free' => $freeFromFree,
            'reversed_paid_shortage_from_free' => $paidShortageFromFree,
            'shortfall_paid_amount' => $paidNeed,
            'shortfall_free_amount' => $freeNeed,
            'completed_at' => V2DatabaseTimestamp::format($instant),
            'created_at' => V2DatabaseTimestamp::format(now()),
        ]);
        $this->rankAdjustments->apply($payment, $adjustment, $plan['impact']['purchase_bonus'], $instant, $adminId);
        $resolved = $this->transitionAdjustment($adjustment, 'succeeded', $source, $adminId, $instant);
        $this->auditAdjustment($payment, $resolved, 'payment.chargeback_processed', $adminId, $reason);
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
    }

    public function recordChargebackReversal(int $providerEventId, int $parentAdjustmentId): object
    {
        return $this->transactions->run(function () use ($providerEventId, $parentAdjustmentId): object {
            $event = DB::table('payment_provider_events')->where('id', $providerEventId)
                ->lockForUpdate()->firstOrFail();
            if ($event->signature_verified_at === null) {
                throw new V2PaymentException('VERIFIED_SERVER_EVENT_REQUIRED');
            }
            $parentView = DB::table('payment_adjustments')->where('id', $parentAdjustmentId)->firstOrFail();
            $paymentView = DB::table('payments')->where('id', $parentView->payment_id)->firstOrFail();
            DB::table('users')->where('id', $paymentView->user_id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $paymentView->id)->lockForUpdate()->firstOrFail();
            $parent = DB::table('payment_adjustments')->where('id', $parentAdjustmentId)
                ->where('type', 'chargeback')->where('status', 'succeeded')->lockForUpdate()->firstOrFail();
            if ((int) $event->payment_id !== (int) $payment->id || $event->event_type !== 'payment.chargeback_reversed') {
                throw new V2PaymentException('PROVIDER_EVENT_ID_REUSED');
            }
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
            $this->assertReversalAvailable($parent);
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

    public function recordManualReversal(object $payment, object $parent, int $adminId, string $reason): object
    {
        if (DB::transactionLevel() < 1 || (int) $parent->payment_id !== (int) $payment->id
            || $parent->type !== 'chargeback' || $parent->status !== 'succeeded') {
            throw new V2PaymentException('CHARGEBACK_REVERSAL_INVALID');
        }
        $this->assertReversalAvailable($parent);
        $adjustment = $this->createAdjustment($payment, 'chargeback_reversal', 'manual_review', $adminId, null, (int) $parent->id, $reason);
        $this->adjustmentHistory($adjustment->id, null, 'manual_review', 'admin', null, $adminId);
        $this->auditAdjustment($payment, $adjustment, 'payment.chargeback_reversal_review', $adminId, $reason);

        return $adjustment;
    }

    private function assertReversalAvailable(object $parent): void
    {
        if (DB::table('payment_adjustments')->where('parent_adjustment_id', $parent->id)->where('type', 'chargeback_reversal')->exists()) {
            throw new V2PaymentException('CHARGEBACK_REVERSAL_ALREADY_RECORDED');
        }
    }

    public function assertRefundEligible(object $payment, bool $lock = false): Collection
    {
        $this->assertAdjustmentAvailable($payment);
        $this->assertRankAdjustmentReady($payment);
        $lots = $this->paymentLots((int) $payment->id, $lock);
        if ($lots->isEmpty()) {
            throw new V2PaymentException('REFUND_POINT_GRANT_NOT_FOUND');
        }
        foreach ($lots as $lot) {
            if ((int) $lot->remaining_amount !== (int) $lot->granted_amount || (int) $lot->reserved_amount !== 0
                || ($lot->expire_at !== null && ! now()->lessThan(CarbonImmutable::parse($lot->expire_at)))) {
                throw new V2PaymentException('REFUND_POINTS_NOT_FULLY_UNUSED');
            }
        }

        return $lots;
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
        $grantedAt = CarbonImmutable::parse($operation->occurred_at)->startOfSecond();
        $expiresAt = $this->expiryPolicy->expiresAt($grantedAt);
        $paid = (int) $payment->paid_point_amount;
        $free = $this->paymentFreeGrantAmount($payment);
        if ($paid > 0) {
            $this->grantLot($operation, $wallet, 'paid', $paid, $expiresAt, $sequence++);
        }
        if ($free > 0) {
            $this->grantLot($operation, $wallet, 'free', $free, $expiresAt, $sequence++);
        }
        DB::table('wallets')->where('id', $wallet->id)->update([
            'paid_balance' => (int) $wallet->paid_balance + $paid,
            'free_balance' => (int) $wallet->free_balance + $free,
            'lock_version' => (int) $wallet->lock_version + 1,
            'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);
        DB::table('payment_point_grants')->insert([
            'payment_id' => $payment->id,
            'point_operation_id' => $operation->id,
            'granted_at' => V2DatabaseTimestamp::format($grantedAt),
        ]);

        return (object) [
            'operation_public_id' => $operation->public_id,
            'granted_at' => $grantedAt,
        ];
    }

    private function grantLot(
        object $operation,
        object $wallet,
        string $type,
        int $amount,
        \DateTimeInterface $expiry,
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
            'expire_at' => V2DatabaseTimestamp::format($expiry),
            'created_at' => V2DatabaseTimestamp::format(now()),
            'updated_at' => V2DatabaseTimestamp::format(now()),
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
            'created_at' => V2DatabaseTimestamp::format(now()),
        ]);
    }

    public function previewChargeback(object $payment): array
    {
        $this->assertAdjustmentAvailable($payment);
        $this->assertRankAdjustmentReady($payment);

        return $this->chargebackPlan($payment, CarbonImmutable::now('UTC')->startOfSecond())['impact'];
    }

    private function chargebackPlan(object $payment, CarbonImmutable $instant): array
    {
        $related = $this->adjustmentLots->inspect($payment);
        $originLots = $related['lots']->keyBy('id');
        $allLots = DB::table('point_lots')->where('user_id', $payment->user_id)
            ->whereColumn('remaining_amount', '>', 'reserved_amount')
            ->where(fn ($query) => $query->whereNull('expire_at')->orWhere('expire_at', '>', V2DatabaseTimestamp::format($instant)))
            ->orderByRaw('expire_at ASC NULLS LAST')->orderBy('granted_at')->orderBy('id')->get();
        $allPaid = $allLots->where('point_type', 'paid')->sortBy([['granted_at', 'asc'], ['id', 'asc']])->values();
        $allFree = $allLots->where('point_type', 'free')->values();
        $wallet = DB::table('wallets')->where('user_id', $payment->user_id)->firstOrFail();
        $available = ['paid' => max(0, (int) $wallet->paid_balance - (int) $wallet->paid_reserved_balance),
            'free' => max(0, (int) $wallet->free_balance - (int) $wallet->free_reserved_balance)];
        $paidNeed = (int) $payment->paid_point_amount;
        $freeNeed = $related['free'];
        $debits = [];
        $consume = function (Collection $lots, int &$need) use (&$available, &$debits): int {
            $consumed = 0;
            foreach ($lots as $lot) {
                $amount = min($need, max(0, (int) $lot->remaining_amount - (int) $lot->reserved_amount), $available[$lot->point_type]);
                if ($amount <= 0) continue;
                $debits[] = ['lot' => clone $lot, 'amount' => $amount];
                $lot->remaining_amount -= $amount;
                $available[$lot->point_type] -= $amount;
                $need -= $amount;
                $consumed += $amount;
            }

            return $consumed;
        };
        $paidFromPaid = $consume($this->orderedOriginFirst($allPaid, $originLots), $paidNeed);
        $freeFromFree = $consume($this->orderedOriginFirst($allFree, $originLots), $freeNeed);
        $paidFromFree = $consume($allFree, $paidNeed);

        return ['debits' => $debits, 'impact' => [
            'required_paid_amount' => (int) $payment->paid_point_amount,
            'required_free_amount' => $related['free'],
            'reversed_paid_from_paid' => $paidFromPaid, 'reversed_free_from_free' => $freeFromFree,
            'reversed_paid_shortage_from_free' => $paidFromFree,
            'shortfall_paid_amount' => $paidNeed, 'shortfall_free_amount' => $freeNeed,
            'purchase_bonus' => $this->adjustmentLots->bonusImpact($related, $freeFromFree),
            'rank_adjustment' => $this->rankAdjustments->preview($payment, $instant, $freeFromFree + $paidFromFree),
        ]];
    }

    public function assertAdjustmentAvailable(object $payment, ?int $excludeId = null): void
    {
        if ($payment->status !== 'succeeded') {
            throw new V2PaymentException('REFUND_PAYMENT_NOT_SUCCEEDED');
        }
        $query = DB::table('payment_adjustments')->where('payment_id', $payment->id)
            ->whereIn('type', ['refund', 'chargeback'])->whereNotIn('status', ['failed', 'canceled']);
        if ($excludeId !== null) $query->where('id', '<>', $excludeId);
        if ($query->exists()) {
            throw new V2PaymentException('PAYMENT_ADJUSTMENT_CONFLICT');
        }
    }

    public function assertRankAdjustmentReady(object $payment): void
    {
        if ($payment->status !== 'succeeded') {
            return;
        }
        $this->adjustmentLots->inspect($payment);
        $this->rankAdjustments->context($payment);
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
            'updated_at' => V2DatabaseTimestamp::format(now()),
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
            'created_at' => V2DatabaseTimestamp::format(now()),
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
            'updated_at' => V2DatabaseTimestamp::format(now()),
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
                'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            DB::table('point_lot_reservations')->where('id', $reservation->id)->update([
                'status' => 'released',
                'released_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
                'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            $lot->point_type === 'paid'
                ? $paid += (int) $reservation->amount
                : $free += (int) $reservation->amount;
        }
        DB::table('wallets')->where('id', $wallet->id)->update([
            'paid_reserved_balance' => (int) $wallet->paid_reserved_balance - $paid,
            'free_reserved_balance' => (int) $wallet->free_reserved_balance - $free,
            'lock_version' => (int) $wallet->lock_version + 1,
            'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);
    }

    private function paymentLots(int $paymentId, bool $lock = true): Collection
    {
        return $this->adjustmentLots->inspect(DB::table('payments')->where('id', $paymentId)->firstOrFail(), $lock)['lots'];
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
            'created_at' => V2DatabaseTimestamp::format(now()),
            'updated_at' => V2DatabaseTimestamp::format(now()),
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
            'occurred_at' => V2DatabaseTimestamp::format($now),
            'business_date' => CarbonImmutable::parse($now)
                ->setTimezone('Asia/Tokyo')->toDateString(),
            'metadata' => '{}',
            'created_at' => V2DatabaseTimestamp::format($now),
        ]);

        return DB::table('point_operations')->where('id', $id)->firstOrFail();
    }

    private function transitionPayment(
        object $payment,
        string $to,
        string $source,
        ?int $eventId,
        ?\DateTimeInterface $transitionedAt = null,
        ?int $limitedBonusPointAmount = null
    ): object {
        $updates = ['status' => $to, 'updated_at' => V2DatabaseTimestamp::format(now())];
        $occurredAt = $transitionedAt === null
            ? now()->startOfSecond()
            : CarbonImmutable::parse($transitionedAt->format('Y-m-d H:i:s.uP'));
        $updates[$to.'_at'] = $occurredAt->utc()->toIso8601String();
        if ($to === 'succeeded') {
            $updates['limited_bonus_point_amount'] = $limitedBonusPointAmount ?? 0;
        }
        DB::table('payments')->where('id', $payment->id)->update($updates);
        $this->paymentHistory(
            $payment->id,
            $payment->status,
            $to,
            $source,
            $eventId,
            $occurredAt
        );

        return DB::table('payments')->where('id', $payment->id)->firstOrFail();
    }

    private function paymentHistory(
        int $paymentId,
        ?string $from,
        string $to,
        string $source,
        ?int $eventId,
        ?\DateTimeInterface $occurredAt = null
    ): void {
        DB::table('payment_status_histories')->insert([
            'payment_id' => $paymentId,
            'from_status' => $from,
            'to_status' => $to,
            'transition_source' => $source,
            'provider_event_id' => $eventId,
            'actor_type' => $source === 'user' ? 'user' : 'system',
            'occurred_at' => $occurredAt === null
                ? V2DatabaseTimestamp::format(now()->startOfSecond())
                : CarbonImmutable::parse($occurredAt->format('Y-m-d H:i:s.uP'))
                    ->utc()->toIso8601String(),
            'request_id' => (string) Str::uuid(),
            'created_at' => V2DatabaseTimestamp::format(now()),
        ]);
    }

    private function createAdjustment(
        object $payment,
        string $type,
        string $status,
        ?int $adminId,
        ?int $eventId,
        ?int $parentId,
        ?string $reason = null
    ): object {
        $id = DB::table('payment_adjustments')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'payment_id' => $payment->id,
            'parent_adjustment_id' => $parentId,
            'type' => $type,
            'status' => $status,
            'amount' => $payment->amount,
            'currency' => 'JPY',
            'reason_code' => $reason === null ? null : 'admin_manual',
            'reason_text' => $reason,
            'requested_by_admin_id' => $adminId,
            'source_provider_event_id' => $eventId,
            'requested_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
            'manual_review_at' => $status === 'manual_review' ? V2DatabaseTimestamp::format(now()->startOfSecond()) : null,
            'created_at' => V2DatabaseTimestamp::format(now()),
            'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);

        return DB::table('payment_adjustments')->where('id', $id)->firstOrFail();
    }

    private function transitionAdjustment(
        object $adjustment,
        string $to,
        string $source,
        ?int $adminId = null,
        ?CarbonImmutable $instant = null
    ): object {
        $updates = ['status' => $to, 'updated_at' => V2DatabaseTimestamp::format(now())];
        if ($to === 'succeeded') {
            $updates['succeeded_at'] = V2DatabaseTimestamp::format($instant ?? now()->startOfSecond());
        } elseif ($to === 'failed') {
            $updates['failed_at'] = V2DatabaseTimestamp::format(now()->startOfSecond());
        }
        DB::table('payment_adjustments')->where('id', $adjustment->id)->update($updates);
        $this->adjustmentHistory($adjustment->id, $adjustment->status, $to, $source, null, $adminId);

        return DB::table('payment_adjustments')->where('id', $adjustment->id)->firstOrFail();
    }

    private function adjustmentHistory(
        int $adjustmentId,
        ?string $from,
        string $to,
        string $source,
        ?int $eventId = null,
        ?int $adminId = null
    ): void {
        DB::table('payment_adjustment_status_histories')->insert([
            'payment_adjustment_id' => $adjustmentId,
            'from_status' => $from,
            'to_status' => $to,
            'transition_source' => $source,
            'provider_event_id' => $eventId,
            'actor_id' => $adminId,
            'reason_code' => $adminId === null ? null : 'admin_manual',
            'actor_type' => $source === 'admin' ? 'admin' : 'system',
            'occurred_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
            'created_at' => V2DatabaseTimestamp::format(now()),
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
            'started_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
            'completed_at' => V2DatabaseTimestamp::format(now()->startOfSecond()),
            'outcome' => $outcome,
            'request_id' => (string) Str::uuid(),
            'created_at' => V2DatabaseTimestamp::format(now()),
        ]);
    }

    private function limitedBonusAt(int $paymentId, CarbonImmutable $succeededAt): int
    {
        $snapshots = DB::table('payment_limited_bonus_snapshots')
            ->where('payment_id', $paymentId)
            ->where('is_enabled', true)
            ->where('starts_at', '<=', $succeededAt->utc()->toIso8601String())
            ->where('ends_at', '>', $succeededAt->utc()->toIso8601String())
            ->orderBy('id')
            ->get();
        if ($snapshots->count() > 1) {
            throw new V2PaymentException('LIMITED_BONUS_SNAPSHOT_STACK_INVALID');
        }

        return $snapshots->isEmpty() ? 0 : (int) $snapshots->first()->bonus_point_amount;
    }

    private function paymentFreeGrantAmount(object $payment): int
    {
        return V2PaymentAdjustmentLots::add((int) $payment->free_point_amount,
            (int) ($payment->limited_bonus_point_amount ?? 0));
    }

    private function sameInstant(mixed $stored, ?\DateTimeInterface $provided): bool
    {
        if ($stored === null || $provided === null) {
            return $stored === null && $provided === null;
        }

        return CarbonImmutable::parse($stored)->utc()->startOfSecond()->equalTo(
            CarbonImmutable::parse($provided->format('Y-m-d H:i:s.uP'))
                ->utc()
                ->startOfSecond()
        );
    }

    private function auditAdjustment(object $payment, object $adjustment, string $action, ?int $adminId = null, ?string $reason = null): void
    {
        $this->audit->record($action, [
            'actor_type' => $adminId === null ? 'system' : 'admin',
            'actor_public_id' => $adminId === null ? null : DB::table('admins')->where('id', $adminId)->value('public_id'),
            'auth_realm' => $adminId === null ? null : 'admin',
            'reason_text' => $reason,
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
