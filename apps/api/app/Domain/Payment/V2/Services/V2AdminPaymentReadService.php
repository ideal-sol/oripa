<?php

namespace App\Domain\Payment\V2\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Domain\Payment\V2\Exceptions\V2AdminPaymentReadException;
use App\Domain\Reporting\Services\V2ReportingCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2AdminPaymentReadService
{
    private const STATUSES = [
        'created',
        'requires_action',
        'processing',
        'succeeded',
        'failed',
        'canceled',
        'expired',
    ];

    private const METHODS = ['credit_card', 'paypay', 'konbini', 'virtual_account'];

    public function __construct(
        private readonly V2AuditLogService $audit,
        private readonly V2ReportingCursor $cursor,
        private readonly V2PermissionAuthorizer $permissions
    ) {
    }

    /** @return array<string, mixed> */
    public function all(
        V2AdminAuthorizationContext $context,
        ?string $cursor,
        int $limit,
        ?string $status,
        ?string $method,
        ?string $userPublicId
    ): array {
        $this->authorize($context);
        $result = $this->page(
            $this->query($status, $method, $userPublicId),
            $cursor,
            $limit
        );
        $this->auditView($context, 'admin.payment.list.viewed');

        return [...$result, 'request_id' => $context->requestId];
    }

    /** @return array<string, mixed> */
    public function forUser(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        ?string $cursor,
        int $limit,
        ?string $status,
        ?string $method
    ): array {
        $this->authorize($context);
        if (! DB::table('users')->where('public_id', $userPublicId)->exists()) {
            throw new V2AdminPaymentReadException('ADMIN_USER_NOT_FOUND', 404, 'The user was not found.');
        }
        $result = $this->page($this->query($status, $method, $userPublicId), $cursor, $limit);
        $this->auditView($context, 'admin.user.payment_history.viewed', $userPublicId);

        return [...$result, 'request_id' => $context->requestId];
    }

    private function query(?string $status, ?string $method, ?string $userPublicId): Builder
    {
        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            throw new V2AdminPaymentReadException('PAYMENT_STATUS_FILTER_INVALID', 422, 'The payment status filter is invalid.');
        }
        if ($method !== null && ! in_array($method, self::METHODS, true)) {
            throw new V2AdminPaymentReadException('PAYMENT_METHOD_FILTER_INVALID', 422, 'The payment method filter is invalid.');
        }
        $query = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where('payments.provider_code', 'fincode')
            ->orderByDesc('payments.id')
            ->select([
                'payments.*',
                'users.public_id as user_public_id',
                'users.display_name as user_display_name',
            ]);
        if ($status !== null) {
            $query->where('payments.status', $status);
        }
        if ($method !== null) {
            $query->where('payments.payment_method', $method);
        }
        if ($userPublicId !== null) {
            $query->where('users.public_id', $userPublicId);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function page(Builder $query, ?string $cursor, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new V2AdminPaymentReadException('PAYMENT_HISTORY_LIMIT_INVALID', 422, 'The payment history limit is invalid.');
        }
        $cursorId = $this->cursor->decode($cursor);
        if ($cursorId !== null) {
            $query->where('payments.id', '<', $cursorId);
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $last = $page->last();

        return [
            'data' => $page->map(fn (object $payment): array => $this->present($payment))->all(),
            'pagination' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && $last !== null
                    ? $this->cursor->encode((int) $last->id)
                    : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function present(object $payment): array
    {
        return [
            'id' => $payment->public_id,
            'user' => [
                'id' => $payment->user_public_id,
                'display_name' => $payment->user_display_name,
            ],
            'provider' => 'fincode',
            'provider_payment_reference' => $payment->provider_payment_id,
            'method' => $payment->payment_method,
            'status' => $payment->status,
            'provider_status' => $payment->provider_status,
            'amount' => ['amount' => (int) $payment->amount, 'currency' => $payment->currency],
            'grant' => [
                'paid_points' => (int) $payment->paid_point_amount,
                'bonus_points' => (int) $payment->free_point_amount,
                'granted_at' => $this->timestamp($payment->points_granted_at),
            ],
            'expires_at' => $this->timestamp($payment->expires_at),
            'succeeded_at' => $this->timestamp($payment->succeeded_at),
            'created_at' => $this->timestamp($payment->created_at),
            'updated_at' => $this->timestamp($payment->updated_at),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null
            ? null
            : CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
    }

    private function auditView(
        V2AdminAuthorizationContext $context,
        string $action,
        ?string $targetPublicId = null
    ): void {
        $this->audit->record($action, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $context->adminPublicId,
            'actor_role' => $context->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => $targetPublicId === null ? 'payment_collection' : 'user',
            'target_public_id' => $targetPublicId,
            'outcome' => 'success',
        ]);
    }

    private function authorize(V2AdminAuthorizationContext $context): void
    {
        if (! $this->permissions->allows($context->role, V2Permission::ReadFinancialReporting)) {
            throw new V2AuthenticationException(
                'AUTHORIZATION_DENIED',
                403,
                'The payment history operation is not permitted.'
            );
        }
    }
}
