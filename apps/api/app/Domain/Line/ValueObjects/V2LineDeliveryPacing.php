<?php

namespace App\Domain\Line\ValueObjects;

use InvalidArgumentException;

/** Immutable per-delivery snapshot; persistence and worker ownership belong to D3. */
final readonly class V2LineDeliveryPacing implements \JsonSerializable
{
    public function __construct(
        public int $pushEvery = 10,
        public int $pushWaitSeconds = 5,
        public int $multicastWaitSeconds = 0,
    ) {
        if ($pushEvery < 1 || $pushEvery > 1000
            || $pushWaitSeconds < 0 || $pushWaitSeconds > 3600
            || $multicastWaitSeconds < 0 || $multicastWaitSeconds > 3600) {
            throw new InvalidArgumentException('Invalid LINE delivery pacing.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['push_every' => $this->pushEvery, 'push_wait_seconds' => $this->pushWaitSeconds,
            'multicast_wait_seconds' => $this->multicastWaitSeconds];
    }

    public function offsetSeconds(string $method, int $index): int
    {
        if ($index < 0 || ! in_array($method, ['push', 'multicast'], true)) {
            throw new InvalidArgumentException('Invalid LINE batch position.');
        }
        $groups = $method === 'push' ? intdiv($index, $this->pushEvery) : $index;
        $wait = $method === 'push' ? $this->pushWaitSeconds : $this->multicastWaitSeconds;
        if ($wait > 0 && $groups > intdiv(PHP_INT_MAX, $wait)) {
            throw new \OverflowException('LINE pacing duration exceeds supported range.');
        }

        return $groups * $wait;
    }

    /** Count recipients, not requests, for quota; this count is only for scheduling. */
    public function batchCount(int $recipients, bool $personalized): int
    {
        if ($recipients < 0) throw new InvalidArgumentException('Invalid recipient count.');

        return $personalized ? $recipients : intdiv($recipients, 500) + (int) ($recipients % 500 !== 0);
    }

    /**
     * Caller supplies only unattempted pending IDs, in original order, under its
     * delivery lock. Retry deadlines must never be passed to or replaced by this
     * calculation. Rebase remaining work at resume time to avoid catch-up bursts.
     * @param list<int> $pendingBatchIds
     * @return array<int, \DateTimeImmutable>
     */
    public function resumeSchedule(string $method, array $pendingBatchIds, \DateTimeImmutable $resumeAt): array
    {
        if (! array_is_list($pendingBatchIds) || count(array_unique($pendingBatchIds, SORT_REGULAR)) !== count($pendingBatchIds)) {
            throw new InvalidArgumentException('Invalid pending batch list.');
        }
        $this->offsetSeconds($method, 0);
        $schedule = [];
        foreach ($pendingBatchIds as $index => $id) {
            if (! is_int($id) || $id < 1) throw new InvalidArgumentException('Invalid pending batch identifier.');
            $schedule[$id] = $resumeAt->add(new \DateInterval('PT'.$this->offsetSeconds($method, $index).'S'));
        }

        return $schedule;
    }
}
