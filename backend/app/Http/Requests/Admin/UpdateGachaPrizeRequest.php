<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGachaPrizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        $wonCount = (int) ($this->route('prize')?->won_count ?? 0);

        return [
            'rank_id' => ['sometimes', 'required', 'integer', 'exists:gacha_ranks,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'image_url' => ['sometimes', 'required', 'string', 'max:2048'],
            'max_win_count' => ['sometimes', 'required', 'integer', 'min:'.$wonCount],
            'cost_price' => ['sometimes', 'required', 'integer', 'min:0'],
            'display_price' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'exchange_point' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'condition' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'is_visible' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }
}
