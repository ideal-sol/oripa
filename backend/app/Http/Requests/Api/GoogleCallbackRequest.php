<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class GoogleCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:128'],
        ];
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    public function state(): string
    {
        return (string) $this->validated('state');
    }

    public function deviceName(): string
    {
        return (string) ($this->validated('device_name') ?? 'web');
    }
}
