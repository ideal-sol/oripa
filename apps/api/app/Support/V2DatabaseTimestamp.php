<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class V2DatabaseTimestamp
{
    public static function format(?DateTimeInterface $instant): ?string
    {
        return $instant === null
            ? null
            : CarbonImmutable::instance($instant)->utc()->format('Y-m-d H:i:sP');
    }
}
