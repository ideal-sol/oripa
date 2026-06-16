<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'provider_payment_id' => $this->provider_payment_id,
            'status' => $this->status?->value ?? $this->status,
            'amount' => $this->amount,
            'paid_point_amount' => $this->paid_point_amount,
            'free_point_amount' => $this->free_point_amount,
            'currency' => $this->currency,
            'metadata' => $this->metadata,
            'user' => new UserResource($this->whenLoaded('user')),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'chargeback_at' => $this->chargeback_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
