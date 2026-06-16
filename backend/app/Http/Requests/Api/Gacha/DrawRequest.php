<?php

namespace App\Http\Requests\Api\Gacha;

use Illuminate\Foundation\Http\FormRequest;

class DrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active';
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'draw_count' => ['required', 'integer', 'min:1', 'max:10'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ];
    }

    public function drawCount(): int
    {
        return (int) $this->validated('draw_count');
    }

    public function idempotencyKey(): string
    {
        return (string) $this->validated('idempotency_key');
    }
}
