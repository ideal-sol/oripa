<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\MemberRank\Services\V2MemberRankPaymentAdjustmentService;
use App\Domain\Payment\V2\Exceptions\V2AdminPaymentReadException;
use App\Domain\Payment\V2\Exceptions\V2PaymentException;
use App\Domain\Point\Exceptions\V2PointException;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Point\Services\V2PointTransactionRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2AdminPaymentAdjustmentService
{
    public function __construct(
        private readonly V2PaymentService $payments,
        private readonly V2AdminPaymentReadService $read,
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2PointTransactionRunner $transactions,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function detail(V2AdminAuthorizationContext $context, string $paymentId): array
    {
        $payment = $this->read->find($context, $paymentId);
        $grant = DB::table('payment_point_grants')->where('payment_id', $payment->id)->value('point_operation_id');
        $bonus = DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->first();
        $lots = DB::table('point_lots')->join('point_operations', 'point_operations.id', '=', 'point_lots.grant_operation_id')
            ->whereIn('grant_operation_id', array_filter([$grant, $bonus?->point_operation_id]))->orderBy('point_lots.id')
            ->select(['point_lots.*', 'point_operations.public_id as operation_public_id', 'point_operations.source_type'])->get();
        try {
            $this->payments->assertRefundEligible($payment);
            $eligibility = ['allowed' => true, 'code' => null, 'reason' => null];
        } catch (V2PaymentException $exception) {
            $eligibility = ['allowed' => false, 'code' => $exception->getMessage(), 'reason' => $this->message($exception->getMessage())];
        }
        $data = [
            'payment' => $this->read->present($payment),
            'lots' => $lots->map(fn ($lot): array => [
                'operation_id' => $lot->operation_public_id, 'point_type' => $lot->point_type, 'source' => $lot->source_type,
                'granted_amount' => (int) $lot->granted_amount, 'remaining_amount' => (int) $lot->remaining_amount,
                'reserved_amount' => (int) $lot->reserved_amount, 'expire_at' => $this->timestamp($lot->expire_at),
                'member_rank_purchase_bonus' => $bonus !== null && (int) $lot->grant_operation_id === (int) $bonus->point_operation_id,
            ])->all(),
            'adjustments' => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderByDesc('id')->get()
                ->map(fn ($adjustment): array => $this->present($adjustment))->all(),
            'refund_eligibility' => $eligibility,
            'rank_adjustment_pending' => $this->rankPending($payment),
        ];
        $this->audit->record('admin.payment.detail.viewed', $this->auditContext($context, $payment->public_id));

        return ['data' => $data, 'request_id' => $context->requestId];
    }

    public function preview(V2AdminAuthorizationContext $context, string $paymentId, array $input): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ManagePaymentAdjustment);
        $this->validate($input, 'chargeback');
        try {
            return DB::transaction(function () use ($context, $paymentId): array {
                $view = $this->read->find($context, $paymentId);
                DB::table('users')->where('id', $view->user_id)->sharedLock()->firstOrFail();
                $payment = DB::table('payments')->where('id', $view->id)->sharedLock()->firstOrFail();
                DB::table('wallets')->where('user_id', $payment->user_id)->sharedLock()->first();

                return ['data' => ['impact' => $this->payments->previewChargeback($payment), 'rank_adjustment_pending' => false], 'request_id' => $context->requestId];
            });
        } catch (V2PaymentException $exception) {
            throw $this->conflict($exception->getMessage());
        }
    }

    public function write(V2AdminAuthorizationContext $context, string $paymentId, string $operation, array $input, string $key, ?string $adjustmentId = null): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ManagePaymentAdjustment);
        $this->validate($input, $operation);
        if (! preg_match('/\A[\x21-\x7e]{16,128}\z/', $key)) {
            throw new V2AdminPaymentReadException('PAYMENT_ADJUSTMENT_INPUT_INVALID', 422, 'Idempotency-Keyを指定してください。');
        }
        try {
            return $this->transactions->run(function () use ($context, $paymentId, $operation, $input, $key, $adjustmentId): array {
                $this->authorization->authorizePermission($context, V2Permission::ManagePaymentAdjustment);
                $claim = $this->idempotency->claim('admin.payment.'.$operation, 'admin', $context->adminPublicId, $key,
                    ['payment' => $paymentId, 'adjustment' => $adjustmentId, 'input' => $input]);
                if ($claim->replay) {
                    return [...$claim->record->response_data, 'idempotent_replay' => true, 'request_id' => $context->requestId];
                }
                $view = $this->read->find($context, $paymentId);
                DB::table('users')->where('id', $view->user_id)->lockForUpdate()->firstOrFail();
                $payment = DB::table('payments')->where('id', $view->id)->lockForUpdate()->firstOrFail();
                $parent = $adjustmentId === null ? null : DB::table('payment_adjustments')
                    ->where('payment_id', $payment->id)->where('public_id', $adjustmentId)->lockForUpdate()->first();
                if ($adjustmentId !== null && $parent === null) {
                    throw new V2AdminPaymentReadException('ADMIN_ADJUSTMENT_NOT_FOUND', 404, '調整が見つかりません。');
                }
                if (in_array($operation, ['complete', 'cancel'], true)
                    && ($parent === null || $parent->type !== 'refund' || $parent->status !== 'points_reserved' || $parent->reason_code !== 'admin_manual')) {
                    throw $this->conflict('REFUND_TERMINAL_STATE');
                }
                $before = $parent?->status;
                $adjustment = match ($operation) {
                    'refund' => $this->payments->reserveFullRefund((int) $payment->id, $key, $context->adminId, $input['reason']),
                    'complete', 'cancel' => $this->payments->resolveRefund((int) $parent->id, $operation === 'complete' ? 'succeeded' : 'canceled', $context->adminId, $input['reason']),
                    'chargeback' => $this->payments->recordManualChargeback((int) $payment->id, $context->adminId, $input['reason']),
                    'reversal' => $this->payments->recordManualReversal($payment, $parent, $context->adminId, $input['reason']),
                    default => throw $this->conflict('PAYMENT_ADJUSTMENT_INVALID'),
                };
                $references = array_intersect_key($input, array_flip(['provider_case_id', 'provider_adjustment_id']));
                if ($references !== []) DB::table('payment_adjustments')->where('id', $adjustment->id)->update($references);
                $adjustment = DB::table('payment_adjustments')->where('id', $adjustment->id)->firstOrFail();
                $presented = $this->present($adjustment);
                $this->audit->record('admin.payment.'.$operation.'.recorded', [
                    ...$this->auditContext($context, $payment->public_id),
                    'reason_text' => $input['reason'],
                    'before' => ['status' => $before], 'after' => ['status' => $adjustment->status],
                    'metadata' => ['adjustment_id' => $adjustment->public_id, 'amount' => (int) $adjustment->amount,
                        ...array_filter($presented['impact'] ?? [], fn ($value): bool => is_int($value))],
                ]);
                $response = ['data' => $presented, 'rank_adjustment_pending' => $this->rankPending($payment), 'idempotent_replay' => false];
                $this->idempotency->complete($claim->record, 'payment_adjustment', $adjustment->public_id, $response);

                return [...$response, 'request_id' => $context->requestId];
            });
        } catch (V2PaymentException|V2PointException $exception) {
            throw $this->conflict($exception->getMessage());
        }
    }

    private function present(object $adjustment): array
    {
        $history = DB::table('payment_adjustment_status_histories as history')->leftJoin('admins', 'admins.id', '=', 'history.actor_id')
            ->where('history.payment_adjustment_id', $adjustment->id)->orderBy('history.id')->select(['history.*', 'admins.public_id as admin_public_id'])->get();
        $impact = DB::table('payment_adjustment_point_impacts')->where('payment_adjustment_id', $adjustment->id)->first();
        $keys = ['required_paid_amount', 'required_free_amount', 'reversed_paid_from_paid', 'reversed_free_from_free', 'reversed_paid_shortage_from_free', 'shortfall_paid_amount', 'shortfall_free_amount'];

        return [
            'id' => $adjustment->public_id, 'type' => $adjustment->type, 'status' => $adjustment->status,
            'parent_id' => $adjustment->parent_adjustment_id === null ? null : DB::table('payment_adjustments')->where('id', $adjustment->parent_adjustment_id)->value('public_id'),
            'amount' => (int) $adjustment->amount, 'reason' => $adjustment->reason_text,
            'requested_by_admin_id' => $adjustment->requested_by_admin_id === null ? null : DB::table('admins')->where('id', $adjustment->requested_by_admin_id)->value('public_id'),
            'provider_adjustment_id' => $adjustment->provider_adjustment_id, 'provider_case_id' => $adjustment->provider_case_id,
            'source' => $adjustment->source_provider_event_id !== null ? 'provider' : ($adjustment->requested_by_admin_id !== null ? 'admin' : 'system'),
            'requested_at' => $this->timestamp($adjustment->requested_at), 'succeeded_at' => $this->timestamp($adjustment->succeeded_at),
            'canceled_at' => $this->timestamp($history->last(fn ($row) => $row->to_status === 'canceled')?->occurred_at),
            'impact' => $impact === null ? null : [...array_combine($keys, array_map(fn ($key): int => (int) $impact->{$key}, $keys)),
                ...app(V2MemberRankPaymentAdjustmentService::class)->present((int) $adjustment->id)],
            'history' => $history->map(fn ($row): array => [
                'from_status' => $row->from_status, 'to_status' => $row->to_status, 'source' => $row->transition_source,
                'admin_id' => $row->actor_type === 'admin' ? $row->admin_public_id : null,
                'reason_code' => $row->reason_code, 'occurred_at' => $this->timestamp($row->occurred_at),
            ])->all(),
        ];
    }

    private function validate(array $input, string $operation): void
    {
        $allowed = $operation === 'complete' ? ['reason', 'provider_refund_confirmed', 'provider_adjustment_id'] : ['reason', 'provider_case_id'];
        if (array_diff(array_keys($input), $allowed) !== [] || ! is_string($input['reason'] ?? null)
            || trim($input['reason']) === '' || mb_strlen($input['reason']) > 500
            || ($operation === 'complete' && ($input['provider_refund_confirmed'] ?? null) !== true)) {
            throw new V2AdminPaymentReadException('PAYMENT_ADJUSTMENT_INPUT_INVALID', 422, '理由と確認事項を入力してください。');
        }
        foreach (['provider_case_id', 'provider_adjustment_id'] as $field) {
            if (isset($input[$field]) && (! is_string($input[$field]) || ! preg_match('/\A[A-Za-z0-9._:-]{1,191}\z/', $input[$field]))) {
                throw new V2AdminPaymentReadException('PAYMENT_ADJUSTMENT_INPUT_INVALID', 422, '決済会社の参照番号を確認してください。');
            }
        }
    }

    private function rankPending(object $payment): bool
    {
        try {
            $this->payments->assertRankAdjustmentReady($payment);

            return false;
        } catch (V2PaymentException) {
            return true;
        }
    }

    private function conflict(string $code): V2AdminPaymentReadException
    {
        return new V2AdminPaymentReadException($code, 409, $this->message($code));
    }

    private function message(string $code): string
    {
        return match ($code) {
            'PAYMENT_RANK_ADJUSTMENT_NOT_READY' => '付与元・ランク計上・会員状態の整合を確認できないため記録できません。凍結・機能停止・未計上の状態を確認してください。',
            'REFUND_POINTS_NOT_FULLY_UNUSED' => '対象ポイントに使用済み・失効・予約中のものがあるため、返金を準備できません。',
            'REFUND_PAYMENT_NOT_SUCCEEDED' => '成立済みの決済のみ操作できます。',
            'REFUND_POINT_GRANT_NOT_FOUND' => '対象決済のポイント付与が見つかりません。',
            'IDEMPOTENCY_KEY_REUSED' => '同じキーで異なる操作内容が送信されました。',
            'IDEMPOTENCY_REQUEST_IN_PROGRESS' => '同じ操作を処理中です。しばらくして再確認してください。',
            'CHARGEBACK_REVERSAL_ALREADY_RECORDED' => 'このチャージバックの取消は記録済みです。',
            default => '決済の調整状態が変わったか、競合する調整があります。最新の詳細を確認してください。',
        };
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
    }

    private function auditContext(V2AdminAuthorizationContext $context, string $paymentId): array
    {
        return ['request_id' => $context->requestId, 'actor_type' => 'admin', 'actor_public_id' => $context->adminPublicId,
            'actor_role' => $context->role->value, 'auth_realm' => 'admin', 'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => 'payment', 'target_public_id' => $paymentId];
    }
}
