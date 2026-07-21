<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReversalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'user_id' => $this->user_id,
            'admin_user_id' => $this->admin_user_id,
            'type' => $this->type?->value ?? $this->type,
            'status' => $this->status?->value ?? $this->status,
            'reason' => $this->reason,
            'payment_amount' => $this->payment_amount,
            'paid_point_amount' => $this->paid_point_amount,
            'free_point_amount' => $this->free_point_amount,
            'paid_reversed_amount' => $this->paid_reversed_amount,
            'free_reversed_amount' => $this->free_reversed_amount,
            'shortfall_paid_amount' => $this->shortfall_paid_amount,
            'shortfall_free_amount' => $this->shortfall_free_amount,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'user' => new UserResource($this->whenLoaded('user')),
            'admin_user' => new AdminUserResource($this->whenLoaded('adminUser')),
            'point_entries' => PaymentReversalPointEntryResource::collection($this->whenLoaded('pointEntries')),
            'prize_actions' => PaymentReversalPrizeActionResource::collection($this->whenLoaded('prizeActions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
