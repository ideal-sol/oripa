<?php

namespace App\Domain\Payment\V2\Exceptions;

use RuntimeException;

final class V2FincodeException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $providerHttpStatus = null,
        public readonly ?string $providerErrorCode = null
    ) {
        parent::__construct($message);
    }
}
