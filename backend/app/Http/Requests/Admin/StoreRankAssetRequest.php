<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRankAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'asset_type' => ['required', 'string', Rule::in(['image', 'video'])],
            'url' => ['required', 'string', 'max:2048'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
