<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingRequestHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipping_request_id' => $this->shipping_request_id,
            'admin_user_id' => $this->admin_user_id,
            'from_status' => $this->from_status?->value ?? $this->from_status,
            'to_status' => $this->to_status?->value ?? $this->to_status,
            'tracking_number' => $this->tracking_number,
            'note' => $this->note,
            'admin_user' => new AdminUserResource($this->whenLoaded('adminUser')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
