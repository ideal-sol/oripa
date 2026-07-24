<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2Permission;

final class V2PermissionAuthorizer
{
    private const ROLE_PERMISSIONS = [
        'owner' => [
            'identity.admin.read',
            'identity.admin.manage',
            'identity.admin.session.revoke',
        ],
        'admin' => [
            'identity.admin.read',
            'identity.admin.session.revoke',
        ],
        'operator' => [
            'identity.admin.read',
        ],
    ];

    public function allows(V2AdminRole|string $role, V2Permission|string $permission): bool
    {
        $roleValue = $role instanceof V2AdminRole ? $role->value : $role;
        $permissionValue = $permission instanceof V2Permission ? $permission->value : $permission;

        if (V2AdminRole::tryFrom($roleValue) === null || V2Permission::tryFrom($permissionValue) === null) {
            return false;
        }

        return in_array($permissionValue, self::ROLE_PERMISSIONS[$roleValue] ?? [], true);
    }
}
