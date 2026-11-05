<?php

namespace App\Domain\Payment\V2\Services;

use Carbon\CarbonImmutable;

final class V2PaidyDeadline
{
    public static function authorization(object $payment): CarbonImmutable
    {
        $value = config('v2_paidy.authorization_grace_minutes', 30);
        $minutes = (is_int($value) || (is_string($value) && preg_match('/\A[0-9]{1,3}\z/', $value)))
            && (int) $value >= 0 && (int) $value <= 120 ? (int) $value : 0;

        return CarbonImmutable::parse($payment->expires_at)->addMinutes(
            in_array($payment->status, ['requires_action', 'processing'], true) ? $minutes : 0
        );
    }

    public static function elapsed(object $payment): bool
    {
        // The deadline itself is excluded, for capture, expiry and settlement.
        return CarbonImmutable::now()->greaterThanOrEqualTo(self::authorization($payment));
    }
}
