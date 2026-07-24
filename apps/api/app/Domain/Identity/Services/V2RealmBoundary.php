<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\V2Realm;
use Illuminate\Auth\Access\AuthorizationException;

final class V2RealmBoundary
{
    /**
     * @throws AuthorizationException
     */
    public function assertAllowed(
        V2Realm $surface,
        bool $userAuthenticated,
        bool $adminAuthenticated,
        ?V2Realm $existingRealm = null,
        bool $adminMfaVerified = false
    ): void {
        if ($surface === V2Realm::Unknown) {
            throw new AuthorizationException('Unknown HTTP surface is denied.');
        }

        if ($existingRealm !== null && $existingRealm !== $surface) {
            throw new AuthorizationException('Realm switching is denied.');
        }

        if ($surface === V2Realm::Webhook) {
            if ($userAuthenticated || $adminAuthenticated) {
                throw new AuthorizationException('Browser sessions are denied on webhook surfaces.');
            }

            return;
        }

        if ($userAuthenticated && $adminAuthenticated) {
            throw new AuthorizationException('Multiple authenticated realms are denied.');
        }

        if ($surface === V2Realm::User && $adminAuthenticated) {
            throw new AuthorizationException('Admin sessions are denied on user surfaces.');
        }

        if ($surface === V2Realm::Admin) {
            if ($userAuthenticated || ! $adminAuthenticated || ! $adminMfaVerified) {
                throw new AuthorizationException('Admin realm access is denied.');
            }
        }
    }
}
