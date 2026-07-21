<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReversalPrizeActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_reversal_id' => $this->payment_reversal_id,
            'user_prize_id' => $this->user_prize_id,
            'shipping_item_id' => $this->shipping_item_id,
            'action_type' => $this->action_type?->value ?? $this->action_type,
            'previous_user_prize_status' => $this->previous_user_prize_status,
            'previous_shipping_item_status' => $this->previous_shipping_item_status,
            'status' => $this->status?->value ?? $this->status,
            'note' => $this->note,
            'mail_sent_at' => $this->mail_sent_at?->toIso8601String(),
            'mail_last_error' => $this->mail_last_error,
            'mail_last_attempted_at' => $this->mail_last_attempted_at?->toIso8601String(),
            'discord_last_error' => $this->discord_last_error,
            'discord_last_attempted_at' => $this->discord_last_attempted_at?->toIso8601String(),
            'user_prize' => new AdminUserPrizeResource($this->whenLoaded('userPrize')),
            'shipping_item' => new ShippingItemResource($this->whenLoaded('shippingItem')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
