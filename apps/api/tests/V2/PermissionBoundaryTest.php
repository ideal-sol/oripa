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
