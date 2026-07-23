<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'admin_user_id' => $this->admin_user_id,
            'adjustment_type' => $this->adjustment_type,
            'point_type' => $this->point_type?->value ?? $this->point_type,
            'amount' => $this->amount,
            'expire_at' => $this->expire_at?->toIso8601String(),
            'reason' => $this->reason,
            'user' => new UserResource($this->whenLoaded('user')),
            'admin_user' => new AdminUserResource($this->whenLoaded('adminUser')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
