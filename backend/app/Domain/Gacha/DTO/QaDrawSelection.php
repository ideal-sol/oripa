<?php

namespace App\Domain\Gacha\DTO;

use App\Models\QaDrawPlan;
use App\Models\QaTestUserMode;

class QaDrawSelection
{
    /**
     * @param  list<QaDrawSelectedItem>  $items
     */
    public function __construct(
        public readonly bool $active,
        public readonly ?QaTestUserMode $mode,
        public readonly ?QaDrawPlan $plan,
        public readonly array $items = [],
    ) {
    }

    public static function inactive(?QaTestUserMode $mode = null): self
    {
        return new self(
            active: false,
            mode: $mode,
            plan: null,
            items: [],
        );
    }

    /**
     * @param  list<QaDrawSelectedItem>  $items
     */
    public static function active(QaTestUserMode $mode, QaDrawPlan $plan, array $items): self
    {
        return new self(
            active: true,
            mode: $mode,
            plan: $plan,
            items: $items,
        );
    }

    public function modeId(): ?int
    {
        return $this->mode?->id;
    }

    public function planId(): ?int
    {
        return $this->plan?->id;
    }
}
