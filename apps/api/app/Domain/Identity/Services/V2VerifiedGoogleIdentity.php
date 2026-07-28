<?php

namespace App\Domain\Identity\Services;

final readonly class V2VerifiedGoogleIdentity
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $emailDisplay,
        public string $emailNormalized
    ) {
    }
}
