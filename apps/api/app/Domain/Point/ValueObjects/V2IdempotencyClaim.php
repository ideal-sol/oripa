<?php

namespace App\Domain\Point\ValueObjects;

use App\Models\V2\IdempotencyRecord;

final readonly class V2IdempotencyClaim
{
    public function __construct(
        public IdempotencyRecord $record,
        public bool $replay
    ) {
    }
}
