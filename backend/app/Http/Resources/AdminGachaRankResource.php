<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminGachaRankResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gacha_id' => $this->gacha_id,
            'gacha' => $this->whenLoaded('gacha', fn () => [
                'id' => $this->gacha?->id,
                'title' => $this->gacha?->title,
                'slug' => $this->gacha?->slug,
                'status' => $this->gacha?->status?->value ?? $this->gacha?->status,
            ]),
            'rank_key' => $this->rank_key,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'image_url' => $this->effectiveImageUrl(),
            'rank_image_asset_id' => $this->rank_image_asset_id,
            'rank_image_asset' => new RankAssetResource($this->whenLoaded('rankImageAsset')),
            'draw_video_url' => $this->effectiveDrawVideoUrl(),
            'draw_video_asset_id' => $this->draw_video_asset_id,
            'draw_video_asset' => new RankAssetResource($this->whenLoaded('drawVideoAsset')),
            'result_image_url' => $this->result_image_url,
            'sort_order' => $this->sort_order,
            'is_visible' => $this->is_visible,
            'prizes_count' => $this->whenCounted('prizes'),
            'prizes' => AdminGachaPrizeResource::collection($this->whenLoaded('prizes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
