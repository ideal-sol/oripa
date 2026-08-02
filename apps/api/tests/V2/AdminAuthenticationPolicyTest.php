<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use App\Models\V2\AdminAuthenticationPolicy;
use App\Models\V2\AdminTotpMethod;
use App\Models\V2\AdminWebauthnMethod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Tests\TestCase;

final class AdminAuthenticationPolicyTest extends TestCase
{
    private const PASSWORD = 'valid configurable admin password';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.transactions.store' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_identity.webauthn.rp_id' => 'admin.example.test',
            'v2_identity.webauthn.origin' => 'https://admin.example.test',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Cache::store('array')->clear();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_default_policy_allows_password_only_login_without_invitation_or_enrollment(): void
    {
        $policy = AdminAuthenticationPolicy::query()->sole();
        self::assertFalse($policy->mfa_required);
        self::assertFalse($policy->invitation_required);
        self::assertSame(1, $policy->revision);

        $admin = $this->admin(V2AdminRole::Operator);
        $response = $this->browserMutation()
            ->postJson('/admin/api/v2/auth/login', [
                'email' => $admin->email_display,
                'password' => self::PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('requires_mfa_enrollment', false)
            ->assertJsonPath('mfa_required', false);

        self::assertNull($response->json('transaction_token'));
        self::assertNotNull($response->getCookie('__Host-oripa_admin_session', false));
    }

    public function test_owner_updates_policy_with_occ_idempotency_and_keeps_mfa_credentials(): void
    {
        [$owner, $session] = $this->adminSession(V2AdminRole::Owner);
        AdminTotpMethod::query()->create([
            'admin_id' => $owner->getKey(),
            'secret_ciphertext' => str_repeat('A', 32),
            'encryption_key_version' => 'test-v1',
            'confirmed_at' => now(),
        ]);
        AdminWebauthnMethod::query()->create([
            'admin_id' => $owner->getKey(),
            'credential_id' => 'credential-'.Str::uuid7(),
            'public_key' => 'synthetic-public-key',
            'sign_count' => 0,
            'label' => 'Test authenticator',
        ]);

        $payload = [
            'expected_revision' => 1,
            'mfa_required' => true,
            'invitation_required' => true,
            'current_password' => self::PASSWORD,
        ];
        $key = (string) Str::uuid7();
        $this->adminMutation($session, 'PUT', '/admin/api/v2/auth/policy', $payload, $key)
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonPath('data.invitation_required', true)
            ->assertJsonPath('idempotent_replay', false);

        Auth::forgetGuards();
        $this->adminMutation($session, 'PUT', '/admin/api/v2/auth/policy', $payload, $key)
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('idempotent_replay', true);
        self::assertDatabaseCount('admin_totp_methods', 1);
        self::assertDatabaseCount('admin_webauthn_credentials', 1);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'identity.admin.authentication_policy.updated',
            'outcome' => 'success',
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'event_type' => 'identity.admin.authentication_policy.updated',
        ]);

        Auth::forgetGuards();
        $this->adminMutation(
            $session,
            'PUT',
            '/admin/api/v2/auth/policy',
            [...$payload, 'invitation_required' => false],
            $key
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_policy_is_owner_only_fresh_and_requires_current_password_and_eligible_owner(): void
    {
        foreach ([V2AdminRole::Admin, V2AdminRole::Operator] as $role) {
            [, $session] = $this->adminSession($role);
            Auth::forgetGuards();
            $this->asAdmin($session)->getJson('/admin/api/v2/auth/policy')
                ->assertForbidden()
                ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
        }

        [$owner, $session] = $this->adminSession(V2AdminRole::Owner);
        $payload = [
            'expected_revision' => 1,
            'mfa_required' => true,
            'invitation_required' => false,
            'current_password' => self::PASSWORD,
        ];
        $this->adminMutation($session, 'PUT', '/admin/api/v2/auth/policy', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'MFA_OWNER_ENROLLMENT_REQUIRED');

        Auth::forgetGuards();
        $this->adminMutation($session, 'PUT', '/admin/api/v2/auth/policy', [
            ...$payload,
            'mfa_required' => false,
            'invitation_required' => true,
            'current_password' => 'incorrect password',
        ])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CURRENT_PASSWORD');

        DB::table('admin_sessions')->where('admin_id', $owner->getKey())->update([
            'mfa_verified_at' => now()->subMinutes(5),
        ]);
        Auth::forgetGuards();
        $this->adminMutation($session, 'PUT', '/admin/api/v2/auth/policy', [
            ...$payload,
            'mfa_required' => false,
            'invitation_required' => true,
        ])->assertForbidden()->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');
    }

    public function test_invitation_setting_switches_admin_creation_without_affecting_existing_login(): void
    {
        [$owner, $session] = $this->adminSession(V2AdminRole::Owner);
        $direct = $this->adminMutation($session, 'POST', '/admin/api/v2/auth/admins', [
            'email' => 'direct-admin@example.test',
            'role' => 'admin',
            'temporary_password' => 'valid direct temporary password',
        ])->assertCreated()
            ->assertJsonPath('data.admin.state', 'active')
            ->assertJsonPath('data.invitation_token', null);
        self::assertTrue(Str::isUuid($direct->json('data.admin.id')));

        $this->adminMutation($session, 'PUT', '/admin/api/v2/auth/policy', [
            'expected_revision' => 1,
            'mfa_required' => false,
            'invitation_required' => true,
            'current_password' => self::PASSWORD,
        ])->assertOk();

        Auth::forgetGuards();
        $invited = $this->adminMutation($session, 'POST', '/admin/api/v2/auth/admins', [
            'email' => 'invited-admin@example.test',
            'role' => 'operator',
        ])->assertCreated()->assertJsonPath('data.admin.state', 'invited');
        $token = $invited->json('data.invitation_token');
        self::assertIsString($token);
        self::assertDatabaseMissing('admin_invitations', ['token_hash' => $token]);

        Auth::forgetGuards();
        $this->adminMutation($session, 'PUT', '/admin/api/v2/auth/policy', [
            'expected_revision' => 2,
            'mfa_required' => false,
            'invitation_required' => false,
            'current_password' => self::PASSWORD,
        ])->assertOk();

        $this->browserMutation()->postJson('/admin/api/v2/auth/invitations/accept', [
            'email' => 'invited-admin@example.test',
            'password' => 'valid invited administrator password',
            'invitation_token' => $token,
        ])->assertOk()->assertJsonPath('status', 'authenticated');

        $this->browserMutation()->postJson('/admin/api/v2/auth/login', [
            'email' => $owner->email_display,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    public function test_database_guard_rejects_revision_bypass(): void
    {
        $this->expectException(QueryException::class);
        DB::table('admin_authentication_policy')->where('id', 1)->update([
            'mfa_required' => true,
            'updated_at' => now(),
        ]);
    }

    public function test_database_guard_rejects_policy_delete(): void
    {
        $this->expectException(QueryException::class);
        DB::table('admin_authentication_policy')->where('id', 1)->delete();
    }

    /** @return array{Admin, string} */
    private function adminSession(V2AdminRole $role): array
    {
        $admin = $this->admin($role);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $createdAt = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $admin->getKey(),
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $createdAt,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $createdAt->copy()->addHours(8),
        ]);

        return [$admin, $token];
    }

    private function admin(V2AdminRole $role): Admin
    {
        $email = $role->value.'-'.Str::uuid7().'@example.test';

        return Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash(self::PASSWORD),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function browserMutation(): static
    {
        $csrf = str_repeat('b', 64);

        return $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ]);
    }

    private function adminMutation(
        string $token,
        string $method,
        string $uri,
        array $payload,
        ?string $idempotencyKey = null
    ) {
        $request = $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', str_repeat('c', 64))
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => str_repeat('c', 64),
                'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid7(),
            ]);

        return $method === 'PUT'
            ? $request->putJson($uri, $payload)
            : $request->postJson($uri, $payload);
    }
}
