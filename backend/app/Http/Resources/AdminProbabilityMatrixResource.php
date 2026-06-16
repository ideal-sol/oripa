<?php

namespace App\Http\Resources;

use App\Models\GachaPrize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProbabilityMatrixResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gacha = $this->resource;
        $version = $gacha->currentProbabilityVersion;
        $stages = $version?->stages ?? collect();

        return [
            'gacha' => [
                'id' => $gacha->id,
                'title' => $gacha->title,
                'total_count' => $gacha->total_count,
                'sold_count' => $gacha->sold_count,
                'current_probability_version_id' => $gacha->current_probability_version_id,
            ],
            'current_probability_version' => $version ? new AdminProbabilityVersionResource($version) : null,
            'stages' => AdminProbabilityStageResource::collection($stages),
            'minimum_guarantee' => [
                'type' => $gacha->minimum_guarantee_type?->value ?? $gacha->minimum_guarantee_type,
                'value' => $gacha->minimum_guarantee_value,
                'cost' => $gacha->minimum_guarantee_cost,
                'ppm' => $this->minimumGuaranteePpmByStage($stages),
            ],
            'ranks' => $gacha->ranks->map(fn ($rank): array => [
                'id' => $rank->id,
                'rank_key' => $rank->rank_key,
                'display_name' => $rank->display_name,
                'sort_order' => $rank->sort_order,
                'prizes' => $rank->prizes->map(fn (GachaPrize $prize): array => [
                    'id' => $prize->id,
                    'name' => $prize->name,
                    'max_win_count' => $prize->max_win_count,
                    'won_count' => $prize->won_count,
                    'is_active' => $prize->is_active,
                    'is_visible' => $prize->is_visible,
                    'ppm' => $this->ppmByStageForPrize($stages, $prize),
                ])->values(),
            ])->values(),
        ];
    }

    private function minimumGuaranteePpmByStage($stages): array
    {
        $ppm = [];

        foreach ($stages as $stage) {
            $ppm[$stage->stage_key] = (int) ($stage->probabilities
                ->firstWhere('is_minimum_guarantee', true)?->probability_ppm ?? 0);
        }

        return $ppm;
    }

    private function ppmByStageForPrize($stages, GachaPrize $prize): array
    {
        $ppm = [];

        foreach ($stages as $stage) {
            $row = $stage->probabilities
                ->where('is_minimum_guarantee', false)
                ->firstWhere('prize_id', $prize->id);

            $ppm[$stage->stage_key] = (int) ($row?->probability_ppm ?? 0);
        }

        return $ppm;
    }
}
