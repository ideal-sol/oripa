<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDrawResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'draw_request_id' => $this->draw_request_id,
            'user_id' => $this->user_id,
            'gacha_id' => $this->gacha_id,
            'draw_sequence_number' => $this->draw_sequence_number,
            'result_type' => $this->result_type?->value ?? $this->result_type,
            'rank_id' => $this->rank_id,
            'prize_id' => $this->prize_id,
            'consumed_point' => $this->consumed_point,
            'granted_point' => $this->granted_point,
            'random_value' => $this->random_value,
            'probability_version_id' => $this->probability_version_id,
            'probability_version_stage_id' => $this->probability_version_stage_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'gacha' => $this->whenLoaded('gacha', fn (): array => [
                'id' => $this->gacha->id,
                'title' => $this->gacha->title,
                'slug' => $this->gacha->slug,
                'price' => $this->gacha->price,
            ]),
            'rank' => $this->whenLoaded('rank', fn (): ?array => $this->rank ? [
                'id' => $this->rank->id,
                'rank_key' => $this->rank->rank_key,
                'display_name' => $this->rank->display_name,
                'image_url' => $this->rank->effectiveImageUrl(),
                'draw_video_url' => $this->rank->effectiveDrawVideoUrl(),
                'result_image_url' => $this->rank->result_image_url,
            ] : null),
            'prize' => $this->whenLoaded('prize', fn (): ?array => $this->prize ? [
                'id' => $this->prize->id,
                'name' => $this->prize->name,
                'image_url' => $this->prize->image_url,
                'display_price' => $this->prize->display_price,
                'exchange_point' => $this->prize->exchange_point,
            ] : null),
            'user_prize' => new AdminUserPrizeResource($this->whenLoaded('userPrize')),
            'probability_version' => $this->whenLoaded('probabilityVersion', fn (): array => [
                'id' => $this->probabilityVersion->id,
                'version_number' => $this->probabilityVersion->version_number,
                'status' => $this->probabilityVersion->status?->value ?? $this->probabilityVersion->status,
                'published_at' => $this->probabilityVersion->published_at?->toIso8601String(),
            ]),
            'probability_stage' => $this->whenLoaded('probabilityVersionStage', fn (): array => [
                'id' => $this->probabilityVersionStage->id,
                'stage_key' => $this->probabilityVersionStage->stage_key,
                'name' => $this->probabilityVersionStage->name,
                'min_draw_number' => $this->probabilityVersionStage->min_draw_number,
                'max_draw_number' => $this->probabilityVersionStage->max_draw_number,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
