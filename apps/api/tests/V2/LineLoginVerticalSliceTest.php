<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2LineOidcTransport;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Exceptions\V2OidcProtocolException;
use App\Domain\Identity\Services\V2ExternalIdentityService;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2LineOidcHttpTransport;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionManager;
use App\Models\V2\AuditLog;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\ExternalIdentityTransaction;
use App\Models\V2\LineFriendship;
use App\Models\V2\LineMessagingSetting;
use App\Models\V2\LinePendingFollow;
use App\Models\V2\User;
use App\Models\V2\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;
use Tests\TestCase;

final class LineLoginVerticalSliceTest extends TestCase
{
    private FakeLineOidcTransport $provider;
    private string $callbackUrl =
        'https://storefront.example.test/api/v2/auth/external/line/callback';

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
            'v2_identity.external_identity.google.client_secret' => 'synthetic-google-secret',
            'v2_identity.external_identity.google.redirect_uri' =>
                'https://storefront.example.test/api/v2/auth/external/google/callback',
            'v2_identity.external_identity.line.client_id' => 'line-channel.test',
            'v2_identity.external_identity.line.client_secret' => 'synthetic-line-secret',
            'v2_identity.external_identity.line.redirect_uri' => $this->callbackUrl,
            'v2_identity.external_identity.line.email_scope_enabled' => false,
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        Cache::store('array')->clear();
        $this->provider = new FakeLineOidcTransport();
        $this->app->instance(V2LineOidcTransport::class, $this->provider);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_line_start_uses_fixed_endpoint_pkce_and_optional_email_scope(): void
    {
        $started = $this->start('login');
        self::assertSame(['openid', 'profile'], explode(' ', $started['query']['scope']));
        self::assertSame('S256', $started['query']['code_challenge_method']);
        self::assertSame('https', parse_url($started['authorization_url'], PHP_URL_SCHEME));
        self::assertSame('access.line.me', parse_url($started['authorization_url'], PHP_URL_HOST));
        self::assertSame('/oauth2/v2.1/authorize', parse_url(
            $started['authorization_url'],
            PHP_URL_PATH
        ));

        config(['v2_identity.external_identity.line.email_scope_enabled' => true]);
        $withEmail = $this->start('login');
        self::assertSame(
            ['openid', 'profile', 'email'],
            explode(' ', $withEmail['query']['scope'])
        );

        $attributes = ExternalIdentityTransaction::query()->latest('id')->firstOrFail()
            ->getAttributes();
        $serialized = json_encode($attributes, JSON_THROW_ON_ERROR);
        foreach (
            [
                $withEmail['query']['state'],
                $withEmail['query']['nonce'],
                $withEmail['binding'],
            ] as $secret
        ) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_line_login_creates_passwordless_user_only_with_verified_email(): void
    {
        config(['v2_identity.external_identity.line.email_scope_enabled' => true]);
        $started = $this->start('login');
        $this->provider->claims(
            $started['query']['nonce'],
            'line-subject-new',
            'line-new@example.test'
        );
        $result = $this->completeLine($started, 'line-code-new');

        self::assertSame('line', ExternalIdentityAccount::query()->sole()->provider);
        self::assertSame('https://access.line.me', ExternalIdentityAccount::query()->sole()->issuer);
        self::assertSame(V2UserState::Active, $result['user']->state);
        self::assertFalse($result['user']->password_login_enabled);
        self::assertNotNull($result['user']->email_verified_at);
        $serialized = json_encode(
            [
                ...ExternalIdentityAccount::query()->sole()->getAttributes(),
                ...ExternalIdentityTransaction::query()->sole()->getAttributes(),
            ],
            JSON_THROW_ON_ERROR
        );
        foreach (['line-subject-new', 'line-code-new', 'line-new@example.test'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_line_login_claims_pending_follow_and_grants_reward_once(): void
    {
        config(['v2_identity.external_identity.line.email_scope_enabled' => true]);
        LineMessagingSetting::query()->whereKey(1)->update([
            'reward_enabled' => true,
            'reward_point_amount' => 75,
        ]);
        $subject = 'pending-before-login';
        $pending = new LinePendingFollow();
        $pending->forceFill([
            'public_id' => (string) Str::uuid7(),
            'subject_hash' => app(V2IdentityCorrelation::class)
                ->hash('line|https://access.line.me|'.$subject),
            'status' => 'pending',
            'followed_at' => now()->subMinute(),
        ])->save();
        $started = $this->start('login');
        $this->provider->claims(
            $started['query']['nonce'],
            $subject,
            'pending-before-login@example.test'
        );
        $result = $this->completeLine($started, 'pending-before-login-code');

        self::assertSame('claimed', LinePendingFollow::query()->sole()->status);
        self::assertSame(
            $result['user']->getKey(),
            LinePendingFollow::query()->sole()->claimed_by_user_id
        );
        self::assertSame(75, Wallet::query()
            ->where('user_id', $result['user']->getKey())->sole()->free_balance);
        self::assertNotNull(LineFriendship::query()->sole()->point_operation_id);
    }

    public function test_line_login_without_email_requires_completion_and_creates_no_user(): void
    {
        $userCount = User::query()->count();
        $started = $this->start('login');
        $this->provider->claims($started['query']['nonce'], 'line-no-email');
        $this->expectCode(
            'EXTERNAL_IDENTITY_EMAIL_COMPLETION_REQUIRED',
            fn () => $this->completeLine($started, 'line-code-no-email')
        );

        self::assertSame($userCount, User::query()->count());
        self::assertSame(0, ExternalIdentityAccount::query()->count());
        self::assertSame('failed', ExternalIdentityTransaction::query()->sole()->status);
    }

    public function test_existing_line_identity_login_does_not_require_email_or_follow_profile(): void
    {
        $user = $this->user('existing-line@example.test');
        $this->account($user, 'stable-subject');
        $started = $this->start('login');
        $this->provider->claims(
            $started['query']['nonce'],
            'stable-subject',
            null,
            ['name' => 'Changed profile', 'picture' => 'https://invalid.example/picture']
        );
        $result = $this->completeLine($started, 'line-existing-code');

        self::assertSame($user->getKey(), $result['user']->getKey());
        self::assertSame('existing-line@example.test', $result['user']->email_normalized);
        self::assertSame(1, ExternalIdentityAccount::query()->count());
    }

    public function test_email_collision_requires_explicit_link_and_link_ignores_email_claim(): void
    {
        config(['v2_identity.external_identity.line.email_scope_enabled' => true]);
        $existing = $this->user('collision-line@example.test');
        $started = $this->start('login');
        $this->provider->claims(
            $started['query']['nonce'],
            'collision-subject',
            'collision-line@example.test'
        );
        $this->expectCode(
            'EXTERNAL_IDENTITY_LINK_REQUIRED',
            fn () => $this->completeLine($started, 'collision-code')
        );
        self::assertSame(0, ExternalIdentityAccount::query()->count());

        [$request, $oldToken] = $this->authenticatedRequest($existing);
        config(['v2_identity.external_identity.line.email_scope_enabled' => false]);
        $link = $this->start('link', $existing, $request);
        $this->provider->claims($link['query']['nonce'], 'collision-subject');
        $linked = $this->completeLine($link, 'link-code', $oldToken);
        self::assertNotSame($oldToken, $linked['session']['token']);
        self::assertSame($existing->getKey(), ExternalIdentityAccount::query()->sole()->user_id);
    }

    public function test_line_reauthentication_and_unlink_rotate_session_and_keep_other_credential(): void
    {
        $user = $this->user('line-reauth@example.test', false);
        $this->account($user, 'line-reauth-subject');
        $google = ExternalIdentityAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'issuer' => 'https://accounts.google.com',
            'subject_hash' => app(V2IdentityCorrelation::class)
                ->hash('google|https://accounts.google.com|google-other-subject'),
            'linked_at' => now(),
            'last_authenticated_at' => now(),
        ]);
        [$request, $oldToken] = $this->authenticatedRequest($user);
        $reauth = $this->start('reauthentication', $user, $request);
        $this->provider->claims($reauth['query']['nonce'], 'line-reauth-subject');
        $fresh = $this->completeLine($reauth, 'reauth-code', $oldToken);
        self::assertNotSame($oldToken, $fresh['session']['token']);

        $rotated = app(V2ExternalIdentityService::class)->unlink(
            'line',
            $user,
            $this->requestWithSession($fresh['session']['token'])
        );
        self::assertNotSame($fresh['session']['token'], $rotated['token']);
        self::assertNotNull(ExternalIdentityAccount::query()
            ->where('provider', 'line')
            ->sole()
            ->revoked_at);
        self::assertNull($google->refresh()->revoked_at);
    }

    public function test_line_protocol_claim_and_provider_failures_are_generic_and_one_time(): void
    {
        $scenarios = [
            'nonce' => ['nonce' => 'wrong'],
            'issuer' => ['iss' => 'https://issuer.example.test'],
            'audience' => ['aud' => 'wrong-channel'],
            'expired' => ['exp' => now()->getTimestamp() - 120],
            'future-iat' => ['iat' => now()->getTimestamp() + 120],
            'invalid-subject' => ['sub' => ''],
        ];
        foreach ($scenarios as $name => $overrides) {
            $started = $this->start('login');
            $this->provider->claims(
                $started['query']['nonce'],
                'subject-'.$name,
                null,
                $overrides
            );
            $this->expectCode(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                fn () => $this->completeLine($started, 'code-'.$name)
            );
        }

        foreach (['timeout', 'rate-limit', 'server-error', 'verify-rejected'] as $failure) {
            $started = $this->start('login');
            $this->provider->claims($started['query']['nonce'], 'subject-'.$failure);
            $this->provider->fail($failure);
            $this->expectCode(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                fn () => $this->completeLine($started, 'code-'.$failure)
            );
            $this->expectCode(
                'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
                fn () => $this->completeLine($started, 'code-'.$failure)
            );
            $this->provider->reset();
        }
    }

    public function test_line_state_pkce_expiry_and_completed_transaction_replay_are_rejected(): void
    {
        $stateMismatch = $this->start('login');
        $this->provider->claims(
            $stateMismatch['query']['nonce'],
            'line-state-mismatch'
        );
        $stateMismatch['state'] = str_repeat('f', 64);
        $this->expectCode(
            'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
            fn () => $this->completeLine($stateMismatch, 'state-mismatch-code')
        );

        $pkceMismatch = $this->start('login');
        $this->provider->claims(
            $pkceMismatch['query']['nonce'],
            'line-pkce-mismatch'
        );
        $this->provider->expectChallenge(str_repeat('x', 43));
        $this->expectCode(
            'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
            fn () => $this->completeLine($pkceMismatch, 'pkce-mismatch-code')
        );

        $expired = $this->start('login');
        $this->provider->claims($expired['query']['nonce'], 'line-expired');
        $this->travel(11)->minutes();
        $this->expectCode(
            'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
            fn () => $this->completeLine($expired, 'expired-code')
        );
        self::assertSame(
            'expired',
            ExternalIdentityTransaction::query()
                ->where('state_hash', app(
                    \App\Domain\Identity\Services\V2SecureToken::class
                )->hash($expired['state']))
                ->sole()
                ->status
        );
        $this->travelBack();

        config(['v2_identity.external_identity.line.email_scope_enabled' => true]);
        $completed = $this->start('login');
        $this->provider->claims(
            $completed['query']['nonce'],
            'line-completed-replay',
            'line-completed-replay@example.test'
        );
        $this->completeLine($completed, 'completed-code');
        $this->expectCode(
            'EXTERNAL_IDENTITY_AUTHENTICATION_FAILED',
            fn () => $this->completeLine($completed, 'completed-code')
        );
    }

    public function test_line_http_transport_uses_only_fixed_endpoints_and_fails_closed(): void
    {
        Http::fake([
            'https://api.line.me/oauth2/v2.1/token' => Http::response([
                'id_token' => 'synthetic-id-token',
            ]),
            'https://api.line.me/oauth2/v2.1/verify' => Http::response([
                'iss' => 'https://access.line.me',
                'sub' => 'synthetic-subject',
                'aud' => 'line-channel.test',
                'exp' => now()->addMinutes(5)->getTimestamp(),
                'iat' => now()->getTimestamp(),
                'nonce' => 'synthetic-nonce',
            ]),
        ]);
        $transport = app(V2LineOidcHttpTransport::class);
        $token = $transport->exchangeAuthorizationCode(
            'synthetic-code',
            str_repeat('v', 64),
            $this->callbackUrl
        );
        self::assertSame('synthetic-id-token', $token);
        self::assertSame('synthetic-subject', $transport->verifyIdToken($token)['sub']);
        Http::assertSentCount(2);
        Http::assertSent(static fn ($request): bool =>
            in_array($request->url(), [
                'https://api.line.me/oauth2/v2.1/token',
                'https://api.line.me/oauth2/v2.1/verify',
            ], true)
        );

    }

    public function test_line_http_transport_fails_closed_on_provider_rate_limit(): void
    {
        Http::fake([
            'https://api.line.me/oauth2/v2.1/token' => Http::response([], 429),
        ]);
        $transport = app(V2LineOidcHttpTransport::class);
        try {
            $transport->exchangeAuthorizationCode(
                'synthetic-code',
                str_repeat('v', 64),
                $this->callbackUrl
            );
            self::fail('LINE 429 must fail closed.');
        } catch (V2OidcProtocolException $exception) {
            self::assertSame('provider_rate_limited', $exception->reasonCode);
        }
    }

    public function test_line_http_contract_and_database_provider_constraints(): void
    {
        $csrf = str_repeat('a', 64);
        $response = $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
            ])
            ->postJson('/api/v2/auth/external/line/start', ['return_path' => '/'])
            ->assertOk()
            ->assertJsonPath('provider', 'line');
        self::assertStringStartsWith(
            'https://access.line.me/oauth2/v2.1/authorize?',
            $response->json('authorization_url')
        );

        $user = $this->user('constraint-line@example.test');
        try {
            ExternalIdentityAccount::query()->create([
                'user_id' => $user->getKey(),
                'provider' => 'line',
                'issuer' => 'https://attacker.example.test',
                'subject_hash' => str_repeat('a', 64),
                'linked_at' => now(),
            ]);
            self::fail('Mismatched LINE issuer must be rejected.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_audit_does_not_expose_line_protocol_or_pii_values(): void
    {
        config(['v2_identity.external_identity.line.email_scope_enabled' => true]);
        $started = $this->start('login');
        $this->provider->claims(
            $started['query']['nonce'],
            'audit-line-subject',
            'audit-line@example.test'
        );
        $this->completeLine($started, 'audit-authorization-code');
        $serialized = AuditLog::query()
            ->get()
            ->map(fn (AuditLog $log): array => $log->getAttributes())
            ->toJson();
        foreach (
            [
                'audit-line-subject',
                'audit-line@example.test',
                'audit-authorization-code',
                $started['query']['state'],
                $started['query']['nonce'],
                $started['binding'],
            ] as $secret
        ) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    /**
     * @return array{
     *   state: string,
     *   binding: string,
     *   authorization_url: string,
     *   query: array<string, string>
     * }
     */
    private function start(
        string $purpose,
        ?User $user = null,
        ?Request $request = null
    ): array {
        $request ??= Request::create('/api/v2/auth/external/line/start', 'POST');
        $result = app(V2ExternalIdentityService::class)->startForProvider(
            'line',
            $purpose,
            '/',
            '192.0.2.70',
            (string) Str::uuid7(),
            $user,
            $request
        );
        parse_str(parse_url($result['authorization_url'], PHP_URL_QUERY), $query);
        $this->provider->expectChallenge($query['code_challenge']);

        return [
            'state' => $query['state'],
            'binding' => $result['binding_token'],
            'authorization_url' => $result['authorization_url'],
            'query' => $query,
        ];
    }

    /**
     * @param array{state: string, binding: string} $started
     * @return array<string, mixed>
     */
    private function completeLine(
        array $started,
        string $code,
        ?string $session = null
    ): array {
        return app(V2ExternalIdentityService::class)->callbackForProvider(
            'line',
            $started['state'],
            $code,
            $started['binding'],
            $this->callbackUrl,
            '192.0.2.71',
            $this->callbackRequest($started['binding'], $session)
        );
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
            'provider' => 'line',
            'issuer' => 'https://access.line.me',
            'subject_hash' => app(V2IdentityCorrelation::class)
                ->hash('line|https://access.line.me|'.$subject),
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
        $request = Request::create('/api/v2/me/external-identities/line', 'POST');
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

    private function expectCode(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('The LINE identity operation must fail.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}

final class FakeLineOidcTransport implements V2LineOidcTransport
{
    /** @var array<string, mixed> */
    private array $claims = [];
    private ?string $expectedChallenge = null;
    private ?string $failure = null;

    /**
     * @param array<string, mixed> $overrides
     */
    public function claims(
        string $nonce,
        string $subject,
        ?string $email = null,
        array $overrides = []
    ): void {
        $now = now()->getTimestamp();
        $claims = [
            'iss' => 'https://access.line.me',
            'sub' => $subject,
            'aud' => 'line-channel.test',
            'exp' => $now + 300,
            'iat' => $now,
            'nonce' => $nonce,
        ];
        if ($email !== null) {
            $claims['email'] = $email;
        }
        $this->claims = [...$claims, ...$overrides];
    }

    public function expectChallenge(string $challenge): void
    {
        $this->expectedChallenge = $challenge;
    }

    public function fail(string $failure): void
    {
        $this->failure = $failure;
    }

    public function reset(): void
    {
        $this->failure = null;
    }

    public function exchangeAuthorizationCode(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $codeVerifier,
        string $redirectUri
    ): string {
        if (
            $this->failure === 'timeout'
            || $this->failure === 'rate-limit'
            || $this->failure === 'server-error'
        ) {
            throw new V2OidcProtocolException(match ($this->failure) {
                'rate-limit' => 'provider_rate_limited',
                'server-error' => 'provider_unavailable',
                default => 'provider_transport_failed',
            });
        }
        if (
            $authorizationCode === ''
            || $redirectUri !==
                'https://storefront.example.test/api/v2/auth/external/line/callback'
            || $this->expectedChallenge === null
            || ! hash_equals(
                $this->expectedChallenge,
                $this->base64Url(hash('sha256', $codeVerifier, true))
            )
        ) {
            throw new V2OidcProtocolException('authorization_code_rejected');
        }

        return 'synthetic-line-id-token';
    }

    public function verifyIdToken(#[SensitiveParameter] string $idToken): array
    {
        if (
            $this->failure === 'verify-rejected'
            || $idToken !== 'synthetic-line-id-token'
            || $this->claims === []
        ) {
            throw new V2OidcProtocolException('line_verify_rejected');
        }

        return $this->claims;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
