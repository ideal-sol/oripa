<?php

namespace App\Domain\Point\Exceptions;

use RuntimeException;

final class V2AdminPointAdjustmentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($message);
    }
}
