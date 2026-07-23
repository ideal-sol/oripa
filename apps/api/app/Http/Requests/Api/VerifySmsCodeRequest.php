<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class VerifySmsCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
