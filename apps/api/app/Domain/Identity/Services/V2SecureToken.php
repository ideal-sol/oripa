<?php

namespace App\Domain\Identity\Services;

use SensitiveParameter;

final class V2SecureToken
{
    public function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
