<?php

namespace App\Domain\Point\Services;

use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PointLotService
{
    public function grantPaid(
        User $user,
        int $amount,
        PointLotSourceType $sourceType = PointLotSourceType::Purchase,
        ?int $sourceId = null,
        ?string $description = null,
        PointLedgerType $ledgerType = PointLedgerType::Purchase,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): PointLot {
        return $this->grant(
            user: $user,
            pointType: PointType::Paid,
            amount: $amount,
            sourceType: $sourceType,
            sourceId: $sourceId,
            expireAt: null,
            ledgerType: $ledgerType,
            relatedType: $relatedType ?? $sourceType->value,
            relatedId: $relatedId ?? $sourceId,
            description: $description,
        );
    }

    public function grantFree(
        User $user,
        int $amount,
        CarbonInterface $expireAt,
        PointLotSourceType $sourceType,
        ?int $sourceId = null,
        PointLedgerType $ledgerType = PointLedgerType::Grant,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?string $description = null,
    ): PointLot {
        return $this->grant(
            user: $user,
            pointType: PointType::Free,
            amount: $amount,
            sourceType: $sourceType,
            sourceId: $sourceId,
            expireAt: $expireAt,
            ledgerType: $ledgerType,
            relatedType: $relatedType ?? $sourceType->value,
            relatedId: $relatedId ?? $sourceId,
            description: $description,
        );
    }

    /**
     * @param array<int, array{amount: int, source_id: int, related_id: int, description: string}> $grants
     * @return array<int, PointLot>
     */
    public function grantFreeBatch(
        User $user,
        array $grants,
        CarbonInterface $expireAt,
        PointLotSourceType $sourceType,
        PointLedgerType $ledgerType = PointLedgerType::Grant,
        string $relatedType = 'draw_result',
    ): array {
        if ($grants === []) {
            return [];
        }

        $wallet = Wallet::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['paid_balance' => 0, 'free_balance' => 0],
        );
        $balanceAfter = (int) $wallet->free_balance;
        $timestamp = now();
        $lotRows = [];

        foreach ($grants as $grant) {
            if ($grant['amount'] <= 0) {
                throw new \InvalidArgumentException('Granted point amount must be greater than zero.');
            }

            $lotRows[] = [
                'user_id' => $user->id,
                'point_type' => PointType::Free->value,
                'granted_amount' => $grant['amount'],
                'remaining_amount' => $grant['amount'],
                'source_type' => $sourceType->value,
                'source_id' => $grant['source_id'],
                'granted_at' => $timestamp,
                'expire_at' => $expireAt,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($lotRows, 250) as $chunk) {
            DB::table('point_lots')->insert($chunk);
        }

        $lotsBySourceId = PointLot::query()
            ->where('user_id', $user->id)
            ->where('source_type', $sourceType)
            ->whereIn('source_id', array_column($grants, 'source_id'))
            ->get()
            ->keyBy('source_id');
        $ledgerRows = [];
        $lots = [];

        foreach ($grants as $grant) {
            /** @var PointLot|null $lot */
            $lot = $lotsBySourceId->get($grant['source_id']);

            if (! $lot) {
                throw new \RuntimeException('Bulk point lot could not be matched to its source.');
            }

            $balanceAfter += $grant['amount'];

            $ledgerRows[] = [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'point_lot_id' => $lot->id,
                'point_type' => PointType::Free->value,
                'ledger_type' => $ledgerType->value,
                'amount' => $grant['amount'],
                'balance_after' => $balanceAfter,
                'related_type' => $relatedType,
                'related_id' => $grant['related_id'],
                'description' => $grant['description'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $lots[] = $lot;
        }

        foreach (array_chunk($ledgerRows, 250) as $chunk) {
            DB::table('point_ledgers')->insert($chunk);
        }

        $wallet->forceFill(['free_balance' => $balanceAfter])->save();

        return $lots;
    }

    private function grant(
        User $user,
        PointType $pointType,
        int $amount,
        PointLotSourceType $sourceType,
        ?int $sourceId,
        ?CarbonInterface $expireAt,
        PointLedgerType $ledgerType,
        ?string $relatedType,
        ?int $relatedId,
        ?string $description,
    ): PointLot {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Granted point amount must be greater than zero.');
        }

        $wallet = Wallet::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['paid_balance' => 0, 'free_balance' => 0],
        );

        $lot = PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'granted_at' => now(),
            'expire_at' => $expireAt,
        ]);

        $balanceColumn = $pointType === PointType::Paid ? 'paid_balance' : 'free_balance';
        $wallet->forceFill([
            $balanceColumn => $wallet->{$balanceColumn} + $amount,
        ])->save();

        PointLedger::query()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => $lot->id,
            'point_type' => $pointType,
            'ledger_type' => $ledgerType,
            'amount' => $amount,
            'balance_after' => $wallet->{$balanceColumn},
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'description' => $description,
        ]);

        return $lot;
    }
}
