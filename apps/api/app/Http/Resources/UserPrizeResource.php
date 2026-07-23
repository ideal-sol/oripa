<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserPrizeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gacha_id' => $this->gacha_id,
            'gacha_prize_id' => $this->gacha_prize_id,
            'draw_result_id' => $this->draw_result_id,
            'status' => $this->status?->value ?? $this->status,
            'converted_point' => $this->converted_point,
            'acquired_at' => $this->acquired_at?->toIso8601String(),
            'storage_expire_at' => $this->storage_expire_at?->toIso8601String(),
            'gacha' => $this->whenLoaded('gacha', fn (): array => [
                'id' => $this->gacha->id,
                'title' => $this->gacha->title,
                'slug' => $this->gacha->slug,
                'main_image_url' => $this->gacha->main_image_url,
            ]),
            'prize' => $this->whenLoaded('prize', fn (): array => [
                'id' => $this->prize->id,
                'rank_id' => $this->prize->rank_id,
                'name' => $this->prize->name,
                'image_url' => $this->prize->image_url,
                'display_price' => $this->prize->display_price,
                'exchange_point' => $this->prize->exchange_point,
                'condition' => $this->prize->condition,
                'rank' => $this->prize->relationLoaded('rank') ? [
                    'id' => $this->prize->rank->id,
                    'rank_key' => $this->prize->rank->rank_key,
                    'display_name' => $this->prize->rank->display_name,
                    'image_url' => $this->prize->rank->effectiveImageUrl(),
                    'result_image_url' => $this->prize->rank->result_image_url,
                ] : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
