<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

final class V2AdminUserStateException extends RuntimeException
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
