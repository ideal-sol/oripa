<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProbabilityVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gacha_id' => $this->gacha_id,
            'version_number' => $this->version_number,
            'status' => $this->status?->value ?? $this->status,
            'snapshot_hash' => $this->snapshot_hash,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->published_by,
            'change_reason' => $this->change_reason,
            'stages' => AdminProbabilityStageResource::collection($this->whenLoaded('stages')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
