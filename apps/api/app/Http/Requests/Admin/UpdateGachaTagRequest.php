<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGachaTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tag = $this->route('tag');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('gacha_tags', 'slug')->ignore($tag?->id)],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
