<?php

namespace App\Domain\Draw\Exceptions;

use RuntimeException;

final class V2DrawException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message);
    }
}
