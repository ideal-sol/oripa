<?php

namespace App\Domain\Probability\DTO;

use LogicException;

class ProbabilityRange
{
    public function __construct(
        public readonly array $entries,
    ) {
    }

    public function pick(int $randomValue): ProbabilityRangeEntry
    {
        if ($randomValue < 0 || $randomValue > 999_999) {
            throw new LogicException('Random value must be between 0 and 999999.');
        }

        foreach ($this->entries as $entry) {
            if ($entry->contains($randomValue)) {
                return $entry;
            }
        }

        throw new LogicException('Probability range does not cover the random value.');
    }

    public function totalPpm(): int
    {
        return array_sum(array_map(
            fn (ProbabilityRangeEntry $entry): int => $entry->probabilityPpm,
            $this->entries,
        ));
    }
}
