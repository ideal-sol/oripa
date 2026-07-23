<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreGachaPrizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'image_url' => ['required', 'string', 'max:2048'],
            'max_win_count' => ['required', 'integer', 'min:0'],
            'cost_price' => ['required', 'integer', 'min:0'],
            'display_price' => ['nullable', 'integer', 'min:0'],
            'exchange_point' => ['nullable', 'integer', 'min:0'],
            'condition' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'is_visible' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
