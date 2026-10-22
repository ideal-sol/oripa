<?php

namespace App\Domain\Point\Services;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class V2PointTransactionRunner
{
    private const MAX_ATTEMPTS = 3;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $outermost = DB::transactionLevel() === 0;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($callback): mixed {
                    app(V2FinancialAdmission::class)->enter();

                    return $callback();
                }, 1);
            } catch (QueryException|DeadlockException $exception) {
                if (! $outermost || ! $this->isRetryable($exception) || $attempt === self::MAX_ATTEMPTS) {
                    throw $exception;
                }
                usleep(random_int(1_000, 5_000));
            }
        }

        throw new \LogicException('V2 point transaction retry loop terminated unexpectedly.');
    }

    private function isRetryable(Throwable $exception): bool
    {
        do {
            $errorInfo = $exception instanceof \PDOException ? $exception->errorInfo : null;
            $state = is_array($errorInfo) ? ($errorInfo[0] ?? null) : null;
            $state ??= is_string($exception->getCode()) ? $exception->getCode() : null;
            if (in_array($state, ['40001', '40P01'], true)) return true;
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }
}
