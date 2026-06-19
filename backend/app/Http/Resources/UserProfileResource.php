<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'last_name_kana' => $this->last_name_kana,
            'first_name_kana' => $this->first_name_kana,
            'postal_code' => $this->postal_code,
            'prefecture' => $this->prefecture,
            'city' => $this->city,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'phone_number' => $this->phone_number,
            'birth_date' => $this->birth_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
