<?php

namespace App\Domain\Point\Exceptions;

use RuntimeException;

final class V2PointReadException extends RuntimeException
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
