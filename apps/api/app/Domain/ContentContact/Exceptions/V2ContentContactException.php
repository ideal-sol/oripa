<?php

namespace App\Domain\ContentContact\Exceptions;

use RuntimeException;

final class V2ContentContactException extends RuntimeException
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
