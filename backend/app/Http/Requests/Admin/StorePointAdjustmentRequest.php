<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePointAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'adjustment_type' => ['required', Rule::in(['grant', 'deduct'])],
            'point_type' => ['required_if:adjustment_type,grant', 'prohibited_unless:adjustment_type,grant', 'nullable', Rule::in(['paid', 'free'])],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
            'expire_at' => ['required_if:point_type,free', 'prohibited_unless:point_type,free', 'nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
