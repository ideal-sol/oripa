<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'wallet' => new WalletResource($this->whenLoaded('wallet')),
            'profile' => new UserProfileResource($this->whenLoaded('profile')),
            'point_lots_count' => $this->whenCounted('pointLots'),
            'point_ledgers_count' => $this->whenCounted('pointLedgers'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
