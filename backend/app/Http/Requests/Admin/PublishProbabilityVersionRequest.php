<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PublishProbabilityVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'change_reason' => ['nullable', 'string', 'max:2000'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.stage_key' => ['required', 'string', 'max:64'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.condition_type' => ['sometimes', 'string', 'in:sold_count'],
            'stages.*.min_draw_number' => ['required', 'integer', 'min:1'],
            'stages.*.max_draw_number' => ['nullable', 'integer', 'min:1'],
            'stages.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'stages.*.probabilities' => ['required', 'array', 'min:1'],
            'stages.*.probabilities.*.prize_id' => ['nullable', 'integer'],
            'stages.*.probabilities.*.is_minimum_guarantee' => ['sometimes', 'boolean'],
            'stages.*.probabilities.*.probability_ppm' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
    public function stages(): array
    {
        return $this->validated('stages');
    }

    public function changeReason(): ?string
    {
        return $this->validated('change_reason') ?? null;
    }
}
