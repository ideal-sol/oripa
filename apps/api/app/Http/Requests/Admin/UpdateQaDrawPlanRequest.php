<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateQaDrawPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof AdminUser && $user->role === AdminRole::Owner;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([QaDrawPlanStatus::Active->value, QaDrawPlanStatus::Paused->value])],
            'title' => ['nullable', 'string', 'max:255'],
            'reason' => ['sometimes', 'required', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.sort_order' => ['required_with:items', 'integer', 'min:0'],
            'items.*.gacha_prize_id' => ['required_with:items', 'integer', 'exists:gacha_prizes,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.rank_image_asset_id' => ['nullable', 'integer', 'exists:rank_assets,id'],
            'items.*.draw_video_asset_id' => ['nullable', 'integer', 'exists:rank_assets,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('ends_at')) {
                    return;
                }

                $startsAt = $this->filled('starts_at')
                    ? CarbonImmutable::parse($this->input('starts_at'))
                    : CarbonImmutable::now();
                $endsAt = CarbonImmutable::parse($this->input('ends_at'));

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add('ends_at', 'The ends at must be after the start time.');
                }
            },
        ];
    }
}
