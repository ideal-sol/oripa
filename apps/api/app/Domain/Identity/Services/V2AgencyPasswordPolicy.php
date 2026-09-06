<?php

namespace App\Domain\Identity\Services;

use InvalidArgumentException;
use SensitiveParameter;

final class V2AgencyPasswordPolicy
{
    public function isAllowed(#[SensitiveParameter] string $password): bool
    {
        return preg_match('/\A[A-Za-z0-9]{6,20}\z/D', $password) === 1;
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        if (! $this->isAllowed($password)) {
            throw new InvalidArgumentException('The Agency credential does not satisfy the policy.');
        }

        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 3,
            'threads' => 1,
        ]);
    }
}
