<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGachaCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('gacha_categories', 'slug')->ignore($category?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
