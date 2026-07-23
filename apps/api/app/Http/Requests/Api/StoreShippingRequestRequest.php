<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreShippingRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'user_prize_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_prize_ids.*' => ['required', 'integer', 'distinct', 'exists:user_prizes,id'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:16'],
            'prefecture' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:32'],
        ];
    }
}
