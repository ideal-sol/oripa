<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Line\Contracts\V2LineMessagingTransport;
use App\Domain\Line\Services\V2LineMessagingHttpTransport;
use App\Domain\Line\ValueObjects\V2LineReplyResult;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\LineFriendship;
use App\Models\V2\LineMessagingSetting;
use App\Models\V2\LinePendingFollow;
use App\Models\V2\LineWebhookEvent;
use App\Models\V2\PointLedgerEntry;
use App\Models\V2\PointLot;
use App\Models\V2\PointOperation;
use App\Models\V2\User;
use App\Models\V2\Wallet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;


use App\Domain\Line\Services\V2LineConfiguration;
use App\Domain\Line\Services\V2LineBotTransport;
use App\Domain\Line\Services\V2LineFriendService;
use Illuminate\Support\Facades\Crypt;

final class LineBotManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_line.messaging.channel_secret' => 'synthetic-messaging-secret',
            'v2_line.messaging.channel_access_token' => 'synthetic-legacy-token',
        ]);
        Cache::store('array')->clear();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.line.me/oauth2/v3/token' => Http::response(['access_token' => 'synthetic-stateless-token', 'expires_in' => 900]),
            'https://api.line.me/v2/bot/info' => Http::response(['basicId' => '@synthetic', 'displayName' => 'Synthetic BOT']),
            'https://api.line.me/v2/bot/message/reply' => Http::response([]),
            'https://api.line.me/v2/bot/message/quota' => Http::response(['type' => 'limited', 'value' => 1000]),
            'https://api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 50]),
            'https://api.line.me/v2/bot/insight/followers*' => Http::response(['status' => 'ready', 'followers' => 10, 'targetedReaches' => 9, 'blocks' => 1]),
        ]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_owner_configuration_is_encrypted_redacted_revisioned_and_idempotent(): void
    {
        $owner = $this->adminSession(V2AdminRole::Owner);
        $payload = ['channel_id' => '1234567', 'channel_secret' => 'synthetic-login-secret', 'expected_revision' => 1];
        $first = $this->adminMutation($owner, 'PUT', '/admin/api/v2/settings/line-login', $payload, 'login-once')
            ->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('secret_configured', true);
        Auth::forgetGuards();
        $again = $this->adminMutation($owner, 'PUT', '/admin/api/v2/settings/line-login', $payload, 'login-once')->assertOk();
        self::assertEquals($first->json(), $again->json());
        Auth::forgetGuards();
        $this->adminMutation($owner, 'PUT', '/admin/api/v2/settings/line-login', $payload)->assertConflict();
        $stored = DB::table('line_login_settings')->first();
        self::assertSame('synthetic-login-secret', Crypt::decryptString($stored->channel_secret_ciphertext));
        self::assertSame('1234567', app(V2LineConfiguration::class)->login('client_id'));
        self::assertSame('synthetic-login-secret', app(V2LineConfiguration::class)->login('client_secret'));
        $evidence = json_encode([$first->json(), DB::table('audit_logs')->get(), DB::table('idempotency_records')->get()]);
        self::assertStringNotContainsString('synthetic-login-secret', $evidence);
        self::assertStringNotContainsString($stored->channel_secret_ciphertext, $evidence);
        Http::assertNothingSent();
    }

    public function test_roles_mfa_csrf_and_unknown_fields_fail_closed(): void
    {
        foreach ([V2AdminRole::Admin, V2AdminRole::Operator] as $role) {
            Auth::forgetGuards();
            $token = $this->adminSession($role);
            $read = $this->asAdmin($token)->getJson('/admin/api/v2/line-bots');
            $role === V2AdminRole::Admin ? $read->assertOk() : $read->assertForbidden();
            Auth::forgetGuards();
            $this->adminMutation($token, 'POST', '/admin/api/v2/line-bots', $this->botInput())->assertForbidden();
        }
        Auth::forgetGuards();
        $stale = $this->adminSession(V2AdminRole::Owner, now()->subMinutes(6));
        $this->adminMutation($stale, 'POST', '/admin/api/v2/line-bots', $this->botInput())
            ->assertForbidden()->assertJsonPath('code', 'FRESH_MFA_REQUIRED');
        Auth::forgetGuards();
        $owner = $this->adminSession(V2AdminRole::Owner);
        $this->adminMutation($owner, 'POST', '/admin/api/v2/line-bots', $this->botInput() + ['access_token' => 'not-accepted'])->assertUnprocessable();
        Auth::forgetGuards();
        $this->asAdmin($owner)->withHeader('X-XSRF-TOKEN', 'wrong')->postJson('/admin/api/v2/line-bots', $this->botInput())->assertForbidden()->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        Http::assertNothingSent();
    }

    public function test_bot_connection_activation_snapshots_and_channel_identity(): void
    {
        $owner = $this->adminSession(V2AdminRole::Owner);
        $bot = $this->adminMutation($owner, 'POST', '/admin/api/v2/line-bots', $this->botInput())->assertOk()
            ->assertJsonPath('is_active', false)->assertJsonPath('connection_status', 'pending')->json();
        $path = '/admin/api/v2/line-bots/'.$bot['id'];
        $this->action($owner, $path.'/activation', 1)->assertConflict();
        $connected = $this->action($owner, $path.'/connection', 1)->assertOk()->assertJsonPath('connection_status', 'succeeded');
        self::assertMatchesRegularExpression('/\AT[0-9]/', substr($connected->json('connected_at'), 10, 2));
        $this->action($owner, $path.'/activation', 2)->assertOk()->assertJsonPath('is_active', true);
        $this->action($owner, $path.'/quota', 3)->assertOk()->assertJsonPath('quota_snapshot.used', 50);
        $this->action($owner, $path.'/followers', 4)->assertOk()->assertJsonPath('followers_snapshot.followers', 10);
        Auth::forgetGuards();
        $this->adminMutation($owner, 'PUT', $path, ['name' => 'Changed', 'channel_id' => '7654321', 'expected_revision' => 5])
            ->assertConflict()->assertJsonPath('code', 'LINE_BOT_CHANNEL_IMMUTABLE');
        self::assertSame(1, DB::table('line_bots')->where('is_active', true)->count());
        $read = $this->asAdmin($owner)->getJson('/admin/api/v2/line-bots')->assertOk()->getContent();
        self::assertStringNotContainsString('synthetic-messaging-secret', $read);
        self::assertStringNotContainsString('synthetic-stateless-token', $read);
        self::assertNotNull(DB::table('line_bots')->where('public_id', $bot['id'])->value('followers_fetched_at'));
    }

    public function test_failed_connection_is_persisted_and_cannot_activate(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['sensitive_provider_body' => 'synthetic-secret-echo'], 500)]);
        $owner = $this->adminSession(V2AdminRole::Owner);
        $bot = $this->adminMutation($owner, 'POST', '/admin/api/v2/line-bots', $this->botInput())->assertOk()->json();
        $path = '/admin/api/v2/line-bots/'.$bot['id'];
        $result = $this->action($owner, $path.'/connection', 1)->assertOk()->assertJsonPath('connection_status', 'failed');
        self::assertStringNotContainsString('synthetic-secret-echo', $result->getContent());
        $this->action($owner, $path.'/activation', 2)->assertConflict();
    }

    public function test_stateless_token_cache_and_one_401_refresh_are_bot_scoped(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'https://api.line.me/oauth2/v3/token' => Http::sequence()
                ->push(['access_token' => 'synthetic-one', 'expires_in' => 900])
                ->push(['access_token' => 'synthetic-two', 'expires_in' => 900])
                ->push(['access_token' => 'synthetic-third-bot', 'expires_in' => 900])
                ->push(['access_token' => 'synthetic-after-ttl', 'expires_in' => 900]),
            'https://api.line.me/v2/bot/info' => Http::sequence()
                ->push(['basicId' => '@first'])->push([], 401)->push(['basicId' => '@second'])->push(['basicId' => '@second'])
                ->push(['basicId' => '@third'])->push(['basicId' => '@expired']),
        ]);
        $bot = $this->storedBot();
        $transport = app(V2LineBotTransport::class);
        $transport->get($bot, '/v2/bot/info');
        $transport->get($bot, '/v2/bot/info');
        $transport->get($bot, '/v2/bot/info');
        Http::assertSentCount(6);
        $transport->get($this->storedBot('7654321'), '/v2/bot/info');
        Http::assertSentCount(8);
        $this->travel(781)->seconds();
        try {
            $transport->get($bot, '/v2/bot/info');
            Http::assertSentCount(10);
        } finally {
            $this->travelBack();
        }
        Http::assertSent(fn ($r) => $r->url() === 'https://api.line.me/oauth2/v3/token'
            && $r['client_id'] === '1234567' && $r['grant_type'] === 'client_credentials');
        self::assertSame(0, DB::table('line_bots')->where('channel_secret_ciphertext', 'like', '%synthetic-one%')->count());
    }

    public function test_environment_import_preserves_admin_values_and_never_imports_tokens_or_activates(): void
    {
        config(['v2_identity.external_identity.line.client_id' => '3333333', 'v2_identity.external_identity.line.client_secret' => 'synthetic-env-login']);
        $configuration = app(V2LineConfiguration::class);
        self::assertSame('3333333', $configuration->login('client_id'));
        $configuration->importEnvironment();
        $first = DB::table('line_login_settings')->first();
        $legacy = DB::table('line_bots')->where('legacy', true)->first();
        config(['v2_identity.external_identity.line.client_id' => '4444444', 'v2_identity.external_identity.line.client_secret' => 'replacement-env']);
        $configuration->importEnvironment();
        self::assertEquals($first, DB::table('line_login_settings')->first());
        self::assertEquals($legacy, DB::table('line_bots')->where('legacy', true)->first());
        self::assertFalse($legacy->is_active);
        self::assertNull($legacy->channel_id);
        self::assertSame('synthetic-messaging-secret', Crypt::decryptString($legacy->channel_secret_ciphertext));
        self::assertStringNotContainsString('synthetic-legacy-token', json_encode([$first, $legacy]));
        Http::assertNothingSent();
    }

    public function test_active_secret_replacement_failure_keeps_prior_credentials_and_selection(): void
    {
        $owner = $this->adminSession(V2AdminRole::Owner);
        $bot = $this->storedBot();
        DB::table('line_bots')->where('id', $bot->id)->update(['is_active' => true]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 'synthetic-provider-private-body'], 500)]);
        $response = $this->adminMutation($owner, 'PUT', '/admin/api/v2/line-bots/'.$bot->public_id,
            ['name' => $bot->name, 'channel_id' => $bot->channel_id, 'channel_secret' => 'synthetic-replacement', 'expected_revision' => 1])
            ->assertServiceUnavailable();
        self::assertStringNotContainsString('synthetic-replacement', $response->getContent());
        self::assertStringNotContainsString('synthetic-provider-private-body', $response->getContent());
        $after = DB::table('line_bots')->where('id', $bot->id)->first();
        self::assertSame($bot->channel_secret_ciphertext, $after->channel_secret_ciphertext);
        self::assertSame(1, $after->revision);
        self::assertTrue($after->is_active);
    }

    public function test_webhook_legacy_unknown_inactive_and_bot_scoped_deduplication(): void
    {
        $event = $this->followEvent('same-event', 'synthetic-subject', 'synthetic-reply');
        $this->postWebhook($event, 'invalid-uuid')->assertUnauthorized();
        $this->postWebhook($event, (string) Str::uuid7())->assertUnauthorized();
        $bot = $this->storedBot();
        $this->postWebhook($event, $bot->public_id)->assertOk();
        self::assertSame(0, LineWebhookEvent::query()->count());
        $this->postWebhook($event)->assertOk();
        self::assertSame(1, LineWebhookEvent::query()->count());
        DB::table('line_bots')->where('id', $bot->id)->update(['is_active' => true]);
        $this->postWebhook($event, $bot->public_id)->assertOk();
        $this->postWebhook($event, $bot->public_id)->assertOk();
        $this->postWebhook($this->followEvent('old-event', 'ignored', 'ignored'))->assertOk();
        self::assertSame(2, LineWebhookEvent::query()->count());
        self::assertSame(2, LinePendingFollow::query()->count());
        self::assertSame(2, LinePendingFollow::query()->distinct('line_bot_id')->count('line_bot_id'));
    }

    public function test_bot_switch_never_grants_member_reward_twice_or_copies_friend_state(): void
    {
        LineMessagingSetting::query()->whereKey(1)->update(['reward_enabled' => true, 'reward_point_amount' => 100]);
        $user = $this->user('synthetic-bot-reward@example.test');
        $this->lineAccount($user, 'synthetic-member');
        $this->postWebhook($this->followEvent('legacy-follow', 'synthetic-member', 'legacy-reply'))->assertOk();
        $legacyId = app(V2LineConfiguration::class)->botId();
        $bot = $this->storedBot();
        DB::table('line_bots')->where('id', $bot->id)->update(['is_active' => true]);
        self::assertFalse(LineFriendship::query()->where('line_bot_id', $bot->id)->exists());
        app(V2LineFriendService::class)->applyObservation('synthetic-member', $user, true, \Carbon\CarbonImmutable::now(), (string) Str::uuid7(), $legacyId);
        self::assertFalse(LineFriendship::query()->where('line_bot_id', $bot->id)->exists());
        $this->postWebhook($this->followEvent('new-follow', 'synthetic-member', 'new-reply'), $bot->public_id)->assertOk();
        self::assertSame(2, LineFriendship::query()->where('user_id', $user->id)->count());
        self::assertSame(1, PointOperation::query()->where('source_type', 'line_friend')->count());
        self::assertSame(100, Wallet::query()->where('user_id', $user->id)->sole()->free_balance);
    }

    public function test_switch_rejected_while_sending_and_database_enforces_one_active(): void
    {
        $owner = $this->adminSession(V2AdminRole::Owner);
        $one = $this->storedBot();
        $two = $this->storedBot('7654321');
        DB::table('line_bots')->where('id', $one->id)->update(['is_active' => true]);
        try {
            DB::transaction(fn () => DB::table('line_bots')->where('id', $two->id)->update(['is_active' => true]));
            self::fail('Second active BOT was accepted');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame('23505', $e->errorInfo[0]);
        }
        DB::statement('CREATE TABLE line_deliveries (status text NOT NULL)');
        DB::table('line_deliveries')->insert(['status' => 'sending']);
        $this->action($owner, '/admin/api/v2/line-bots/'.$two->public_id.'/activation', 1)
            ->assertConflict()->assertJsonPath('code', 'LINE_DELIVERY_SENDING');
        self::assertTrue(DB::table('line_bots')->where('id', $one->id)->value('is_active'));
    }

    public function test_observation_rejects_same_bot_with_changed_login_credentials(): void
    {
        $user = $this->user('synthetic-stale-observation@example.test');
        $this->lineAccount($user, 'synthetic-stale-observation');
        $bot = app(V2LineConfiguration::class)->currentBot();
        $loginRevision = app(V2LineConfiguration::class)->loginRevision();
        DB::table('line_login_settings')->where('id', 1)->update(['revision' => $loginRevision + 1]);
        app(V2LineFriendService::class)->applyObservation('synthetic-stale-observation', $user, true,
            \Carbon\CarbonImmutable::now(), (string) Str::uuid7(), (int) $bot->id, $loginRevision, (int) $bot->revision);
        self::assertFalse(LineFriendship::query()->where('user_id', $user->id)->exists());
        self::assertFalse(DB::table('line_friend_observations')->whereIn('external_identity_account_id',
            ExternalIdentityAccount::query()->where('user_id', $user->id)->select('id'))->exists());
    }

    public function test_webhook_url_uses_only_a_valid_configured_https_public_origin(): void
    {
        $owner = $this->adminSession(V2AdminRole::Owner);
        $cases = [
            ['https://public.example.test', 'https://public.example.test'],
            ['https://public.example.test:8443/', 'https://public.example.test:8443'],
            [null, null], ['', null], ['http://public.example.test', null], ['//public.example.test', null],
            ['https://user:pass@public.example.test', null], ['https://public.example.test/path', null],
            ['https://public.example.test?query', null], ['https://public.example.test#fragment', null],
            ["https://public.example.test\n", null], ['https://public.example.test\\evil', null],
        ];
        config(['app.url' => 'https://wrong-app.example.test']);
        foreach ($cases as [$origin, $expected]) {
            config(['v2_identity.origins.user' => $origin]);
            Auth::forgetGuards();
            $bot = $this->asAdmin($owner)->withHeaders([
                'Host' => 'untrusted.example.test', 'X-Forwarded-Host' => 'injected.example.test', 'X-Forwarded-Proto' => 'http',
            ])->getJson('/admin/api/v2/line-bots')->assertOk()->json('items.0');
            self::assertSame($expected === null ? null : $expected.$bot['webhook_path'], $bot['webhook_url']);
        }
        Http::assertNothingSent();
    }

    public function test_observation_accepts_metadata_revision_but_rejects_replaced_bot_credentials(): void
    {
        $owner = $this->adminSession(V2AdminRole::Owner);
        $bot = $this->storedBot();
        DB::table('line_bots')->where('id', $bot->id)->update(['is_active' => true]);
        $loginRevision = app(V2LineConfiguration::class)->loginRevision();
        $user = $this->user('observation-revision@example.test');
        $this->lineAccount($user, 'observation-subject');
        $path = '/admin/api/v2/line-bots/'.$bot->public_id;
        $this->action($owner, $path.'/quota', 1)->assertOk();
        app(V2LineFriendService::class)->applyObservation('observation-subject', $user, true,
            \Carbon\CarbonImmutable::now(), (string) Str::uuid7(), (int) $bot->id, $loginRevision, (int) $bot->authentication_revision);
        self::assertSame('friend', LineFriendship::query()->where('user_id', $user->id)->sole()->status);
        Auth::forgetGuards();
        $this->adminMutation($owner, 'PUT', $path, ['name' => $bot->name, 'channel_id' => $bot->channel_id,
            'channel_secret' => 'synthetic-replacement', 'expected_revision' => 2])->assertOk();
        app(V2LineFriendService::class)->applyObservation('observation-subject', $user, false,
            \Carbon\CarbonImmutable::now()->addSecond(), (string) Str::uuid7(), (int) $bot->id, $loginRevision, (int) $bot->authentication_revision);
        self::assertSame('friend', LineFriendship::query()->where('user_id', $user->id)->sole()->status);
    }

    private function botInput(): array
    {
        return ['name' => 'Synthetic BOT', 'channel_id' => '1234567', 'channel_secret' => 'synthetic-messaging-secret'];
    }

    private function storedBot(string $channel = '1234567'): object
    {
        $id = DB::table('line_bots')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'name' => 'Synthetic BOT', 'channel_id' => $channel,
            'channel_secret_ciphertext' => Crypt::encryptString('synthetic-messaging-secret'),
            'connection_status' => 'succeeded', 'connected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('line_bots')->where('id', $id)->first();
    }

    private function action(string $owner, string $path, int $revision)
    {
        Auth::forgetGuards();
        return $this->adminMutation($owner, 'POST', $path, ['expected_revision' => $revision]);
    }

    /** @return array<string, mixed> */
    private function followEvent(string $eventId, string $subject, string $replyToken): array
    {
        return [
            'type' => 'follow',
            'timestamp' => now()->getTimestampMs(),
            'source' => ['type' => 'user', 'userId' => $subject],
            'replyToken' => $replyToken,
            'webhookEventId' => $eventId,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    private function postWebhook(array $event, ?string $botId = null)
    {
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac(
            'sha256',
            $body,
            'synthetic-messaging-secret',
            true
        ));

        return $this->call(
                'POST',
                '/webhooks/v2/line'.($botId === null ? '' : '/'.$botId),
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_LINE_SIGNATURE' => $signature,
                ],
                $body
            );
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid line messaging test password'),
            'password_login_enabled' => true,
            'state' => V2UserState::Active,
        ]);
    }

    private function lineAccount(User $user, string $subject): void
    {
        ExternalIdentityAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'line',
            'issuer' => 'https://access.line.me',
            'subject_hash' => app(V2IdentityCorrelation::class)
                ->hash('line|https://access.line.me|'.$subject),
            'linked_at' => now(),
            'last_authenticated_at' => now(),
        ]);
    }

    private function adminSession(
        V2AdminRole $role,
        ?\DateTimeInterface $mfaVerifiedAt = null
    ): string {
        $email = $role->value.'-'.Str::uuid7().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid line messaging admin password'),
            'role' => $role->value,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => $mfaVerifiedAt ?? now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $created->copy()->addHours(8),
        ]));

        return $token;
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function adminMutation(
        string $token,
        string $method,
        string $uri,
        array $payload,
        ?string $key = null
    ) {
        $csrf = str_repeat('a', 64);
        $request = $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => $key ?? (string) Str::uuid7(),
            ]);

        return $method === 'PUT'
            ? $request->putJson($uri, $payload)
            : $request->postJson($uri, $payload);
    }
}
