<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class PermissionBoundaryTest extends TestCase
{
    public function test_permissions_are_centralized_and_deny_unknown_codes(): void
    {
        $authorizer = new V2PermissionAuthorizer();

        self::assertTrue(
            $authorizer->allows(V2AdminRole::Owner, V2Permission::ManageAdminIdentity)
        );
        self::assertFalse(
            $authorizer->allows(V2AdminRole::Operator, V2Permission::ManageAdminIdentity)
        );
        self::assertFalse($authorizer->allows('custom-role', 'identity.admin.read'));
        self::assertFalse($authorizer->allows('owner', 'unregistered.permission'));
        self::assertTrue(
            $authorizer->allows(V2AdminRole::Operator, V2Permission::ReadCatalog)
        );
        self::assertTrue(
            $authorizer->allows(V2AdminRole::Owner, V2Permission::ManageCatalog)
        );
        self::assertTrue(
            $authorizer->allows(V2AdminRole::Admin, V2Permission::ManageCatalog)
        );
        self::assertFalse(
            $authorizer->allows(V2AdminRole::Operator, V2Permission::ManageCatalog)
        );
        self::assertTrue(
            $authorizer->allows(V2AdminRole::Owner, V2Permission::PublishCatalog)
        );
        self::assertTrue(
            $authorizer->allows(V2AdminRole::Admin, V2Permission::PublishCatalog)
        );
        self::assertFalse(
            $authorizer->allows(V2AdminRole::Operator, V2Permission::PublishCatalog)
        );
        self::assertTrue(
            $authorizer->allows(V2AdminRole::Owner, V2Permission::ManagePointAdjustment)
        );
        self::assertTrue(
            $authorizer->allows(V2AdminRole::Admin, V2Permission::ManagePointAdjustment)
        );
        self::assertFalse(
            $authorizer->allows(V2AdminRole::Operator, V2Permission::ManagePointAdjustment)
        );
    }

    public function test_effective_permissions_are_registered_unique_and_role_scoped(): void
    {
        $authorizer = new V2PermissionAuthorizer();
        $known = array_map(
            static fn (V2Permission $permission): string => $permission->value,
            V2Permission::cases()
        );

        foreach (V2AdminRole::cases() as $role) {
            $effective = $authorizer->effectivePermissions($role);
            self::assertSame($effective, array_values(array_unique($effective)));
            self::assertSame([], array_diff($effective, $known));
            self::assertContains(V2Permission::ReadCatalog->value, $effective);
        }

        self::assertContains(
            V2Permission::ManageQaDraw->value,
            $authorizer->effectivePermissions(V2AdminRole::Owner)
        );
        self::assertNotContains(
            V2Permission::ManageQaDraw->value,
            $authorizer->effectivePermissions(V2AdminRole::Admin)
        );
        self::assertNotContains(
            V2Permission::ReadFinancialReporting->value,
            $authorizer->effectivePermissions(V2AdminRole::Operator)
        );
        self::assertSame([], $authorizer->effectivePermissions('unregistered-role'));
    }

    public function test_laravel_gate_uses_the_v2_admin_provider_model(): void
    {
        $operator = new Admin(['role' => V2AdminRole::Operator->value]);

        self::assertTrue(
            Gate::forUser($operator)->allows(
                'v2.permission',
                V2Permission::ReadAdminIdentity->value
            )
        );
        self::assertFalse(
            Gate::forUser($operator)->allows(
                'v2.permission',
                V2Permission::ManageAdminIdentity->value
            )
        );
    }

    public function test_auth_providers_and_guards_do_not_share_models(): void
    {
        self::assertSame('v2_realm_session', config('auth.guards.v2_user.driver'));
        self::assertSame('v2_realm_session', config('auth.guards.v2_admin.driver'));
        self::assertSame('v2_user', config('auth.guards.v2_user.provider'));
        self::assertSame('v2_admin', config('auth.guards.v2_admin.provider'));
        self::assertSame('user', config('auth.guards.v2_user.realm'));
        self::assertSame('admin', config('auth.guards.v2_admin.realm'));
        self::assertSame(User::class, config('auth.providers.v2_user.model'));
        self::assertSame(Admin::class, config('auth.providers.v2_admin.model'));
        self::assertNotSame(
            config('auth.providers.v2_user.model'),
            config('auth.providers.v2_admin.model')
        );
    }
}
