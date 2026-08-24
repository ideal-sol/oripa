<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AdminUserReadException;
use App\Domain\Reporting\Services\V2ReportingCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2AdminUserReadService
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly V2AuditLogService $audit,
        private readonly V2ReportingCursor $cursor
    ) {
    }

    /** @return array<string, mixed> */
    public function users(
        V2AdminAuthorizationContext $context,
        ?string $cursor,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $operationAt = CarbonImmutable::now()->startOfSecond();
        $query = DB::table('users')
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->leftJoinSub(
                $this->availableBalances($operationAt),
                'available_points',
                'available_points.user_id',
                '=',
                'users.id'
            )
            ->leftJoinSub(
                $this->lotCounts(),
                'point_lot_counts',
                'point_lot_counts.user_id',
                '=',
                'users.id'
            )
            ->orderByDesc('users.id')
            ->select([
                'users.id',
                'users.public_id',
                'users.display_name',
                'users.state',
                'users.created_at',
                'wallets.id as wallet_id',
                'wallets.paid_balance',
                'wallets.free_balance',
                'point_lot_counts.paid_count',
                'point_lot_counts.free_count',
                'available_points.paid_balance as available_paid_balance',
                'available_points.free_balance as available_free_balance',
            ]);
        $page = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'id' => (string) $row->public_id,
            'display_name' => $row->display_name === null
                ? null
                : (string) $row->display_name,
            'status' => (string) $row->state,
            'point_balance' => $this->pointBalance($row),
            'created_at' => $this->timestamp($row->created_at),
        ], 'users.id');

        $this->auditView($context, 'admin.user.list.viewed');

        return [...$page, 'request_id' => $context->requestId];
    }

    /** @return array<string, mixed> */
    public function user(
        V2AdminAuthorizationContext $context,
        string $userPublicId
    ): array {
        $operationAt = CarbonImmutable::now()->startOfSecond();
        $row = DB::table('users')
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->leftJoinSub(
                $this->canonicalAvailableBalances($operationAt),
                'available_points',
                'available_points.user_id',
                '=',
                'users.id'
            )
            ->leftJoinSub(
                $this->lotCounts(),
                'point_lot_counts',
                'point_lot_counts.user_id',
                '=',
                'users.id'
            )
            ->leftJoinSub(
                $this->nextExpiryBalances($operationAt),
                'next_expiry_points',
                'next_expiry_points.user_id',
                '=',
                'users.id'
            )
            ->where('users.public_id', $userPublicId)
            ->select([
                'users.id',
                'users.public_id',
                'users.display_name',
                'users.email_display',
                'users.email_verified_at',
                'users.state',
                'users.state_revision',
                'users.created_at',
                'users.updated_at',
                'users.tag_assignment_revision',
                'wallets.id as wallet_id',
                'wallets.paid_balance',
                'wallets.free_balance',
                'wallets.paid_reserved_balance',
                'wallets.free_reserved_balance',
                'point_lot_counts.paid_count',
                'point_lot_counts.free_count',
                'available_points.paid_balance as available_paid_balance',
                'available_points.free_balance as available_free_balance',
                'next_expiry_points.amount as next_expiring_amount',
                'next_expiry_points.expire_at as next_expires_at',
            ])
            ->first();
        if ($row === null) {
            throw $this->notFound();
        }
        $this->auditView($context, 'admin.user.detail.viewed', $userPublicId);

        return [
            'data' => [
                'id' => (string) $row->public_id,
                'display_name' => $row->display_name === null
                    ? null
                    : (string) $row->display_name,
                'email' => (string) $row->email_display,
                'email_verified_at' => $row->email_verified_at === null
                    ? null
                    : $this->timestamp($row->email_verified_at),
                'status' => (string) $row->state,
                'state_revision' => (int) $row->state_revision,
                'point_balance' => $this->pointBalance($row),
                'tag_assignment_revision' => (int) $row->tag_assignment_revision,
                'tags' => $this->userTags((int) $row->id),
                'created_at' => $this->timestamp($row->created_at),
                'updated_at' => $this->timestamp($row->updated_at),
            ],
            'request_id' => $context->requestId,
        ];
    }

    /** @return array<string, mixed> */
    public function gachaHistory(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        ?string $cursor,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $userId = DB::table('users')
            ->where('public_id', $userPublicId)
            ->value('id');
        if (! is_numeric($userId)) {
            throw $this->notFound();
        }
        $query = DB::table('user_prizes as ownership')
            ->join('draw_results as result', 'result.id', '=', 'ownership.draw_result_id')
            ->join(
                'catalog_gacha_version_prizes as version_prize',
                'version_prize.id',
                '=',
                'ownership.gacha_version_prize_id'
            )
            ->join(
                'catalog_gacha_versions as version',
                'version.id',
                '=',
                'version_prize.gacha_version_id'
            )
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'version.gacha_id')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'version_prize.prize_id')
            ->join('catalog_ranks as rank', 'rank.id', '=', 'version_prize.rank_id')
            ->where('ownership.user_id', (int) $userId)
            ->orderByDesc('ownership.id')
            ->select([
                'ownership.id',
                'ownership.public_id',
                'result.public_id as draw_result_public_id',
                'gacha.public_id as gacha_public_id',
                'version.public_id as version_public_id',
                'version.title as gacha_title',
                'prize.public_id as prize_public_id',
                'version_prize.display_name as prize_name',
                'rank.public_id as rank_public_id',
                'version_prize.rank_display_name as rank_name',
                'ownership.status',
                'ownership.exchange_point_snapshot',
                'ownership.exchanged_point_amount',
                'ownership.acquired_at',
                'ownership.storage_expires_at',
                'ownership.terminal_at',
            ]);
        $page = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'id' => (string) $row->public_id,
            'draw_result_id' => (string) $row->draw_result_public_id,
            'gacha_id' => (string) $row->gacha_public_id,
            'gacha_version_id' => (string) $row->version_public_id,
            'gacha_title' => (string) $row->gacha_title,
            'prize_id' => (string) $row->prize_public_id,
            'prize_name' => (string) $row->prize_name,
            'rank_id' => (string) $row->rank_public_id,
            'rank_name' => (string) $row->rank_name,
            'status' => (string) $row->status,
            'exchange_point_snapshot' => (int) $row->exchange_point_snapshot,
            'exchanged_point_amount' => $row->exchanged_point_amount === null
                ? null
                : (int) $row->exchanged_point_amount,
            'acquired_at' => $this->timestamp($row->acquired_at),
            'storage_expires_at' => $this->timestamp($row->storage_expires_at),
            'terminal_at' => $row->terminal_at === null
                ? null
                : $this->timestamp($row->terminal_at),
        ], 'ownership.id');

        $this->auditView($context, 'admin.user.gacha_history.viewed', $userPublicId);

        return [
            'user_id' => $userPublicId,
            ...$page,
            'request_id' => $context->requestId,
        ];
    }

    /** @return array<string, mixed> */
    public function referralHistory(
        V2AdminAuthorizationContext $context,
        string $userPublicId,
        ?string $cursor,
        int $limit = self::DEFAULT_LIMIT
    ): array {
        $userId = DB::table('users')
            ->where('public_id', $userPublicId)
            ->value('id');
        if (! is_numeric($userId)) {
            throw $this->notFound();
        }
        $query = DB::table('user_referrals as referral')
            ->join('users as referred_user', 'referred_user.id', '=', 'referral.referred_user_id')
            ->where('referral.referrer_user_id', (int) $userId)
            ->whereColumn('referral.referred_user_id', '<>', 'referral.referrer_user_id')
            ->orderByDesc('referral.id')
            ->select([
                'referral.id',
                'referral.public_id',
                'referral.status',
                'referral.created_at',
                'referred_user.public_id as referred_user_public_id',
                'referred_user.display_name as referred_user_display_name',
                'referred_user.created_at as referred_user_created_at',
            ]);
        $page = $this->page($query, $cursor, $limit, fn (object $row): array => [
            'id' => (string) $row->public_id,
            'referred_user_id' => (string) $row->referred_user_public_id,
            'referred_user_display_name' => $row->referred_user_display_name === null
                ? null
                : (string) $row->referred_user_display_name,
            'status' => (string) $row->status,
            'referred_at' => $this->timestamp($row->created_at),
            'registered_at' => $this->timestamp($row->referred_user_created_at),
        ], 'referral.id');

        $this->auditView($context, 'admin.user.referral_history.viewed', $userPublicId);

        return [
            'user_id' => $userPublicId,
            ...$page,
            'request_id' => $context->requestId,
        ];
    }

    /**
     * @param callable(object): array<string, mixed> $transform
     * @return array{items: array<int, array<string, mixed>>, next_cursor: ?string}
     */
    private function page(
        Builder $query,
        ?string $cursor,
        int $limit,
        callable $transform,
        string $idColumn
    ): array {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new V2AdminUserReadException(
                'ADMIN_USER_QUERY_INVALID',
                422,
                'The User query is invalid.'
            );
        }
        $cursorId = $this->cursor->decode($cursor);
        if ($cursorId !== null) {
            $query->where($idColumn, '<', $cursorId);
        }
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $last = $rows->last();

        return [
            'items' => $rows->map($transform)->values()->all(),
            'next_cursor' => $hasMore && $last !== null
                ? $this->cursor->encode((int) $last->id)
                : null,
        ];
    }

    /** @return array<string, int|string|null>|null */
    private function pointBalance(object $row): ?array
    {
        if ($row->wallet_id === null) {
            return null;
        }
        $paid = (int) ($row->paid_count ?? 0) > 0
            ? (int) $row->available_paid_balance
            : (int) $row->paid_balance - (property_exists($row, 'paid_reserved_balance')
                ? (int) $row->paid_reserved_balance
                : 0);
        $free = (int) ($row->free_count ?? 0) > 0
            ? (int) $row->available_free_balance
            : (int) $row->free_balance - (property_exists($row, 'free_reserved_balance')
                ? (int) $row->free_reserved_balance
                : 0);

        $balance = [
            'total_balance' => $paid + $free,
            'paid_balance' => $paid,
            'free_balance' => $free,
        ];
        if (property_exists($row, 'next_expiring_amount')) {
            $balance['next_expiring_amount'] = (int) ($row->next_expiring_amount ?? 0);
            $balance['next_expires_at'] = $row->next_expires_at === null
                ? null
                : $this->timestamp($row->next_expires_at);
        }

        return $balance;
    }

    private function availableBalances(CarbonImmutable $operationAt): Builder
    {
        $operationAtIso = $operationAt->toIso8601String();

        return DB::table('point_lots')
            ->where(function (Builder $query) use ($operationAtIso): void {
                $query->whereNull('expire_at')->orWhere('expire_at', '>', $operationAtIso);
            })
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw(
                <<<'SQL'
                    COALESCE(SUM(remaining_amount) FILTER (
                        WHERE point_type = 'paid'
                    ), 0) AS paid_balance,
                    COALESCE(SUM(remaining_amount) FILTER (
                        WHERE point_type = 'free'
                    ), 0) AS free_balance
                SQL
            );
    }

    private function canonicalAvailableBalances(CarbonImmutable $operationAt): Builder
    {
        $operationAtIso = $operationAt->toIso8601String();

        return DB::table('point_lots')
            ->whereRaw('remaining_amount > reserved_amount')
            ->where(function (Builder $query) use ($operationAtIso): void {
                $query->whereNull('expire_at')->orWhere('expire_at', '>', $operationAtIso);
            })
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw(
                <<<'SQL'
                    COALESCE(SUM(remaining_amount - reserved_amount) FILTER (
                        WHERE point_type = 'paid'
                    ), 0) AS paid_balance,
                    COALESCE(SUM(remaining_amount - reserved_amount) FILTER (
                        WHERE point_type = 'free'
                    ), 0) AS free_balance
                SQL
            );
    }

    private function nextExpiryBalances(CarbonImmutable $operationAt): Builder
    {
        $operationAtIso = $operationAt->toIso8601String();
        $byTimestamp = DB::table('point_lots')
            ->whereNotNull('expire_at')
            ->where('expire_at', '>', $operationAtIso)
            ->whereRaw('remaining_amount > reserved_amount')
            ->groupBy(['user_id', 'expire_at'])
            ->select(['user_id', 'expire_at'])
            ->selectRaw('SUM(remaining_amount - reserved_amount) AS amount')
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY expire_at ASC) AS expiry_rank'
            );

        return DB::query()
            ->fromSub($byTimestamp, 'expiry_buckets')
            ->where('expiry_rank', 1)
            ->select(['user_id', 'expire_at', 'amount']);
    }

    private function lotCounts(): Builder
    {
        return DB::table('point_lots')
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw(
                <<<'SQL'
                    COUNT(*) FILTER (WHERE point_type = 'paid') AS paid_count,
                    COUNT(*) FILTER (WHERE point_type = 'free') AS free_count
                SQL
            );
    }

    /** @return list<array<string, mixed>> */
    private function userTags(int $userId): array
    {
        return DB::table('user_tag_assignments as assignment')
            ->join('user_tags as tag', 'tag.id', '=', 'assignment.user_tag_id')
            ->where('assignment.user_id', $userId)
            ->orderBy('tag.normalized_name')
            ->orderBy('tag.id')
            ->get([
                'tag.public_id',
                'tag.name',
                'tag.is_active',
                'assignment.assigned_at',
            ])
            ->map(fn (object $tag): array => [
                'id' => (string) $tag->public_id,
                'name' => (string) $tag->name,
                'is_active' => (bool) $tag->is_active,
                'assigned_at' => $this->timestamp($tag->assigned_at),
            ])->values()->all();
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
            'target_type' => $targetPublicId === null ? null : 'user',
            'target_public_id' => $targetPublicId,
            'outcome' => 'success',
        ]);
    }

    private function notFound(): V2AdminUserReadException
    {
        return new V2AdminUserReadException(
            'ADMIN_USER_NOT_FOUND',
            404,
            'The User was not found.'
        );
    }
}
