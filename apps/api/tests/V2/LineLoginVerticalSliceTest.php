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
            'database.connections.pgsql.timezone' => 'UTC',
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
        DB::purge('pgsql');
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
        self::assertNull($result['user']->pre_registered_at);
        self::assertTrue($result['user']->first_registration_qualified_at->equalTo($result['user']->email_verified_at));
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
        $qualifiedAt = $user->first_registration_qualified_at;
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
        self::assertEquals($qualifiedAt, $user->fresh()->first_registration_qualified_at);
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
    public function test_normal_closure_allows_new_line_identity_and_later_financial_stop_keeps_new_login(): void
    {
        config(['v2_identity.external_identity.line.email_scope_enabled' => true]);
        $first = $this->start('login');
        $this->provider->claims($first['query']['nonce'], 'closure-line-subject', 'closure-line@example.test');
        $old = $this->completeLine($first, 'closure-first-code')['user'];
        app(\App\Domain\Identity\Services\V2UserClosureService::class)->closeLocked($old, 'admin', 'normal', (string) Str::uuid7());
        $second = $this->start('login');
        $this->provider->claims($second['query']['nonce'], 'closure-line-subject', 'closure-line@example.test');
        $new = $this->completeLine($second, 'closure-second-code')['user'];
        self::assertNotSame($old->id, $new->id);
        self::assertSame(2, ExternalIdentityAccount::query()->where('provider', 'line')->count());
        self::assertNotNull(ExternalIdentityAccount::query()->where('user_id', $old->id)->value('superseded_at'));
        app(\App\Domain\Identity\Services\V2UserClosureService::class)->financialStop($old->id);
        $third = $this->start('login');
        $this->provider->claims($third['query']['nonce'], 'closure-line-subject', 'closure-line@example.test');
        self::assertSame($new->id, $this->completeLine($third, 'closure-third-code')['user']->id);
        app(\App\Domain\Identity\Services\V2UserClosureService::class)->closeLocked($new, 'admin', 'normal', (string) Str::uuid7());
        $fourth = $this->start('login');
        $this->provider->claims($fourth['query']['nonce'], 'closure-line-subject', 'closure-line@example.test');
        $this->expectException(V2AuthenticationException::class);
        $this->completeLine($fourth, 'closure-fourth-code');
    }

    public function test_friendship_failure_preserves_successful_link_and_backfills_subject(): void
    {
        config(['v2_line.friendship_check_enabled' => true]);
        $this->provider->friendshipFails = true;
        $user = $this->user('friendship-failure@example.test');
        [$request, $session] = $this->authenticatedRequest($user);
        $started = $this->start('link', $user, $request);
        $this->provider->claims($started['query']['nonce'], 'synthetic-failure-subject', null);
        $result = $this->completeLine($started, 'synthetic-code', $session);
        self::assertSame($user->id, $result['user']->id);
        self::assertSame('friendship_check_failed', $result['line_result']);
        self::assertNotNull(ExternalIdentityAccount::query()->where('user_id', $user->id)->value('subject_ciphertext'));
        self::assertSame(0, DB::table('line_friendships')->count());
        self::assertSame('completed', DB::table('external_identity_transactions')->value('status'));
    }

    public function test_reauthentication_rechecks_friendship_and_fills_existing_identity(): void
    {
        config(['v2_line.friendship_check_enabled' => true]);
        $this->provider->friendFlag = true;
        $user = $this->user('friendship-reauth@example.test');
        $this->account($user, 'synthetic-reauth-subject');
        [$request, $session] = $this->authenticatedRequest($user);
        $started = $this->start('reauthentication', $user, $request);
        $this->provider->claims($started['query']['nonce'], 'synthetic-reauth-subject', null);
        $result = $this->completeLine($started, 'synthetic-reauth-code', $session);
        self::assertNull($result['line_result']);
        self::assertSame('friend', DB::table('line_friendships')->value('status'));
        self::assertNotNull(ExternalIdentityAccount::query()->where('user_id', $user->id)->value('subject_ciphertext'));
    }

    public function test_disabled_check_keeps_reauthentication_free_of_pending_reward_mutation(): void
    {
        config(['v2_line.friendship_check_enabled' => false]);
        $user = $this->user('friendship-disabled@example.test');
        $subject = 'synthetic-disabled-reauth';
        $this->account($user, $subject);
        $account = ExternalIdentityAccount::query()->where('user_id', $user->id)->sole();
        DB::table('line_pending_follows')->insert(['public_id' => (string) Str::uuid7(), 'subject_hash' => $account->subject_hash,
            'status' => 'pending', 'followed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        [$request, $session] = $this->authenticatedRequest($user);
        $started = $this->start('reauthentication', $user, $request);
        $this->provider->claims($started['query']['nonce'], $subject, null);
        $this->completeLine($started, 'synthetic-disabled-code', $session);
        self::assertSame(0, $this->provider->friendshipCalls);
        self::assertSame('pending', DB::table('line_pending_follows')->value('status'));
        self::assertSame(0, DB::table('line_friendships')->count());
        self::assertSame($subject, \Illuminate\Support\Facades\Crypt::decryptString($account->fresh()->subject_ciphertext));
        self::assertNotNull(DB::table('line_pending_follows')->value('subject_ciphertext'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('callbackRepresentations')]
    public function test_line_callback_with_different_database_timezone(string $purpose, string $accept, int $status): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        config(['app.timezone' => 'Asia/Tokyo']);
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        try {
            self::assertSame('UTC', DB::selectOne('SHOW TimeZone')->TimeZone);
            self::assertSame('UTC', DB::connection()->getConfig('timezone'));
            self::assertSame('timestamp with time zone', DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'external_identity_transactions' AND column_name = 'expires_at'")->data_type);
            $instant = now();
            self::assertSame($instant->format('Y-m-d H:i:s'), DB::connection()->prepareBindings([$instant])[0]);
            $user = $this->user('timezone-callback@example.test');
            if ($purpose === 'reauthentication') $this->account($user, 'timezone-subject');
            [$initial, $session] = $this->authenticatedRequest($user);
            $started = $this->start($purpose, $user, $initial, '/mypage/line');
            $this->provider->claims($started['query']['nonce'], 'timezone-subject');
            $request = $this->callbackRequest($started['binding'], $session);
            $request->query->replace(['code' => 'synthetic-timezone-code', 'state' => $started['state']]);
            $request->headers->set('Accept', $accept);
            $request->headers->set('Sec-Fetch-Mode', $status === 303 ? 'navigate' : 'cors');
            $request->headers->set('Sec-Fetch-Dest', $status === 303 ? 'document' : 'empty');
            self::assertSame($status === 200, $request->expectsJson());
            self::assertSame(0, ExternalIdentityTransaction::query()->where('expires_at', '>', now())->count());
            self::assertSame(1, ExternalIdentityTransaction::query()->where('expires_at', '>', \App\Support\V2DatabaseTimestamp::format(now()))->count());
            $response = app(\App\Http\Controllers\V2\V2PublicAuthController::class)->completeLine($request);
            self::assertSame($status, $response->getStatusCode());
            if ($status === 303) {
                self::assertSame('/mypage/line', $response->headers->get('Location'));
                self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
                self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                self::assertStringNotContainsString('authenticated', $response->getContent());
            } else {
                self::assertTrue(json_decode($response->getContent(), true)['authenticated']);
            }
            $cookies = [];
            foreach ($response->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie;
            self::assertNotSame($session, $cookies['__Host-oripa_user_session']->getValue());
            self::assertTrue($cookies['__Host-oripa_user_session']->isSecure());
            self::assertTrue($cookies['__Host-oripa_user_session']->isHttpOnly());
            self::assertLessThan(time(), $cookies['__Host-oripa_oidc_transaction']->getExpiresTime());
            self::assertSame($user->id, ExternalIdentityAccount::query()->sole()->user_id);
            self::assertNull(app(V2ExternalIdentityService::class)->lineBrowserReturnSession($request));
            $this->expectCode('EXTERNAL_IDENTITY_AUTHENTICATION_FAILED', fn () =>
                app(\App\Http\Controllers\V2\V2PublicAuthController::class)->completeLine($request));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public static function callbackRepresentations(): array
    {
        $cases = [];
        foreach (['link', 'reauthentication'] as $purpose) {
            foreach (['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', '*/*', 'application/json'] as $accept) {
                $cases[] = [$purpose, $accept, $accept === 'application/json' ? 200 : 303];
            }
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeBrowserCallbacks')]
    public function test_browser_return_rejects_invalid_binding_session_or_expiry(string $mutation): void
    {
        $user = $this->user('unsafe-browser@example.test');
        [$initial, $session] = $this->authenticatedRequest($user);
        $started = $this->start('link', $user, $initial, '/mypage/line');
        $this->provider->claims($started['query']['nonce'], 'unsafe-browser-subject');
        $request = $this->callbackRequest($started['binding'], $session);
        $request->query->replace(['code' => 'synthetic-unsafe-code', 'state' => $started['state']]);
        $request->headers->set('Accept', 'text/html');
        match ($mutation) {
            'binding' => $request->cookies->set('__Host-oripa_oidc_transaction', str_repeat('b', 64)),
            'missing_session' => $request->cookies->remove('__Host-oripa_user_session'),
            'other_user' => $request->cookies->set('__Host-oripa_user_session', $this->authenticatedRequest($this->user('other-browser@example.test'))[1]),
            'other_session' => $request->cookies->set('__Host-oripa_user_session', $this->authenticatedRequest($user)[1]),
            'expired' => $this->travel(11)->minutes(),
        };
        self::assertNull(app(V2ExternalIdentityService::class)->lineBrowserReturnSession($request));
        $this->expectCode('EXTERNAL_IDENTITY_AUTHENTICATION_FAILED', fn () =>
            app(\App\Http\Controllers\V2\V2PublicAuthController::class)->completeLine($request));
        self::assertSame(0, ExternalIdentityAccount::query()->count());
    }

    public static function unsafeBrowserCallbacks(): array
    {
        return array_map(fn ($value) => [$value], ['binding', 'missing_session', 'other_user', 'other_session', 'expired']);
    }

    public function test_browser_line_link_returns_to_exact_path_and_session_bound_notice(): void
    {
        config(['v2_line.friendship_check_enabled' => true]);
        $this->provider->friendshipFails = true;
        $user = $this->user('browser-line-return@example.test');
        [$initialRequest, $session] = $this->authenticatedRequest($user);
        $started = $this->start('link', $user, $initialRequest, '/mypage/line');
        $this->provider->claims($started['query']['nonce'], 'synthetic-browser-subject', null);
        $request = $this->callbackRequest($started['binding'], $session);
        $request->query->replace(['code' => 'synthetic-browser-code', 'state' => $started['state']]);
        $request->headers->set('Accept', 'text/html');
        $response = app(\App\Http\Controllers\V2\V2PublicAuthController::class)->completeLine($request);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/mypage/line', $response->headers->get('Location'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $sessionCookie = null;
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === '__Host-oripa_user_session') $sessionCookie = $cookie->getValue();
        }
        self::assertNotNull($sessionCookie);
        $hash = app(\App\Domain\Identity\Services\V2SessionPolicy::class)->hashSessionId($sessionCookie);
        $notice = app(\App\Domain\Line\Services\V2LineCallbackNotice::class);
        self::assertSame('friendship_check_failed', $notice->consume($hash));
        self::assertNull($notice->consume($hash));
    }

    public function test_mypage_return_is_not_allowed_for_line_login_or_google(): void
    {
        $this->expectCode('INVALID_REDIRECT', fn () => $this->start('login', null, null, '/mypage/line'));
        $this->expectCode('INVALID_REDIRECT', fn () => app(V2ExternalIdentityService::class)->startForProvider(
            'google', 'login', '/mypage/line', '192.0.2.70', (string) Str::uuid7(), null, Request::create('/')));
        $user = $this->user('redirect-allowlist@example.test');
        [$request] = $this->authenticatedRequest($user);
        foreach (['https://attacker.example/mypage/line', '//attacker.example', '/mypage/line?next=https://attacker.example'] as $path) {
            $this->expectCode('INVALID_REDIRECT', fn () => $this->start('link', $user, $request, $path));
        }
    }

    public function test_browser_cancel_consumes_transaction_and_returns_auth_failure_once(): void
    {
        $user = $this->user('browser-cancel@example.test');
        [$initialRequest, $session] = $this->authenticatedRequest($user);
        $started = $this->start('link', $user, $initialRequest, '/mypage/line');
        $request = $this->callbackRequest($started['binding'], $session);
        $request->query->replace(['error' => 'access_denied', 'state' => $started['state']]);
        $request->headers->set('Accept', 'text/html');
        $response = app(\App\Http\Controllers\V2\V2PublicAuthController::class)->completeLine($request);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/mypage/line', $response->headers->get('Location'));
        self::assertSame('revoked', DB::table('external_identity_transactions')->value('status'));
        self::assertNull(app(V2ExternalIdentityService::class)->lineBrowserReturnSession($request));
        $notice = app(\App\Domain\Line\Services\V2LineCallbackNotice::class);
        $hash = app(\App\Domain\Identity\Services\V2SessionPolicy::class)->hashSessionId($session);
        self::assertSame('line_auth_failed', $notice->consume($hash));
        self::assertNull($notice->consume($hash));
    }

    public function test_disabled_check_does_not_call_friendship_provider(): void
    {
        config(['v2_line.friendship_check_enabled' => false]);
        $this->provider->friendshipFails = true;
        $user = $this->user('disabled-friend-check@example.test');
        [$request, $session] = $this->authenticatedRequest($user);
        $started = $this->start('link', $user, $request);
        $this->provider->claims($started['query']['nonce'], 'synthetic-disabled-subject', null);
        $result = $this->completeLine($started, 'synthetic-code', $session);
        self::assertSame($user->id, $result['user']->id);
        self::assertSame(0, $this->provider->friendshipCalls);
    }

    public function test_tls_terminated_browser_callback_links_with_exact_uri_and_binding(): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.10'], 'v2_line.friendship_check_enabled' => true]);
        $user = $this->user('proxy-link@example.test');
        [$initial, $session] = $this->authenticatedRequest($user);
        $started = $this->start('link', $user, $initial, '/mypage/line');
        $this->provider->claims($started['query']['nonce'], 'synthetic-proxy-link-subject');
        $request = $this->proxiedCallback($started, $session);
        try {
            $response = (new \Illuminate\Http\Middleware\TrustProxies())->handle($request,
                fn (Request $r) => app(\App\Http\Controllers\V2\V2PublicAuthController::class)->completeLine($r));
            self::assertSame($this->callbackUrl, $request->url());
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/mypage/line', $response->headers->get('Location'));
            self::assertSame('completed', ExternalIdentityTransaction::query()->sole()->status);
            self::assertSame($user->id, ExternalIdentityAccount::query()->sole()->user_id);
            self::assertSame(1, $this->provider->exchangeCalls);
            self::assertSame(1, $this->provider->verifyCalls);
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_PROTO);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('proxyRejections')]
    public function test_proxy_does_not_relax_callback_or_browser_binding(string $mutation): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $user = $this->user('proxy-reject@example.test');
        [$initial, $session] = $this->authenticatedRequest($user);
        $started = $this->start('link', $user, $initial, '/mypage/line');
        $request = $this->proxiedCallback($started, $session);
        match ($mutation) {
            'untrusted' => $request->server->set('REMOTE_ADDR', '192.0.2.11'),
            'host' => $request->headers->set('Host', 'wrong.example.test'),
            'path' => $request->server->set('REQUEST_URI', '/api/v2/auth/external/line/wrong'),
            'cookie' => $request->cookies->set('__Host-oripa_oidc_transaction', str_repeat('a', 64)),
            'session' => $request->cookies->remove('__Host-oripa_user_session'),
        };
        try {
            $this->expectCode('EXTERNAL_IDENTITY_AUTHENTICATION_FAILED', fn () =>
                (new \Illuminate\Http\Middleware\TrustProxies())->handle($request, fn (Request $r) =>
                    app(V2ExternalIdentityService::class)->callbackForProvider('line', $started['state'], 'synthetic-proxy-code',
                        $r->cookies->get('__Host-oripa_oidc_transaction'), $r->url(), '192.0.2.70', $r)));
            self::assertSame(0, $this->provider->exchangeCalls);
            self::assertSame(0, $this->provider->verifyCalls);
            self::assertSame(0, ExternalIdentityAccount::query()->count());
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_PROTO);
        }
    }

    public static function proxyRejections(): array
    {
        return array_map(fn ($value) => [$value], ['untrusted', 'host', 'path', 'cookie', 'session']);
    }

    private function proxiedCallback(array $started, string $session): Request
    {
        $r = Request::create(str_replace('https://', 'http://', $this->callbackUrl), 'GET',
            ['code' => 'synthetic-proxy-code', 'state' => $started['state']],
            ['__Host-oripa_oidc_transaction' => $started['binding'], '__Host-oripa_user_session' => $session], [],
            ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_ACCEPT' => 'text/html']);
        return $r;
    }

    private function start(
        string $purpose,
        ?User $user = null,
        ?Request $request = null,
        string $returnPath = '/'
    ): array {
        $request ??= Request::create('/api/v2/auth/external/line/start', 'POST');
        $result = app(V2ExternalIdentityService::class)->startForProvider(
            'line',
            $purpose,
            $returnPath,
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
    public bool $friendFlag = false;
    public bool $friendshipFails = false;
    public int $friendshipCalls = 0;
    public int $exchangeCalls = 0;
    public int $verifyCalls = 0;

    public function friendshipStatus(): bool
    {
        $this->friendshipCalls++;
        if ($this->friendshipFails) throw new V2OidcProtocolException('friendship_transport_failed');
        return $this->friendFlag;
    }

    public function discardAccessToken(): void {}

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
        $this->exchangeCalls++;
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
        $this->verifyCalls++;
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
