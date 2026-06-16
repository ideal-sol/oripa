<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'paid_balance' => $this->paid_balance,
            'free_balance' => $this->free_balance,
            'total_balance' => (int) $this->paid_balance + (int) $this->free_balance,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
