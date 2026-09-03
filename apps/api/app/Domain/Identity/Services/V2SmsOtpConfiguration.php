<?php

namespace App\Domain\Identity\Services;

use RuntimeException;

final class V2SmsOtpConfiguration
{
    public const MAXIMUM_TTL_MINUTES = 1440;

    public function ttlMinutes(): int
    {
        $configuredTtl = config('v2_identity.sms_verification.ttl_minutes');
        $ttlMinutes = is_string($configuredTtl)
            ? filter_var($configuredTtl, FILTER_VALIDATE_INT)
            : $configuredTtl;
        if (
            ! is_int($ttlMinutes)
            || $ttlMinutes < 1
            || $ttlMinutes > self::MAXIMUM_TTL_MINUTES
        ) {
            throw new RuntimeException('SMS OTP TTL configuration is invalid.');
        }

        return $ttlMinutes;
    }
}
