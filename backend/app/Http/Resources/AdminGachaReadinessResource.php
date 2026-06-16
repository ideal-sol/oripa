<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminGachaReadinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'gacha_id' => $this->resource['gacha_id'],
            'ready' => $this->resource['ready'],
            'checks' => $this->resource['checks'],
        ];
    }
}
