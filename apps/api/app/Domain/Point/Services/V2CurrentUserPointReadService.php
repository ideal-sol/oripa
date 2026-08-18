<?php

namespace App\Domain\Point\Services;

use App\Domain\Point\Exceptions\V2PointReadException;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2CurrentUserPointReadService
{
    /** @return array{paid_points: int, free_points: int, total_points: int} */
    public function wallet(User $user): array
    {
        $operationAt = CarbonImmutable::now()->startOfSecond();
        $operationAtIso = $operationAt->toIso8601String();
        $balance = DB::table('point_lots')
            ->where('user_id', $user->id)
            ->where(function (Builder $query) use ($operationAtIso): void {
                $query->whereNull('expire_at')->orWhere('expire_at', '>', $operationAtIso);
            })
            ->selectRaw(
                <<<'SQL'
                    COALESCE(SUM(remaining_amount - reserved_amount) FILTER (
                        WHERE point_type = 'paid'
                    ), 0) AS paid,
                    COALESCE(SUM(remaining_amount - reserved_amount) FILTER (
                        WHERE point_type = 'free'
                    ), 0) AS free
                SQL
            )
            ->first();
        $paid = (int) $balance->paid;
        $free = (int) $balance->free;

        return [
            'paid_points' => $paid,
            'free_points' => $free,
            'total_points' => $paid + $free,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, next_cursor: string|null} */
    public function history(User $user, ?string $cursor, int $limit): array
    {
        $limit = $this->limit($limit);
        $query = $this->historyQuery($user->id);
        $this->applyCursor($query, $user->id, $this->decodeCursor($cursor));
        $rows = $query
            ->orderByDesc('operation.occurred_at')
            ->orderByDesc('operation.id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $visible = $rows->take($limit);
        $items = $visible->map(fn (object $row): array => [
            'id' => $row->public_id,
            'occurred_at' => CarbonImmutable::parse($row->occurred_at)
                ->utc()->startOfSecond()->toIso8601ZuluString(),
            'amount_delta' => (int) $row->amount_delta,
            'reason' => $this->reason($row->source_type, $row->operation_type),
        ])->values()->all();

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $visible->isNotEmpty()
                ? $this->encodeCursor((string) $visible->last()->public_id)
                : null,
        ];
    }

    private function historyQuery(int $userId): Builder
    {
        return DB::table('point_operations as operation')
            ->join(
                'point_ledger_entries as ledger',
                'ledger.point_operation_id',
                '=',
                'operation.id'
            )
            ->where('operation.user_id', $userId)
            ->where('ledger.user_id', $userId)
            ->groupBy([
                'operation.id',
                'operation.public_id',
                'operation.occurred_at',
                'operation.source_type',
                'operation.operation_type',
            ])
            ->select([
                'operation.id',
                'operation.public_id',
                'operation.occurred_at',
                'operation.source_type',
                'operation.operation_type',
            ])
            ->selectRaw('SUM(ledger.amount_delta) AS amount_delta');
    }

    private function applyCursor(Builder $query, int $userId, ?string $publicId): void
    {
        if ($publicId === null) {
            return;
        }
        $row = DB::table('point_operations')
            ->where('user_id', $userId)
            ->where('public_id', $publicId)
            ->first(['id', 'occurred_at']);
        if ($row === null) {
            throw $this->invalidCursor();
        }
        $query->where(function (Builder $page) use ($row): void {
            $page->where('operation.occurred_at', '<', $row->occurred_at)
                ->orWhere(function (Builder $sameTime) use ($row): void {
                    $sameTime->where('operation.occurred_at', '=', $row->occurred_at)
                        ->where('operation.id', '<', $row->id);
                });
        });
    }

    /** @return array{label: string} */
    private function reason(string $sourceType, string $operationType): array
    {
        $label = match (true) {
            $operationType === 'point_expire' => 'ポイント失効',
            $sourceType === 'payment' => 'ポイント購入',
            $sourceType === 'draw' && $operationType === 'free_grant' => 'ガチャポイント還元',
            $sourceType === 'draw' => 'ガチャ利用',
            $sourceType === 'prize_exchange' => '景品のポイント交換',
            $sourceType === 'admin_adjustment' => 'ポイント調整',
            $sourceType === 'payment_adjustment' => 'ポイント購入の調整',
            $sourceType === 'line_friend' => 'LINE友だち特典',
            $sourceType === 'referral' => '紹介特典',
            $operationType === 'free_grant', $operationType === 'paid_grant' => 'ポイント付与',
            default => 'その他',
        };

        return ['label' => $label];
    }

    private function limit(int $limit): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new V2PointReadException(
                'INVALID_PAGINATION',
                422,
                'The pagination input is invalid.'
            );
        }

        return $limit;
    }

    private function encodeCursor(string $publicId): string
    {
        return rtrim(strtr(base64_encode($publicId), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,128}$/', $cursor)) {
            throw $this->invalidCursor();
        }
        $decoded = base64_decode(
            strtr($cursor, '-_', '+/').str_repeat('=', (4 - strlen($cursor) % 4) % 4),
            true
        );
        if (! is_string($decoded) || ! Str::isUuid($decoded)) {
            throw $this->invalidCursor();
        }

        return $decoded;
    }

    private function invalidCursor(): V2PointReadException
    {
        return new V2PointReadException('INVALID_CURSOR', 422, 'The cursor is invalid.');
    }
}
