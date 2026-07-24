<?php

namespace App\Domain\Identity\Contracts;

interface V2SecurityEventSink
{
    /**
     * @param array<string, bool|int|string|null> $context
     */
    public function record(string $event, array $context): void;
}
