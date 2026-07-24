<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2EmailVerificationNotifier;
use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminAuthenticationService;
use App\Domain\Identity\Services\V2AuthTransactionStore;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2RecoveryCodeService;
use App\Domain\Identity\Services\V2SecureToken;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2TotpService;
use App\Domain\Identity\Services\V2UserAuthenticationService;
use App\Domain\Identity\Services\V2WebauthnService;
use App\Models\V2\Admin;
use App\Models\V2\AdminInvitation;
use App\Models\V2\AdminRecoveryCode;
use App\Models\V2\AdminTotpMethod;
use App\Models\V2\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OTPHP\TOTP;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AuthenticationFlowTest extends TestCase
{
    private CapturingEmailNotifier $notifier;
    private CapturingSecurityEventSink $events;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'v2_identity.transactions.store' => 'array',
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.webauthn.rp_id' => 'admin.example.test',
            'v2_identity.webauthn.origin' => 'https://admin.example.test',
        ]);
        Cache::store('array')->clear();
        $this->notifier = new CapturingEmailNotifier();
        $this->events = new CapturingSecurityEventSink();
        $this->app->instance(V2EmailVerificationNotifier::class, $this->notifier);
        $this->app->instance(V2SecurityEventSink::class, $this->events);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_registration_verification_login_and_logout_use_hash_only_sessions(): void
    {
        $service = app(V2UserAuthenticationService::class);
        $first = $service->register(
            'User@example.test',
            'valid user password',
            '/',
            '192.0.2.10'
        );
        $second = $service->register(
            'user@example.test',
            'another valid password',
            '/',
            '192.0.2.11'
        );

        self::assertSame(V2UserState::PendingVerification, $first->state);
        self::assertNotSame($first->getKey(), $second->getKey());
        self::assertCount(2, $this->notifier->messages);
        $token = $this->notifier->messages[0]['token'];
        self::assertDatabaseMissing('user_email_verifications', ['token_hash' => $token]);
        self::assertDatabaseHas('user_email_verifications', [
            'token_hash' => hash('sha256', $token),
        ]);

        $verified = $service->verify($first->public_id, $token);
        self::assertSame(V2UserState::Active, $verified['user']->state);
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $verified['session']['token']),
        ]);
        self::assertDatabaseMissing('user_sessions', [
            'session_id_hash' => $verified['session']['token'],
        ]);
        self::assertSame(
            V2UserState::Closed->value,
            DB::table('users')->where('id', $second->getKey())->value('state')
        );

        try {
            $service->verify($first->public_id, $token);
            self::fail('A verification token must be one-time.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_VERIFICATION_LINK', $exception->errorCode);
        }

        $login = $service->login(
            'USER@example.test',
            'valid user password',
            '192.0.2.12'
        );
        $request = Request::create('/api/v2/auth/logout', 'POST');
        $request->cookies->set('__Host-oripa_user_session', $login['session']['token']);
        $service->logout($request);
        self::assertNotNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $login['session']['token']))
            ->value('revoked_at'));
    }

    public function test_resend_revokes_old_verification_and_expiry_fails(): void
    {
        $service = app(V2UserAuthenticationService::class);
        $user = $service->register(
            'resend@example.test',
            'valid resend password',
            '/',
            '192.0.2.20'
        );
        $old = $this->notifier->messages[0]['token'];
        $service->resend($user->public_id, '/');
        $new = $this->notifier->messages[1]['token'];

        self::assertNotSame($old, $new);
        self::assertNotNull(DB::table('user_email_verifications')
            ->where('token_hash', hash('sha256', $old))
            ->value('revoked_at'));
        $this->travel(61)->minutes();

        $this->expectException(V2AuthenticationException::class);
        $service->verify($user->public_id, $new);
    }

    public function test_user_login_is_generic_and_rejects_unverified_or_suspended_accounts(): void
    {
        $password = app(V2PasswordPolicy::class)->hash('valid state password');
        foreach ([
            ['pending@example.test', null, V2UserState::PendingVerification],
            ['suspended@example.test', now(), V2UserState::Suspended],
        ] as [$email, $verifiedAt, $state]) {
            User::query()->create([
                'email_display' => $email,
                'email_normalized' => $email,
                'email_verified_at' => $verifiedAt,
                'password_hash' => $password,
                'state' => $state,
            ]);
        }

        foreach (['pending@example.test', 'suspended@example.test', 'absent@example.test'] as $email) {
            try {
                app(V2UserAuthenticationService::class)->login(
                    $email,
                    'valid state password',
                    '192.0.2.'.random_int(30, 60)
                );
                self::fail('Unavailable accounts must not login.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_CREDENTIALS', $exception->errorCode);
                self::assertSame('The credentials could not be verified.', $exception->getMessage());
            }
        }
    }

    public function test_admin_password_is_only_preauth_and_totp_issues_separate_session(): void
    {
        $admin = $this->createAdmin(V2AdminRole::Operator);
        $secret = TOTP::generate()->getSecret();
        AdminTotpMethod::query()->create([
            'admin_id' => $admin->getKey(),
            'secret_ciphertext' => $secret,
            'encryption_key_version' => 'test-v1',
            'confirmed_at' => now(),
        ]);
        $service = app(V2AdminAuthenticationService::class);
        $preauth = $service->login(
            $admin->email_display,
            'valid admin password',
            '192.0.2.70'
        );

        self::assertSame(['totp'], $preauth['methods']);
        self::assertDatabaseCount('admin_sessions', 0);
        $code = TOTP::create($secret, 30, 'sha1', 6)->now();
        $verified = $service->verifyMfa(
            $preauth['transaction_token'],
            'totp',
            $code
        );
        self::assertFalse($verified['requires_mfa_enrollment']);
        self::assertDatabaseHas('admin_sessions', [
            'session_id_hash' => hash('sha256', $verified['session']['token']),
            'requires_mfa_enrollment' => false,
        ]);
        self::assertNotNull(DB::table('admin_sessions')
            ->where('session_id_hash', hash('sha256', $verified['session']['token']))
            ->value('mfa_verified_at'));

        $this->expectException(V2AuthenticationException::class);
        $service->verifyMfa($preauth['transaction_token'], 'totp', $code);
    }

    public function test_totp_enrollment_is_encrypted_confirmed_and_replay_safe(): void
    {
        $admin = $this->createAdmin(V2AdminRole::Operator, V2AdminState::Invited);
        $transaction = app(V2AuthTransactionStore::class)->issue(
            'admin_preauth',
            ['admin_id' => $admin->getKey(), 'methods' => []],
            300
        );
        $service = app(V2AdminAuthenticationService::class);
        $enrollment = $service->beginTotp($transaction['token']);
        $code = TOTP::create($enrollment['secret'], 30, 'sha1', 6)->now();
        $service->confirmTotp(
            $transaction['token'],
            $enrollment['enrollment_token'],
            $code
        );
        $method = AdminTotpMethod::query()->where('admin_id', $admin->getKey())->firstOrFail();

        self::assertNotSame($enrollment['secret'], $method->getRawOriginal('secret_ciphertext'));
        self::assertNotNull($method->confirmed_at);
        self::assertSame(V2AdminState::Active, $admin->refresh()->state);
        self::assertFalse(app(V2TotpService::class)->verify($method, $code));
    }

    public function test_recovery_codes_are_hash_only_one_time_and_force_reenrollment(): void
    {
        $admin = $this->createAdmin(V2AdminRole::Operator);
        $secret = TOTP::generate()->getSecret();
        AdminTotpMethod::query()->create([
            'admin_id' => $admin->getKey(),
            'secret_ciphertext' => $secret,
            'encryption_key_version' => 'test-v1',
            'confirmed_at' => now(),
        ]);
        $recovery = app(V2RecoveryCodeService::class);
        $codes = $recovery->regenerate($admin);

        self::assertCount(10, $codes);
        self::assertSame(10, AdminRecoveryCode::query()->where('admin_id', $admin->getKey())->count());
        self::assertDatabaseMissing('admin_recovery_codes', ['code_hash' => $codes[0]]);
        self::assertTrue($recovery->consume($admin, $codes[0]));
        self::assertFalse($recovery->consume($admin, $codes[0]));
    }

    public function test_mfa_enrollment_rejects_password_only_and_accepts_recovery_transaction(): void
    {
        $admin = $this->createAdmin(V2AdminRole::Operator);
        $secret = TOTP::generate()->getSecret();
        AdminTotpMethod::query()->create([
            'admin_id' => $admin->getKey(),
            'secret_ciphertext' => $secret,
            'encryption_key_version' => 'test-v1',
            'confirmed_at' => now(),
        ]);
        $service = app(V2AdminAuthenticationService::class);
        $preauth = $service->login(
            $admin->email_display,
            'valid admin password',
            '192.0.2.72'
        );

        try {
            $service->beginTotp($preauth['transaction_token']);
            self::fail('Password-only preauth must not authorize MFA enrollment.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_AUTH_TRANSACTION', $exception->errorCode);
        }

        $codes = app(V2RecoveryCodeService::class)->regenerate($admin);
        $recovered = $service->verifyMfa(
            $preauth['transaction_token'],
            'recovery_code',
            $codes[0]
        );

        self::assertTrue($recovered['requires_mfa_enrollment']);
        self::assertIsArray($recovered['enrollment_transaction']);
        self::assertSame(
            300,
            $recovered['enrollment_transaction']['expires_in']
        );
        $enrollment = $service->beginTotp(
            $recovered['enrollment_transaction']['token']
        );
        self::assertArrayHasKey('secret', $enrollment);
        self::assertDatabaseHas('admin_sessions', [
            'session_id_hash' => hash('sha256', $recovered['session']['token']),
            'requires_mfa_enrollment' => true,
        ]);
    }

    public function test_webauthn_options_require_exact_rp_origin_uv_and_one_time_challenge(): void
    {
        $admin = $this->createAdmin(V2AdminRole::Operator, V2AdminState::Invited);
        $result = app(V2WebauthnService::class)->beginRegistration($admin, 'Test key');

        self::assertSame('admin.example.test', $result['options']['rp']['id']);
        self::assertSame('required', $result['options']['authenticatorSelection']['userVerification']);
        self::assertSame('none', $result['options']['attestation']);
        self::assertSame(300000, $result['options']['timeout']);

        try {
            app(V2WebauthnService::class)->completeRegistration(
                $admin,
                $result['challenge_token'],
                ['id' => 'invalid']
            );
            self::fail('Invalid WebAuthn data must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_MFA_CREDENTIAL', $exception->errorCode);
        }
        $this->expectException(V2AuthenticationException::class);
        app(V2WebauthnService::class)->completeRegistration(
            $admin,
            $result['challenge_token'],
            ['id' => 'invalid']
        );
    }

    public function test_initial_owner_command_is_console_only_and_one_time(): void
    {
        $this->artisan('v2:identity:create-owner-invitation', [
            'email' => 'owner@example.test',
        ])->assertSuccessful();

        self::assertDatabaseHas('admins', [
            'email_normalized' => 'owner@example.test',
            'role' => V2AdminRole::Owner->value,
            'state' => V2AdminState::Invited->value,
        ]);
        self::assertDatabaseCount('admin_invitations', 1);
        $this->artisan('v2:identity:create-owner-invitation', [
            'email' => 'second-owner@example.test',
        ])->assertFailed();
        self::assertDatabaseCount('admins', 1);
    }

    public function test_admin_invitation_is_consumed_atomically_and_only_once(): void
    {
        $admin = $this->createAdmin(V2AdminRole::Operator, V2AdminState::Invited);
        $tokens = app(V2SecureToken::class);
        $token = $tokens->generate();
        $invitation = AdminInvitation::query()->create([
            'admin_id' => $admin->getKey(),
            'token_hash' => $tokens->hash($token),
            'expires_at' => now()->addMinutes(30),
        ]);
        $service = app(V2AdminAuthenticationService::class);

        try {
            $service->login(
                $admin->email_display,
                'short',
                '192.0.2.80',
                $token
            );
            self::fail('Invalid password must not consume an invitation.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_CREDENTIALS', $exception->errorCode);
        }
        self::assertNull($invitation->refresh()->used_at);

        $preauth = $service->login(
            $admin->email_display,
            'valid invited password',
            '192.0.2.81',
            $token
        );
        self::assertSame([], $preauth['methods']);
        self::assertNotNull($invitation->refresh()->used_at);

        try {
            $service->login(
                $admin->email_display,
                'valid invited password',
                '192.0.2.82',
                $token
            );
            self::fail('An invitation must not be replayed.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_CREDENTIALS', $exception->errorCode);
        }
    }

    public function test_session_cookie_attributes_are_realm_specific_and_host_only(): void
    {
        $sessions = app(V2SessionManager::class);
        $response = new Response();
        $sessions->attachSession(
            $response,
            V2Realm::User,
            str_repeat('a', 64),
            now()->addHour()
        );
        $sessions->attachSession(
            $response,
            V2Realm::Admin,
            str_repeat('b', 64),
            now()->addHour()
        );
        $cookies = $response->headers->getCookies();

        self::assertSame('__Host-oripa_user_session', $cookies[0]->getName());
        self::assertSame('lax', $cookies[0]->getSameSite());
        self::assertSame('__Host-oripa_admin_session', $cookies[1]->getName());
        self::assertSame('strict', $cookies[1]->getSameSite());
        foreach ($cookies as $cookie) {
            self::assertTrue($cookie->isSecure());
            self::assertTrue($cookie->isHttpOnly());
            self::assertNull($cookie->getDomain());
            self::assertSame('/', $cookie->getPath());
        }
    }

    private function createAdmin(
        V2AdminRole $role,
        V2AdminState $state = V2AdminState::Active
    ): Admin {
        return Admin::query()->create([
            'email_display' => strtolower($role->value).'@example.test',
            'email_normalized' => strtolower($role->value).'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid admin password'),
            'role' => $role,
            'state' => $state,
        ]);
    }
}

final class CapturingEmailNotifier implements V2EmailVerificationNotifier
{
    /** @var list<array{user_id: string, token: string, redirect_path: string}> */
    public array $messages = [];

    public function send(
        User $user,
        #[SensitiveParameter] string $token,
        string $redirectPath
    ): void {
        $this->messages[] = [
            'user_id' => $user->public_id,
            'token' => $token,
            'redirect_path' => $redirectPath,
        ];
    }
}

final class CapturingSecurityEventSink implements V2SecurityEventSink
{
    /** @var list<array{event: string, context: array<string, bool|int|string|null>}> */
    public array $records = [];

    public function record(string $event, array $context): void
    {
        foreach (array_keys($context) as $key) {
            if (in_array($key, [
                'password',
                'token',
                'session_id',
                'mfa_secret',
                'recovery_code',
                'email',
            ], true)) {
                throw new \RuntimeException('Sensitive event context is prohibited.');
            }
        }
        $this->records[] = ['event' => $event, 'context' => $context];
    }
}
