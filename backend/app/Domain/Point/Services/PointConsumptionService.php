<?php

namespace App\Domain\Point\Services;

use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Point\Exceptions\InsufficientPointsException;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;

class PointConsumptionService
{
    public function assertSpendable(User $user, int $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Consumed point amount must not be negative.');
        }

        $wallet = Wallet::query()->where('user_id', $user->id)->first();
        $balance = $wallet ? (int) $wallet->paid_balance + (int) $wallet->free_balance : 0;

        if ($balance < $amount) {
            throw new InsufficientPointsException('Point balance is insufficient.');
        }
    }

    public function consume(User $user, int $amount, string $relatedType, int $relatedId, ?string $description = null): array
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Consumed point amount must not be negative.');
        }

        if ($amount === 0) {
            return [];
        }

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertSpendableWithWallet($wallet, $amount);

        $remaining = $amount;
        $consumptions = [];

        // 無償ポイントは期限があるため、有効期限が近いロットから先に消費する。
        foreach ($this->lockedConsumableLots($user, PointType::Free) as $lot) {
            $remaining = $this->consumeFromLot($wallet, $lot, $remaining, $relatedType, $relatedId, $description, $consumptions);

            if ($remaining === 0) {
                return $consumptions;
            }
        }

        // 有償ポイントは期限なしの方針なので、無償ポイントで不足した分だけ消費する。
        foreach ($this->lockedConsumableLots($user, PointType::Paid) as $lot) {
            $remaining = $this->consumeFromLot($wallet, $lot, $remaining, $relatedType, $relatedId, $description, $consumptions);

            if ($remaining === 0) {
                return $consumptions;
            }
        }

        throw new InsufficientPointsException('Point lot balance is insufficient.');
    }

    private function assertSpendableWithWallet(Wallet $wallet, int $amount): void
    {
        if ((int) $wallet->paid_balance + (int) $wallet->free_balance < $amount) {
            throw new InsufficientPointsException('Point balance is insufficient.');
        }
    }

    private function lockedConsumableLots(User $user, PointType $pointType): iterable
    {
        $query = PointLot::query()
            ->where('user_id', $user->id)
            ->where('point_type', $pointType->value)
            ->where('remaining_amount', '>', 0)
            ->lockForUpdate();

        if ($pointType === PointType::Free) {
            $query
                ->where('expire_at', '>', now())
                ->orderBy('expire_at')
                ->orderBy('granted_at')
                ->orderBy('id');
        } else {
            $query
                ->orderBy('granted_at')
                ->orderBy('id');
        }

        return $query->get();
    }

    private function consumeFromLot(
        Wallet $wallet,
        PointLot $lot,
        int $remaining,
        string $relatedType,
        int $relatedId,
        ?string $description,
        array &$consumptions,
    ): int {
        $amount = min($remaining, (int) $lot->remaining_amount);

        if ($amount <= 0) {
            return $remaining;
        }

        // ロット残高、ウォレット残高、台帳を同じトランザクション内で更新する。
        $lot->forceFill([
            'remaining_amount' => (int) $lot->remaining_amount - $amount,
        ])->save();

        $balanceColumn = $lot->point_type === PointType::Paid ? 'paid_balance' : 'free_balance';
        $wallet->forceFill([
            $balanceColumn => (int) $wallet->{$balanceColumn} - $amount,
        ])->save();

        PointLedger::query()->create([
            'user_id' => $wallet->user_id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => $lot->id,
            'point_type' => $lot->point_type,
            'ledger_type' => PointLedgerType::Spend,
            'amount' => -$amount,
            'balance_after' => $wallet->{$balanceColumn},
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'description' => $description,
        ]);

        $consumptions[] = [
            'lot_id' => $lot->id,
            'point_type' => $lot->point_type instanceof PointType ? $lot->point_type->value : (string) $lot->point_type,
            'amount' => $amount,
        ];

        return $remaining - $amount;
    }
}
