<?php

namespace App\Domain\Identity\Events;

final readonly class V2SecurityEvent
{
    /**
     * @param array<string, bool|int|string|null> $context
     */
    public function __construct(
        public string $event,
        public array $context
    ) {
    }
}
