<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Identity\Services\V2AdminReauthenticationService;
use App\Domain\Identity\Services\V2AuthTransactionStore;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2WebauthnService;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Models\V2\Admin;
use App\Models\V2\AdminTotpMethod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OTPHP\TOTP;
use Tests\TestCase;

final class AdminFreshMfaQaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-28T06:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_identity.transactions.store' => 'array',
            'v2_identity.fresh_mfa.minutes' => 5,
            'v2_identity.webauthn.rp_id' => 'admin.example.test',
            'v2_identity.webauthn.origin' => 'https://admin.example.test',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('q', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
        Cache::store('array')->clear();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_freshness_uses_server_session_time_and_expires_at_exactly_five_minutes(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        [$context] = $this->adminSession($owner, now()->subMinutes(4)->subSeconds(59));
        self::assertSame(
            $owner->id,
            app(V2AdminFreshMfaAuthorizer::class)->authorizeQa($context)->id
        );

        CarbonImmutable::setTestNow('2026-07-28T06:00:01Z');
        try {
            app(V2AdminFreshMfaAuthorizer::class)->authorizeQa($context);
            self::fail('Fresh MFA must expire at exactly five minutes.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('FRESH_AUTHENTICATION_REQUIRED', $exception->errorCode);
            self::assertSame(403, $exception->status);
            self::assertFalse($exception->retryable);
        }
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'admin.fresh_mfa.required',
            'outcome' => 'failure',
        ]);
        self::assertNotSame(
            $context->sessionIdHash,
            DB::table('audit_logs')
                ->where('action_code', 'admin.fresh_mfa.required')
                ->value('session_correlation_hash')
        );
    }

    public function test_null_revoked_expired_and_client_clock_cannot_bypass_server_session_state(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        [$nullContext] = $this->adminSession($owner, null);
        $this->assertAuthenticationError(
            fn () => app(V2AdminFreshMfaAuthorizer::class)->authorizeQa($nullContext),
            'FRESH_AUTHENTICATION_REQUIRED'
        );

        [$revokedContext] = $this->adminSession($owner, now());
        DB::table('admin_sessions')
            ->where('session_id_hash', $revokedContext->sessionIdHash)
            ->update(['revoked_at' => now()]);
        $this->assertAuthenticationError(
            fn () => app(V2AdminFreshMfaAuthorizer::class)->authorizeQa($revokedContext),
            'AUTHENTICATION_REQUIRED'
        );

        [$expiredContext, $raw] = $this->adminSession($owner, now());
        DB::table('admin_sessions')
            ->where('session_id_hash', $expiredContext->sessionIdHash)
            ->update([
                'mfa_verified_at' => now()->subHours(2),
                'created_at' => now()->subHours(3),
                'last_activity_at' => now()->subMinutes(70),
                'idle_expires_at' => now()->subHour(),
                'absolute_expires_at' => now()->subHour(),
            ]);
        $request = Request::create('/admin/api/v2/qa/mode', 'GET');
        $request->cookies->set('__Host-oripa_admin_session', $raw);
        $request->headers->set('X-Client-Time', now()->addYear()->toIso8601String());
        $this->assertAuthenticationError(
            fn () => app(V2AdminFreshMfaAuthorizer::class)->context($request),
            'AUTHENTICATION_REQUIRED'
        );
    }

    public function test_invalid_session_enrollment_and_non_owner_fail_closed_before_qa_data_access(): void
    {
        foreach ([V2AdminRole::Admin, V2AdminRole::Operator] as $role) {
            [$context] = $this->adminSession($this->admin($role), now());
            try {
                app(V2QaDrawAdminService::class)->mode($context, (string) Str::uuid7());
                self::fail('Non-Owner must not access QA data.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
            }
        }

        $owner = $this->admin(V2AdminRole::Owner);
        [$context] = $this->adminSession($owner, now(), requiresEnrollment: true);
        $this->expectException(V2AuthenticationException::class);
        app(V2QaDrawAdminService::class)->mode($context, (string) Str::uuid7());
    }

    public function test_totp_reauthentication_rotates_session_without_extending_absolute_expiry(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        [$context, $raw, $absolute] = $this->adminSession(
            $owner,
            now()->subMinutes(10)
        );
        $secret = 'JBSWY3DPEHPK3PXP';
        AdminTotpMethod::query()->create([
            'admin_id' => $owner->id,
            'secret_ciphertext' => $secret,
            'encryption_key_version' => 'laravel-app-key-v1',
            'confirmed_at' => now()->subDay(),
        ]);
        $code = TOTP::create($secret, 30, 'sha1', 6)->at(time());

        $result = app(V2AdminReauthenticationService::class)->reauthenticate(
            $context,
            'totp',
            $code
        );
        $newHash = hash('sha256', $result['session']['token']);
        self::assertNotSame($raw, $result['session']['token']);
        self::assertDatabaseHas('admin_sessions', [
            'session_id_hash' => $context->sessionIdHash,
        ]);
        self::assertNotNull(DB::table('admin_sessions')
            ->where('session_id_hash', $context->sessionIdHash)
            ->value('revoked_at'));
        self::assertDatabaseHas('admin_sessions', [
            'session_id_hash' => $newHash,
            'requires_mfa_enrollment' => false,
        ]);
        self::assertSame(
            CarbonImmutable::parse($absolute)->toIso8601String(),
            CarbonImmutable::parse($result['session']['absolute_expires_at'])->toIso8601String()
        );
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'admin.reauthentication.succeeded',
            'outcome' => 'success',
        ]);

        try {
            app(V2AdminReauthenticationService::class)->reauthenticate(
                $context,
                'totp',
                $code
            );
            self::fail('The revoked Session and replayed TOTP step must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHENTICATION_REQUIRED', $exception->errorCode);
        }
    }

    public function test_password_reauthentication_is_available_only_while_mfa_is_disabled(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        [$context, $raw] = $this->adminSession($owner, now()->subMinutes(10));

        $result = app(V2AdminReauthenticationService::class)->reauthenticate(
            $context,
            'password',
            password: 'valid password'
        );

        self::assertNotSame($raw, $result['session']['token']);
        self::assertNotNull(DB::table('admin_sessions')
            ->where('session_id_hash', $context->sessionIdHash)
            ->value('revoked_at'));
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'admin.reauthentication.succeeded',
            'outcome' => 'success',
        ]);
    }

    public function test_password_recovery_and_invalid_totp_do_not_revoke_current_session(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        [$context] = $this->adminSession($owner, now()->subMinutes(10));
        foreach (['password', 'recovery_code', 'totp'] as $method) {
            try {
                app(V2AdminReauthenticationService::class)->reauthenticate(
                    $context,
                    $method,
                    '000000'
                );
                self::fail('Unsupported or invalid Fresh MFA must fail.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_MFA_CREDENTIAL', $exception->errorCode);
            }
            self::assertNull(DB::table('admin_sessions')
                ->where('session_id_hash', $context->sessionIdHash)
                ->value('revoked_at'));
        }
    }

    public function test_reauthentication_rate_limit_is_session_scoped_and_audited(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        [$context] = $this->adminSession($owner, now()->subMinutes(10));
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                app(V2AdminReauthenticationService::class)->reauthenticate(
                    $context,
                    'totp',
                    '000000'
                );
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_MFA_CREDENTIAL', $exception->errorCode);
            }
        }
        try {
            app(V2AdminReauthenticationService::class)->reauthenticate(
                $context,
                'totp',
                '000000'
            );
            self::fail('The sixth Session-scoped MFA attempt must be rate limited.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('RATE_LIMITED', $exception->errorCode);
            self::assertSame(429, $exception->status);
            self::assertNotNull($exception->retryAfterSeconds);
        }
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'admin.reauthentication.rate_limited',
            'outcome' => 'failure',
        ]);
    }

    public function test_webauthn_reauthentication_transaction_is_session_bound_and_one_time(): void
    {
        $owner = $this->admin(V2AdminRole::Owner);
        [$context] = $this->adminSession($owner, now()->subMinutes(10));
        $transaction = app(V2AuthTransactionStore::class)->issue(
            'admin_webauthn_reauthentication',
            [
                'admin_id' => (int) $owner->id,
                'session_id_hash' => $context->sessionIdHash,
                'options' => '{}',
            ],
            300
        );
        $service = app(V2WebauthnService::class);

        self::assertFalse($service->verifyReauthenticationAssertion(
            $owner,
            $transaction['token'],
            [],
            hash('sha256', 'different-session')
        ));
        self::assertFalse($service->verifyReauthenticationAssertion(
            $owner,
            $transaction['token'],
            [],
            $context->sessionIdHash
        ));
    }

    private function admin(V2AdminRole $role): Admin
    {
        $email = 'fresh-mfa-'.Str::uuid7().'@example.test';

        return Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
    }

    private function assertAuthenticationError(
        callable $operation,
        string $expectedCode
    ): void {
        try {
            $operation();
            self::fail('The authentication boundary must fail closed.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame($expectedCode, $exception->errorCode);
        }
    }

    /**
     * @return array{V2AdminAuthorizationContext, string, CarbonImmutable}
     */
    private function adminSession(
        Admin $admin,
        ?\DateTimeInterface $verifiedAt,
        bool $requiresEnrollment = false
    ): array {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $absolute = CarbonImmutable::now()->addHours(7);
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => $verifiedAt,
            'requires_mfa_enrollment' => $requiresEnrollment,
            'created_at' => now()->subHour(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $absolute,
            'revoked_at' => null,
        ]);

        return [
            new V2AdminAuthorizationContext(
                (int) $admin->id,
                $admin->public_id,
                $admin->role,
                $hash,
                app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)
                    ->correlation($hash),
                (string) Str::uuid7()
            ),
            $raw,
            $absolute,
        ];
    }
}
