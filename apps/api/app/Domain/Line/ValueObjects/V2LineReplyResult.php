<?php

namespace App\Domain\Line\ValueObjects;

final readonly class V2LineReplyResult
{
    public function __construct(
        public bool $succeeded,
        public ?string $failureCode = null
    ) {
    }
}
