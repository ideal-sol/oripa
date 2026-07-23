<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status?->value ?? $this->status,
            'recipient_name' => $this->recipient_name,
            'postal_code' => $this->postal_code,
            'prefecture' => $this->prefecture,
            'city' => $this->city,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'phone_number' => $this->phone_number,
            'tracking_number' => $this->tracking_number,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'items_count' => $this->whenCounted('items'),
            'user' => new UserResource($this->whenLoaded('user')),
            'items' => ShippingItemResource::collection($this->whenLoaded('items')),
            'histories' => ShippingRequestHistoryResource::collection($this->whenLoaded('histories')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
