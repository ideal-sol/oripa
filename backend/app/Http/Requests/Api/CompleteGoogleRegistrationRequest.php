<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CompleteGoogleRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'registration_token' => ['required', 'string'],
            'referral_code' => ['nullable', 'string', 'max:32'],
            'device_name' => ['sometimes', 'string', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('referral_code')) {
            $this->merge([
                'referral_code' => strtoupper(trim((string) $this->input('referral_code'))),
            ]);
        }
    }

    public function registrationToken(): string
    {
        return (string) $this->validated('registration_token');
    }

    public function referralCode(): ?string
    {
        $value = $this->validated('referral_code');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function deviceName(): string
    {
        return (string) ($this->validated('device_name') ?? 'web');
    }
}
