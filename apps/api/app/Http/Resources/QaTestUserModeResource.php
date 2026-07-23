<?php

namespace App\Http\Resources;

use App\Domain\Gacha\Services\QaTestUserModeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QaTestUserModeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'is_enabled' => (bool) $this->is_enabled,
            'is_active' => app(QaTestUserModeService::class)->isActive($this->resource),
            'reason' => $this->reason,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'enabled_by_admin_user_id' => $this->enabled_by_admin_user_id,
            'disabled_by_admin_user_id' => $this->disabled_by_admin_user_id,
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
