<?php

namespace App\Domain\Identity\Services;

use App\Support\V2HmacKeyring;
use SensitiveParameter;

final class V2IdentityCorrelation
{
    public function __construct(private readonly V2HmacKeyring $keyring)
    {
    }

    public function hash(#[SensitiveParameter] string $value): string
    {
        return $this->keyring->activeHash(
            'v2_identity.sms_verification.phone_hmac_key',
            $value,
            'Identity correlation key'
        );
    }

    /** @return list<string> */
    public function hashes(#[SensitiveParameter] string $value): array
    {
        return $this->keyring->hashes(
            'v2_identity.sms_verification.phone_hmac_key',
            'v2_identity.sms_verification.phone_hmac_previous_keys',
            $value,
            'Identity correlation key'
        );
    }
}
