<?php

namespace App\Http\Resources;

use App\Models\LineFriendLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LineFriendSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'friend_add_url' => $this->friend_add_url,
            'reward_point_amount' => $this->reward_point_amount,
            'reward_expiration_days' => $this->reward_expiration_days,
            'is_active' => (bool) $this->is_active,
            'auto_reply_message' => $this->auto_reply_message,
            'friends_count' => LineFriendLink::query()->whereIn('status', ['friend', 'linked'])->count(),
            'blocked_count' => LineFriendLink::query()->where('status', 'blocked')->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
