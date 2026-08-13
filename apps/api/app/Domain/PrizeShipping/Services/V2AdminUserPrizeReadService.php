<?php

namespace App\Domain\PrizeShipping\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\PrizeShipping\Exceptions\V2PrizeShippingException;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Domain\Reporting\Services\V2ReportingCursor;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2AdminUserPrizeReadService
{
    private const MAX_LIMIT = 100;

    private const STATUSES = [
        'stored',
        'exchange_processing',
        'converted',
        'shipping_requested',
        'packing',
        'shipped',
        'delivered',
        'hold',
        'return_requested',
        'returned',
        'expired',
        'canceled',
    ];

    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorizer,
        private readonly V2PrizeShippingService $fulfillment,
        private readonly V2ReportingCursor $cursor,
        private readonly V2AuditLogService $audit
    ) {
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function listing(
        V2AdminAuthorizationContext $context,
        array $filters
    ): array {
        $this->authorize($context);
        $limit = $this->limit($filters['limit'] ?? 20);
        try {
            $cursorId = $this->cursor->decode($this->nullableString($filters['cursor'] ?? null));
        } catch (V2ReportingException) {
            throw $this->invalidQuery();
        }
        $query = $this->query();
        if ($cursorId !== null) {
            $query->where('ownership.id', '<', $cursorId);
        }
        $this->applyFilters($query, $filters);
        $rows = $query->orderByDesc('ownership.id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $now = CarbonImmutable::parse(now())->startOfSecond();
        $items = $page->map(
            fn (object $row): array => $this->summary($row, $now)
        )->values()->all();
        $last = $page->last();

        $this->auditView($context, 'admin.user_prize.list.viewed');

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $last !== null
                ? $this->cursor->encode((int) $last->id)
                : null,
            'request_id' => $context->requestId,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $admin = $this->authorize($context);
        $row = $this->query()->where('ownership.public_id', $publicId)->first();
        if ($row === null) {
            throw $this->notFound();
        }
        $data = $this->summary($row, CarbonImmutable::parse(now())->startOfSecond());
        $data['draw'] = [
            'request_id' => (string) $row->draw_request_public_id,
            'result_id' => (string) $row->draw_result_public_id,
            'requested_count' => (int) $row->requested_count,
            'executed_count' => (int) $row->executed_count,
            'consumed_points' => (int) $row->consumed_points,
            'completed_at' => $this->timestamp($row->draw_completed_at),
        ];
        $data['status_history'] = DB::table('user_prize_status_histories')
            ->where('user_prize_id', $row->id)
            ->orderBy('id')
            ->get(['from_status', 'to_status', 'reason_code', 'occurred_at'])
            ->map(fn (object $history): array => [
                'from_status' => $history->from_status === null
                    ? null
                    : (string) $history->from_status,
                'to_status' => (string) $history->to_status,
                'reason_code' => (string) $history->reason_code,
                'occurred_at' => $this->timestamp($history->occurred_at),
            ])->values()->all();
        $data['shipping'] = $row->shipping_request_public_id === null
            ? null
            : $this->fulfillment->adminShippingDetail(
                $admin,
                (string) $row->shipping_request_public_id,
                $context->requestId
            );
        $data['point_exchange'] = $row->exchange_request_public_id === null
            ? null
            : [
                'id' => (string) $row->exchange_request_public_id,
                'status' => (string) $row->exchange_request_status,
                'exchange_points' => (int) $row->exchange_point_snapshot,
                'completed_at' => $row->exchange_completed_at === null
                    ? null
                    : $this->timestamp($row->exchange_completed_at),
            ];

        $this->auditView($context, 'admin.user_prize.detail.viewed', $publicId);

        return ['data' => $data, 'request_id' => $context->requestId];
    }

    private function authorize(V2AdminAuthorizationContext $context): Admin
    {
        return $this->authorizer->authorizePermission(
            $context,
            V2Permission::ManageShippingRequest,
            action: 'admin.user_prize.read'
        );
    }

    private function query(): Builder
    {
        return DB::table('user_prizes as ownership')
            ->join('users as user', 'user.id', '=', 'ownership.user_id')
            ->join('draw_results as result', 'result.id', '=', 'ownership.draw_result_id')
            ->join('draw_requests as draw_request', 'draw_request.id', '=', 'result.draw_request_id')
            ->join('catalog_gacha_versions as version', 'version.id', '=', 'draw_request.gacha_version_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'version.gacha_id')
            ->leftJoin('shipping_request_items as shipping_item', 'shipping_item.user_prize_id', '=', 'ownership.id')
            ->leftJoin('shipping_requests as shipping_request', 'shipping_request.id', '=', 'shipping_item.shipping_request_id')
            ->leftJoin('prize_exchange_request_items as exchange_item', 'exchange_item.user_prize_id', '=', 'ownership.id')
            ->leftJoin('prize_exchange_requests as exchange_request', 'exchange_request.id', '=', 'exchange_item.prize_exchange_request_id')
            ->leftJoinSub(
                DB::table('payment_adjustment_prize_actions')
                    ->select('user_prize_id')
                    ->whereIn('action_type', ['hold', 'return_request'])
                    ->whereIn('status', ['pending', 'completed', 'manual_review'])
                    ->groupBy('user_prize_id'),
                'active_payment_hold',
                'active_payment_hold.user_prize_id',
                '=',
                'ownership.id'
            )
            ->select([
                'ownership.id',
                'ownership.public_id',
                'ownership.status',
                'ownership.exchange_point_snapshot',
                'ownership.exchanged_point_amount',
                'ownership.acquired_at',
                'ownership.storage_expires_at',
                'ownership.terminal_at',
                'ownership.updated_at',
                'user.public_id as user_public_id',
                'user.display_name as user_display_name',
                'result.public_id as draw_result_public_id',
                'result.display_snapshot',
                'draw_request.public_id as draw_request_public_id',
                'draw_request.requested_count',
                'draw_request.executed_count',
                'draw_request.point_cost_total as consumed_points',
                'draw_request.completed_at as draw_completed_at',
                'version.public_id as version_public_id',
                'version.title as gacha_title',
                'gacha.public_code as gacha_public_id',
                'shipping_request.public_id as shipping_request_public_id',
                'shipping_request.status as shipping_request_status',
                'exchange_request.public_id as exchange_request_public_id',
                'exchange_request.status as exchange_request_status',
                'exchange_request.completed_at as exchange_completed_at',
                'active_payment_hold.user_prize_id as active_payment_hold_prize_id',
            ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $user = $this->filter($filters, 'user');
        if ($user !== null) {
            if (Str::isUuid($user)) {
                $query->where('user.public_id', $user);
            } else {
                $query->where('user.display_name', 'ilike', '%'.$this->like($user).'%');
            }
        }
        $prize = $this->filter($filters, 'prize_name');
        if ($prize !== null) {
            $query->whereRaw(
                "result.display_snapshot->'prize'->>'name' ILIKE ? ESCAPE E'\\\\'",
                ['%'.$this->like($prize).'%']
            );
        }
        $gacha = $this->filter($filters, 'gacha');
        if ($gacha !== null) {
            $query->where(function (Builder $builder) use ($gacha): void {
                $builder->where('gacha.public_code', $gacha)
                    ->orWhere('version.title', 'ilike', '%'.$this->like($gacha).'%');
            });
        }
        $status = $this->filter($filters, 'status');
        if ($status !== null) {
            if (! in_array($status, self::STATUSES, true)) {
                throw $this->invalidQuery();
            }
            $query->where('ownership.status', $status);
        }
    }

    /** @return array<string, mixed> */
    private function summary(object $row, CarbonImmutable $now): array
    {
        $snapshot = is_string($row->display_snapshot)
            ? json_decode($row->display_snapshot, true, 32, JSON_THROW_ON_ERROR)
            : (array) $row->display_snapshot;
        $prize = is_array($snapshot['prize'] ?? null) ? $snapshot['prize'] : null;
        $rank = is_array($snapshot['rank'] ?? null) ? $snapshot['rank'] : null;
        if ($prize === null || $rank === null) {
            throw new V2PrizeShippingException(
                'USER_PRIZE_PRESENTATION_UNAVAILABLE',
                500,
                'The User Prize presentation is unavailable.'
            );
        }

        return [
            'id' => (string) $row->public_id,
            'user' => [
                'id' => (string) $row->user_public_id,
                'display_name' => $row->user_display_name === null
                    ? null
                    : (string) $row->user_display_name,
            ],
            'prize' => [
                'id' => (string) ($prize['id'] ?? ''),
                'name' => (string) ($prize['name'] ?? ''),
                'image' => $this->snapshotAsset(
                    is_array($prize['presentation_asset'] ?? null)
                        ? $prize['presentation_asset']
                        : null
                ),
                'rank' => [
                    'id' => (string) ($rank['id'] ?? ''),
                    'code' => (string) ($rank['code'] ?? ''),
                    'name' => (string) ($rank['name'] ?? ''),
                ],
            ],
            'gacha' => [
                'id' => (string) $row->gacha_public_id,
                'version_id' => (string) $row->version_public_id,
                'title' => (string) $row->gacha_title,
            ],
            'status' => (string) $row->status,
            'fulfillment' => [
                'shipping_status' => $row->shipping_request_status === null
                    ? null
                    : (string) $row->shipping_request_status,
                'point_exchange_status' => $row->exchange_request_status === null
                    ? null
                    : (string) $row->exchange_request_status,
            ],
            'exchange_points' => (int) $row->exchange_point_snapshot,
            'exchanged_points' => $row->exchanged_point_amount === null
                ? null
                : (int) $row->exchanged_point_amount,
            'acquired_at' => $this->timestamp($row->acquired_at),
            'storage_expires_at' => $this->timestamp($row->storage_expires_at),
            'terminal_at' => $row->terminal_at === null
                ? null
                : $this->timestamp($row->terminal_at),
            'status_updated_at' => $this->timestamp($row->updated_at),
            'allowed_actions' => $this->fulfillment->prizeAllowedActions(
                $row,
                $now,
                $row->active_payment_hold_prize_id !== null
            ),
        ];
    }

    private function limit(mixed $value): int
    {
        $limit = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw $this->invalidQuery();
        }

        return $limit;
    }

    /** @param array<string, mixed> $filters */
    private function filter(array $filters, string $key): ?string
    {
        if (! array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
            return null;
        }
        if (! is_string($filters[$key])) {
            throw $this->invalidQuery();
        }
        $value = trim($filters[$key]);
        if ($value === '' || mb_strlen($value) > 191) {
            throw $this->invalidQuery();
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw $this->invalidQuery();
        }

        return $value;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @param array<string, mixed>|null $asset @return array<string, mixed>|null */
    private function snapshotAsset(?array $asset): ?array
    {
        if ($asset === null || ! is_string($asset['id'] ?? null)) {
            return null;
        }
        $path = is_string($asset['path'] ?? null) ? $asset['path'] : null;

        return [
            'id' => $asset['id'],
            'media_type' => (string) ($asset['media_type'] ?? ''),
            'mime_type' => (string) ($asset['mime_type'] ?? ''),
            'alt_text' => is_string($asset['alt_text'] ?? null) ? $asset['alt_text'] : null,
            'public_path' => $path,
            'is_public' => $path !== null,
        ];
    }

    private function timestamp(mixed $value): string
    {
        return CarbonImmutable::parse($value)->utc()->toIso8601String();
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
            'target_type' => $targetPublicId === null ? null : 'user_prize',
            'target_public_id' => $targetPublicId,
            'outcome' => 'success',
        ]);
    }

    private function notFound(): V2PrizeShippingException
    {
        return new V2PrizeShippingException(
            'ADMIN_USER_PRIZE_NOT_FOUND',
            404,
            'The User Prize was not found.'
        );
    }

    private function invalidQuery(): V2PrizeShippingException
    {
        return new V2PrizeShippingException(
            'ADMIN_USER_PRIZE_QUERY_INVALID',
            422,
            'The User Prize query is invalid.'
        );
    }
}
