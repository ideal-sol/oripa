<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GachaListResource extends JsonResource
{
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
            'price' => $this->price,
            'total_count' => $this->total_count,
            'daily_draw_limit' => $this->daily_draw_limit,
            'sold_count' => $this->sold_count,
            'remaining_count' => max(0, (int) $this->total_count - (int) $this->sold_count),
            'probability_mode' => $this->probability_mode?->value ?? $this->probability_mode,
            'minimum_guarantee' => [
                'type' => $this->minimum_guarantee_type?->value ?? $this->minimum_guarantee_type,
                'value' => $this->minimum_guarantee_value,
            ],
            'status' => $this->status?->value ?? $this->status,
            'main_image_url' => $this->main_image_url,
            'show_on_top_slider' => (bool) $this->show_on_top_slider,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
        ];
    }
}
