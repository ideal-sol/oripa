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
            'point.ledger.read',
            'point.adjustment.request',
            'point.adjustment.free.approve',
            'point.adjustment.paid.approve',
            'catalog.read',
            'shipping.request.manage',
            'qa.draw.manage',
            'reporting.financial.read',
            'reporting.financial.export',
            'content.read',
            'content.manage',
            'content.publish',
            'contact.read',
            'contact.manage',
        ],
        'admin' => [
            'identity.admin.read',
            'identity.admin.session.revoke',
            'point.ledger.read',
            'point.adjustment.request',
            'point.adjustment.free.approve',
            'catalog.read',
            'shipping.request.manage',
            'reporting.financial.read',
            'reporting.financial.export',
            'content.read',
            'content.manage',
            'content.publish',
            'contact.read',
            'contact.manage',
        ],
        'operator' => [
            'identity.admin.read',
            'point.ledger.read',
            'catalog.read',
            'shipping.request.manage',
            'content.read',
            'contact.read',
        ],
    ];

    public function allows(V2AdminRole|string $role, V2Permission|string $permission): bool
    {
        $permissionValue = $permission instanceof V2Permission ? $permission->value : $permission;

        if (V2Permission::tryFrom($permissionValue) === null) {
            return false;
        }

        return in_array($permissionValue, $this->effectivePermissions($role), true);
    }

    /**
     * @return list<string>
     */
    public function effectivePermissions(V2AdminRole|string $role): array
    {
        $roleValue = $role instanceof V2AdminRole ? $role->value : $role;
        if (V2AdminRole::tryFrom($roleValue) === null) {
            return [];
        }

        $permissions = self::ROLE_PERMISSIONS[$roleValue] ?? [];
        if (count($permissions) !== count(array_unique($permissions))) {
            return [];
        }
        foreach ($permissions as $permission) {
            if (V2Permission::tryFrom($permission) === null) {
                return [];
            }
        }

        sort($permissions, SORT_STRING);

        return $permissions;
    }
}
