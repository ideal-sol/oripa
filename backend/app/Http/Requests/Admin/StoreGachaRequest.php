<?php

namespace App\Http\Requests\Admin;

use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
use App\Domain\Gacha\Enums\ProbabilityMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGachaRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('gachas', 'slug')],
            'category_id' => ['required', 'integer', 'exists:gacha_categories,id'],
            'price' => ['required', 'integer', 'min:0'],
            'total_count' => ['required', 'integer', 'min:1'],
            'probability_mode' => ['required', Rule::enum(ProbabilityMode::class)],
            'minimum_guarantee_type' => ['required', Rule::enum(MinimumGuaranteeType::class)],
            'minimum_guarantee_value' => ['required', 'integer', 'min:0'],
            'minimum_guarantee_cost' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::enum(GachaStatus::class)],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'description' => ['nullable', 'string'],
            'caution' => ['nullable', 'string'],
            'main_image_url' => ['nullable', 'string', 'max:2048'],
            'show_on_top_slider' => ['sometimes', 'boolean'],
            'target_margin' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
        ];
    }
}
