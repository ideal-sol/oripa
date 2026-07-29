<?php

namespace App\Domain\Identity\Services;

final readonly class V2VerifiedExternalIdentity
{
    public function __construct(
        public string $issuer,
        public string $subject,
        public ?string $emailDisplay,
        public ?string $emailNormalized
    ) {
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->emailDisplay !== null && $this->emailNormalized !== null;
    }
}
