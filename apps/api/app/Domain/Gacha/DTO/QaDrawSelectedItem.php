<?php

namespace App\Domain\Gacha\DTO;

use App\Models\GachaPrize;
use App\Models\QaDrawPlanItem;
use App\Models\RankAsset;

class QaDrawSelectedItem
{
    public function __construct(
        public readonly QaDrawPlanItem $planItem,
        public readonly GachaPrize $prize,
        public readonly ?RankAsset $rankImageAsset,
        public readonly ?RankAsset $drawVideoAsset,
    ) {
    }

    public function fixedRankImageUrl(): ?string
    {
        return $this->rankImageAsset?->url;
    }

    public function fixedDrawVideoUrl(): ?string
    {
        return $this->drawVideoAsset?->url;
    }
}
