<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QaDrawPlanItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,
            'gacha_prize_id' => $this->gacha_prize_id,
            'gacha_prize' => $this->whenLoaded('prize', fn () => [
                'id' => $this->prize->id,
                'name' => $this->prize->name,
                'rank_id' => $this->prize->rank_id,
                'rank_name' => $this->prize->rank?->display_name,
                'image_url' => $this->prize->image_url,
            ]),
            'quantity' => $this->quantity,
            'consumed_count' => $this->consumed_count,
            'remaining_count' => max(0, $this->quantity - $this->consumed_count),
            'rank_image_asset_id' => $this->rank_image_asset_id,
            'rank_image_asset' => $this->whenLoaded('rankImageAsset', fn () => RankAssetResource::make($this->rankImageAsset)),
            'draw_video_asset_id' => $this->draw_video_asset_id,
            'draw_video_asset' => $this->whenLoaded('drawVideoAsset', fn () => RankAssetResource::make($this->drawVideoAsset)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
