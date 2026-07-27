<?php

namespace App\Http\Requests\Api\Gacha;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }
    public function rules(): array
    {
        return [
            'draw_count' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $count = filter_var($value, FILTER_VALIDATE_INT);

                    if ($count === false || ! ($count >= 1 && $count <= 10) && ! in_array($count, [100, 1000], true)) {
                        $fail('The draw count must be between 1 and 10, or one of 100 and 1000.');
                    }
                },
            ],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $drawCount = (int) $this->input('draw_count');
                $bodyKey = $this->input('idempotency_key');
                $headerKey = $this->header('Idempotency-Key');

                if (in_array($drawCount, [100, 1000], true)) {
                    if (! is_string($headerKey) || trim($headerKey) === '' || strlen($headerKey) > 128) {
                        $validator->errors()->add('idempotency_key', 'Idempotency-Key header is required for bulk draws.');
                    } elseif (is_string($bodyKey) && $bodyKey !== '' && ! hash_equals($headerKey, $bodyKey)) {
                        $validator->errors()->add('idempotency_key', 'Idempotency keys in the header and body must match.');
                    }

                    return;
                }

                if (! is_string($bodyKey) || trim($bodyKey) === '') {
                    $validator->errors()->add('idempotency_key', 'The idempotency key field is required.');
                }
            },
        ];
    }

    public function drawCount(): int
    {
        return (int) $this->validated('draw_count');
    }

    public function idempotencyKey(): string
    {
        if (in_array($this->drawCount(), [100, 1000], true)) {
            return trim((string) $this->header('Idempotency-Key'));
        }

        return trim((string) $this->validated('idempotency_key'));
    }
}
