<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserPrizeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'gacha_id' => $this->gacha_id,
            'gacha_prize_id' => $this->gacha_prize_id,
            'draw_result_id' => $this->draw_result_id,
            'status' => $this->status?->value ?? $this->status,
            'converted_point' => $this->converted_point,
            'acquired_at' => $this->acquired_at?->toIso8601String(),
            'storage_expire_at' => $this->storage_expire_at?->toIso8601String(),
            'user' => new UserResource($this->whenLoaded('user')),
            'gacha' => $this->whenLoaded('gacha', fn (): array => [
                'id' => $this->gacha->id,
                'title' => $this->gacha->title,
                'slug' => $this->gacha->slug,
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
                ] : null,
            ]),
            'draw_result' => new AdminDrawResultResource($this->whenLoaded('drawResult')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
