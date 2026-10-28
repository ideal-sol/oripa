<?php

namespace App\Domain\Identity\Services;

use App\Support\V2DatabaseTimestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Caller holds the user lock inside the financial admission transaction. */
final class V2AccountClosurePoints
{
    public function forfeit(int $userId): int
    {
        $this->requireTransaction();
        $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
        if ($wallet === null) return 0;
        $lots = DB::table('point_lots')->where('user_id', $userId)->orderBy('id')->lockForUpdate()->get();
        $total = 0;
        foreach ($lots as $lot) {
            $amount = (int) $lot->remaining_amount - (int) $lot->reserved_amount;
            if ($amount > 0) {
                $this->entry($wallet, $lot, -$amount, 'forfeit');
                $total += $amount;
            }
        }

        return $total;
    }

    public function outstanding(int $lotId): int
    {
        return max(0, -(int) DB::table('point_ledger_entries as l')
            ->join('point_operations as o', 'o.id', '=', 'l.point_operation_id')
            ->where('l.point_lot_id', $lotId)->where('o.source_type', 'account_closure')
            ->whereIn('l.entry_type', ['forfeit', 'restore'])->sum('l.amount_delta'));
    }

    /** Restore only closure forfeitures, inside the same transaction as reservation/debit. */
    public function restoreForAdjustment(int $userId, iterable $lots): void
    {
        $this->requireTransaction();
        if (! DB::table('account_closures')->where('user_id', $userId)->exists()) return;
        $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->firstOrFail();
        foreach ($lots as $lot) {
            $amount = $this->outstanding((int) $lot->id);
            if ($amount > 0) $this->entry($wallet, $lot, $amount, 'restore');
        }
    }

    private function entry(object $wallet, object $lot, int $delta, string $type): void
    {
        $now = now()->startOfSecond();
        $at = V2DatabaseTimestamp::format($now);
        $date = $now->copy()->timezone('Asia/Tokyo')->toDateString();
        $id = DB::table('point_operations')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'user_id' => $lot->user_id,
            'operation_type' => $type === 'forfeit' ? 'account_closed' : 'closure_adjustment_restore',
            'business_key' => 'account.closure:'.Str::uuid7(), 'source_type' => 'account_closure',
            'source_id' => $lot->user_id, 'actor_type' => 'system', 'is_qa' => false,
            'occurred_at' => $at, 'business_date' => $date,
            'metadata' => json_encode(['reason' => 'account_closed'], JSON_THROW_ON_ERROR), 'created_at' => $at,
        ]);
        $column = $lot->point_type.'_balance';
        $wallet->{$column} = (int) $wallet->{$column} + $delta;
        $lot->remaining_amount = (int) $lot->remaining_amount + $delta;
        DB::table('point_lots')->where('id', $lot->id)->update(['remaining_amount' => $lot->remaining_amount, 'updated_at' => $at]);
        DB::table('wallets')->where('id', $wallet->id)->update([
            $column => $wallet->{$column}, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => $at,
        ]);
        DB::table('point_ledger_entries')->insert([
            'point_operation_id' => $id, 'sequence_no' => 1, 'user_id' => $lot->user_id,
            'wallet_id' => $wallet->id, 'point_lot_id' => $lot->id, 'point_type' => $lot->point_type,
            'entry_type' => $type, 'amount_delta' => $delta, 'wallet_balance_after' => $wallet->{$column},
            'lot_remaining_after' => $lot->remaining_amount, 'occurred_at' => $at,
            'business_date' => $date, 'created_at' => $at,
        ]);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Account closure requires a transaction.');
    }
}
