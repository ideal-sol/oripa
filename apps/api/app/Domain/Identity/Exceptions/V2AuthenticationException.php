<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

final class V2AuthenticationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message = 'The authentication request could not be completed.',
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message);
    }
}
