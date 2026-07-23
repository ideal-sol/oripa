<?php

namespace App\Domain\Probability\DTO;

class ProbabilityRangeEntry
{
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly ?int $prizeId,
        public readonly bool $isMinimumGuarantee,
        public readonly int $probabilityPpm,
    ) {
    }

    public function contains(int $randomValue): bool
    {
        return $randomValue >= $this->start && $randomValue < $this->end;
    }

    public function isPrize(): bool
    {
        return ! $this->isMinimumGuarantee && $this->prizeId !== null;
    }
}
