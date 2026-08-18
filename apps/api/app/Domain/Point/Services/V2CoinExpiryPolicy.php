<?php

namespace App\Domain\Point\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class V2CoinExpiryPolicy
{
    public const EXPIRY_DAYS = 180;

    public function expiresAt(CarbonInterface $grantedAt): CarbonImmutable
    {
        return CarbonImmutable::instance($grantedAt)->startOfSecond()->addDays(self::EXPIRY_DAYS);
    }
}
