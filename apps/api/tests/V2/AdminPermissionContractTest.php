<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminPermissionContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_owner_admin_and_operator_receive_only_effective_permissions(): void
    {
        $authorizer = app(V2PermissionAuthorizer::class);

        foreach (V2AdminRole::cases() as $role) {
            $token = $this->createAdminSession($role);
            Auth::forgetGuards();
            $response = $this
                ->withCredentials()
                ->withUnencryptedCookie('__Host-oripa_admin_session', $token)
                ->getJson('/admin/api/v2/auth/permissions')
                ->assertOk()
                ->assertJsonPath('role', $role->value)
                ->assertJsonPath(
                    'permissions',
                    $authorizer->effectivePermissions($role)
                );

            $cacheControl = $response->headers->get('Cache-Control');
            self::assertIsString($cacheControl);
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
            self::assertTrue(Str::isUuid($response->json('request_id')));
            self::assertSame(
                $response->json('request_id'),
                $response->headers->get('X-Request-Id')
            );
        }
    }

    public function test_module_permissions_follow_the_central_matrix(): void
    {
        $owner = app(V2PermissionAuthorizer::class)
            ->effectivePermissions(V2AdminRole::Owner);
        $admin = app(V2PermissionAuthorizer::class)
            ->effectivePermissions(V2AdminRole::Admin);
        $operator = app(V2PermissionAuthorizer::class)
            ->effectivePermissions(V2AdminRole::Operator);

        foreach ([
            V2Permission::ReadCatalog->value,
            V2Permission::ManageShippingRequest->value,
            V2Permission::ReadContent->value,
            V2Permission::ReadContact->value,
            V2Permission::ReadLineMessaging->value,
            V2Permission::ReadReferralSettings->value,
            V2Permission::ReadPointPurchasePlan->value,
            V2Permission::ReadUserTag->value,
        ] as $permission) {
            self::assertContains($permission, $owner);
            self::assertContains($permission, $admin);
            self::assertContains($permission, $operator);
        }
        self::assertContains(V2Permission::ManageQaDraw->value, $owner);
        self::assertNotContains(V2Permission::ManageQaDraw->value, $admin);
        self::assertNotContains(V2Permission::ManageQaDraw->value, $operator);
        self::assertContains(V2Permission::ManageCatalog->value, $owner);
        self::assertContains(V2Permission::ManageCatalog->value, $admin);
        self::assertNotContains(V2Permission::ManageCatalog->value, $operator);
        self::assertContains(V2Permission::ManagePointAdjustment->value, $owner);
        self::assertContains(V2Permission::ManagePointAdjustment->value, $admin);
        self::assertNotContains(V2Permission::ManagePointAdjustment->value, $operator);
        self::assertContains(V2Permission::ManageReferralSettings->value, $owner);
        self::assertContains(V2Permission::ManageReferralSettings->value, $admin);
        self::assertNotContains(V2Permission::ManageReferralSettings->value, $operator);
        self::assertContains(V2Permission::ManagePointPurchasePlan->value, $owner);
        self::assertContains(V2Permission::ManagePointPurchasePlan->value, $admin);
        self::assertNotContains(V2Permission::ManagePointPurchasePlan->value, $operator);
        self::assertContains(V2Permission::ManageLineMessaging->value, $owner);
        self::assertContains(V2Permission::ManageLineMessaging->value, $admin);
        self::assertNotContains(V2Permission::ManageLineMessaging->value, $operator);
        self::assertContains(V2Permission::ManageUserTag->value, $owner);
        self::assertContains(V2Permission::ManageUserTag->value, $admin);
        self::assertNotContains(V2Permission::ManageUserTag->value, $operator);
        self::assertContains(V2Permission::PublishCatalog->value, $owner);
        self::assertContains(V2Permission::PublishCatalog->value, $admin);
        self::assertNotContains(V2Permission::PublishCatalog->value, $operator);
        self::assertContains(V2Permission::ReadFinancialReporting->value, $owner);
        self::assertContains(V2Permission::ReadFinancialReporting->value, $admin);
        self::assertNotContains(
            V2Permission::ReadFinancialReporting->value,
            $operator
        );
    }

    public function test_permission_contract_rejects_missing_or_incomplete_admin_session(): void
    {
        $this->getJson('/admin/api/v2/auth/permissions')->assertUnauthorized();

        $incomplete = $this->createAdminSession(
            V2AdminRole::Owner,
            requiresEnrollment: true
        );
        $this
            ->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $incomplete)
            ->getJson('/admin/api/v2/auth/permissions')
            ->assertUnauthorized();

        $withoutMfa = $this->createAdminSession(
            V2AdminRole::Owner,
            mfaVerified: false
        );
        $this
            ->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $withoutMfa)
            ->getJson('/admin/api/v2/auth/permissions')
            ->assertUnauthorized();
    }

    public function test_user_realm_cookie_cannot_access_admin_permissions(): void
    {
        $this
            ->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_user_session', str_repeat('f', 64))
            ->getJson('/admin/api/v2/auth/permissions')
            ->assertUnauthorized();
    }

    private function createAdminSession(
        V2AdminRole $role,
        bool $requiresEnrollment = false,
        bool $mfaVerified = true
    ): string {
        $email = $role->value.'-'.Str::uuid7().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid permission test password'),
            'role' => $role->value,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $createdAt = now()->subSecond();
        $lastActivityAt = $createdAt->copy()->addSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => $mfaVerified ? now() : null,
            'requires_mfa_enrollment' => $requiresEnrollment,
            'created_at' => $createdAt,
            'last_activity_at' => $lastActivityAt,
            'idle_expires_at' => $lastActivityAt->copy()->addMinutes(15),
            'absolute_expires_at' => $createdAt->copy()->addHours(8),
        ]);

        return $token;
    }
}
