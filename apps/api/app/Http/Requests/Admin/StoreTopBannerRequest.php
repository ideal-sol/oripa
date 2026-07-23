<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTopBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image_url' => ['required', 'string', 'max:2048'],
            'link_url' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
