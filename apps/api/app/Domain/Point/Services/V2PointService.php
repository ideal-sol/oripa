<?php

namespace App\Domain\Point\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Point\Exceptions\V2PointException;
use App\Models\V2\PointLedgerEntry;
use App\Models\V2\PointLot;
use App\Models\V2\PointOperation;
use App\Models\V2\User;
use App\Models\V2\Wallet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class V2PointService
{
    public function __construct(
        private readonly V2PointTransactionRunner $transactions,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function initializeWallet(int $userId): Wallet
    {
        return $this->transactions->run(function () use ($userId): Wallet {
            $user = User::query()->whereKey($userId)->firstOrFail();
            $inserted = DB::table('wallets')->insertOrIgnore([
                'user_id' => $userId,
                'paid_balance' => 0,
                'free_balance' => 0,
                'paid_reserved_balance' => 0,
                'free_reserved_balance' => 0,
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $wallet = Wallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if ($inserted === 1) {
                $this->audit->record('point.wallet_initialized', [
                    'target_type' => 'user_wallet',
                    'target_public_id' => $user->public_id,
                    'metadata' => ['paid_balance' => 0, 'free_balance' => 0],
                ]);
            }

            return $wallet;
        });
    }

    public function grantFree(
        int $userId,
        int $amount,
        CarbonInterface $expireAt,
        string $idempotencyKey,
        ?CarbonInterface $occurredAt = null
    ): PointOperation {
        if ($amount <= 0) {
            throw new V2PointException('Free point grant amount must be positive.');
        }
        $occurred = CarbonImmutable::parse($occurredAt ?? now())->startOfSecond();
        $expiry = CarbonImmutable::parse($expireAt)->startOfSecond();
        if ($expiry->lessThanOrEqualTo($occurred)) {
            throw new V2PointException('Free point expiry must be after grant time.');
        }

        return $this->transactions->run(function () use (
            $userId,
            $amount,
            $expiry,
            $idempotencyKey,
            $occurred
        ): PointOperation {
            $user = User::query()->whereKey($userId)->firstOrFail();
            $wallet = $this->lockWallet($userId);
            $claim = $this->idempotency->claim(
                'point.free_grant',
                'system',
                $user->public_id,
                $idempotencyKey,
                [
                    'amount' => $amount,
                    'expire_at' => $expiry->utc()->toIso8601String(),
                ]
            );
            if ($claim->replay) {
                return PointOperation::query()
                    ->where('public_id', $claim->record->resource_public_id)
                    ->firstOrFail();
            }

            $before = (int) $wallet->free_balance;
            $operation = $this->operation(
                $userId,
                'free_grant',
                'system',
                'point.free_grant:'.$claim->record->key_hash,
                $occurred
            );
            $lot = new PointLot();
            $lot->forceFill([
                'user_id' => $userId,
                'grant_operation_id' => $operation->id,
                'point_type' => 'free',
                'granted_amount' => $amount,
                'remaining_amount' => $amount,
                'reserved_amount' => 0,
                'granted_at' => $occurred,
                'expire_at' => $expiry,
            ])->save();
            $wallet->forceFill([
                'free_balance' => $before + $amount,
                'lock_version' => (int) $wallet->lock_version + 1,
            ])->save();
            $this->ledger(
                $operation,
                $wallet,
                $lot,
                1,
                'free',
                'grant',
                $amount,
                (int) $wallet->free_balance,
                $amount,
                $occurred
            );
            $this->idempotency->complete(
                $claim->record,
                'point_operation',
                $operation->public_id
            );
            $this->audit->record('point.free_granted', [
                'target_type' => 'user_wallet',
                'target_public_id' => $user->public_id,
                'before' => ['free_balance' => $before],
                'after' => ['free_balance' => (int) $wallet->free_balance],
                'metadata' => [
                    'amount' => $amount,
                    'operation_public_id' => $operation->public_id,
                ],
            ]);

            return $operation;
        });
    }

    public function consume(
        int $userId,
        int $amount,
        string $idempotencyKey,
        ?CarbonInterface $occurredAt = null
    ): PointOperation {
        if ($amount <= 0) {
            throw new V2PointException('Point consumption amount must be positive.');
        }
        $occurred = CarbonImmutable::parse($occurredAt ?? now())->startOfSecond();

        return $this->transactions->run(function () use (
            $userId,
            $amount,
            $idempotencyKey,
            $occurred
        ): PointOperation {
            $user = User::query()->whereKey($userId)->firstOrFail();
            $wallet = $this->lockWallet($userId);
            $claim = $this->idempotency->claim(
                'point.consume',
                'user',
                $user->public_id,
                $idempotencyKey,
                ['amount' => $amount]
            );
            if ($claim->replay) {
                return PointOperation::query()
                    ->where('public_id', $claim->record->resource_public_id)
                    ->firstOrFail();
            }
            $availablePaid = (int) $wallet->paid_balance
                - (int) $wallet->paid_reserved_balance;
            $freeLots = $this->lockFreeLots($userId, $occurred);
            $availableFree = $freeLots->sum(
                fn (PointLot $lot): int =>
                    (int) $lot->remaining_amount - (int) $lot->reserved_amount
            );
            if ($availableFree + $availablePaid < $amount) {
                throw new V2PointException('INSUFFICIENT_POINT_BALANCE');
            }
            $freeToConsume = min($amount, $availableFree);
            $paidToConsume = $amount - $freeToConsume;
            $paidLots = $paidToConsume > 0
                ? $this->lockPaidLots($userId)
                : collect();
            $operation = $this->operation(
                $userId,
                'spend',
                'point',
                'point.consume:'.$claim->record->key_hash,
                $occurred,
                'user',
                $userId
            );
            $sequence = 1;
            $remainingFree = $freeToConsume;
            $runningFree = (int) $wallet->free_balance;
            $this->consumeLots(
                $freeLots,
                $remainingFree,
                $operation,
                $wallet,
                'free',
                $runningFree,
                $sequence,
                $occurred
            );
            $remainingPaid = $paidToConsume;
            $runningPaid = (int) $wallet->paid_balance;
            $this->consumeLots(
                $paidLots,
                $remainingPaid,
                $operation,
                $wallet,
                'paid',
                $runningPaid,
                $sequence,
                $occurred
            );
            if ($remainingFree !== 0 || $remainingPaid !== 0) {
                throw new V2PointException('Locked point lots do not match wallet availability.');
            }

            $before = [
                'paid_balance' => (int) $wallet->paid_balance,
                'free_balance' => (int) $wallet->free_balance,
            ];
            $wallet->forceFill([
                'paid_balance' => $runningPaid,
                'free_balance' => $runningFree,
                'lock_version' => (int) $wallet->lock_version + 1,
            ])->save();
            $this->idempotency->complete(
                $claim->record,
                'point_operation',
                $operation->public_id
            );
            $this->audit->record('point.consumed', [
                'actor_type' => 'user',
                'actor_public_id' => $user->public_id,
                'auth_realm' => 'user',
                'target_type' => 'user_wallet',
                'target_public_id' => $user->public_id,
                'before' => $before,
                'after' => [
                    'paid_balance' => $runningPaid,
                    'free_balance' => $runningFree,
                ],
                'metadata' => [
                    'amount' => $amount,
                    'free_amount' => $freeToConsume,
                    'paid_amount' => $paidToConsume,
                    'operation_public_id' => $operation->public_id,
                ],
            ]);

            return $operation;
        });
    }

    public function expireFree(CarbonInterface $cutoff): int
    {
        $cutoffAt = CarbonImmutable::parse($cutoff)->startOfSecond();
        $userIds = PointLot::query()
            ->where('point_type', 'free')
            ->where('expire_at', '<=', $cutoffAt)
            ->where('remaining_amount', '>', 0)
            ->where('reserved_amount', 0)
            ->orderBy('user_id')
            ->distinct()
            ->pluck('user_id');
        $expiredLots = 0;
        foreach ($userIds as $userId) {
            $expiredLots += $this->transactions->run(
                fn (): int => $this->expireUserFree((int) $userId, $cutoffAt)
            );
        }

        return $expiredLots;
    }

    private function expireUserFree(int $userId, CarbonImmutable $cutoff): int
    {
        $user = User::query()->whereKey($userId)->firstOrFail();
        $wallet = $this->lockWallet($userId);
        $lots = PointLot::query()
            ->where('user_id', $userId)
            ->where('point_type', 'free')
            ->where('expire_at', '<=', $cutoff)
            ->where('remaining_amount', '>', 0)
            ->where('reserved_amount', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $count = 0;
        foreach ($lots as $lot) {
            $amount = (int) $lot->remaining_amount;
            $operation = $this->operation(
                $userId,
                'free_expire',
                'point',
                'point.free_expire:'.$lot->id,
                $cutoff
            );
            $before = (int) $wallet->free_balance;
            $lot->forceFill(['remaining_amount' => 0])->save();
            $wallet->forceFill([
                'free_balance' => $before - $amount,
                'lock_version' => (int) $wallet->lock_version + 1,
            ])->save();
            $this->ledger(
                $operation,
                $wallet,
                $lot,
                1,
                'free',
                'expire',
                -$amount,
                (int) $wallet->free_balance,
                0,
                $cutoff
            );
            $this->audit->record('point.free_expired', [
                'target_type' => 'user_wallet',
                'target_public_id' => $user->public_id,
                'before' => ['free_balance' => $before],
                'after' => ['free_balance' => (int) $wallet->free_balance],
                'metadata' => [
                    'amount' => $amount,
                    'operation_public_id' => $operation->public_id,
                ],
            ]);
            $count++;
        }

        return $count;
    }

    private function lockWallet(int $userId): Wallet
    {
        DB::table('wallets')->insertOrIgnore([
            'user_id' => $userId,
            'paid_balance' => 0,
            'free_balance' => 0,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Wallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return Collection<int, PointLot>
     */
    private function lockFreeLots(int $userId, CarbonImmutable $occurred): Collection
    {
        return PointLot::query()
            ->where('user_id', $userId)
            ->where('point_type', 'free')
            ->where('expire_at', '>', $occurred)
            ->whereColumn('remaining_amount', '>', 'reserved_amount')
            ->orderBy('expire_at')
            ->orderBy('granted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, PointLot>
     */
    private function lockPaidLots(int $userId): Collection
    {
        return PointLot::query()
            ->where('user_id', $userId)
            ->where('point_type', 'paid')
            ->whereColumn('remaining_amount', '>', 'reserved_amount')
            ->orderBy('granted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param Collection<int, PointLot> $lots
     */
    private function consumeLots(
        Collection $lots,
        int &$remaining,
        PointOperation $operation,
        Wallet $wallet,
        string $pointType,
        int &$runningBalance,
        int &$sequence,
        CarbonImmutable $occurred
    ): void {
        foreach ($lots as $lot) {
            if ($remaining === 0) {
                break;
            }
            $available = (int) $lot->remaining_amount - (int) $lot->reserved_amount;
            $used = min($remaining, $available);
            if ($used <= 0) {
                continue;
            }
            $lotRemaining = (int) $lot->remaining_amount - $used;
            $runningBalance -= $used;
            if ($runningBalance < 0 || $lotRemaining < 0) {
                throw new V2PointException('Point consumption would create a negative balance.');
            }
            $lot->forceFill(['remaining_amount' => $lotRemaining])->save();
            $this->ledger(
                $operation,
                $wallet,
                $lot,
                $sequence++,
                $pointType,
                'spend',
                -$used,
                $runningBalance,
                $lotRemaining,
                $occurred
            );
            $remaining -= $used;
        }
    }

    private function operation(
        int $userId,
        string $type,
        string $sourceType,
        string $businessKey,
        CarbonImmutable $occurred,
        string $actorType = 'system',
        ?int $actorId = null
    ): PointOperation {
        $operation = new PointOperation();
        $operation->forceFill([
            'user_id' => $userId,
            'operation_type' => $type,
            'business_key' => $businessKey,
            'source_type' => $sourceType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'is_qa' => false,
            'occurred_at' => $occurred,
            'business_date' => $occurred->setTimezone('Asia/Tokyo')->toDateString(),
            'metadata' => (object) [],
        ])->save();

        return $operation;
    }

    private function ledger(
        PointOperation $operation,
        Wallet $wallet,
        PointLot $lot,
        int $sequence,
        string $pointType,
        string $entryType,
        int $delta,
        int $walletAfter,
        int $lotAfter,
        CarbonImmutable $occurred
    ): void {
        $entry = new PointLedgerEntry();
        $entry->forceFill([
            'point_operation_id' => $operation->id,
            'sequence_no' => $sequence,
            'user_id' => $operation->user_id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => $lot->id,
            'point_type' => $pointType,
            'entry_type' => $entryType,
            'amount_delta' => $delta,
            'wallet_balance_after' => $walletAfter,
            'lot_remaining_after' => $lotAfter,
            'occurred_at' => $occurred,
            'business_date' => $occurred->setTimezone('Asia/Tokyo')->toDateString(),
        ])->save();
    }
}
