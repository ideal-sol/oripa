<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'referrer_user_id' => $this->referrer_user_id,
            'referred_user_id' => $this->referred_user_id,
            'referral_code' => $this->referral_code,
            'status' => $this->status,
            'reward_point_amount' => $this->reward_point_amount,
            'reward_expiration_days' => $this->reward_expiration_days,
            'rewarded_at' => $this->rewarded_at?->toIso8601String(),
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'referrer' => new UserResource($this->whenLoaded('referrer')),
            'referred' => new UserResource($this->whenLoaded('referred')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
