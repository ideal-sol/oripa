<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProbabilityRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prize_id' => $this->prize_id,
            'is_minimum_guarantee' => $this->is_minimum_guarantee,
            'probability_ppm' => $this->probability_ppm,
        ];
    }
}
