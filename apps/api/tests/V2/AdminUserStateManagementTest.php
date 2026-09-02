<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUserStateManagementTest extends TestCase
{
    private const USER_PASSWORD = 'valid user state password';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('u', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Cache::store('array')->clear();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_owner_and_admin_apply_only_canonical_transitions_and_revoke_access(): void
    {
        $user = $this->user(V2UserState::Active);
        $userSession = $this->userSession($user);
        $this->rememberDevice($user);
        DB::table('user_phone_numbers')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->getKey(),
            'phone_ciphertext' => Crypt::encryptString('+819012345678'),
            'phone_hmac' => hash('sha256', 'admin-state-phone-'.$user->public_id),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owner = $this->adminSession(V2AdminRole::Owner);

        $suspended = $this->mutate($owner, $user, 'suspended', 1, 'Support investigation.')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.state_revision', 2)
            ->assertJsonPath('idempotent_replay', false);
        self::assertSame('false', $suspended->headers->get('Idempotency-Replayed'));
        self::assertNotNull(DB::table('user_sessions')
            ->where('session_id_hash', app(V2SessionPolicy::class)->hashSessionId($userSession))
            ->value('revoked_at'));
        self::assertNotNull(DB::table('user_remember_devices')
            ->where('user_id', $user->id)->value('revoked_at'));
        self::assertNull(DB::table('user_phone_numbers')
            ->where('user_id', $user->id)->value('revoked_at'));

        Auth::forgetGuards();
        $admin = $this->adminSession(V2AdminRole::Admin);
        $this->mutate($admin, $user, 'active', 2, 'Account review completed.')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.state_revision', 3);

        $secondSession = $this->userSession($user);
        $this->rememberDevice($user);
        Auth::forgetGuards();
        $this->mutate($owner, $user, 'closed', 3, 'User requested account closure.')
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.state_revision', 4);
        self::assertNotNull(DB::table('user_sessions')
            ->where('session_id_hash', app(V2SessionPolicy::class)->hashSessionId($secondSession))
            ->value('revoked_at'));
        self::assertNotNull(DB::table('user_phone_numbers')
            ->where('user_id', $user->id)->value('revoked_at'));
        self::assertSame(3, DB::table('audit_logs')->where('action_code', 'user.state.updated')->count());
        self::assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 200,
            'free_balance' => 300,
        ]);
        self::assertSame(1, DB::table('mail_deliveries')
            ->where('event_key', 'user.closed:'.$user->public_id)->count());
    }

    public function test_permissions_validation_occ_transition_and_idempotency_fail_closed(): void
    {
        $user = $this->user(V2UserState::Active);
        $operator = $this->adminSession(V2AdminRole::Operator);
        $this->mutate($operator, $user, 'suspended', 1, 'Operator attempt.')
            ->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

        Auth::forgetGuards();
        $owner = $this->adminSession(V2AdminRole::Owner);
        $this->mutate($owner, $user, 'suspended', 2, 'Stale revision.')
            ->assertConflict()->assertJsonPath('code', 'ADMIN_USER_STATE_REVISION_CONFLICT');
        Auth::forgetGuards();
        $this->mutate($owner, $user, 'active', 1, 'Same state.')
            ->assertConflict()->assertJsonPath('code', 'ADMIN_USER_STATE_TRANSITION_INVALID');
        Auth::forgetGuards();
        $this->mutate($owner, $user, 'closed', 1, '')
            ->assertUnprocessable()->assertJsonPath('code', 'ADMIN_USER_STATE_INVALID');

        $key = (string) Str::uuid7();
        Auth::forgetGuards();
        $first = $this->mutate($owner, $user, 'suspended', 1, 'Security suspension.', $key)
            ->assertOk()->assertJsonPath('idempotent_replay', false);
        Auth::forgetGuards();
        $replay = $this->mutate($owner, $user, 'suspended', 1, 'Security suspension.', $key)
            ->assertOk()->assertJsonPath('idempotent_replay', true);
        self::assertEquals($first->json('data'), $replay->json('data'));
        Auth::forgetGuards();
        $this->mutate($owner, $user, 'closed', 1, 'Different payload.', $key)
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        foreach ([V2UserState::PendingVerification, V2UserState::Restricted, V2UserState::Anonymized] as $state) {
            $target = $this->user($state);
            Auth::forgetGuards();
            $this->mutate($owner, $target, 'active', 1, 'Unsupported manual transition.')
                ->assertConflict()->assertJsonPath('code', 'ADMIN_USER_STATE_TRANSITION_INVALID');
        }
    }

    public function test_suspended_and_closed_users_cannot_login_or_reuse_existing_sessions(): void
    {
        foreach (['suspended', 'closed'] as $state) {
            $user = $this->user(V2UserState::Active);
            $session = $this->userSession($user);
            $owner = $this->adminSession(V2AdminRole::Owner);
            $this->mutate($owner, $user, $state, 1, 'Authentication boundary test.')->assertOk();

            Auth::forgetGuards();
            $this->withCredentials()
                ->withUnencryptedCookie('__Host-oripa_user_session', $session)
                ->getJson('/api/v2/auth/session')
                ->assertUnauthorized();

            try {
                app(V2UserAuthenticationService::class)->login(
                    $user->email_display,
                    self::USER_PASSWORD,
                    $state === 'suspended' ? '192.0.2.61' : '192.0.2.62'
                );
                self::fail('Inactive User login must be rejected.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_CREDENTIALS', $exception->errorCode);
            }
            Auth::forgetGuards();
        }
    }

    public function test_unauthenticated_and_missing_user_responses_use_private_problem_details(): void
    {
        $missing = (string) Str::uuid7();
        $this->putJson('/admin/api/v2/users/'.$missing.'/state', [
            'status' => 'suspended',
            'expected_revision' => 1,
            'reason' => 'Missing user.',
        ])->assertUnauthorized();

        $owner = $this->adminSession(V2AdminRole::Owner);
        Auth::forgetGuards();
        $response = $this->mutateRaw($owner, $missing, [
            'status' => 'suspended',
            'expected_revision' => 1,
            'reason' => 'Missing user.',
        ])->assertNotFound()->assertJsonPath('code', 'ADMIN_USER_NOT_FOUND');
        self::assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function user(V2UserState $state): User
    {
        $email = 'state-target-'.Str::uuid7().'@example.test';
        $user = User::query()->create([
            'display_name' => 'Synthetic state target',
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash(self::USER_PASSWORD),
            'state' => $state,
        ]);
        DB::table('wallets')->insert([
            'user_id' => $user->id,
            'paid_balance' => 200,
            'free_balance' => 300,
            'paid_reserved_balance' => 0,
            'free_reserved_balance' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function userSession(User $user): string
    {
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        DB::table('user_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'user_id' => $user->id,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(12),
            'absolute_expires_at' => now()->addHours(24),
            'reauthenticated_at' => now(),
            'revoked_at' => null,
        ]);

        return $token;
    }

    private function rememberDevice(User $user): void
    {
        DB::table('user_remember_devices')->insert([
            'user_id' => $user->id,
            'selector' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'rotation_counter' => 0,
            'expires_at' => now()->addDays(30),
            'last_used_at' => null,
            'revoked_at' => null,
            'replay_detected_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function adminSession(V2AdminRole $role): string
    {
        $email = 'state-admin-'.$role->value.'-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid admin password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => now()->addHours(12),
            'revoked_at' => null,
        ]);

        return $token;
    }

    private function mutate(
        string $token,
        User $user,
        string $state,
        int $revision,
        string $reason,
        ?string $key = null
    ) {
        return $this->mutateRaw($token, $user->public_id, [
            'status' => $state,
            'expected_revision' => $revision,
            'reason' => $reason,
        ], $key);
    }

    /** @param array<string, mixed> $payload */
    private function mutateRaw(string $token, string $userPublicId, array $payload, ?string $key = null)
    {
        $csrf = str_repeat('c', 64);

        return $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token)
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => $key ?? (string) Str::uuid7(),
            ])
            ->putJson('/admin/api/v2/users/'.$userPublicId.'/state', $payload);
    }
}
