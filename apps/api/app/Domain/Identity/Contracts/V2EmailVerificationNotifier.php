<?php

namespace App\Domain\Identity\Contracts;

use App\Models\V2\User;
use SensitiveParameter;

interface V2EmailVerificationNotifier
{
    public function send(
        User $user,
        #[SensitiveParameter] string $token,
        string $redirectPath,
        string $deduplicationKey
    ): void;
}
