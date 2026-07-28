<?php

namespace App\Domain\Identity\Services;

use RuntimeException;
use SensitiveParameter;

final class V2IdentityCorrelation
{
    public function hash(#[SensitiveParameter] string $value): string
    {
        $encoded = config('v2_identity.sms_verification.phone_hmac_key');
        if (! is_string($encoded) || ! str_starts_with($encoded, 'base64:')) {
            throw new RuntimeException('Identity correlation key is unavailable.');
        }
        $key = base64_decode(substr($encoded, 7), true);
        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Identity correlation key is invalid.');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
