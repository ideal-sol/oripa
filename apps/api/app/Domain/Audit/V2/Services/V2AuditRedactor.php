<?php

namespace App\Domain\Audit\V2\Services;

use RuntimeException;

final class V2AuditRedactor
{
    private const PROHIBITED_KEY_PARTS = [
        'authorization',
        'cookie',
        'csrf',
        'email',
        'password',
        'recovery',
        'secret',
        'session',
        'token',
    ];

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function sanitize(?array $value): array
    {
        if ($value === null) {
            return [];
        }
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key)) {
                throw new RuntimeException('Audit metadata key is invalid.');
            }
            $lower = strtolower($key);
            foreach (self::PROHIBITED_KEY_PARTS as $prohibited) {
                if (str_contains($lower, $prohibited)) {
                    throw new RuntimeException('Audit metadata contains prohibited sensitive data.');
                }
            }
            if (! is_bool($item) && ! is_float($item) && ! is_int($item) && ! is_string($item) && $item !== null) {
                throw new RuntimeException('Audit metadata value is invalid.');
            }
            if (is_string($item) && strlen($item) > 500) {
                throw new RuntimeException('Audit metadata value is too long.');
            }
            $sanitized[$key] = $item;
        }
        ksort($sanitized, SORT_STRING);

        return $sanitized;
    }
}
