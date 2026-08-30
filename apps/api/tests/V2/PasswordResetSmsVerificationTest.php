<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PasswordRecoveryService;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SmsVerificationService;
use App\Models\V2\OutboxMessage;
use App\Models\V2\PasswordResetToken;
use App\Models\V2\SmsVerificationChallenge;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use App\Models\V2\UserRememberDevice;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter as LaravelRateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class PasswordResetSmsVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_identity.sms_verification.phone_hmac_key' =>
                'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.sms_verification.phone_hmac_previous_keys' => [],
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        Cache::store('array')->clear();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_password_reset_request_is_generic_and_verified_account_only(): void
    {
        $verified = $this->user('verified@example.test');
        $this->user('pending@example.test', V2UserState::PendingVerification, false);
        $this->user('suspended@example.test', V2UserState::Suspended);
        $this->user('closed@example.test', V2UserState::Closed);
        $this->user('anonymized@example.test', V2UserState::Anonymized);
        $service = app(V2PasswordRecoveryService::class);
        $outboxCount = OutboxMessage::query()->count();
        $resetCount = PasswordResetToken::query()->count();

        $service->request('absent@example.test', '/', '192.0.2.10');
        $service->request('pending@example.test', '/', '192.0.2.11');
        $service->request('suspended@example.test', '/', '192.0.2.12');
        $service->request('closed@example.test', '/', '192.0.2.13');
        $service->request('anonymized@example.test', '/', '192.0.2.14');
        self::assertSame($resetCount, PasswordResetToken::query()->count());
        self::assertSame($outboxCount, OutboxMessage::query()->count());

        $service->request('VERIFIED@example.test', '/', '192.0.2.15');
        $reset = PasswordResetToken::query()->where('user_id', $verified->getKey())->sole();
        $message = $this->outboxMessage('identity.password-reset');
        $delivery = json_decode(
            Crypt::decryptString($message->payload['message_ciphertext']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertSame($verified->public_id, $delivery['user_public_id']);
        self::assertSame(64, strlen($delivery['reset_token']));
        self::assertNotSame($delivery['reset_token'], $reset->token_hash);
        self::assertSame(hash('sha256', $delivery['reset_token']), $reset->token_hash);
        self::assertStringNotContainsString(
            $delivery['reset_token'],
            json_encode($message->payload, JSON_THROW_ON_ERROR)
        );
        self::assertSame(60 * 60, (int) $reset->created_at->diffInSeconds($reset->expires_at));
    }

    public function test_password_reset_http_response_is_generic_and_success_mints_no_session(): void
    {
        $user = $this->user('http-reset@example.test');
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );
        $csrf = str_repeat('d', 64);
        $request = fn (string $email) => $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson('/api/v2/auth/password/forgot', [
                'email' => $email,
                'redirect_path' => '/',
            ]);

        $unknown = $request('http-reset-absent@example.test');
        $known = $request($user->email_display);
        self::assertSame($unknown->getStatusCode(), $known->getStatusCode());
        self::assertSame($unknown->getContent(), $known->getContent());
        $unknown->assertAccepted();

        $delivery = $this->decryptedOutbox('identity.password-reset');
        $response = $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_session', $session['token'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson('/api/v2/auth/password/reset', [
                'user_id' => $user->public_id,
                'token' => $delivery['reset_token'],
                'password' => 'new http reset password',
            ]);

        $response->assertOk()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('user', null)
            ->assertJsonPath('next_action', 'login');
        self::assertSame(0, DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->count());
        $cookies = collect($response->headers->getCookies());
        self::assertSame('', $cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_session'
        )?->getValue());
        self::assertSame('', $cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_xsrf'
        )?->getValue());
    }

    public function test_password_reset_rechecks_user_state_without_mutating_credentials(): void
    {
        $user = $this->user('suspended-after-reset@example.test');
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );
        UserRememberDevice::query()->create([
            'user_id' => $user->getKey(),
            'selector' => str_repeat('e', 32),
            'token_hash' => str_repeat('f', 64),
            'expires_at' => now()->addDays(30),
        ]);
        $service = app(V2PasswordRecoveryService::class);
        $service->request($user->email_display, '/', '192.0.2.16');
        $delivery = $this->decryptedOutbox('identity.password-reset');
        $passwordHash = $user->password_hash;
        $user->forceFill(['state' => V2UserState::Suspended])->save();

        try {
            $service->confirm(
                $user->public_id,
                $delivery['reset_token'],
                'new suspended password'
            );
            self::fail('A suspended User must not complete Password Reset.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_PASSWORD_RESET', $exception->errorCode);
        }

        self::assertSame($passwordHash, $user->refresh()->password_hash);
        self::assertSame(V2UserState::Suspended, $user->state);
        self::assertNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $session['token']))
            ->value('revoked_at'));
        self::assertNull(UserRememberDevice::query()
            ->where('user_id', $user->getKey())
            ->sole()
            ->revoked_at);
        self::assertDatabaseMissing('outbox_messages', [
            'topic' => 'identity.password-changed',
            'aggregate_public_id' => $user->public_id,
        ]);
    }

    public function test_malformed_password_reset_link_uses_stable_problem(): void
    {
        $csrf = str_repeat('e', 64);
        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson('/api/v2/auth/password/reset', [
                'user_id' => 'malformed',
                'token' => 'malformed',
                'password' => 'new valid password',
            ])
            ->assertStatus(410)
            ->assertJsonPath('code', 'INVALID_PASSWORD_RESET');
    }

    public function test_password_reset_is_one_time_and_revokes_all_sessions_and_devices(): void
    {
        $user = $this->user('reset@example.test');
        $sessions = app(V2SessionManager::class);
        $oldSession = $sessions->issue(V2Realm::User, (int) $user->getKey());
        UserRememberDevice::query()->create([
            'user_id' => $user->getKey(),
            'selector' => str_repeat('b', 32),
            'token_hash' => str_repeat('c', 64),
            'expires_at' => now()->addDays(30),
        ]);
        $service = app(V2PasswordRecoveryService::class);
        $service->request($user->email_display, '/', '192.0.2.20');
        $delivery = $this->decryptedOutbox('identity.password-reset');

        $result = $service->confirm(
            $user->public_id,
            $delivery['reset_token'],
            'new valid password'
        );
        self::assertTrue(app(V2PasswordPolicy::class)->verify(
            'new valid password',
            $user->refresh()->password_hash
        ));
        self::assertNotNull(PasswordResetToken::query()
            ->where('user_id', $user->getKey())
            ->sole()
            ->used_at);
        self::assertNotNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $oldSession['token']))
            ->value('revoked_at'));
        self::assertNotNull(UserRememberDevice::query()
            ->where('user_id', $user->getKey())
            ->sole()
            ->revoked_at);
        self::assertSame(['redirect_path' => '/'], $result);
        self::assertSame(0, DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->count());
        self::assertDatabaseHas('outbox_messages', [
            'topic' => 'identity.password-changed',
        ]);

        try {
            $service->confirm(
                $user->public_id,
                $delivery['reset_token'],
                'another valid password'
            );
            self::fail('A Password Reset Token must not be replayed.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_PASSWORD_RESET', $exception->errorCode);
        }
    }

    public function test_password_reset_attempts_are_isolated_to_exact_token_row_and_expire_at_sixty_minutes(): void
    {
        $user = $this->user('attempts@example.test');
        $service = app(V2PasswordRecoveryService::class);
        $service->request($user->email_display, '/', '192.0.2.30');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $service->confirm(
                    $user->public_id,
                    str_repeat((string) $attempt, 64),
                    'new valid password'
                );
                self::fail('An invalid Password Reset Token must fail.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_PASSWORD_RESET', $exception->errorCode);
            }
        }
        $reset = PasswordResetToken::query()->where('user_id', $user->getKey())->sole();
        self::assertSame(0, $reset->failed_attempts);
        self::assertNull($reset->revoked_at);

        $delivery = $this->decryptedOutbox('identity.password-reset');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            Cache::store('array')->clear();
            try {
                $service->confirm(
                    '0198a001-0000-7000-8000-000000000399',
                    $delivery['reset_token'],
                    'new valid password'
                );
                self::fail('A mismatched Password Reset account must fail.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_PASSWORD_RESET', $exception->errorCode);
            }
        }
        self::assertSame(5, $reset->refresh()->failed_attempts);
        self::assertNotNull($reset->revoked_at);

        Cache::store('array')->clear();
        $service->request($user->email_display, '/', '192.0.2.31');
        $latest = PasswordResetToken::query()->latest('id')->firstOrFail();
        $latestDelivery = $this->decryptedOutbox('identity.password-reset');
        $this->travel(61)->minutes();
        try {
            $service->confirm(
                $user->public_id,
                $latestDelivery['reset_token'],
                'new valid password'
            );
            self::fail('An expired Password Reset Token must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_PASSWORD_RESET', $exception->errorCode);
        }
        self::assertNotNull($latest->refresh()->revoked_at);
    }

    public function test_password_reset_rejects_open_redirect_and_uses_password_policy(): void
    {
        $user = $this->user('redirect@example.test');
        $service = app(V2PasswordRecoveryService::class);
        foreach (['https://evil.example/', '//evil.example/'] as $redirect) {
            try {
                $service->request($user->email_display, $redirect, '192.0.2.40');
                self::fail('An external Password Reset redirect must fail.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_REDIRECT', $exception->errorCode);
            }
        }
        $service->request($user->email_display, '/', '192.0.2.41');
        $delivery = $this->decryptedOutbox('identity.password-reset');
        try {
            $service->confirm($user->public_id, $delivery['reset_token'], 'short');
            self::fail('Password Policy must apply to Password Reset.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PASSWORD_POLICY_VIOLATION', $exception->errorCode);
        }
        self::assertNull(PasswordResetToken::query()
            ->where('user_id', $user->getKey())
            ->sole()
            ->used_at);
    }

    public function test_password_reset_rolls_back_token_and_outbox_when_audit_fails(): void
    {
        $user = $this->user('rollback@example.test');
        $outboxCount = OutboxMessage::query()->count();
        $resetCount = PasswordResetToken::query()->count();
        $this->app->instance(V2SecurityEventSink::class, new ThrowingRecoveryEventSink());
        try {
            app(V2PasswordRecoveryService::class)->request(
                $user->email_display,
                '/',
                '192.0.2.50'
            );
            self::fail('Audit failure must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('synthetic-audit-failure', $exception->getMessage());
        }
        self::assertSame($resetCount, PasswordResetToken::query()->count());
        self::assertSame($outboxCount, OutboxMessage::query()->count());
    }

    public function test_sms_send_verify_encrypts_phone_hashes_code_and_rotates_session(): void
    {
        $user = $this->user('sms@example.test');
        [$request, $oldSession] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);

        $accepted = $service->send($user, $request, '+81 90-1234-5678', '192.0.2.60');
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->sole();
        $delivery = $this->decryptedOutbox('identity.sms-verification');
        self::assertSame($accepted['challenge_id'], $challenge->public_id);
        self::assertSame(hash('sha256', $delivery['verification_code']), $challenge->code_hash);
        self::assertStringNotContainsString('+819012345678', $challenge->phone_ciphertext);
        self::assertStringNotContainsString(
            '+819012345678',
            json_encode($this->outboxMessage('identity.sms-verification')->payload, JSON_THROW_ON_ERROR)
        );

        $verified = $service->verify(
            $user,
            $request,
            $challenge->public_id,
            $delivery['verification_code']
        );
        $phone = UserPhoneNumber::query()->where('user_id', $user->getKey())->sole();
        self::assertNotSame('+819012345678', $phone->phone_ciphertext);
        self::assertSame('+819012345678', Crypt::decryptString($phone->phone_ciphertext));
        self::assertNotSame('+819012345678', $phone->phone_hmac);
        self::assertNotNull($phone->verified_at);
        self::assertTrue($verified['status']['verified']);
        self::assertNotNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $oldSession['token']))
            ->value('revoked_at'));
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $verified['session']['token']),
            'revoked_at' => null,
        ]);
    }

    public function test_phone_ownership_lookup_rejects_previous_key_match_after_rotation(): void
    {
        $old = 'base64:'.base64_encode(str_repeat('o', 32));
        $new = 'base64:'.base64_encode(str_repeat('n', 32));
        config([
            'v2_identity.sms_verification.phone_hmac_key' => $old,
            'v2_identity.sms_verification.phone_hmac_previous_keys' => [],
        ]);
        $first = $this->user('rotation-phone-first@example.test');
        [$firstRequest] = $this->authenticatedRequest($first);
        $service = app(V2SmsVerificationService::class);
        $service->send($first, $firstRequest, '+819077778888', '192.0.2.90');
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $first->getKey())->sole();
        $service->verify(
            $first,
            $firstRequest,
            $challenge->public_id,
            $this->decryptedOutbox(
                'identity.sms-verification',
                $challenge->public_id
            )['verification_code']
        );

        config([
            'v2_identity.sms_verification.phone_hmac_key' => $new,
            'v2_identity.sms_verification.phone_hmac_previous_keys' => [$old],
        ]);
        $second = $this->user('rotation-phone-second@example.test');
        [$secondRequest] = $this->authenticatedRequest($second);
        try {
            $service->send($second, $secondRequest, '+819077778888', '192.0.2.91');
            self::fail('A previous-key phone ownership match must remain unavailable.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_NUMBER_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_sms_resend_revokes_old_challenge_preserves_failures_and_rejects_replay(): void
    {
        $user = $this->user('resend-sms@example.test');
        [$request] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);
        $service->send($user, $request, '+819011112222', '192.0.2.70');
        $first = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->sole();
        try {
            $service->verify($user, $request, $first->public_id, '999999');
            self::fail('An invalid SMS Code must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_SMS_VERIFICATION', $exception->errorCode);
        }
        $service->resend($user, $request, '192.0.2.70');
        $second = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        self::assertNotSame($first->public_id, $second->public_id);
        self::assertNotNull($first->refresh()->revoked_at);
        self::assertSame(1, $second->failed_attempts);
        $delivery = $this->decryptedOutbox('identity.sms-verification', $second->public_id);
        $verified = $service->verify(
            $user,
            $request,
            $second->public_id,
            $delivery['verification_code']
        );
        $newRequest = Request::create('/api/v2/me/sms-verification/verify', 'POST');
        $newRequest->cookies->set('__Host-oripa_user_session', $verified['session']['token']);
        try {
            $service->verify(
                $user,
                $newRequest,
                $second->public_id,
                $delivery['verification_code']
            );
            self::fail('An SMS Code must be one-time.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_SMS_VERIFICATION', $exception->errorCode);
        }
    }

    public function test_pending_phone_duplicates_are_allowed_but_verified_phone_is_unique(): void
    {
        $first = $this->user('phone-first@example.test');
        $second = $this->user('phone-second@example.test');
        [$firstRequest] = $this->authenticatedRequest($first);
        [$secondRequest] = $this->authenticatedRequest($second);
        $service = app(V2SmsVerificationService::class);
        $service->send($first, $firstRequest, '+819033334444', '192.0.2.80');
        $service->send($second, $secondRequest, '+819033334444', '192.0.2.81');
        self::assertSame(1, SmsVerificationChallenge::query()
            ->where('user_id', $first->getKey())
            ->count());
        self::assertSame(1, SmsVerificationChallenge::query()
            ->where('user_id', $second->getKey())
            ->count());
        $secondChallenge = SmsVerificationChallenge::query()
            ->where('user_id', $second->getKey())
            ->sole();
        $secondCode = $this->decryptedOutbox(
            'identity.sms-verification',
            $secondChallenge->public_id
        )['verification_code'];
        $service->verify($second, $secondRequest, $secondChallenge->public_id, $secondCode);

        $firstChallenge = SmsVerificationChallenge::query()
            ->where('user_id', $first->getKey())
            ->sole();
        $firstCode = $this->decryptedOutbox(
            'identity.sms-verification',
            $firstChallenge->public_id
        )['verification_code'];
        try {
            $service->verify($first, $firstRequest, $firstChallenge->public_id, $firstCode);
            self::fail('A verified phone must be unique across active accounts.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_NUMBER_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_closed_account_phone_can_be_reused_and_phone_change_resets_verification(): void
    {
        $first = $this->user('withdrawn@example.test');
        [$firstRequest] = $this->authenticatedRequest($first);
        $service = app(V2SmsVerificationService::class);
        $service->send($first, $firstRequest, '+819055556666', '192.0.2.90');
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $first->getKey())
            ->sole();
        $code = $this->decryptedOutbox(
            'identity.sms-verification',
            $challenge->public_id
        )['verification_code'];
        $firstResult = $service->verify($first, $firstRequest, $challenge->public_id, $code);
        $first->forceFill(['state' => V2UserState::Closed])->save();
        self::assertSame(V2UserState::Closed, $first->fresh()->state);
        self::assertSame(
            $first->getKey(),
            UserPhoneNumber::query()->where('user_id', $first->getKey())->value('user_id')
        );

        $second = $this->user('reuse@example.test');
        [$secondRequest] = $this->authenticatedRequest($second);
        $service->send($second, $secondRequest, '+819055556666', '192.0.2.91');
        self::assertNotNull(UserPhoneNumber::query()
            ->where('user_id', $first->getKey())
            ->value('revoked_at'));

        $first->forceFill(['state' => V2UserState::Active])->save();
        $changeRequest = Request::create('/api/v2/me/sms-verification', 'POST');
        $changeRequest->cookies->set(
            '__Host-oripa_user_session',
            $firstResult['session']['token']
        );
        $service->send($first, $changeRequest, '+819077778888', '192.0.2.92');
        self::assertFalse($service->status($first)['verified']);
    }

    public function test_sms_requires_server_side_fresh_session_and_rate_limiter_fails_closed(): void
    {
        $user = $this->user('fresh@example.test');
        [$request] = $this->authenticatedRequest($user);
        $challengeCount = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->count();
        $this->travel(10)->minutes();
        try {
            app(V2SmsVerificationService::class)->send(
                $user,
                $request,
                '+819099990000',
                '192.0.2.100'
            );
            self::fail('A stale session must not change phone verification.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('FRESH_AUTHENTICATION_REQUIRED', $exception->errorCode);
        }
        self::assertSame($challengeCount, SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->count());

        $this->travelBack();
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->andThrow(new \RuntimeException('cache unavailable'));
        $this->app->instance(
            V2RateLimiter::class,
            new V2RateLimiter(new LaravelRateLimiter($cache))
        );
        try {
            app(V2PasswordRecoveryService::class)->request(
                $user->email_display,
                '/',
                '192.0.2.101'
            );
            self::fail('A missing critical limiter must fail closed.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTH_SERVICE_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_password_reset_rate_limits_account_ip_and_confirm(): void
    {
        $service = app(V2PasswordRecoveryService::class);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $service->request('limited@example.test', '/', '192.0.2.110');
        }
        $this->assertRateLimited(static fn () => $service->request(
            'limited@example.test',
            '/',
            '192.0.2.111'
        ));

        Cache::store('array')->clear();
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $service->request(
                "absent-{$attempt}@example.test",
                '/',
                '192.0.2.112'
            );
        }
        $this->assertRateLimited(static fn () => $service->request(
            'absent-final@example.test',
            '/',
            '192.0.2.112'
        ));

        Cache::store('array')->clear();
        $user = $this->user('confirm-limit@example.test');
        $service->request($user->email_display, '/', '192.0.2.113');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $service->confirm(
                    $user->public_id,
                    str_repeat((string) $attempt, 64),
                    'new valid password'
                );
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_PASSWORD_RESET', $exception->errorCode);
            }
        }
        $this->assertRateLimited(static fn () => $service->confirm(
            $user->public_id,
            str_repeat('9', 64),
            'new valid password'
        ));
    }

    public function test_sms_rate_limits_phone_and_challenge(): void
    {
        $user = $this->user('sms-limit@example.test');
        [$request] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $service->send($user, $request, '+819011112222', '192.0.2.120');
        }
        $this->assertRateLimited(static fn () => $service->send(
            $user,
            $request,
            '+819011112222',
            '192.0.2.121'
        ));

        Cache::store('array')->clear();
        $challenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $service->verify(
                    $user,
                    $request,
                    $challenge->public_id,
                    sprintf('%06d', $attempt)
                );
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_SMS_VERIFICATION', $exception->errorCode);
            }
        }
        $this->assertRateLimited(static fn () => $service->verify(
            $user,
            $request,
            $challenge->public_id,
            '999999'
        ));
    }

    private function assertRateLimited(callable $operation): void
    {
        try {
            $operation();
            self::fail('The configured rate limit must fail closed.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('RATE_LIMITED', $exception->errorCode);
            self::assertSame(429, $exception->status);
            self::assertNotNull($exception->retryAfterSeconds);
        }
    }

    private function user(
        string $email,
        V2UserState $state = V2UserState::Active,
        bool $verified = true
    ): User {
        return User::query()->create([
            'email_display' => $email,
            'email_normalized' => strtolower($email),
            'email_verified_at' => $verified ? now() : null,
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid old password'),
            'state' => $state,
        ]);
    }

    /** @return array{Request, array{token: string, absolute_expires_at: \DateTimeInterface}} */
    private function authenticatedRequest(User $user): array
    {
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );
        $request = Request::create('/api/v2/me/sms-verification', 'POST');
        $request->cookies->set('__Host-oripa_user_session', $session['token']);

        return [$request, $session];
    }

    private function outboxMessage(string $topic, ?string $aggregate = null): OutboxMessage
    {
        $query = OutboxMessage::query()->where('topic', $topic);
        if ($aggregate !== null) {
            $query->where('deduplication_key', 'like', '%'.$aggregate);
        }

        return $query->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function decryptedOutbox(string $topic, ?string $aggregate = null): array
    {
        return json_decode(
            Crypt::decryptString(
                $this->outboxMessage($topic, $aggregate)->payload['message_ciphertext']
            ),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}

final class ThrowingRecoveryEventSink implements V2SecurityEventSink
{
    public function record(string $event, array $context): void
    {
        throw new \RuntimeException('synthetic-audit-failure');
    }
}
