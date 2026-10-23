<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2Permission;

final class V2PermissionAuthorizer
{
    private const ROLE_PERMISSIONS = [
        'owner' => [
            'member_rank.settings.read',
            'member_rank.settings.manage',
            'member_rank.user.manage',
            'payment.adjustment.manage',
            'agency.read',
            'agency.manage',
            'identity.admin.read',
            'identity.admin.manage',
            'identity.admin.session.revoke',
            'identity.line.read',
            'identity.line.manage',
            'user.tag.read',
            'user.tag.manage',
            'user.state.manage',
            'point.ledger.read',
            'point.adjustment.request',
            'point.adjustment.free.approve',
            'point.adjustment.paid.approve',
            'point.adjustment.manage',
            'referral.settings.read',
            'referral.settings.manage',
            'shipping.settings.manage',
            'pre_registration.settings.read',
            'pre_registration.settings.manage',
            'payment.plan.read',
            'payment.plan.manage',
            'catalog.read',
            'catalog.manage',
            'catalog.publish',
            'shipping.settings.read',
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
            'member_rank.settings.read',
            'member_rank.settings.manage',
            'member_rank.user.manage',
            'payment.adjustment.manage',
            'agency.read',
            'agency.manage',
            'identity.admin.read',
            'identity.admin.session.revoke',
            'identity.line.read',
            'identity.line.manage',
            'user.tag.read',
            'user.tag.manage',
            'user.state.manage',
            'point.ledger.read',
            'point.adjustment.request',
            'point.adjustment.free.approve',
            'point.adjustment.manage',
            'referral.settings.read',
            'referral.settings.manage',
            'shipping.settings.manage',
            'pre_registration.settings.read',
            'pre_registration.settings.manage',
            'payment.plan.read',
            'payment.plan.manage',
            'catalog.read',
            'catalog.manage',
            'catalog.publish',
            'shipping.settings.read',
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
            'member_rank.settings.read',
            'pre_registration.settings.read',
            'agency.read',
            'identity.admin.read',
            'identity.line.read',
            'user.tag.read',
            'point.ledger.read',
            'referral.settings.read',
            'payment.plan.read',
            'catalog.read',
            'shipping.settings.read',
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
