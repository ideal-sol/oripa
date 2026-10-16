<?php

namespace App\Domain\MemberRank\Exceptions;

use RuntimeException;

final class V2MemberRankException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message);
    }
}
