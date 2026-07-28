<?php

namespace App\Domain\Identity\Contracts;

use App\Models\V2\User;

interface V2SuspiciousRecoveryBoundary
{
    /**
     * @param list<string> $verifiedSignals
     */
    public function requiresSecurityHold(User $user, array $verifiedSignals): bool;
}
