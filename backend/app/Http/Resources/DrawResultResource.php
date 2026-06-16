<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DrawResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'draw_sequence_number' => $this->draw_sequence_number,
            'result_type' => $this->result_type?->value ?? $this->result_type,
            'rank_id' => $this->rank_id,
            'prize_id' => $this->prize_id,
            'consumed_point' => $this->consumed_point,
            'granted_point' => $this->granted_point,
            'random_value' => $this->random_value,
            'probability_version_id' => $this->probability_version_id,
            'probability_version_stage_id' => $this->probability_version_stage_id,
            'rank' => $this->whenLoaded('rank', fn (): ?array => $this->rank ? [
                'id' => $this->rank->id,
                'rank_key' => $this->rank->rank_key,
                'display_name' => $this->rank->display_name,
                'draw_video_url' => $this->rank->draw_video_url,
                'result_image_url' => $this->rank->result_image_url,
            ] : null),
            'prize' => $this->whenLoaded('prize', fn (): ?array => $this->prize ? [
                'id' => $this->prize->id,
                'name' => $this->prize->name,
                'image_url' => $this->prize->image_url,
                'display_price' => $this->prize->display_price,
                'exchange_point' => $this->prize->exchange_point,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
