<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGachaRankRequest extends FormRequest
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
        $rank = $this->route('rank');

        return [
            'rank_key' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('gacha_ranks', 'rank_key')
                    ->where('gacha_id', $rank?->gacha_id)
                    ->ignore($rank?->id),
            ],
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'rank_image_asset_id' => ['sometimes', 'nullable', 'integer', Rule::exists('rank_assets', 'id')->where('asset_type', 'image')],
            'draw_video_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'draw_video_asset_id' => ['sometimes', 'nullable', 'integer', Rule::exists('rank_assets', 'id')->where('asset_type', 'video')],
            'result_image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_visible' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
