<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PointLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'point_lot_id' => $this->point_lot_id,
            'point_type' => $this->point_type?->value ?? $this->point_type,
            'ledger_type' => $this->ledger_type?->value ?? $this->ledger_type,
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'description' => $this->description,
            'point_lot' => new PointLotResource($this->whenLoaded('pointLot')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
