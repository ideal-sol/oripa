<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentReversalPointEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_reversal_id' => $this->payment_reversal_id,
            'payment_id' => $this->payment_id,
            'user_id' => $this->user_id,
            'point_lot_id' => $this->point_lot_id,
            'point_ledger_id' => $this->point_ledger_id,
            'point_type' => $this->point_type?->value ?? $this->point_type,
            'bucket' => $this->bucket?->value ?? $this->bucket,
            'amount' => $this->amount,
            'shortfall_amount' => $this->shortfall_amount,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
