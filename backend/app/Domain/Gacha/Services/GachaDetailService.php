<?php

namespace App\Domain\Gacha\Services;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Models\Gacha;

class GachaDetailService
{
    public function findForUser(Gacha $gacha): Gacha
    {
        abort_if(
            $gacha->status === GachaStatus::SoldOut || (int) $gacha->sold_count >= (int) $gacha->total_count,
            404,
        );

        return $gacha->load([
            'category',
            'ranks' => fn ($query) => $query
                ->where('is_visible', true)
                ->orderBy('sort_order')
                ->orderBy('id'),
            'ranks.rankImageAsset',
            'ranks.drawVideoAsset',
            'ranks.prizes' => fn ($query) => $query
                ->where('is_visible', true)
                ->orderBy('sort_order')
                ->orderBy('id'),
            'currentProbabilityVersion.stages' => fn ($query) => $query
                ->orderBy('sort_order')
                ->orderBy('min_draw_number'),
            'currentProbabilityVersion.stages.probabilities' => fn ($query) => $query
                ->with('prize')
                ->orderBy('id'),
        ]);
    }
}
