<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminGachaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'category' => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
            ],
            'category_id' => $this->category_id,
            'price' => $this->price,
            'total_count' => $this->total_count,
            'sold_count' => $this->sold_count,
            'remaining_count' => max(0, (int) $this->total_count - (int) $this->sold_count),
            'probability_mode' => $this->probability_mode?->value ?? $this->probability_mode,
            'current_probability_version_id' => $this->current_probability_version_id,
            'current_probability_version' => $this->whenLoaded('currentProbabilityVersion', fn (): ?array => $this->currentProbabilityVersion ? [
                'id' => $this->currentProbabilityVersion->id,
                'version_number' => $this->currentProbabilityVersion->version_number,
                'status' => $this->currentProbabilityVersion->status?->value ?? $this->currentProbabilityVersion->status,
                'published_at' => $this->currentProbabilityVersion->published_at?->toIso8601String(),
            ] : null),
            'minimum_guarantee' => [
                'type' => $this->minimum_guarantee_type?->value ?? $this->minimum_guarantee_type,
                'value' => $this->minimum_guarantee_value,
                'cost' => $this->minimum_guarantee_cost,
            ],
            'status' => $this->status?->value ?? $this->status,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'description' => $this->description,
            'caution' => $this->caution,
            'main_image_url' => $this->main_image_url,
            'show_on_top_slider' => (bool) $this->show_on_top_slider,
            'target_margin' => $this->target_margin !== null ? (float) $this->target_margin : null,
            'ranks_count' => $this->whenCounted('ranks'),
            'prizes_count' => $this->whenCounted('prizes'),
            'ranks' => AdminGachaRankResource::collection($this->whenLoaded('ranks')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
