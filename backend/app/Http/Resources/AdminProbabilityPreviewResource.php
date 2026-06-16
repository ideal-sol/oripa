<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProbabilityPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'valid' => true,
            'gacha_id' => $this->resource['gacha_id'],
            'total_ppm' => $this->resource['total_ppm'],
            'stages' => $this->resource['stages'],
        ];
    }
}
