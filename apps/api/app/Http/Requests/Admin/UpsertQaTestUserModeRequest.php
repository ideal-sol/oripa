<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertQaTestUserModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof AdminUser && $user->role === AdminRole::Owner;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['required', 'date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $startsAt = $this->filled('starts_at')
                    ? CarbonImmutable::parse($this->input('starts_at'))
                    : CarbonImmutable::now();
                $endsAt = CarbonImmutable::parse($this->input('ends_at'));

                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add('ends_at', 'The ends at must be after the start time.');

                    return;
                }

                if ($startsAt->diffInSeconds($endsAt, false) > 86400) {
                    $validator->errors()->add('ends_at', 'The QA test user mode period may not exceed 24 hours.');
                }
            },
        ];
    }
}
