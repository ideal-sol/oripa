<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2SecurityEventSink;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2EmailChangeService;
use App\Domain\Identity\Services\V2PasswordChangeService;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PasswordRecoveryService;
use App\Domain\Identity\Services\V2RateLimiter;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Mail\Services\V2IdentityMailOutboxWorker;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Models\V2\OutboxMessage;
use App\Models\V2\User;
use App\Models\V2\UserEmailChangeRequest;
use App\Models\V2\UserRememberDevice;
use App\Models\V2\UserSession;
use Illuminate\Cache\RateLimiter as LaravelRateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class AccountSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
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

    public function test_email_change_start_requires_only_active_session_and_supersedes_pending_request(): void
    {
        $user = $this->user('email-start@example.test');
        [$request] = $this->authenticatedRequest($user, '/api/v2/me/email-change-requests');

        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->start(
                $user,
                $request,
                'EMAIL-START@example.test',
                '/'
            ),
            'EMAIL_UNCHANGED'
        );
        Cache::store('array')->clear();
        $this->user('email-start-claimed@example.test');
        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->start(
                $user,
                $request,
                'email-start-claimed@example.test',
                '/'
            ),
            'EMAIL_ALREADY_CLAIMED'
        );
        Cache::store('array')->clear();
        $this->travel(11)->minutes();

        $first = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'New-Email@example.test',
            '/'
        );
        $change = UserEmailChangeRequest::query()->where('public_id', $first['request_id'])->sole();
        $payload = $this->decryptedOutbox('identity.email-change-verification');

        self::assertSame('email-start@example.test', $user->refresh()->email_normalized);
        self::assertSame('new-email@example.test', $change->new_email_normalized);
        self::assertSame(60 * 60, (int) $change->created_at->diffInSeconds($change->expires_at));
        self::assertSame(hash('sha256', $payload['verification_token']), $change->token_hash);
        self::assertNotSame($payload['verification_token'], $change->token_hash);
        self::assertSame('New-Email@example.test', $payload['recipient']);

        Cache::store('array')->clear();
        $second = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'replacement@example.test',
            '/'
        );
        self::assertNotSame($first['request_id'], $second['request_id']);
        self::assertNotNull($change->refresh()->revoked_at);
        self::assertSame(1, UserEmailChangeRequest::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->count());
    }

    public function test_email_change_same_browser_rotates_current_session_and_notifies_new_email_only(): void
    {
        $user = $this->user('email-same-browser@example.test');
        [$request, $initiating] = $this->authenticatedRequest(
            $user,
            '/api/v2/me/email-change-requests'
        );
        $other = app(V2SessionManager::class)->issue(V2Realm::User, (int) $user->getKey());
        $this->rememberDevice($user);
        $initiatingSession = UserSession::query()
            ->whereKey(hash('sha256', $initiating['token']))
            ->sole();
        $reauthenticatedAt = $initiatingSession->reauthenticated_at;
        $createdAt = $initiatingSession->created_at;
        $this->travel(20)->minutes();
        $started = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'email-same-browser-new@example.test',
            '/'
        );
        $payload = $this->decryptedOutbox('identity.email-change-verification');

        $result = app(V2EmailChangeService::class)->complete(
            $started['request_id'],
            $payload['verification_token'],
            $request
        );

        self::assertNotNull($result['session']);
        self::assertTrue($result['initiating_session_preserved']);
        self::assertFalse($result['request_session_revoked']);
        self::assertSame('email-same-browser-new@example.test', $user->refresh()->email_normalized);
        self::assertNotNull(UserEmailChangeRequest::query()
            ->where('public_id', $started['request_id'])->value('used_at'));
        foreach ([$initiating['token'], $other['token']] as $oldToken) {
            self::assertNotNull(DB::table('user_sessions')
                ->where('session_id_hash', hash('sha256', $oldToken))
                ->value('revoked_at'));
        }
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $result['session']['token']),
            'revoked_at' => null,
        ]);
        self::assertTrue(UserSession::query()
            ->whereKey(hash('sha256', $result['session']['token']))
            ->sole()
            ->reauthenticated_at
            ->equalTo($reauthenticatedAt));
        self::assertTrue(UserSession::query()
            ->whereKey(hash('sha256', $result['session']['token']))
            ->sole()
            ->created_at
            ->equalTo($createdAt));
        self::assertNotNull(UserRememberDevice::query()
            ->where('user_id', $user->getKey())->sole()->revoked_at);
        $completed = $this->decryptedOutbox('identity.email-change-completed');
        self::assertSame('email-same-browser-new@example.test', $completed['recipient']);
        self::assertNotSame('email-same-browser@example.test', $completed['recipient']);

        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->complete(
                $started['request_id'],
                $payload['verification_token'],
                $request
            ),
            'INVALID_EMAIL_CHANGE_REQUEST'
        );
    }

    public function test_email_change_cross_browser_preserves_initiating_session_without_minting_session(): void
    {
        $user = $this->user('email-cross-browser@example.test');
        [$initiatingRequest, $initiating] = $this->authenticatedRequest(
            $user,
            '/api/v2/me/email-change-requests'
        );
        $started = app(V2EmailChangeService::class)->start(
            $user,
            $initiatingRequest,
            'email-cross-browser-new@example.test',
            '/'
        );
        $payload = $this->decryptedOutbox('identity.email-change-verification');
        $completionRequest = Request::create(
            '/api/v2/me/email-change-requests/'.$started['request_id'].'/complete',
            'POST'
        );

        $result = app(V2EmailChangeService::class)->complete(
            $started['request_id'],
            $payload['verification_token'],
            $completionRequest
        );

        self::assertNull($result['session']);
        self::assertTrue($result['initiating_session_preserved']);
        self::assertFalse($result['request_session_revoked']);
        self::assertSame(1, DB::table('user_sessions')
            ->where('user_id', $user->getKey())->whereNull('revoked_at')->count());
        self::assertNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $initiating['token']))
            ->value('revoked_at'));
    }

    public function test_email_change_expiry_duplicate_authority_and_invalid_links_use_stable_problems(): void
    {
        $user = $this->user('email-problems@example.test');
        [$request] = $this->authenticatedRequest($user, '/api/v2/me/email-change-requests');
        $started = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'email-final-authority@example.test',
            '/'
        );
        $payload = $this->decryptedOutbox('identity.email-change-verification');
        $this->user('email-final-authority@example.test');

        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->complete(
                $started['request_id'],
                $payload['verification_token'],
                $request
            ),
            'EMAIL_ALREADY_CLAIMED'
        );
        self::assertSame('email-problems@example.test', $user->refresh()->email_normalized);

        Cache::store('array')->clear();
        $expiring = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'email-expired@example.test',
            '/'
        );
        $expiredPayload = $this->decryptedOutbox('identity.email-change-verification');
        $this->travel(61)->minutes();
        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->complete(
                $expiring['request_id'],
                $expiredPayload['verification_token'],
                $request
            ),
            'INVALID_EMAIL_CHANGE_REQUEST'
        );
        self::assertNotNull(UserEmailChangeRequest::query()
            ->where('public_id', $expiring['request_id'])->value('revoked_at'));
    }

    public function test_email_change_attempts_are_isolated_and_limited_to_exact_token_row(): void
    {
        $user = $this->user('email-attempts@example.test');
        [$request] = $this->authenticatedRequest($user, '/api/v2/me/email-change-requests');
        $first = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'email-attempts-first@example.test',
            '/'
        );
        $firstPayload = $this->decryptedOutbox('identity.email-change-verification');
        Cache::store('array')->clear();
        $second = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'email-attempts-second@example.test',
            '/'
        );
        $secondPayload = $this->decryptedOutbox('identity.email-change-verification');
        $secondRequest = UserEmailChangeRequest::query()
            ->where('public_id', $second['request_id'])
            ->sole();

        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->complete(
                $first['request_id'],
                $firstPayload['verification_token'],
                $request
            ),
            'INVALID_EMAIL_CHANGE_REQUEST'
        );
        self::assertSame(0, $secondRequest->refresh()->failed_attempts);
        self::assertNull($secondRequest->revoked_at);

        Cache::store('array')->clear();
        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->complete(
                $second['request_id'],
                str_repeat('9', 64),
                $request
            ),
            'INVALID_EMAIL_CHANGE_REQUEST'
        );
        self::assertSame(0, $secondRequest->refresh()->failed_attempts);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            Cache::store('array')->clear();
            $this->assertProblemCode(
                fn () => app(V2EmailChangeService::class)->complete(
                    (string) Str::uuid7(),
                    $secondPayload['verification_token'],
                    $request
                ),
                'INVALID_EMAIL_CHANGE_REQUEST'
            );
        }
        self::assertSame(5, $secondRequest->refresh()->failed_attempts);
        self::assertNotNull($secondRequest->revoked_at);
    }

    public function test_email_change_cross_browser_http_completion_mints_no_session(): void
    {
        $user = $this->user('email-http-cross@example.test');
        [$request, $initiating] = $this->authenticatedRequest(
            $user,
            '/api/v2/me/email-change-requests'
        );
        $started = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'email-http-cross-new@example.test',
            '/'
        );
        $payload = $this->decryptedOutbox('identity.email-change-verification');
        $csrf = str_repeat('f', 64);

        $response = $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_session', 'anonymous-session')
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson(
                '/api/v2/me/email-change-requests/'.$started['request_id'].'/complete',
                ['token' => $payload['verification_token']]
            );

        $response->assertOk()
            ->assertJsonPath('authenticated', false)
            ->assertJsonPath('session_rotated', false)
            ->assertJsonPath('initiating_session_preserved', true);
        self::assertNull(collect($response->headers->getCookies())->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_session'
        ));
        self::assertNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $initiating['token']))
            ->value('revoked_at'));

        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_session', 'anonymous-session')
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson('/api/v2/me/email-change-requests/malformed/complete', [
                'token' => 'malformed',
            ])
            ->assertStatus(410)
            ->assertJsonPath('code', 'INVALID_EMAIL_CHANGE_REQUEST');
    }

    public function test_email_change_same_browser_http_completion_rotates_session_and_csrf(): void
    {
        $user = $this->user('email-http-same@example.test');
        [$request, $initiating] = $this->authenticatedRequest(
            $user,
            '/api/v2/me/email-change-requests'
        );
        $started = app(V2EmailChangeService::class)->start(
            $user,
            $request,
            'email-http-same-new@example.test',
            '/'
        );
        $payload = $this->decryptedOutbox('identity.email-change-verification');
        $csrf = str_repeat('a', 64);

        $response = $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_session', $initiating['token'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson(
                '/api/v2/me/email-change-requests/'.$started['request_id'].'/complete',
                ['token' => $payload['verification_token']]
            );

        $response->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('session_rotated', true)
            ->assertJsonPath('initiating_session_preserved', true);
        $cookies = collect($response->headers->getCookies());
        $sessionCookie = $cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_session'
        );
        $csrfCookie = $cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_xsrf'
        );
        self::assertNotNull($sessionCookie);
        self::assertNotNull($csrfCookie);
        self::assertNotSame($initiating['token'], $sessionCookie->getValue());
        self::assertNotSame($csrf, $csrfCookie->getValue());
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $sessionCookie->getValue()),
            'revoked_at' => null,
        ]);
    }

    public function test_password_change_is_immediate_rotates_current_session_and_revokes_other_credentials(): void
    {
        $user = $this->user('password-change@example.test');
        [$request, $current] = $this->authenticatedRequest($user, '/api/v2/me/password', 'PUT');
        $other = app(V2SessionManager::class)->issue(V2Realm::User, (int) $user->getKey());
        $this->rememberDevice($user);
        $service = app(V2PasswordChangeService::class);
        $this->travel(11)->minutes();

        $this->assertProblemCode(
            fn () => $service->change($user, $request, 'wrong current password', 'new valid password'),
            'INVALID_REAUTHENTICATION'
        );
        Cache::store('array')->clear();
        $this->assertProblemCode(
            fn () => $service->change($user, $request, 'valid old password', 'valid old password'),
            'PASSWORD_UNCHANGED'
        );
        Cache::store('array')->clear();
        $this->assertProblemCode(
            fn () => $service->change($user, $request, 'valid old password', 'short'),
            'PASSWORD_POLICY_VIOLATION'
        );
        Cache::store('array')->clear();

        $result = $service->change(
            $user,
            $request,
            'valid old password',
            'new valid password'
        );

        self::assertTrue(app(V2PasswordPolicy::class)->verify(
            'new valid password',
            $user->refresh()->password_hash
        ));
        self::assertFalse(app(V2PasswordPolicy::class)->verify(
            'valid old password',
            $user->password_hash
        ));
        foreach ([$current['token'], $other['token']] as $oldToken) {
            self::assertNotNull(DB::table('user_sessions')
                ->where('session_id_hash', hash('sha256', $oldToken))
                ->value('revoked_at'));
        }
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $result['session']['token']),
            'revoked_at' => null,
        ]);
        self::assertNotNull(UserRememberDevice::query()
            ->where('user_id', $user->getKey())->sole()->revoked_at);
        self::assertDatabaseHas('outbox_messages', ['topic' => 'identity.password-changed']);
        self::assertDatabaseMissing('outbox_messages', [
            'topic' => 'identity.password-change-verification',
        ]);
        self::assertFalse(Schema::hasTable('password_change_requests'));
    }

    public function test_password_change_http_response_preserves_login_with_rotated_cookies(): void
    {
        $user = $this->user('password-change-http@example.test');
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );
        $csrf = str_repeat('b', 64);

        $response = $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_session', $session['token'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->putJson('/api/v2/me/password', [
                'current_password' => 'valid old password',
                'new_password' => 'new valid password',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'password_updated')
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('session_rotated', true)
            ->assertJsonPath('next_action', 'return_to_account');
        $cookies = collect($response->headers->getCookies());
        $sessionCookie = $cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_session'
        );
        $csrfCookie = $cookies->first(
            fn ($cookie): bool => $cookie->getName() === '__Host-oripa_user_xsrf'
        );
        self::assertNotNull($sessionCookie);
        self::assertNotNull($csrfCookie);
        self::assertNotSame($session['token'], $sessionCookie->getValue());
        self::assertNotSame($csrf, $csrfCookie->getValue());
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $sessionCookie->getValue()),
            'revoked_at' => null,
        ]);
        self::assertTrue(app(V2PasswordPolicy::class)->verify(
            'new valid password',
            $user->refresh()->password_hash
        ));
    }

    public function test_email_and_password_change_rate_limits_fail_closed(): void
    {
        $user = $this->user('account-rate-limit@example.test');
        [$emailRequest] = $this->authenticatedRequest(
            $user,
            '/api/v2/me/email-change-requests'
        );
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            app(V2EmailChangeService::class)->start(
                $user,
                $emailRequest,
                "account-rate-email-{$attempt}@example.test",
                '/'
            );
        }
        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->start(
                $user,
                $emailRequest,
                'account-rate-email-final@example.test',
                '/'
            ),
            'RATE_LIMITED'
        );
        self::assertSame([10, 86400], config('v2_identity.rate_limits.email_change_day'));

        Cache::store('array')->clear();
        $passwordUser = $this->user('password-rate-limit@example.test');
        [$passwordRequest] = $this->authenticatedRequest(
            $passwordUser,
            '/api/v2/me/password',
            'PUT'
        );
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertProblemCode(
                fn () => app(V2PasswordChangeService::class)->change(
                    $passwordUser,
                    $passwordRequest,
                    'wrong current password',
                    'new valid password'
                ),
                'INVALID_REAUTHENTICATION'
            );
        }
        $this->assertProblemCode(
            fn () => app(V2PasswordChangeService::class)->change(
                $passwordUser,
                $passwordRequest,
                'valid old password',
                'new valid password'
            ),
            'RATE_LIMITED'
        );
        self::assertTrue(app(V2PasswordPolicy::class)->verify(
            'valid old password',
            $passwordUser->refresh()->password_hash
        ));
    }

    public function test_browser_security_requires_authentication_csrf_and_same_origin(): void
    {
        $user = $this->user('browser-security@example.test');
        $session = app(V2SessionManager::class)->issue(V2Realm::User, (int) $user->getKey());
        $csrf = str_repeat('c', 64);

        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://storefront.example.test'])
            ->withUnencryptedCookie('__Host-oripa_user_session', $session['token'])
            ->postJson('/api/v2/me/email-change-requests', [
                'email' => 'browser-security-new@example.test',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');

        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_session', 'anonymous-session')
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson('/api/v2/me/email-change-requests', [
                'email' => 'browser-security-new@example.test',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');

        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://evil.example.test',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->withUnencryptedCookie('__Host-oripa_user_session', $session['token'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->putJson('/api/v2/me/password', [
                'current_password' => 'valid old password',
                'new_password' => 'new valid password',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_identity_mail_worker_uses_fixed_templates_and_does_not_claim_other_topics(): void
    {
        $capture = new AccountSecurityMailCapture();
        Mail::spy();
        Mail::shouldReceive('html')->once()->withArgs(
            function (string $body, callable $callback) use ($capture): bool {
                $message = new Message(new Email());
                $callback($message);
                $symfony = $message->getSymfonyMessage();
                $capture->messages[] = [
                    'body' => $body,
                    'subject' => (string) $symfony->getSubject(),
                    'to' => $symfony->getTo()[0]->getAddress(),
                ];

                return true;
            }
        );
        $user = $this->user('worker-reset@example.test');
        app(V2PasswordRecoveryService::class)->request(
            $user->email_display,
            '/',
            '192.0.2.210'
        );
        DB::transaction(fn () => app(V2OutboxService::class)->enqueue(
            'payment.synthetic',
            'payment',
            null,
            'payment.synthetic',
            ['result' => 'safe'],
            'payment-synthetic-account-security'
        ));
        self::assertSame(1, OutboxMessage::query()
            ->where('topic', 'identity.password-reset')
            ->where('status', 'pending')
            ->count());
        self::assertSame(1, OutboxMessage::query()
            ->where('topic', 'payment.synthetic')
            ->where('status', 'pending')
            ->count());

        self::assertSame(1, app(V2IdentityMailOutboxWorker::class)->run(
            'account-security-mail-worker',
            10
        ));

        self::assertCount(1, $capture->messages);
        self::assertSame('worker-reset@example.test', $capture->messages[0]['to']);
        self::assertSame('パスワード再設定のご案内', $capture->messages[0]['subject']);
        self::assertStringContainsString(
            'https://storefront.example.test/?password_reset_user_id=',
            $capture->messages[0]['body']
        );
        self::assertDatabaseHas('outbox_messages', [
            'topic' => 'identity.password-reset',
            'status' => 'delivered',
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'topic' => 'payment.synthetic',
            'status' => 'pending',
        ]);
    }

    public function test_mail_provider_failure_retries_without_rolling_back_password_change(): void
    {
        $user = $this->user('mail-failure-password@example.test');
        [$request] = $this->authenticatedRequest($user, '/api/v2/me/password', 'PUT');
        app(V2PasswordChangeService::class)->change(
            $user,
            $request,
            'valid old password',
            'new valid password'
        );
        Mail::shouldReceive('html')->once()->andThrow(new RuntimeException('synthetic failure'));

        self::assertSame(1, app(V2IdentityMailOutboxWorker::class)->run(
            'account-security-failing-worker',
            10
        ));

        self::assertTrue(app(V2PasswordPolicy::class)->verify(
            'new valid password',
            $user->refresh()->password_hash
        ));
        self::assertDatabaseHas('outbox_messages', [
            'topic' => 'identity.password-changed',
            'status' => 'pending',
            'attempts' => 1,
            'last_error_code' => 'identity_mail_delivery_failed',
        ]);
    }

    public function test_outbox_persistence_failure_rolls_back_password_and_session_rotation(): void
    {
        $user = $this->user('outbox-rollback-password@example.test');
        [$request, $session] = $this->authenticatedRequest(
            $user,
            '/api/v2/me/password',
            'PUT'
        );
        $passwordHash = $user->password_hash;
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_test_reject_account_security_outbox()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.topic = 'identity.password-changed' THEN
                    RAISE EXCEPTION 'synthetic Account Security outbox failure';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER v2_test_reject_account_security_outbox '.
            'BEFORE INSERT ON outbox_messages FOR EACH ROW '.
            'EXECUTE FUNCTION v2_test_reject_account_security_outbox()'
        );

        try {
            app(V2PasswordChangeService::class)->change(
                $user,
                $request,
                'valid old password',
                'new valid password'
            );
            self::fail('Password Change must roll back when Outbox persistence fails.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'synthetic Account Security outbox failure',
                $exception->getMessage()
            );
        } finally {
            DB::statement(
                'DROP TRIGGER IF EXISTS v2_test_reject_account_security_outbox '.
                'ON outbox_messages'
            );
            DB::statement('DROP FUNCTION IF EXISTS v2_test_reject_account_security_outbox()');
        }

        self::assertSame($passwordHash, $user->refresh()->password_hash);
        self::assertNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $session['token']))
            ->value('revoked_at'));
        self::assertDatabaseMissing('outbox_messages', [
            'topic' => 'identity.password-changed',
            'aggregate_public_id' => $user->public_id,
        ]);
    }

    public function test_limiter_and_audit_failures_fail_closed_before_account_mutation(): void
    {
        $user = $this->user('failure-boundary@example.test');
        [$request] = $this->authenticatedRequest($user, '/api/v2/me/email-change-requests');
        $cache = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('get')->andThrow(new RuntimeException('cache unavailable'));
        $this->app->instance(
            V2RateLimiter::class,
            new V2RateLimiter(new LaravelRateLimiter($cache))
        );
        $this->assertProblemCode(
            fn () => app(V2EmailChangeService::class)->start(
                $user,
                $request,
                'limiter-unavailable@example.test',
                '/'
            ),
            'AUTH_SERVICE_UNAVAILABLE'
        );
        self::assertSame(0, UserEmailChangeRequest::query()
            ->where('user_id', $user->getKey())->count());

        Cache::store('array')->clear();
        $this->app->forgetInstance(V2RateLimiter::class);
        $this->app->instance(V2SecurityEventSink::class, new ThrowingAccountSecurityEventSink());
        try {
            app(V2EmailChangeService::class)->start(
                $user,
                $request,
                'audit-failure@example.test',
                '/'
            );
            self::fail('Audit failure must roll back Email Change Start.');
        } catch (RuntimeException $exception) {
            self::assertSame('synthetic-account-security-audit-failure', $exception->getMessage());
        }
        self::assertSame(0, UserEmailChangeRequest::query()
            ->where('user_id', $user->getKey())->count());
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'email_display' => $email,
            'email_normalized' => strtolower($email),
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid old password'),
            'password_login_enabled' => true,
            'state' => V2UserState::Active,
        ]);
    }

    /** @return array{Request, array{token: string, absolute_expires_at: \DateTimeInterface}} */
    private function authenticatedRequest(
        User $user,
        string $path,
        string $method = 'POST'
    ): array {
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );
        $request = Request::create($path, $method);
        $request->cookies->set('__Host-oripa_user_session', $session['token']);

        return [$request, $session];
    }

    private function rememberDevice(User $user): void
    {
        UserRememberDevice::query()->create([
            'user_id' => $user->getKey(),
            'selector' => bin2hex(random_bytes(16)),
            'token_hash' => hash('sha256', random_bytes(32)),
            'expires_at' => now()->addDays(30),
        ]);
    }

    /** @return array<string, mixed> */
    private function decryptedOutbox(string $topic): array
    {
        $message = OutboxMessage::query()->where('topic', $topic)->latest('id')->firstOrFail();

        return json_decode(
            Crypt::decryptString($message->payload['message_ciphertext']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    private function assertProblemCode(callable $operation, string $code): void
    {
        try {
            $operation();
            self::fail('The Account Security operation must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}

final class AccountSecurityMailCapture
{
    /** @var list<array{body: string, subject: string, to: string}> */
    public array $messages = [];
}

final class ThrowingAccountSecurityEventSink implements V2SecurityEventSink
{
    public function record(string $event, array $context): void
    {
        throw new RuntimeException('synthetic-account-security-audit-failure');
    }
}
