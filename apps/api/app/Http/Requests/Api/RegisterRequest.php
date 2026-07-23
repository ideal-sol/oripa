<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use App\Models\User;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $localPart = explode('@', (string) $value, 2)[0] ?? '';

                    if (str_contains($localPart, '+')) {
                        $fail('+ を含むメールアドレスは登録できません。');
                    }
                },
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $exists = User::query()
                        ->whereRaw('LOWER(email) = LOWER(?)', [(string) $value])
                        ->whereNotNull('email_verified_at')
                        ->whereIn('status', ['active', 'suspended'])
                        ->exists();

                    if ($exists) {
                        $fail('このメールアドレスはすでに認証済みです。');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::min(8)],
            'last_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'referral_code' => [
                'nullable',
                'string',
                'max:32',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value) {
                        return;
                    }

                    if (! User::query()->where('referral_code', (string) $value)->exists()) {
                        $fail('紹介コードが見つかりません。');
                    }
                },
            ],
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

    public function deviceName(): string
    {
        return (string) ($this->validated('device_name') ?? 'web');
    }
}
