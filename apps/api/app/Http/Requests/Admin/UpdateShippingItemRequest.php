<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShippingItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:requested,packing,shipped,delivered,returned,canceled'],
            'tracking_number' => ['nullable', 'required_if:status,shipped', 'string', 'max:255'],
            'shipped_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
