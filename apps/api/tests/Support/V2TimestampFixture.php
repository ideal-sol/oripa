<?php

namespace Tests\Support;

use App\Support\V2DatabaseTimestamp;
use DateTimeInterface;

final class V2TimestampFixture
{
    public static function attributes(array $attributes): array
    {
        foreach ($attributes as $column => $value) {
            if ($value instanceof DateTimeInterface && preg_match('/(?:_at|_until|_from)$/', $column)) {
                $attributes[$column] = V2DatabaseTimestamp::format($value);
            }
        }

        return $attributes;
    }
}
