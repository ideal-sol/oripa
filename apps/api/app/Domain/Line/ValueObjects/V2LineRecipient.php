<?php

namespace App\Domain\Line\ValueObjects;

use App\Domain\Line\Enums\V2LineRecipientGroup;

/** Internal reference only. Never contains a subject, ciphertext or profile. */
final readonly class V2LineRecipient
{
    public function __construct(
        public int $botId,
        public int $authenticationRevision,
        public string $source,
        public int $sourceId,
        public ?int $userId,
        public V2LineRecipientGroup $group,
    ) {}
}
