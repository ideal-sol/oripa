<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
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
            'point_purchase_plan_id' => ['nullable', 'integer', 'exists:point_purchase_plans,id'],
            'amount' => ['required_without:point_purchase_plan_id', 'integer', 'min:1', 'max:1000000'],
            'paid_point_amount' => ['required_without:point_purchase_plan_id', 'integer', 'min:1', 'max:1000000'],
            'free_point_amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'currency' => ['nullable', Rule::in(['JPY'])],
            'provider' => ['nullable', Rule::in(['mock'])],
            'terms_accepted' => ['accepted'],
        ];
    }
}
