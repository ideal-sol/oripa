<?php

namespace App\Domain\Payment\V2\Exceptions;

use RuntimeException;

final class V2PointPurchasePlanException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly bool $retryable = false
    ) {
        parent::__construct($message);
    }
}
