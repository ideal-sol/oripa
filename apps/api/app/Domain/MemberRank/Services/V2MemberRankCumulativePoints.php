<?php

namespace App\Domain\MemberRank\Services;

use Illuminate\Support\Facades\DB;

final class V2MemberRankCumulativePoints
{
    public function components(int $userId): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT
                (SELECT COALESCE(sum(points), 0)::text FROM member_rank_month_totals WHERE user_id = ?) AS entries,
                (SELECT COALESCE(sum(points), 0)::text FROM member_rank_transfer_allowances WHERE user_id = ?) AS transfer,
                (SELECT COALESCE(sum(delta), 0)::text FROM member_rank_compensation_entries WHERE user_id = ?) AS compensation
            SQL, [$userId, $userId, $userId]);

        return array_map($this->integer(...), (array) $row);
    }

    public function total(int $userId, bool $includeTransfer = true, bool $clamp = true): int
    {
        $parts = $this->components($userId);
        if (! $includeTransfer) {
            $parts['transfer'] = 0;
        }

        return $this->sum($parts, $clamp);
    }

    public function sum(array $parts, bool $clamp = true): int
    {
        $total = 0;
        foreach ($parts as $part) {
            $total += $this->integer($part);
            if (! is_int($total)) {
                throw new \OverflowException('Cumulative member rank points exceed the supported integer range.');
            }
        }

        return $clamp ? max(0, $total) : $total;
    }

    private function integer(mixed $value): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false) {
            throw new \OverflowException('Cumulative member rank points exceed the supported integer range.');
        }

        return $integer;
    }
}
