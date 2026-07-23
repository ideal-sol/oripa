<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipping_request_id' => $this->shipping_request_id,
            'user_prize_id' => $this->user_prize_id,
            'status' => $this->status?->value ?? $this->status,
            'tracking_number' => $this->tracking_number,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'user_prize' => new UserPrizeResource($this->whenLoaded('userPrize')),
        ];
    }
}
