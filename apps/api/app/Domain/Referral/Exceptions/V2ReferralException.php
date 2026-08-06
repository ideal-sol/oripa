<?php

namespace App\Domain\Referral\Exceptions;

use RuntimeException;

final class V2ReferralException extends RuntimeException
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
