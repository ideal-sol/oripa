<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'point_type' => $this->point_type?->value ?? $this->point_type,
            'granted_amount' => $this->granted_amount,
            'remaining_amount' => $this->remaining_amount,
            'source_type' => $this->source_type?->value ?? $this->source_type,
            'source_id' => $this->source_id,
            'granted_at' => $this->granted_at?->toIso8601String(),
            'expire_at' => $this->expire_at?->toIso8601String(),
        ];
    }
}
