<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDrawRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'gacha_id' => $this->gacha_id,
            'draw_count' => $this->draw_count,
            'idempotency_key' => $this->idempotency_key,
            'status' => $this->status?->value ?? $this->status,
            'consumed_point_total' => $this->consumed_point_total,
            'results_count' => $this->whenCounted('results'),
            'user' => new UserResource($this->whenLoaded('user')),
            'gacha' => $this->whenLoaded('gacha', fn (): array => [
                'id' => $this->gacha->id,
                'title' => $this->gacha->title,
                'slug' => $this->gacha->slug,
                'price' => $this->gacha->price,
            ]),
            'results' => AdminDrawResultResource::collection($this->whenLoaded('results')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
