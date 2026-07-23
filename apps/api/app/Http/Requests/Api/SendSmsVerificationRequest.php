<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SendSmsVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function phoneNumber(): ?string
    {
        $value = $this->validated('phone_number');

        return is_string($value) ? $value : null;
    }
}
