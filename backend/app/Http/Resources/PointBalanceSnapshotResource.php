<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointBalanceSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paidBalance = (int) $this->paid_unused_balance;
        $freeBalance = (int) $this->free_unused_balance;

        return [
            'id' => $this->id,
            'snapshot_date' => $this->snapshot_date?->toDateString(),
            'paid_balance' => $paidBalance,
            'free_balance' => $freeBalance,
            'total_balance' => $paidBalance + $freeBalance,
            'is_base_date' => (bool) $this->is_base_date,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
