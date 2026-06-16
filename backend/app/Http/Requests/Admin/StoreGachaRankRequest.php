<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGachaRankRequest extends FormRequest
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
        $gachaId = $this->route('gacha')?->id;

        return [
            'rank_key' => ['required', 'string', 'max:64', Rule::unique('gacha_ranks', 'rank_key')->where('gacha_id', $gachaId)],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'draw_video_url' => ['nullable', 'string', 'max:2048'],
            'result_image_url' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_visible' => ['required', 'boolean'],
        ];
    }
}
