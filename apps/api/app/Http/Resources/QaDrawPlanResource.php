<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QaDrawPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'gacha_id' => $this->gacha_id,
            'gacha' => $this->whenLoaded('gacha', fn () => [
                'id' => $this->gacha->id,
                'title' => $this->gacha->title,
                'status' => $this->gacha->status instanceof \BackedEnum ? $this->gacha->status->value : $this->gacha->status,
            ]),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'title' => $this->title,
            'reason' => $this->reason,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'created_by_admin_user_id' => $this->created_by_admin_user_id,
            'updated_by_admin_user_id' => $this->updated_by_admin_user_id,
            'items' => QaDrawPlanItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
