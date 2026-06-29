<?php

namespace App\Http\Resources;

use App\Models\GachaPrize;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GachaDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $version = $this->currentProbabilityVersion;
        $stages = $version?->stages ?? collect();
        $stageTotalsByRank = $this->stageTotalsByRank();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
                'description' => $this->category?->description,
            ],
            'tags' => GachaTagResource::collection($this->whenLoaded('tags')),
            'price' => $this->price,
            'total_count' => $this->total_count,
            'daily_draw_limit' => $this->daily_draw_limit,
            'sold_count' => $this->sold_count,
            'remaining_count' => max(0, (int) $this->total_count - (int) $this->sold_count),
            'probability_mode' => $this->probability_mode?->value ?? $this->probability_mode,
            'status' => $this->status?->value ?? $this->status,
            'description' => $this->description,
            'caution' => $this->caution,
            'main_image_url' => $this->main_image_url,
            'minimum_guarantee' => [
                'type' => $this->minimum_guarantee_type?->value ?? $this->minimum_guarantee_type,
                'value' => $this->minimum_guarantee_value,
                'stage_ppm' => $this->minimumGuaranteePpmByStage(),
            ],
            'current_probability_version' => $version ? [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'snapshot_hash' => $version->snapshot_hash,
                'published_at' => $version->published_at?->toIso8601String(),
            ] : null,
            'current_stage' => $this->stagePayload($this->currentStage()),
            'next_stage' => $this->stagePayload($this->nextStage()),
            'stages' => $stages->map(fn ($stage): array => [
                'id' => $stage->id,
                'stage_key' => $stage->stage_key,
                'name' => $stage->name,
                'condition_type' => $stage->condition_type?->value ?? $stage->condition_type,
                'min_draw_number' => $stage->min_draw_number,
                'max_draw_number' => $stage->max_draw_number,
                'minimum_guarantee_ppm' => $stage->probabilities
                    ->firstWhere('is_minimum_guarantee', true)?->probability_ppm,
            ])->values(),
            'ranks' => $this->ranks->map(fn ($rank): array => [
                'id' => $rank->id,
                'rank_key' => $rank->rank_key,
                'display_name' => $rank->display_name,
                'description' => $rank->description,
                'image_url' => $rank->effectiveImageUrl(),
                'draw_video_url' => $rank->effectiveDrawVideoUrl(),
                'result_image_url' => $rank->result_image_url,
                'sort_order' => $rank->sort_order,
                'stage_total_ppm' => $stageTotalsByRank[$rank->id] ?? [],
                'prizes' => $rank->prizes->map(fn (GachaPrize $prize): array => [
                    'id' => $prize->id,
                    'name' => $prize->name,
                    'image_url' => $prize->image_url,
                    'max_win_count' => $prize->max_win_count,
                    'won_count' => $prize->won_count,
                    'remaining_win_count' => max(0, (int) $prize->max_win_count - (int) $prize->won_count),
                    'display_price' => $prize->display_price,
                    'exchange_point' => $prize->exchange_point,
                    'condition' => $prize->condition,
                    'is_active' => $prize->is_active,
                    'sort_order' => $prize->sort_order,
                    'ppm' => $this->ppmByStageForPrize($prize),
                ])->values(),
            ])->values(),
        ];
    }
    private function stageTotalsByRank(): array
    {
        $totals = [];

        foreach (($this->currentProbabilityVersion?->stages ?? collect()) as $stage) {
            foreach ($stage->probabilities->where('is_minimum_guarantee', false) as $probability) {
                $rankId = $probability->prize?->rank_id;

                if (! $rankId) {
                    continue;
                }

                $totals[$rankId][$stage->stage_key] = ($totals[$rankId][$stage->stage_key] ?? 0)
                    + (int) $probability->probability_ppm;
            }
        }

        return $totals;
    }
    private function minimumGuaranteePpmByStage(): array
    {
        $ppm = [];

        foreach (($this->currentProbabilityVersion?->stages ?? collect()) as $stage) {
            $ppm[$stage->stage_key] = (int) ($stage->probabilities
                ->firstWhere('is_minimum_guarantee', true)?->probability_ppm ?? 0);
        }

        return $ppm;
    }
    private function ppmByStageForPrize(GachaPrize $prize): array
    {
        $ppm = [];

        foreach (($this->currentProbabilityVersion?->stages ?? collect()) as $stage) {
            $row = $stage->probabilities
                ->where('is_minimum_guarantee', false)
                ->firstWhere('prize_id', $prize->id);

            $ppm[$stage->stage_key] = (int) ($row?->probability_ppm ?? 0);
        }

        return $ppm;
    }

    private function currentStage(): mixed
    {
        $nextSequence = (int) $this->sold_count + 1;

        return ($this->currentProbabilityVersion?->stages ?? collect())
            ->first(fn ($stage): bool => $stage->min_draw_number <= $nextSequence
                && ($stage->max_draw_number === null || $stage->max_draw_number >= $nextSequence));
    }

    private function nextStage(): mixed
    {
        $nextSequence = (int) $this->sold_count + 1;

        return ($this->currentProbabilityVersion?->stages ?? collect())
            ->filter(fn ($stage): bool => $stage->min_draw_number > $nextSequence)
            ->sortBy('min_draw_number')
            ->first();
    }

    private function stagePayload(mixed $stage): ?array
    {
        if (! $stage) {
            return null;
        }

        return [
            'id' => $stage->id,
            'stage_key' => $stage->stage_key,
            'name' => $stage->name,
            'condition_type' => $stage->condition_type?->value ?? $stage->condition_type,
            'min_draw_number' => $stage->min_draw_number,
            'max_draw_number' => $stage->max_draw_number,
        ];
    }
}
