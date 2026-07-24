<?php

namespace App\Domain\Point\Services;

use App\Domain\Point\Exceptions\V2PointException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class V2PointLedgerService
{
    /**
     * @return array{paid: int, free: int}
     */
    public function rebuild(int $userId, ?CarbonInterface $cutoff = null): array
    {
        $query = DB::table('point_ledger_entries')
            ->selectRaw('point_type, COALESCE(SUM(amount_delta), 0) AS balance')
            ->where('user_id', $userId)
            ->groupBy('point_type');
        if ($cutoff !== null) {
            $query->where('occurred_at', '<', $cutoff);
        }
        $balances = ['paid' => 0, 'free' => 0];
        foreach ($query->get() as $row) {
            $balances[$row->point_type] = (int) $row->balance;
        }
        if ($balances['paid'] < 0 || $balances['free'] < 0) {
            throw new V2PointException('Ledger reconstruction produced a negative balance.');
        }

        return $balances;
    }
}
