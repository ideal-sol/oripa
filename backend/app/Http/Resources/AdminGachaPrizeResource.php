<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminGachaPrizeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gacha_id' => $this->gacha_id,
            'rank_id' => $this->rank_id,
            'gacha' => $this->whenLoaded('gacha', fn () => [
                'id' => $this->gacha?->id,
                'title' => $this->gacha?->title,
                'slug' => $this->gacha?->slug,
                'status' => $this->gacha?->status,
            ]),
            'rank' => $this->whenLoaded('rank', fn () => [
                'id' => $this->rank?->id,
                'display_name' => $this->rank?->display_name,
                'rank_key' => $this->rank?->rank_key,
            ]),
            'name' => $this->name,
            'image_url' => $this->image_url,
            'max_win_count' => $this->max_win_count,
            'won_count' => $this->won_count,
            'remaining_win_count' => max(0, (int) $this->max_win_count - (int) $this->won_count),
            'cost_price' => $this->cost_price,
            'display_price' => $this->display_price,
            'exchange_point' => $this->exchange_point,
            'condition' => $this->condition,
            'is_active' => $this->is_active,
            'is_visible' => $this->is_visible,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
