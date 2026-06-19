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
    public function rules(): array
    {
        $gachaId = $this->route('gacha')?->id;

        return [
            'rank_key' => ['required', 'string', 'max:64', Rule::unique('gacha_ranks', 'rank_key')->where('gacha_id', $gachaId)],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'rank_image_asset_id' => ['nullable', 'integer', Rule::exists('rank_assets', 'id')->where('asset_type', 'image')],
            'draw_video_url' => ['nullable', 'string', 'max:2048'],
            'draw_video_asset_id' => ['nullable', 'integer', Rule::exists('rank_assets', 'id')->where('asset_type', 'video')],
            'result_image_url' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_visible' => ['required', 'boolean'],
        ];
    }
}
