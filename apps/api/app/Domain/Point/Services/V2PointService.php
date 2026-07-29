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
use Illuminate\Support\Str;

final class V2PointService
{
    public const MAX_LINE_FRIEND_REWARD_AMOUNT = 1_000_000;

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

    public function grantLineFriendReward(
        int $userId,
        int $friendshipId,
        string $friendshipPublicId,
        int $amount,
        CarbonInterface $expireAt,
        CarbonInterface $occurredAt
    ): ?PointOperation {
        if ($amount === 0) {
            return null;
        }
        if (
            DB::transactionLevel() < 1
            || $userId < 1
            || $friendshipId < 1
            || ! Str::isUuid($friendshipPublicId)
            || $amount < 1
            || $amount > self::MAX_LINE_FRIEND_REWARD_AMOUNT
        ) {
            throw new V2PointException('LINE friend reward input is invalid.');
        }
        $occurred = CarbonImmutable::parse($occurredAt)->startOfSecond();
        $expiry = CarbonImmutable::parse($expireAt)->startOfSecond();
        if ($expiry->lessThanOrEqualTo($occurred)) {
            throw new V2PointException('LINE friend reward expiry is invalid.');
        }
        $businessKey = 'line.friend_reward:'.$friendshipPublicId;
        $existing = PointOperation::query()
            ->where('business_key', $businessKey)
            ->first();
        if ($existing instanceof PointOperation) {
            return $existing;
        }

        $user = User::query()->whereKey($userId)->firstOrFail();
        $wallet = $this->lockWallet($userId);
        $before = (int) $wallet->free_balance;
        $operation = $this->operation(
            $userId,
            'free_grant',
            'line_friend',
            $businessKey,
            $occurred,
            'webhook',
            null,
            $friendshipId
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
        $this->audit->record('point.line_friend_granted', [
            'actor_type' => 'system',
            'auth_realm' => 'system',
            'target_type' => 'line_friendship',
            'target_public_id' => $friendshipPublicId,
            'before' => ['free_balance' => $before],
            'after' => ['free_balance' => (int) $wallet->free_balance],
            'metadata' => [
                'amount' => $amount,
                'operation_public_id' => $operation->public_id,
                'user_public_id' => $user->public_id,
            ],
        ]);

        return $operation;
    }

    /**
     * Prize Exchange Transaction内でfree Pointを付与する。
     *
     * @return array{operation: PointOperation, wallet_free_after: int}
     */
    public function grantPrizeExchange(
        int $userId,
        int $exchangeRequestId,
        string $exchangeRequestPublicId,
        int $amount,
        CarbonInterface $expireAt,
        CarbonInterface $occurredAt
    ): array {
        if (
            DB::transactionLevel() < 1
            || $userId < 1
            || $exchangeRequestId < 1
            || ! Str::isUuid($exchangeRequestPublicId)
            || $amount <= 0
        ) {
            throw new V2PointException('Prize exchange point grant input is invalid.');
        }
        $occurred = CarbonImmutable::parse($occurredAt)->startOfSecond();
        $expiry = CarbonImmutable::parse($expireAt)->startOfSecond();
        if ($expiry->lessThanOrEqualTo($occurred)) {
            throw new V2PointException('Prize exchange point expiry is invalid.');
        }

        $wallet = $this->lockWallet($userId);
        $before = (int) $wallet->free_balance;
        $operation = $this->operation(
            $userId,
            'free_grant',
            'prize_exchange',
            'prize.exchange:'.$exchangeRequestPublicId,
            $occurred,
            'user',
            $userId,
            $exchangeRequestId
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
        $this->audit->record('point.prize_exchange_granted', [
            'actor_type' => 'user',
            'auth_realm' => 'user',
            'target_type' => 'prize_exchange_request',
            'target_public_id' => $exchangeRequestPublicId,
            'before' => ['free_balance' => $before],
            'after' => ['free_balance' => (int) $wallet->free_balance],
            'metadata' => [
                'amount' => $amount,
                'operation_public_id' => $operation->public_id,
            ],
        ]);

        return [
            'operation' => $operation,
            'wallet_free_after' => (int) $wallet->free_balance,
        ];
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

    /**
     * QA DrawのInventory検証前に既存順序でWallet／LotをLockする。
     */
    public function lockAndValidateForDraw(
        int $userId,
        int $amount,
        CarbonInterface $occurredAt
    ): void {
        if (DB::transactionLevel() < 1 || $amount <= 0) {
            throw new V2PointException('Draw point validation input is invalid.');
        }
        $occurred = CarbonImmutable::parse($occurredAt)->startOfSecond();
        $wallet = $this->lockWallet($userId);
        $freeLots = $this->lockFreeLots($userId, $occurred);
        $availableFree = $freeLots->sum(
            fn (PointLot $lot): int =>
                (int) $lot->remaining_amount - (int) $lot->reserved_amount
        );
        $availablePaid = (int) $wallet->paid_balance
            - (int) $wallet->paid_reserved_balance;
        if ($availableFree + $availablePaid < $amount) {
            throw new V2PointException('INSUFFICIENT_POINT_BALANCE');
        }
        if ($availablePaid > 0) {
            $this->lockPaidLots($userId);
        }
    }

    /**
     * Draw Transaction内で既存のfree優先／paid FIFO規則を適用する。
     *
     * @return array{
     *   operation: PointOperation,
     *   paid: int,
     *   free: int,
     *   wallet_paid_after: int,
     *   wallet_free_after: int
     * }
     */
    public function consumeForDraw(
        int $userId,
        int $amount,
        int $drawRequestId,
        string $drawRequestPublicId,
        CarbonInterface $occurredAt
    ): array {
        if (
            DB::transactionLevel() < 1
            || $amount <= 0
            || $drawRequestId < 1
            || ! Str::isUuid($drawRequestPublicId)
        ) {
            throw new V2PointException('Draw point consumption input is invalid.');
        }
        $occurred = CarbonImmutable::parse($occurredAt)->startOfSecond();
        $wallet = $this->lockWallet($userId);
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
            'draw',
            'draw.consume:'.$drawRequestPublicId,
            $occurred,
            'user',
            $userId,
            $drawRequestId
        );
        $sequence = 1;
        $remainingFree = $freeToConsume;
        $runningFree = (int) $wallet->free_balance;
        $this->consumeLotsForDraw(
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
        $this->consumeLotsForDraw(
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
        $wallet->forceFill([
            'paid_balance' => $runningPaid,
            'free_balance' => $runningFree,
            'lock_version' => (int) $wallet->lock_version + 1,
        ])->save();

        return [
            'operation' => $operation,
            'paid' => $paidToConsume,
            'free' => $freeToConsume,
            'wallet_paid_after' => $runningPaid,
            'wallet_free_after' => $runningFree,
        ];
    }

    /**
     * @param list<array{
     *   draw_result_id: int,
     *   draw_result_public_id: string,
     *   amount: int
     * }> $grants
     * @return array{total: int, wallet_free_after: int}
     */
    public function grantDrawPointBackBatch(
        int $userId,
        int $drawRequestId,
        string $drawRequestPublicId,
        array $grants,
        CarbonInterface $occurredAt
    ): array {
        if (
            DB::transactionLevel() < 1
            || $drawRequestId < 1
            || ! Str::isUuid($drawRequestPublicId)
        ) {
            throw new V2PointException('Draw point-back input is invalid.');
        }
        if ($grants === []) {
            $wallet = $this->lockWallet($userId);

            return ['total' => 0, 'wallet_free_after' => (int) $wallet->free_balance];
        }

        $occurred = CarbonImmutable::parse($occurredAt)->startOfSecond();
        $expiry = $occurred->addDays((int) config('v2_draw.point_back_expiry_days', 180));
        $businessDate = $occurred->setTimezone('Asia/Tokyo')->toDateString();
        $wallet = $this->lockWallet($userId);
        $operationRows = [];
        $operationKeys = [];
        $total = 0;
        foreach ($grants as $grant) {
            if (
                ! isset(
                    $grant['draw_result_id'],
                    $grant['draw_result_public_id'],
                    $grant['amount']
                )
                || ! is_int($grant['draw_result_id'])
                || $grant['draw_result_id'] < 1
                || ! is_string($grant['draw_result_public_id'])
                || ! Str::isUuid($grant['draw_result_public_id'])
                || ! is_int($grant['amount'])
                || $grant['amount'] < 0
            ) {
                throw new V2PointException('Draw point-back grant is invalid.');
            }
            if ($grant['amount'] === 0) {
                continue;
            }
            $businessKey = 'draw.point_back:'.$grant['draw_result_public_id'];
            $operationKeys[$grant['draw_result_id']] = $businessKey;
            $operationRows[] = [
                'public_id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'operation_type' => 'free_grant',
                'business_key' => $businessKey,
                'source_type' => 'draw',
                'source_id' => $drawRequestId,
                'actor_type' => 'system',
                'actor_id' => null,
                'is_qa' => false,
                'qa_draw_execution_id' => null,
                'occurred_at' => $occurred,
                'business_date' => $businessDate,
                'metadata' => json_encode([
                    'draw_request_public_id' => $drawRequestPublicId,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $occurred,
            ];
            $total += $grant['amount'];
        }
        if ($operationRows === []) {
            return ['total' => 0, 'wallet_free_after' => (int) $wallet->free_balance];
        }
        foreach (array_chunk($operationRows, 250) as $chunk) {
            DB::table('point_operations')->insert($chunk);
        }
        $operations = PointOperation::query()
            ->whereIn('business_key', array_values($operationKeys))
            ->get()
            ->keyBy('business_key');
        $lotRows = [];
        $grantByOperation = [];
        foreach ($grants as $grant) {
            if ($grant['amount'] === 0) {
                continue;
            }
            $operation = $operations->get($operationKeys[$grant['draw_result_id']]);
            if (! $operation instanceof PointOperation) {
                throw new V2PointException('Draw point-back operation mapping failed.');
            }
            $grantByOperation[$operation->id] = $grant;
            $lotRows[] = [
                'user_id' => $userId,
                'grant_operation_id' => $operation->id,
                'point_type' => 'free',
                'granted_amount' => $grant['amount'],
                'remaining_amount' => $grant['amount'],
                'reserved_amount' => 0,
                'granted_at' => $occurred,
                'expire_at' => $expiry,
                'created_at' => $occurred,
                'updated_at' => $occurred,
            ];
        }
        foreach (array_chunk($lotRows, 250) as $chunk) {
            DB::table('point_lots')->insert($chunk);
        }
        $lots = PointLot::query()
            ->whereIn('grant_operation_id', array_keys($grantByOperation))
            ->orderBy('id')
            ->get()
            ->keyBy('grant_operation_id');
        $runningFree = (int) $wallet->free_balance;
        $ledgerRows = [];
        foreach ($operations->sortBy('id') as $operation) {
            $lot = $lots->get($operation->id);
            $grant = $grantByOperation[$operation->id] ?? null;
            if (! $lot instanceof PointLot || ! is_array($grant)) {
                throw new V2PointException('Draw point-back lot mapping failed.');
            }
            $runningFree += $grant['amount'];
            $ledgerRows[] = [
                'point_operation_id' => $operation->id,
                'sequence_no' => 1,
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'point_lot_id' => $lot->id,
                'point_type' => 'free',
                'entry_type' => 'grant',
                'amount_delta' => $grant['amount'],
                'wallet_balance_after' => $runningFree,
                'lot_remaining_after' => $grant['amount'],
                'occurred_at' => $occurred,
                'business_date' => $businessDate,
                'created_at' => $occurred,
            ];
        }
        foreach (array_chunk($ledgerRows, 250) as $chunk) {
            DB::table('point_ledger_entries')->insert($chunk);
        }
        $wallet->forceFill([
            'free_balance' => $runningFree,
            'lock_version' => (int) $wallet->lock_version + 1,
        ])->save();

        return ['total' => $total, 'wallet_free_after' => $runningFree];
    }

    /**
     * Draw TransactionではLock済みLotを同じ消費順のまま一括永続化する。
     *
     * @param Collection<int, PointLot> $lots
     */
    private function consumeLotsForDraw(
        Collection $lots,
        int &$remaining,
        PointOperation $operation,
        Wallet $wallet,
        string $pointType,
        int &$runningBalance,
        int &$sequence,
        CarbonImmutable $occurred
    ): void {
        $updates = [];
        $ledgerRows = [];
        $businessDate = $occurred->setTimezone('Asia/Tokyo')->toDateString();
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
            $updates[(int) $lot->id] = $lotRemaining;
            $ledgerRows[] = [
                'point_operation_id' => $operation->id,
                'sequence_no' => $sequence++,
                'user_id' => $operation->user_id,
                'wallet_id' => $wallet->id,
                'point_lot_id' => $lot->id,
                'point_type' => $pointType,
                'entry_type' => 'spend',
                'amount_delta' => -$used,
                'wallet_balance_after' => $runningBalance,
                'lot_remaining_after' => $lotRemaining,
                'occurred_at' => $occurred,
                'business_date' => $businessDate,
                'created_at' => $occurred,
            ];
            $lot->forceFill(['remaining_amount' => $lotRemaining]);
            $remaining -= $used;
        }
        foreach (array_chunk($updates, 250, true) as $chunk) {
            $cases = [];
            $bindings = [];
            foreach ($chunk as $id => $lotRemaining) {
                $cases[] = 'WHEN ?::bigint THEN ?::bigint';
                $bindings[] = $id;
                $bindings[] = $lotRemaining;
            }
            $bindings[] = $occurred;
            $ids = array_keys($chunk);
            array_push($bindings, ...$ids);
            DB::update(
                'UPDATE point_lots SET remaining_amount = CASE id '.
                implode(' ', $cases).
                ' END, updated_at = ? WHERE id IN ('.
                implode(',', array_fill(0, count($ids), '?')).')',
                $bindings
            );
        }
        foreach (array_chunk($ledgerRows, 250) as $chunk) {
            DB::table('point_ledger_entries')->insert($chunk);
        }
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
        ?int $actorId = null,
        ?int $sourceId = null
    ): PointOperation {
        $operation = new PointOperation();
        $operation->forceFill([
            'user_id' => $userId,
            'operation_type' => $type,
            'business_key' => $businessKey,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
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
