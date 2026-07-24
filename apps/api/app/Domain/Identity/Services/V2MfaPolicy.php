<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\V2AdminRole;

final class V2MfaPolicy
{
    public function allowsAccess(
        V2AdminRole $role,
        int $activeWebauthnCredentials,
        int $activeTotpMethods
    ): bool {
        if ($activeWebauthnCredentials < 0 || $activeTotpMethods < 0) {
            return false;
        }

        $authenticatorCount = $activeWebauthnCredentials + $activeTotpMethods;

        return match ($role) {
            V2AdminRole::Owner =>
                $authenticatorCount >= 2 && $activeWebauthnCredentials >= 1,
            V2AdminRole::Admin, V2AdminRole::Operator => $authenticatorCount >= 1,
        };
    }
}
