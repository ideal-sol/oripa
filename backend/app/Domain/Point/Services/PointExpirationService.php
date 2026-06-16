<?php

namespace App\Domain\Point\Services;

use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class PointExpirationService
{
    /**
     * @return array{expired_lot_count: int, expired_point_amount: int}
     */
    public function expire(int $limit = 1000): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Expiration limit must be greater than zero.');
        }

        return DB::transaction(function () use ($limit): array {
            $lots = PointLot::query()
                ->where('point_type', PointType::Free->value)
                ->where('remaining_amount', '>', 0)
                ->where('expire_at', '<=', now())
                ->orderBy('expire_at')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $expiredLotCount = 0;
            $expiredPointAmount = 0;

            foreach ($lots as $lot) {
                /** @var Wallet $wallet */
                $wallet = Wallet::query()
                    ->where('user_id', $lot->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $amount = min((int) $lot->remaining_amount, (int) $wallet->free_balance);

                $lot->forceFill([
                    'remaining_amount' => 0,
                ])->save();

                if ($amount <= 0) {
                    continue;
                }

                $wallet->forceFill([
                    'free_balance' => (int) $wallet->free_balance - $amount,
                ])->save();

                PointLedger::query()->create([
                    'user_id' => $lot->user_id,
                    'wallet_id' => $wallet->id,
                    'point_lot_id' => $lot->id,
                    'point_type' => PointType::Free,
                    'ledger_type' => PointLedgerType::Expire,
                    'amount' => -$amount,
                    'balance_after' => $wallet->free_balance,
                    'related_type' => 'point_lot',
                    'related_id' => $lot->id,
                    'description' => 'Free points expired.',
                ]);

                $expiredLotCount++;
                $expiredPointAmount += $amount;
            }

            return [
                'expired_lot_count' => $expiredLotCount,
                'expired_point_amount' => $expiredPointAmount,
            ];
        });
    }
}
