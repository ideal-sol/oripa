<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2GoogleOidcTransport;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2ExternalIdentityService;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2PasswordRecoveryService;
use App\Domain\Identity\Services\V2SessionManager;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\ExternalIdentityTransaction;
use App\Models\V2\OutboxMessage;
use App\Models\V2\User;
use App\Models\V2\UserRememberDevice;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Tests\TestCase;

final class GoogleOidcVerticalSliceTest extends TestCase
{
    private FakeGoogleOidcTransport $provider;
    private string $callbackUrl = 'https://storefront.example.test/api/v2/auth/external/google/callback';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_identity.sms_verification.phone_hmac_key' =>
                'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.external_identity.google.client_id' => 'google-client.test',
            'v2_identity.external_identity.google.client_secret' => 'synthetic-client-secret',
            'v2_identity.external_identity.google.redirect_uri' => $this->callbackUrl,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        Cache::store('array')->clear();
        $this->provider = new FakeGoogleOidcTransport();
        $this->app->instance(V2GoogleOidcTransport::class, $this->provider);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_google_login_creates_passwordless_user_and_never_persists_protocol_secrets(): void
    {
        $started = $this->start('login');
        $this->provider->issue($started['nonce'], 'google-subject-new', 'new@example.test');
        $result = app(V2ExternalIdentityService::class)->callback(
            $started['state'],
            'one-time-authorization-code',
            $started['binding'],
            $this->callbackUrl,
            '192.0.2.10',
            $this->callbackRequest($started['binding'])
        );

        self::assertSame('login', $result['purpose']);
        self::assertSame(V2UserState::Active, $result['user']->state);
        self::assertFalse($result['user']->password_login_enabled);
        self::assertNotNull($result['user']->email_verified_at);
        self::assertDatabaseHas('external_identity_accounts', [
            'user_id' => $result['user']->getKey(),
            'provider' => 'google',
            'issuer' => 'https://accounts.google.com',
            'revoked_at' => null,
        ]);
        $serialized = json_encode(
            ExternalIdentityAccount::query()->firstOrFail()->getAttributes(),
            JSON_THROW_ON_ERROR
        );
        foreach (
            [
                'google-subject-new',
                'one-time-authorization-code',
                $started['state'],
                $started['nonce'],
                $started['binding'],
                'synthetic-client-secret',
            ] as $secret
        ) {
            self::assertStringNotContainsString($secret, $serialized);
        }
        self::assertSame('completed', ExternalIdentityTransaction::query()->sole()->status);
        self::assertDatabaseHas('outbox_messages', [
            'topic' => 'identity.external-user-created',
            'aggregate_public_id' => $result['user']->public_id,
        ]);
        self::assertDatabaseHas('user_sessions', [
            'session_id_hash' => hash('sha256', $result['session']['token']),
            'user_id' => $result['user']->getKey(),
            'revoked_at' => null,
        ]);
    }

    public function test_existing_subject_logs_in_without_using_changed_provider_email(): void
    {
        $user = $this->user('original@example.test');
        $this->account($user, 'stable-subject');
        $started = $this->start('login');
        $this->provider->issue($started['nonce'], 'stable-subject', 'changed@example.test');

        $result = app(V2ExternalIdentityService::class)->callback(
            $started['state'],
            'login-code',
            $started['binding'],
            $this->callbackUrl,
            '192.0.2.11',
            $this->callbackRequest($started['binding'])
        );

        self::assertTrue($result['user']->is($user));
        self::assertSame('original@example.test', $user->refresh()->email_normalized);
    }

    public function test_verified_email_collision_requires_explicit_link(): void
    {
        $this->user('collision@example.test');
        $started = $this->start('login');
        $this->provider->issue($started['nonce'], 'unbound-subject', 'collision@example.test');

        $this->expectAuthenticationCode(
            'EXTERNAL_IDENTITY_LINK_REQUIRED',
            fn () => app(V2ExternalIdentityService::class)->callback(
                $started['state'],
                'collision-code',
                $started['binding'],
                $this->callbackUrl,
                '192.0.2.12',
                $this->callbackRequest($started['binding'])
            )
        );
        self::assertSame(1, User::query()->where('email_normalized', 'collision@example.test')->count());
        self::assertSame(0, ExternalIdentityAccount::query()->count());
    }

    public function test_link_revalidates_google_and_rotates_session_then_unlink_requires_recent_auth(): void
    {
        $user = $this->user('link@example.test');
        [$request, $oldToken] = $this->authenticatedRequest($user);
        $started = $this->start('link', $user, $request);
        $this->provider->issue($started['nonce'], 'linked-subject', 'different@example.test');
        $callback = $this->callbackRequest($started['binding'], $oldToken);
        $linked = app(V2ExternalIdentityService::class)->callback(
            $started['state'],
            'link-code',
            $started['binding'],
            $this->callbackUrl,
            '192.0.2.13',
            $callback
        );

        self::assertSame('link', $linked['purpose']);
        self::assertNotSame($oldToken, $linked['session']['token']);
        self::assertNotNull(DB::table('user_sessions')
            ->where('session_id_hash', hash('sha256', $oldToken))
            ->value('revoked_at'));
        self::assertSame(1, app(V2ExternalIdentityService::class)->identities($user)->count());

        UserRememberDevice::query()->create([
            'user_id' => $user->getKey(),
            'selector' => str_repeat('b', 32),
            'token_hash' => str_repeat('c', 64),
            'expires_at' => now()->addDays(30),
        ]);
        $unlinkRequest = $this->requestWithSession($linked['session']['token']);
        $rotated = app(V2ExternalIdentityService::class)->unlinkGoogle($user, $unlinkRequest);
        self::assertNotSame($linked['session']['token'], $rotated['token']);
        self::assertNotNull(ExternalIdentityAccount::query()->sole()->revoked_at);
        self::assertNotNull(UserRememberDevice::query()->sole()->revoked_at);
    }

    public function test_google_passwordless_user_cannot_unlink_last_credential_or_password_reauthenticate(): void
    {
        $user = $this->user('passwordless@example.test', false);
        $this->account($user, 'last-subject');
        [$request] = $this->authenticatedRequest($user);

        $this->expectAuthenticationCode(
            'LAST_CREDENTIAL_REQUIRED',
            fn () => app(V2ExternalIdentityService::class)->unlinkGoogle($user, $request)
        );
        $this->expectAuthenticationCode(
            'INVALID_REAUTHENTICATION',
            fn () => app(V2ExternalIdentityService::class)->reauthenticatePassword(
                $user,
                $request,
                'valid fixture password'
            )
        );
        self::assertNull(ExternalIdentityAccount::query()->sole()->revoked_at);
    }

    public function test_password_reauthentication_rotates_session_and_five_minute_boundary_is_server_side(): void
    {
        $user = $this->user('fresh@example.test');
        $this->account($user, 'fresh-subject');
        [$request, $token] = $this->authenticatedRequest($user);
        $rotated = app(V2ExternalIdentityService::class)->reauthenticatePassword(
            $user,
            $request,
            'valid fixture password'
        );
        self::assertNotSame($token, $rotated['token']);

        $this->travel(4)->minutes();
        $this->travel(59)->seconds();
        app(V2ExternalIdentityService::class)->unlinkGoogle(
            $user,
            $this->requestWithSession($rotated['token'])
        );
        self::assertNotNull(ExternalIdentityAccount::query()->sole()->revoked_at);

        $second = $this->user('stale@example.test');
        $this->account($second, 'stale-subject');
        [$staleRequest] = $this->authenticatedRequest($second);
        $this->travel(5)->minutes();
        $this->expectAuthenticationCode(
            'FRESH_AUTHENTICATION_REQUIRED',
            fn () => app(V2ExternalIdentityService::class)->unlinkGoogle(
                $second,
                $staleRequest
            )
        );
    }

    public function test_state_nonce_pkce_signature_issuer_audience_and_replay_fail_closed(): void
    {
        $scenarios = [
            'nonce' => ['nonce' => 'wrong-nonce'],
            'issuer' => ['iss' => 'https://issuer.example.test'],
            'audience' => ['aud' => 'wrong-client'],
            'azp' => ['azp' => 'wrong-client'],
            'future-iat' => ['iat' => now()->getTimestamp() + 120],
            'expired' => ['exp' => now()->getTimestamp() - 120],
            'unverified-email' => ['email_verified' => false],
        ];
        foreach ($scenarios as $name => $overrides) {
            Cache::store('array')->clear();
            $started = $this->start('login');
            $this->provider->issue(
                $started['nonce'],
                'subject-'.$name,
                $name.'@example.test',
                $overrides
            );
            $this->expectAuthenticationCode(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                fn () => app(V2ExternalIdentityService::class)->callback(
                    $started['state'],
                    'code-'.$name,
                    $started['binding'],
                    $this->callbackUrl,
                    '192.0.2.20',
                    $this->callbackRequest($started['binding'])
                )
            );
        }

        $started = $this->start('login');
        $this->provider->issue($started['nonce'], 'replay-subject', 'replay@example.test');
        $request = $this->callbackRequest($started['binding']);
        app(V2ExternalIdentityService::class)->callback(
            $started['state'],
            'replay-code',
            $started['binding'],
            $this->callbackUrl,
            '192.0.2.21',
            $request
        );
        $this->expectAuthenticationCode(
            'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
            fn () => app(V2ExternalIdentityService::class)->callback(
                $started['state'],
                'replay-code',
                $started['binding'],
                $this->callbackUrl,
                '192.0.2.21',
                $request
            )
        );
    }

    public function test_state_binding_expiry_unknown_key_and_provider_failure_are_generic(): void
    {
        $started = $this->start('login');
        $this->provider->issue($started['nonce'], 'binding-subject', 'binding@example.test');
        foreach (
            [
                ['state' => str_repeat('f', 64), 'binding' => $started['binding']],
                ['state' => $started['state'], 'binding' => str_repeat('e', 64)],
            ] as $case
        ) {
            $this->expectAuthenticationCode(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                fn () => app(V2ExternalIdentityService::class)->callback(
                    $case['state'],
                    'rejected-code',
                    $case['binding'],
                    $this->callbackUrl,
                    '192.0.2.22',
                    $this->callbackRequest($case['binding'])
                )
            );
        }

        Cache::store('array')->clear();
        $expired = $this->start('login');
        $this->provider->issue($expired['nonce'], 'expired-transaction', 'expiry@example.test');
        $this->travel(11)->minutes();
        $this->expectAuthenticationCode(
            'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
            fn () => app(V2ExternalIdentityService::class)->callback(
                $expired['state'],
                'expired-code',
                $expired['binding'],
                $this->callbackUrl,
                '192.0.2.23',
                $this->callbackRequest($expired['binding'])
            )
        );
        self::assertSame(
            'expired',
            ExternalIdentityTransaction::query()
                ->where('state_hash', hash('sha256', $expired['state']))
                ->sole()
                ->status
        );
    }

    public function test_algorithm_key_signature_jwks_and_provider_failures_fail_closed(): void
    {
        $scenarios = [
            'unsupported-algorithm' => static function (
                FakeGoogleOidcTransport $provider,
                array $started
            ): void {
                $provider->issue(
                    $started['nonce'],
                    'unsupported-algorithm',
                    'unsupported@example.test',
                    algorithm: 'HS256'
                );
            },
            'unknown-key' => static function (
                FakeGoogleOidcTransport $provider,
                array $started
            ): void {
                $provider->issue(
                    $started['nonce'],
                    'unknown-key',
                    'unknown-key@example.test',
                    keyId: 'rotated-key'
                );
            },
            'invalid-signature' => static function (
                FakeGoogleOidcTransport $provider,
                array $started
            ): void {
                $provider->issue(
                    $started['nonce'],
                    'invalid-signature',
                    'invalid-signature@example.test',
                    tamperSignature: true
                );
            },
            'jwks-unavailable' => static function (
                FakeGoogleOidcTransport $provider,
                array $started
            ): void {
                $provider->issue(
                    $started['nonce'],
                    'jwks-unavailable',
                    'jwks-unavailable@example.test'
                );
                $provider->failJwks();
            },
            'provider-unavailable' => static function (
                FakeGoogleOidcTransport $provider,
                array $started
            ): void {
                $provider->issue(
                    $started['nonce'],
                    'provider-unavailable',
                    'provider-unavailable@example.test'
                );
                $provider->failExchange();
            },
        ];

        foreach ($scenarios as $name => $prepare) {
            Cache::store('array')->clear();
            $started = $this->start('login');
            $prepare($this->provider, $started);
            $this->expectAuthenticationCode(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                fn () => app(V2ExternalIdentityService::class)->callback(
                    $started['state'],
                    'code-'.$name,
                    $started['binding'],
                    $this->callbackUrl,
                    '192.0.2.24',
                    $this->callbackRequest($started['binding'])
                )
            );
            $this->provider->resetFailures();
        }

        self::assertSame(0, ExternalIdentityAccount::query()->count());
    }

    public function test_account_state_and_link_conflicts_fail_closed(): void
    {
        foreach ([V2UserState::Suspended, V2UserState::Closed] as $state) {
            $user = $this->user(strtolower($state->value).'@example.test');
            $user->forceFill(['state' => $state])->save();
            $subject = strtolower($state->value).'-subject';
            $this->account($user, $subject);
            $started = $this->start('login');
            $this->provider->issue(
                $started['nonce'],
                $subject,
                strtolower($state->value).'-changed@example.test'
            );
            $this->expectAuthenticationCode(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                fn () => app(V2ExternalIdentityService::class)->callback(
                    $started['state'],
                    'state-code',
                    $started['binding'],
                    $this->callbackUrl,
                    '192.0.2.25',
                    $this->callbackRequest($started['binding'])
                )
            );
        }

        $owner = $this->user('identity-owner@example.test');
        $this->account($owner, 'already-owned-subject');
        $other = $this->user('identity-linker@example.test');
        [$request, $token] = $this->authenticatedRequest($other);
        $started = $this->start('link', $other, $request);
        $this->provider->issue(
            $started['nonce'],
            'already-owned-subject',
            'identity-linker@example.test'
        );
        $this->expectAuthenticationCode(
            'EXTERNAL_IDENTITY_CONFLICT',
            fn () => app(V2ExternalIdentityService::class)->callback(
                $started['state'],
                'link-conflict-code',
                $started['binding'],
                $this->callbackUrl,
                '192.0.2.26',
                $this->callbackRequest($started['binding'], $token)
            )
        );
        self::assertSame(1, ExternalIdentityAccount::query()
            ->where('subject_hash', app(
                \App\Domain\Identity\Services\V2IdentityCorrelation::class
            )->hash('google|https://accounts.google.com|already-owned-subject'))
            ->count());
    }

    public function test_http_start_contract_enforces_browser_security_and_cookie_boundary(): void
    {
        $csrf = str_repeat('a', 64);
        $response = $this
            ->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->postJson('/api/v2/auth/external/google/start', ['return_path' => '/'])
            ->assertOk()
            ->assertJsonPath('provider', 'google');
        self::assertStringStartsWith(
            'https://accounts.google.com/o/oauth2/v2/auth?',
            $response->json('authorization_url')
        );
        $transactionCookie = collect($response->headers->getCookies())
            ->first(static fn ($cookie): bool =>
                $cookie->getName() === '__Host-oripa_oidc_transaction');
        self::assertNotNull($transactionCookie);
        self::assertTrue($transactionCookie->isSecure());
        self::assertTrue($transactionCookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $transactionCookie->getSameSite()));

        $this->withHeaders([
            'Origin' => 'https://attacker.example.test',
            'Sec-Fetch-Site' => 'cross-site',
            'X-XSRF-TOKEN' => $csrf,
        ])->postJson('/api/v2/auth/external/google/start', ['return_path' => '/'])
            ->assertForbidden()
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_password_reset_enables_password_login_for_external_user(): void
    {
        $user = $this->user('reset-external@example.test', false);
        $service = app(V2PasswordRecoveryService::class);
        $service->request($user->email_display, '/', '192.0.2.30');
        $message = OutboxMessage::query()
            ->where('topic', 'identity.password-reset')
            ->latest('id')
            ->firstOrFail();
        $delivery = json_decode(
            Crypt::decryptString($message->payload['message_ciphertext']),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $service->confirm($user->public_id, $delivery['reset_token'], 'new valid password');
        self::assertTrue($user->refresh()->password_login_enabled);
    }

    /**
     * @return array{state: string, nonce: string, binding: string}
     */
    private function start(
        string $purpose,
        ?User $user = null,
        ?Request $request = null
    ): array {
        $request ??= Request::create('/api/v2/auth/external/google/start', 'POST');
        $result = app(V2ExternalIdentityService::class)->start(
            $purpose,
            '/',
            '192.0.2.1',
            (string) Str::uuid7(),
            $user,
            $request
        );
        parse_str(parse_url($result['authorization_url'], PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('openid email', $query['scope']);
        $this->provider->expectChallenge($query['code_challenge']);

        return [
            'state' => $query['state'],
            'nonce' => $query['nonce'],
            'binding' => $result['binding_token'],
        ];
    }

    private function user(string $email, bool $passwordEnabled = true): User
    {
        return User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid fixture password'),
            'password_login_enabled' => $passwordEnabled,
            'state' => V2UserState::Active,
        ]);
    }

    private function account(User $user, string $subject): ExternalIdentityAccount
    {
        return ExternalIdentityAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'issuer' => 'https://accounts.google.com',
            'subject_hash' => app(\App\Domain\Identity\Services\V2IdentityCorrelation::class)
                ->hash('google|https://accounts.google.com|'.$subject),
            'linked_at' => now(),
            'last_authenticated_at' => now(),
        ]);
    }

    /** @return array{Request, string} */
    private function authenticatedRequest(User $user): array
    {
        $session = app(V2SessionManager::class)->issue(
            V2Realm::User,
            (int) $user->getKey()
        );

        return [$this->requestWithSession($session['token']), $session['token']];
    }

    private function requestWithSession(string $token): Request
    {
        $request = Request::create('/api/v2/me/external-identities/google', 'POST');
        $request->cookies->set('__Host-oripa_user_session', $token);

        return $request;
    }

    private function callbackRequest(string $binding, ?string $session = null): Request
    {
        $request = Request::create($this->callbackUrl, 'GET');
        $request->cookies->set('__Host-oripa_oidc_transaction', $binding);
        if ($session !== null) {
            $request->cookies->set('__Host-oripa_user_session', $session);
        }

        return $request;
    }

    private function expectAuthenticationCode(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('The authentication operation must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}

final class FakeGoogleOidcTransport implements V2GoogleOidcTransport
{
    private string $privateKey;
    /** @var array<string, mixed> */
    private array $jwk;
    private string $idToken = '';
    private ?string $expectedChallenge = null;
    private bool $exchangeFailure = false;
    private bool $jwksFailure = false;

    public function __construct()
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new \RuntimeException('Synthetic RSA key generation failed.');
        }
        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new \RuntimeException('Synthetic RSA public key extraction failed.');
        }
        $this->privateKey = $privateKey;
        $this->jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'fixture-key',
            'n' => $this->base64Url($details['rsa']['n']),
            'e' => $this->base64Url($details['rsa']['e']),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function issue(
        string $nonce,
        string $subject,
        string $email,
        array $overrides = [],
        string $algorithm = 'RS256',
        string $keyId = 'fixture-key',
        bool $tamperSignature = false
    ): void {
        $now = now()->getTimestamp();
        $claims = [
            'iss' => 'https://accounts.google.com',
            'aud' => 'google-client.test',
            'azp' => 'google-client.test',
            'sub' => $subject,
            'email' => $email,
            'email_verified' => true,
            'nonce' => $nonce,
            'iat' => $now,
            'exp' => $now + 300,
        ];
        $this->idToken = JWT::encode(
            [...$claims, ...$overrides],
            $algorithm === 'RS256' ? $this->privateKey : str_repeat('h', 32),
            $algorithm,
            $keyId
        );
        if ($tamperSignature) {
            $segments = explode('.', $this->idToken);
            $segments[2][0] = $segments[2][0] === 'a' ? 'b' : 'a';
            $this->idToken = implode('.', $segments);
        }
    }

    public function expectChallenge(string $challenge): void
    {
        $this->expectedChallenge = $challenge;
    }

    public function failExchange(): void
    {
        $this->exchangeFailure = true;
    }

    public function failJwks(): void
    {
        $this->jwksFailure = true;
    }

    public function resetFailures(): void
    {
        $this->exchangeFailure = false;
        $this->jwksFailure = false;
    }

    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string {
        if (
            $this->exchangeFailure
            ||
            $this->idToken === ''
            || $authorizationCode === ''
            || ! preg_match('/\A[A-Za-z0-9_-]{43,128}\z/', $codeVerifier)
            || $this->expectedChallenge === null
            || ! hash_equals(
                $this->expectedChallenge,
                $this->base64Url(hash('sha256', $codeVerifier, true))
            )
            || $redirectUri !==
                'https://storefront.example.test/api/v2/auth/external/google/callback'
        ) {
            throw new \RuntimeException('Synthetic provider rejected the request.');
        }

        return $this->idToken;
    }

    public function jwks(bool $refresh = false): array
    {
        if ($this->jwksFailure) {
            throw new \RuntimeException('Synthetic JWKS transport failure.');
        }

        return ['keys' => [$this->jwk]];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
