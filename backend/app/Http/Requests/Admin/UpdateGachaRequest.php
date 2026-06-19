<?php

namespace App\Http\Requests\Admin;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
use App\Domain\Gacha\Enums\ProbabilityMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGachaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        $gachaId = $this->route('gacha')?->id;
        $soldCount = (int) ($this->route('gacha')?->sold_count ?? 0);

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash', Rule::unique('gachas', 'slug')->ignore($gachaId)],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:gacha_categories,id'],
            'price' => ['sometimes', 'required', 'integer', 'min:0'],
            'total_count' => ['sometimes', 'required', 'integer', 'min:'.max(1, $soldCount)],
            'probability_mode' => ['sometimes', 'required', Rule::enum(ProbabilityMode::class)],
            'minimum_guarantee_type' => ['sometimes', 'required', Rule::enum(MinimumGuaranteeType::class)],
            'minimum_guarantee_value' => ['sometimes', 'required', 'integer', 'min:0'],
            'minimum_guarantee_cost' => ['sometimes', 'required', 'integer', 'min:0'],
            'status' => ['sometimes', 'required', Rule::enum(GachaStatus::class)],
            'start_at' => ['sometimes', 'nullable', 'date'],
            'end_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_at'],
            'description' => ['sometimes', 'nullable', 'string'],
            'caution' => ['sometimes', 'nullable', 'string'],
            'main_image_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'show_on_top_slider' => ['sometimes', 'boolean'],
            'target_margin' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999.99'],
        ];
    }
}
