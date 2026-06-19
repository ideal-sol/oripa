<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePointPurchasePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'paid_point_amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'free_point_amount' => ['required', 'integer', 'min:0', 'max:1000000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
