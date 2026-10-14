<?php

namespace App\Domain\Integrations\Notion;

class NotionClock
{
    public function wait(float $seconds, callable $heartbeat): void
    {
        $until = microtime(true) + $seconds;
        do {
            $heartbeat();
            $remaining = $until - microtime(true);
            if ($remaining > 0) {
                usleep((int) (min(1, $remaining) * 1000000));
            }
        } while ($remaining > 0);
    }
}
