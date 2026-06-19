<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name_kana' => ['nullable', 'string', 'max:255'],
            'first_name_kana' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'prefecture' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'date'],
        ];
    }
}
