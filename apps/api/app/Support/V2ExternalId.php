<?php

namespace App\Support;

use Throwable;

final class V2ExternalId
{
    public static function normalize(mixed $value, Throwable $invalid): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw $invalid;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $value) !== 1) {
            throw $invalid;
        }

        return $value;
    }
}
