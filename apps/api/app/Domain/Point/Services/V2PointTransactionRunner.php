<?php

namespace App\Domain\Point\Services;

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
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction($callback, 1);
            } catch (QueryException $exception) {
                if (! $this->isRetryable($exception) || $attempt === self::MAX_ATTEMPTS) {
                    throw $exception;
                }
                usleep(random_int(1_000, 5_000));
            }
        }

        throw new \LogicException('V2 point transaction retry loop terminated unexpectedly.');
    }

    private function isRetryable(QueryException $exception): bool
    {
        $previous = $exception->getPrevious();
        $errorInfo = $previous instanceof \PDOException ? $previous->errorInfo : null;
        $state = is_array($errorInfo) ? ($errorInfo[0] ?? null) : null;
        $state ??= is_string($exception->getCode()) ? $exception->getCode() : null;

        return in_array($state, ['40001', '40P01'], true);
    }
}
