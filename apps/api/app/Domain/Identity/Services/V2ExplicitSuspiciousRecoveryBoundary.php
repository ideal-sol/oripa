<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\V2SuspiciousRecoveryBoundary;
use App\Models\V2\User;

final class V2ExplicitSuspiciousRecoveryBoundary implements V2SuspiciousRecoveryBoundary
{
    public function requiresSecurityHold(User $user, array $verifiedSignals): bool
    {
        return $verifiedSignals !== [];
    }
}
