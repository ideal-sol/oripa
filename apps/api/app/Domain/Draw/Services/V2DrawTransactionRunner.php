<?php

namespace App\Domain\Draw\Services;

use App\Domain\Draw\Exceptions\V2DrawException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class V2DrawTransactionRunner
{
    private const MAX_ATTEMPTS = 3;

    /**
     * @template T
     * @param callable(int): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(fn () => $callback($attempt), 1);
            } catch (QueryException $exception) {
                if (! $this->isRetryable($exception)) {
                    throw $exception;
                }
                if ($attempt === self::MAX_ATTEMPTS) {
                    throw new V2DrawException(
                        'DRAW_RETRY_EXHAUSTED',
                        503,
                        'The draw could not be completed after a database retry.',
                        true,
                        1
                    );
                }
                usleep(random_int(1_000, 5_000));
            }
        }

        throw new \LogicException('V2 Draw retry loop terminated unexpectedly.');
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
