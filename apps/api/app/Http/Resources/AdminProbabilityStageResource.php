<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProbabilityStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage_key' => $this->stage_key,
            'name' => $this->name,
            'condition_type' => $this->condition_type?->value ?? $this->condition_type,
            'min_draw_number' => $this->min_draw_number,
            'max_draw_number' => $this->max_draw_number,
            'sort_order' => $this->sort_order,
            'probabilities' => AdminProbabilityRowResource::collection($this->whenLoaded('probabilities')),
        ];
    }
}
