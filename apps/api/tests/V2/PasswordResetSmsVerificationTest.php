<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PasswordRecoveryService;
use App\Domain\Identity\Services\V2PhoneNormalizer;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SmsVerificationService;
use App\Domain\Identity\Services\V2SmsOtpConfiguration;
use App\Models\V2\OutboxMessage;
use App\Models\V2\PasswordResetToken;
use App\Models\V2\SmsVerificationChallenge;
use App\Models\V2\User;
use App\Models\V2\UserPhoneNumber;
use App\Models\V2\UserRememberDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
        self::assertSame(60, app(V2SmsOtpConfiguration::class)->ttlMinutes());
        $user = $this->user('sms@example.test');
        [$request, $oldSession] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);

        $accepted = $service->send($user, $request, '090-1234-5678');
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->sole();
        $delivery = $this->decryptedOutbox('identity.sms-verification');
        self::assertSame($accepted['challenge_id'], $challenge->public_id);
        self::assertMatchesRegularExpression('/\A[0-9]{6}\z/', $delivery['verification_code']);
        self::assertSame(hash('sha256', $delivery['verification_code']), $challenge->code_hash);
        self::assertSame(3600, (int) $challenge->created_at->diffInSeconds($challenge->expires_at));
        self::assertSame($challenge->expires_at->toIso8601String(), $accepted['expires_at']);
        self::assertSame(
            $challenge->expires_at->toIso8601String(),
            $service->status($user)['challenge']['expires_at']
        );
        self::assertStringNotContainsString('+819012345678', $challenge->phone_ciphertext);
        self::assertStringNotContainsString(
            '+819012345678',
            json_encode($this->outboxMessage('identity.sms-verification')->payload, JSON_THROW_ON_ERROR)
        );
        self::assertSame('pending', $accepted['delivery_state']);
        $this->acceptChallenge($challenge);

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
        self::assertNull($verified['status']['challenge']);
        self::assertNotNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $oldSession['token']))
            ->value('revoked_at'));
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $verified['session']['token']),
            'revoked_at' => null,
        ]);
    }

    public function test_sms_otp_is_valid_during_minute_59_and_expires_at_minute_60(): void
    {
        $service = app(V2SmsVerificationService::class);
        $validUser = $this->user('sms-ttl-valid@example.test');
        [$validRequest] = $this->authenticatedRequest($validUser);
        $service->send($validUser, $validRequest, '09011112222');
        $validChallenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $this->acceptChallenge($validChallenge);
        $validCode = $this->decryptedOutbox(
            'identity.sms-verification',
            $validChallenge->public_id
        )['verification_code'];

        $this->travel(59)->minutes();
        $this->travel(59)->seconds();
        self::assertSame('accepted', $service->status($validUser)['challenge']['status']);
        self::assertTrue($service->verify(
            $validUser,
            $validRequest,
            $validChallenge->public_id,
            $validCode
        )['status']['verified']);
        $this->travelBack();

        $expiredUser = $this->user('sms-ttl-expired@example.test');
        [$expiredRequest] = $this->authenticatedRequest($expiredUser);
        $service->send($expiredUser, $expiredRequest, '08011112222');
        $expiredChallenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $this->acceptChallenge($expiredChallenge);
        $expiredCode = $this->decryptedOutbox(
            'identity.sms-verification',
            $expiredChallenge->public_id
        )['verification_code'];

        $this->travel(60)->minutes();
        self::assertSame('expired', $service->status($expiredUser)['challenge']['status']);
        try {
            $service->verify(
                $expiredUser,
                $expiredRequest,
                $expiredChallenge->public_id,
                $expiredCode
            );
            self::fail('An SMS OTP must expire at minute 60.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_SMS_VERIFICATION', $exception->errorCode);
        }
        self::assertNotNull($expiredChallenge->refresh()->revoked_at);
    }

    public function test_invalid_sms_otp_ttl_configuration_fails_before_persistence(): void
    {
        $service = app(V2SmsVerificationService::class);
        foreach ([0, 1441, '60.5', 'invalid'] as $index => $invalidTtl) {
            config(['v2_identity.sms_verification.ttl_minutes' => $invalidTtl]);
            $user = $this->user("sms-invalid-ttl-{$index}@example.test");
            [$request] = $this->authenticatedRequest($user);
            $challengeCount = SmsVerificationChallenge::query()->count();
            $outboxCount = OutboxMessage::query()
                ->where('topic', 'identity.sms-verification')
                ->count();
            try {
                $service->send($user, $request, '0701111222'.($index + 1));
                self::fail('An invalid SMS OTP TTL must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('SMS OTP TTL configuration is invalid.', $exception->getMessage());
            }
            self::assertSame($challengeCount, SmsVerificationChallenge::query()->count());
            self::assertSame(
                $outboxCount,
                OutboxMessage::query()->where('topic', 'identity.sms-verification')->count()
            );
        }
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
        $service->send($first, $firstRequest, '09077778888');
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $first->getKey())->sole();
        $this->acceptChallenge($challenge);
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
        $challengeCount = SmsVerificationChallenge::query()->count();
        $outboxCount = OutboxMessage::query()->where('topic', 'identity.sms-verification')->count();
        try {
            $service->send($second, $secondRequest, '09077778888');
            self::fail('A previous-key phone ownership match must be unavailable before send.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_NUMBER_UNAVAILABLE', $exception->errorCode);
        }
        self::assertSame($challengeCount, SmsVerificationChallenge::query()->count());
        self::assertSame(
            $outboxCount,
            OutboxMessage::query()->where('topic', 'identity.sms-verification')->count()
        );
    }

    public function test_sms_resend_revokes_old_challenge_preserves_failures_and_rejects_replay(): void
    {
        $user = $this->user('resend-sms@example.test');
        [$request] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);
        $service->send($user, $request, '09011112222');
        $first = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())
            ->sole();
        $this->acceptChallenge($first);
        try {
            $service->verify($user, $request, $first->public_id, '999999');
            self::fail('An invalid SMS Code must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_SMS_VERIFICATION', $exception->errorCode);
        }
        $this->travel(60)->seconds();
        $service->resend($user, $request);
        $second = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        self::assertNotSame($first->public_id, $second->public_id);
        self::assertNotNull($first->refresh()->revoked_at);
        self::assertSame(0, $second->failed_attempts);
        $this->acceptChallenge($second);
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
        $service->send($first, $firstRequest, '09033334444');
        $service->send($second, $secondRequest, '09033334444');
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
        $this->acceptChallenge($secondChallenge);
        $service->verify($second, $secondRequest, $secondChallenge->public_id, $secondCode);

        $third = $this->user('phone-third@example.test');
        [$thirdRequest] = $this->authenticatedRequest($third);
        $challengeCount = SmsVerificationChallenge::query()->count();
        $outboxCount = OutboxMessage::query()->where('topic', 'identity.sms-verification')->count();
        try {
            $service->send($third, $thirdRequest, '09033334444');
            self::fail('An active verified phone must be rejected before send.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_NUMBER_UNAVAILABLE', $exception->errorCode);
            self::assertSame(
                'This phone number is unavailable. Enter another phone number.',
                $exception->getMessage()
            );
        }
        self::assertSame($challengeCount, SmsVerificationChallenge::query()->count());
        self::assertSame(
            $outboxCount,
            OutboxMessage::query()->where('topic', 'identity.sms-verification')->count()
        );

        $firstChallenge = SmsVerificationChallenge::query()
            ->where('user_id', $first->getKey())
            ->sole();
        $firstCode = $this->decryptedOutbox(
            'identity.sms-verification',
            $firstChallenge->public_id
        )['verification_code'];
        $this->acceptChallenge($firstChallenge);
        try {
            $service->verify($first, $firstRequest, $firstChallenge->public_id, $firstCode);
            self::fail('A verified phone must be unique across active accounts.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_NUMBER_UNAVAILABLE', $exception->errorCode);
        }
    }

    public function test_suspended_holder_keeps_ownership_and_suspended_user_cannot_use_sms(): void
    {
        $holder = $this->user('suspended-holder@example.test');
        [$holderRequest] = $this->authenticatedRequest($holder);
        $service = app(V2SmsVerificationService::class);
        $service->send($holder, $holderRequest, '09066667777');
        $holderChallenge = SmsVerificationChallenge::query()
            ->where('user_id', $holder->getKey())->sole();
        $this->acceptChallenge($holderChallenge);
        $service->verify(
            $holder,
            $holderRequest,
            $holderChallenge->public_id,
            $this->decryptedOutbox(
                'identity.sms-verification',
                $holderChallenge->public_id
            )['verification_code']
        );
        $holder->forceFill(['state' => V2UserState::Suspended])->save();
        self::assertNull(UserPhoneNumber::query()
            ->where('user_id', $holder->getKey())->value('revoked_at'));
        try {
            $service->send($holder, $holderRequest, '08066667777');
            self::fail('A suspended User must not start SMS verification.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHENTICATION_REQUIRED', $exception->errorCode);
        }

        $claimant = $this->user('suspended-holder-claimant@example.test');
        [$claimantRequest] = $this->authenticatedRequest($claimant);
        $challengeCount = SmsVerificationChallenge::query()->count();
        $outboxCount = OutboxMessage::query()->where('topic', 'identity.sms-verification')->count();
        try {
            $service->send($claimant, $claimantRequest, '09066667777');
            self::fail('A suspended holder phone must be rejected before send.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_NUMBER_UNAVAILABLE', $exception->errorCode);
        }
        self::assertSame($challengeCount, SmsVerificationChallenge::query()->count());
        self::assertSame(
            $outboxCount,
            OutboxMessage::query()->where('topic', 'identity.sms-verification')->count()
        );
    }

    public function test_closed_account_phone_can_be_reused_and_phone_change_resets_verification(): void
    {
        $first = $this->user('withdrawn@example.test');
        [$firstRequest] = $this->authenticatedRequest($first);
        $service = app(V2SmsVerificationService::class);
        $service->send($first, $firstRequest, '09055556666');
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $first->getKey())
            ->sole();
        $code = $this->decryptedOutbox(
            'identity.sms-verification',
            $challenge->public_id
        )['verification_code'];
        $this->acceptChallenge($challenge);
        $firstResult = $service->verify($first, $firstRequest, $challenge->public_id, $code);
        $first->forceFill(['state' => V2UserState::Closed])->save();
        self::assertSame(V2UserState::Closed, $first->fresh()->state);
        self::assertSame(
            $first->getKey(),
            UserPhoneNumber::query()->where('user_id', $first->getKey())->value('user_id')
        );

        $second = $this->user('reuse@example.test');
        [$secondRequest] = $this->authenticatedRequest($second);
        $service->send($second, $secondRequest, '09055556666');
        $secondChallenge = SmsVerificationChallenge::query()
            ->where('user_id', $second->getKey())->sole();
        $this->acceptChallenge($secondChallenge);
        $service->verify(
            $second,
            $secondRequest,
            $secondChallenge->public_id,
            $this->decryptedOutbox(
                'identity.sms-verification',
                $secondChallenge->public_id
            )['verification_code']
        );
        self::assertNotNull(UserPhoneNumber::query()
            ->where('user_id', $first->getKey())
            ->value('revoked_at'));

        $first->forceFill(['state' => V2UserState::Active])->save();
        $changeRequest = Request::create('/api/v2/me/sms-verification', 'POST');
        $changeRequest->cookies->set(
            '__Host-oripa_user_session',
            $firstResult['session']['token']
        );
        $service->send($first, $changeRequest, '09077778888');
        self::assertFalse($service->status($first)['verified']);
    }

    public function test_anonymized_account_phone_can_be_reused_on_claim(): void
    {
        $holder = $this->user('anonymized-phone-holder@example.test');
        [$holderRequest] = $this->authenticatedRequest($holder);
        $service = app(V2SmsVerificationService::class);
        $service->send($holder, $holderRequest, '07055556666');
        $holderChallenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $this->acceptChallenge($holderChallenge);
        $service->verify(
            $holder,
            $holderRequest,
            $holderChallenge->public_id,
            $this->decryptedOutbox(
                'identity.sms-verification',
                $holderChallenge->public_id
            )['verification_code']
        );
        $holder->forceFill(['state' => V2UserState::Anonymized])->save();

        $claimant = $this->user('anonymized-phone-claimant@example.test');
        [$claimantRequest] = $this->authenticatedRequest($claimant);
        $service->send($claimant, $claimantRequest, '07055556666');
        $claim = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $this->acceptChallenge($claim);
        $service->verify(
            $claimant,
            $claimantRequest,
            $claim->public_id,
            $this->decryptedOutbox(
                'identity.sms-verification',
                $claim->public_id
            )['verification_code']
        );

        self::assertNotNull(UserPhoneNumber::query()
            ->where('user_id', $holder->getKey())
            ->value('revoked_at'));
        self::assertNotNull(UserPhoneNumber::query()
            ->where('user_id', $claimant->getKey())
            ->whereNull('revoked_at')
            ->value('verified_at'));
    }

    public function test_phone_change_preserves_old_phone_until_atomic_success_and_keeps_other_access(): void
    {
        $user = $this->user('phone-change@example.test');
        [$request] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);
        $service->send($user, $request, '09044445555');
        $initial = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())->sole();
        $this->acceptChallenge($initial);
        $initialResult = $service->verify(
            $user,
            $request,
            $initial->public_id,
            $this->decryptedOutbox('identity.sms-verification', $initial->public_id)['verification_code']
        );
        $otherSession = app(V2SessionManager::class)->issue(V2Realm::User, (int) $user->getKey());
        UserRememberDevice::query()->create([
            'user_id' => $user->getKey(),
            'selector' => str_repeat('7', 32),
            'token_hash' => str_repeat('8', 64),
            'expires_at' => now()->addDays(30),
        ]);
        $changeRequest = Request::create('/api/v2/me/sms-verification', 'POST');
        $changeRequest->cookies->set(
            '__Host-oripa_user_session',
            $initialResult['session']['token']
        );
        $this->travel(60)->seconds();

        $challengeCount = SmsVerificationChallenge::query()->count();
        $outboxCount = OutboxMessage::query()->where('topic', 'identity.sms-verification')->count();
        try {
            $service->send($user, $changeRequest, '09044445555');
            self::fail('A User self-number must remain already verified.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_ALREADY_VERIFIED', $exception->errorCode);
        }
        self::assertSame($challengeCount, SmsVerificationChallenge::query()->count());
        self::assertSame(
            $outboxCount,
            OutboxMessage::query()->where('topic', 'identity.sms-verification')->count()
        );

        $targetOwner = $this->user('phone-change-target-owner@example.test');
        [$targetOwnerRequest] = $this->authenticatedRequest($targetOwner);
        $service->send($targetOwner, $targetOwnerRequest, '07044445555');
        $targetChallenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $this->acceptChallenge($targetChallenge);
        $service->verify(
            $targetOwner,
            $targetOwnerRequest,
            $targetChallenge->public_id,
            $this->decryptedOutbox(
                'identity.sms-verification',
                $targetChallenge->public_id
            )['verification_code']
        );
        $challengeCount = SmsVerificationChallenge::query()->count();
        $outboxCount = OutboxMessage::query()->where('topic', 'identity.sms-verification')->count();
        try {
            $service->send($user, $changeRequest, '07044445555');
            self::fail('A used phone-change target must be rejected before send.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('PHONE_NUMBER_UNAVAILABLE', $exception->errorCode);
        }
        self::assertSame($challengeCount, SmsVerificationChallenge::query()->count());
        self::assertSame(
            $outboxCount,
            OutboxMessage::query()->where('topic', 'identity.sms-verification')->count()
        );
        self::assertSame('+819044445555', Crypt::decryptString(
            UserPhoneNumber::query()->where('user_id', $user->getKey())->sole()->phone_ciphertext
        ));

        $service->send($user, $changeRequest, '08044445555');
        $change = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        self::assertSame('phone_change', $change->purpose);
        self::assertSame('+819044445555', Crypt::decryptString(
            UserPhoneNumber::query()->where('user_id', $user->getKey())->sole()->phone_ciphertext
        ));
        $this->acceptChallenge($change);
        $changeCode = $this->decryptedOutbox(
            'identity.sms-verification',
            $change->public_id
        )['verification_code'];
        try {
            $service->verify(
                $user,
                $changeRequest,
                $change->public_id,
                $changeCode === '000000' ? '000001' : '000000'
            );
            self::fail('A wrong change OTP must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_SMS_VERIFICATION', $exception->errorCode);
        }
        self::assertSame('+819044445555', Crypt::decryptString(
            UserPhoneNumber::query()->where('user_id', $user->getKey())->sole()->phone_ciphertext
        ));

        $result = $service->verify(
            $user,
            $changeRequest,
            $change->public_id,
            $changeCode
        );
        self::assertSame('+818044445555', $result['status']['phone']);
        self::assertSame(1, DB::table('mail_deliveries as delivery')
            ->join('mail_templates as template', 'template.id', '=', 'delivery.mail_template_id')
            ->where('template.template_key', 'phone_changed')
            ->where('delivery.event_key', 'user.phone_changed:'.$change->public_id)
            ->count());
        self::assertNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $otherSession['token']))
            ->value('revoked_at'));
        self::assertNull(UserRememberDevice::query()->sole()->revoked_at);
        self::assertNotNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $initialResult['session']['token']))
            ->value('revoked_at'));

        $releasedPhoneUser = $this->user('released-old-phone@example.test');
        [$releasedRequest] = $this->authenticatedRequest($releasedPhoneUser);
        $service->send($releasedPhoneUser, $releasedRequest, '09044445555');
        $releasedChallenge = SmsVerificationChallenge::query()
            ->where('user_id', $releasedPhoneUser->getKey())->sole();
        $this->acceptChallenge($releasedChallenge);
        $service->verify(
            $releasedPhoneUser,
            $releasedRequest,
            $releasedChallenge->public_id,
            $this->decryptedOutbox(
                'identity.sms-verification',
                $releasedChallenge->public_id
            )['verification_code']
        );
    }

    public function test_expired_phone_change_preserves_old_phone_and_sends_no_mail(): void
    {
        $user = $this->user('phone-change-expired@example.test');
        [$request] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);
        $service->send($user, $request, '09088889999');
        $initial = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $this->acceptChallenge($initial);
        $verified = $service->verify(
            $user,
            $request,
            $initial->public_id,
            $this->decryptedOutbox(
                'identity.sms-verification',
                $initial->public_id
            )['verification_code']
        );

        $changeRequest = Request::create('/api/v2/me/sms-verification', 'POST');
        $changeRequest->cookies->set('__Host-oripa_user_session', $verified['session']['token']);
        $service->send($user, $changeRequest, '08088889999');
        $change = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $this->acceptChallenge($change);
        $changeCode = $this->decryptedOutbox(
            'identity.sms-verification',
            $change->public_id
        )['verification_code'];
        $this->travel(60)->minutes();

        try {
            $service->verify($user, $changeRequest, $change->public_id, $changeCode);
            self::fail('An expired phone change Challenge must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('INVALID_SMS_VERIFICATION', $exception->errorCode);
        }
        self::assertSame('+819088889999', Crypt::decryptString(
            UserPhoneNumber::query()->where('user_id', $user->getKey())->sole()->phone_ciphertext
        ));
        self::assertNotNull($change->refresh()->revoked_at);
        self::assertSame(0, DB::table('mail_deliveries as delivery')
            ->join('mail_templates as template', 'template.id', '=', 'delivery.mail_template_id')
            ->where('template.template_key', 'phone_changed')
            ->where('delivery.source_public_id', $user->public_id)
            ->count());
    }

    public function test_sms_verify_requires_provider_acceptance_and_five_failures_invalidate_challenge(): void
    {
        $user = $this->user('sms-delivery-required@example.test');
        [$request] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);
        $service->send($user, $request, '07044445555');
        $challenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        try {
            $service->verify(
                $user,
                $request,
                $challenge->public_id,
                $this->decryptedOutbox('identity.sms-verification')['verification_code']
            );
            self::fail('A pending delivery must not verify.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('SMS_DELIVERY_PENDING', $exception->errorCode);
        }
        $this->acceptChallenge($challenge);
        $validCode = $this->decryptedOutbox('identity.sms-verification')['verification_code'];
        foreach (range(1, 5) as $attempt) {
            try {
                $service->verify(
                    $user,
                    $request,
                    $challenge->public_id,
                    $validCode === sprintf('%06d', $attempt)
                        ? sprintf('%06d', $attempt + 10)
                        : sprintf('%06d', $attempt)
                );
            } catch (V2AuthenticationException $exception) {
                self::assertSame('INVALID_SMS_VERIFICATION', $exception->errorCode);
            }
        }
        self::assertSame(5, $challenge->refresh()->failed_attempts);
        self::assertNotNull($challenge->revoked_at);
        self::assertNull(UserPhoneNumber::query()->where('user_id', $user->getKey())->first());
    }

    public function test_initial_sms_allows_normal_session_but_phone_change_requires_fresh_session(): void
    {
        $user = $this->user('fresh@example.test');
        [$request] = $this->authenticatedRequest($user);
        $service = app(V2SmsVerificationService::class);
        $this->travel(11)->minutes();
        $service->send($user, $request, '09099990000');
        $challenge = SmsVerificationChallenge::query()
            ->where('user_id', $user->getKey())->sole();
        self::assertSame('registration', $challenge->purpose);
        $this->acceptChallenge($challenge);
        $verified = $service->verify(
            $user,
            $request,
            $challenge->public_id,
            $this->decryptedOutbox('identity.sms-verification')['verification_code']
        );
        $staleRequest = Request::create('/api/v2/me/sms-verification', 'POST');
        $staleRequest->cookies->set('__Host-oripa_user_session', $verified['session']['token']);
        try {
            $service->send($user, $staleRequest, '08099990000');
            self::fail('A stale session must not start a phone change.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('FRESH_AUTHENTICATION_REQUIRED', $exception->errorCode);
        }
        self::assertSame('+819099990000', Crypt::decryptString(
            UserPhoneNumber::query()->where('user_id', $user->getKey())->sole()->phone_ciphertext
        ));
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
            $service->send($user, $request, '09011112222');
            $this->travel(60)->seconds();
        }
        $this->assertRateLimited(static fn () => $service->send(
            $user,
            $request,
            '09011112222'
        ));

        Cache::store('array')->clear();
        $challenge = SmsVerificationChallenge::query()->latest('id')->firstOrFail();
        $this->acceptChallenge($challenge);
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

    public function test_sms_daily_limit_cooldown_and_no_ip_limit(): void
    {
        self::assertNull(config('v2_identity.rate_limits.sms_ip'));
        $service = app(V2SmsVerificationService::class);
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $user = $this->user("sms-daily-limit-{$attempt}@example.test");
            [$request] = $this->authenticatedRequest($user);
            $service->send($user, $request, '08011112222');
            $this->travel(3601)->seconds();
        }
        $limitedUser = $this->user('sms-daily-limit-final@example.test');
        [$limitedRequest] = $this->authenticatedRequest($limitedUser);
        $this->assertRateLimited(static fn () => $service->send(
            $limitedUser,
            $limitedRequest,
            '08011112222'
        ));

        Cache::store('array')->clear();
        $cooldownUser = $this->user('sms-cooldown@example.test');
        [$cooldownRequest] = $this->authenticatedRequest($cooldownUser);
        $service->send($cooldownUser, $cooldownRequest, '07011112222');
        $this->travel(59)->seconds();
        $this->assertRateLimited(static fn () => $service->resend(
            $cooldownUser,
            $cooldownRequest
        ));
        $this->travel(1)->second();
        $service->resend($cooldownUser, $cooldownRequest);

        Cache::store('array')->clear();
        $noIpUser = $this->user('sms-no-ip-limit@example.test');
        [$noIpRequest] = $this->authenticatedRequest($noIpUser);
        foreach (range(1, 6) as $suffix) {
            $service->send(
                $noIpUser,
                $noIpRequest,
                '0702000000'.$suffix
            );
        }
        self::assertSame(6, SmsVerificationChallenge::query()
            ->where('user_id', $noIpUser->getKey())
            ->count());
    }

    public function test_sms_rate_limiter_unavailable_fails_closed(): void
    {
        $cache = \Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andThrow(new \RuntimeException('cache unavailable'));
        $this->app->instance(
            V2RateLimiter::class,
            new V2RateLimiter(new \Illuminate\Cache\RateLimiter($cache))
        );
        $user = $this->user('sms-limiter-unavailable@example.test');
        [$request] = $this->authenticatedRequest($user);
        try {
            app(V2SmsVerificationService::class)->send($user, $request, '07030000001');
            self::fail('An unavailable SMS limiter must fail closed.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTH_SERVICE_UNAVAILABLE', $exception->errorCode);
            self::assertSame(503, $exception->status);
        }
    }

    public function test_japanese_mobile_phone_validation_and_canonical_conversion(): void
    {
        $normalizer = app(V2PhoneNormalizer::class);
        self::assertSame('+817012345678', $normalizer->normalize('07012345678'));
        self::assertSame('+818012345678', $normalizer->normalize('080-1234-5678'));
        self::assertSame('+819012345678', $normalizer->normalize('09012345678'));
        self::assertSame('09012345678', $normalizer->toDomestic('+819012345678'));

        foreach ([
            '05012345678',
            '0312345678',
            '0901234567',
            '090123456789',
            '0901234abcd',
            '+819012345678',
        ] as $invalid) {
            try {
                $normalizer->normalize($invalid);
                self::fail('Unsupported phone input must fail.');
            } catch (\InvalidArgumentException) {
            }
        }
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

    private function acceptChallenge(SmsVerificationChallenge $challenge): void
    {
        $now = now()->startOfSecond();
        $challenge->forceFill([
            'delivery_state' => 'accepted',
            'provider_request_id' => 'fixture-request-'.$challenge->getKey(),
            'delivery_attempted_at' => $now,
            'delivery_accepted_at' => $now,
        ])->save();
    }
}

final class ThrowingRecoveryEventSink implements V2SecurityEventSink
{
    public function record(string $event, array $context): void
    {
        throw new \RuntimeException('synthetic-audit-failure');
    }
}
