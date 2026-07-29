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
use Tests\TestCase;

final class LineMessagingVerticalSliceTest extends TestCase
{
    private FakeLineMessagingTransport $messaging;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.origins.admin' => 'https://admin.example.test',
            'v2_identity.sms_verification.phone_hmac_key' =>
                'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_line.messaging.channel_secret' => 'synthetic-messaging-secret',
            'v2_line.messaging.channel_access_token' => 'test-token',
            'v2_line.messaging.login_relative_path' => '/login',
        ]);
        Cache::store('array')->clear();
        $this->messaging = new FakeLineMessagingTransport();
        $this->app->instance(V2LineMessagingTransport::class, $this->messaging);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_owner_updates_and_previews_messages_with_fresh_mfa_occ_and_replay(): void
    {
        $owner = $this->adminSession(V2AdminRole::Owner);
        $read = $this->asAdmin($owner)
            ->getJson('/admin/api/v2/identity/line-messaging')
            ->assertOk()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.reward_enabled', false)
            ->assertJsonPath('data.reward_point_amount', 0)
            ->assertJsonPath('data.reward_expiration_days', 180)
            ->assertJsonPath('data.login_relative_path', '/login');
        self::assertTrue(Str::isUuid($read->json('request_id')));

        $payload = [
            'expected_revision' => 1,
            'linked_follow_message' => '友だち追加が完了しました。',
            'pending_follow_message' => '{login_url} からログインしてください。',
            'reward_enabled' => true,
            'reward_point_amount' => 500,
            'reward_expiration_days' => 365,
        ];
        $preview = $this->adminMutation(
            $owner,
            'POST',
            '/admin/api/v2/identity/line-messaging/preview',
            array_diff_key($payload, ['expected_revision' => true])
        )->assertOk();
        self::assertSame(
            '/login からログインしてください。',
            $preview->json('pending_follow_message')
        );
        self::assertTrue($preview->json('reward_enabled'));
        self::assertSame(500, $preview->json('reward_point_amount'));
        self::assertSame(365, $preview->json('reward_expiration_days'));

        $key = 'line-message-setting-canonical';
        $updated = $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            $payload,
            $key
        )->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('idempotent_replay', false);
        Auth::forgetGuards();
        $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            $payload,
            $key
        )->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('idempotent_replay', true);
        self::assertSame($updated->json('data'), LineMessagingSetting::query()
            ->whereKey(1)->get()->map(fn ($setting) => [
                'id' => $setting->public_id,
                'linked_follow_message' => $setting->linked_follow_message,
                'pending_follow_message' => $setting->pending_follow_message,
                'login_relative_path' => $setting->login_relative_path,
                'reward_enabled' => (bool) $setting->reward_enabled,
                'reward_point_amount' => (int) $setting->reward_point_amount,
                'reward_expiration_days' => (int) $setting->reward_expiration_days,
                'revision' => (int) $setting->revision,
                'updated_at' => $setting->updated_at->toIso8601String(),
            ])->sole());

        Auth::forgetGuards();
        $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            [...$payload, 'linked_follow_message' => '別Message'],
            $key
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_message_settings_are_owner_only_fresh_and_reject_html_unknown_placeholder(): void
    {
        foreach ([V2AdminRole::Admin, V2AdminRole::Operator] as $role) {
            Auth::forgetGuards();
            $this->asAdmin($this->adminSession($role))
                ->getJson('/admin/api/v2/identity/line-messaging')
                ->assertForbidden()
                ->assertJsonPath('code', 'AUTHORIZATION_DENIED');
        }
        $stale = $this->adminSession(V2AdminRole::Owner, now()->subMinutes(5));
        $this->adminMutation(
            $stale,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            [
                'expected_revision' => 1,
                'linked_follow_message' => '完了',
                'pending_follow_message' => '{login_url}',
                'reward_enabled' => false,
                'reward_point_amount' => 0,
                'reward_expiration_days' => 180,
            ]
        )->assertForbidden()->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');

        $owner = $this->adminSession(V2AdminRole::Owner);
        foreach ([
            '<script>alert(1)</script>',
            '{unknown}',
            '{}',
            '{unknown',
        ] as $invalid) {
            Auth::forgetGuards();
            $this->adminMutation(
                $owner,
                'POST',
                '/admin/api/v2/identity/line-messaging/preview',
                [
                    'linked_follow_message' => $invalid,
                    'pending_follow_message' => '{login_url}',
                    'reward_enabled' => false,
                    'reward_point_amount' => 0,
                    'reward_expiration_days' => 180,
                ]
            )->assertUnprocessable()->assertJsonPath(
                'code',
                'LINE_MESSAGING_SETTING_INVALID'
            );
        }
    }

    public function test_legacy_message_contract_preserves_current_reward_setting(): void
    {
        LineMessagingSetting::query()->whereKey(1)->update([
            'reward_enabled' => true,
            'reward_point_amount' => 250,
            'reward_expiration_days' => 90,
        ]);
        $owner = $this->adminSession(V2AdminRole::Owner);
        $messages = [
            'linked_follow_message' => '友だち追加が完了しました。',
            'pending_follow_message' => '{login_url} からログインしてください。',
        ];

        $this->adminMutation(
            $owner,
            'POST',
            '/admin/api/v2/identity/line-messaging/preview',
            $messages
        )->assertOk()
            ->assertJsonPath('reward_enabled', true)
            ->assertJsonPath('reward_point_amount', 250)
            ->assertJsonPath('reward_expiration_days', 90);

        Auth::forgetGuards();
        $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            ['expected_revision' => 1, ...$messages],
            'line-message-setting-legacy-contract'
        )->assertOk()
            ->assertJsonPath('data.reward_enabled', true)
            ->assertJsonPath('data.reward_point_amount', 250)
            ->assertJsonPath('data.reward_expiration_days', 90);
    }

    public function test_reward_settings_enforce_enablement_amount_expiration_occ_and_idempotency(): void
    {
        $owner = $this->adminSession(V2AdminRole::Owner);
        $base = [
            'expected_revision' => 1,
            'linked_follow_message' => '友だち追加が完了しました。',
            'pending_follow_message' => '{login_url} からログインしてください。',
        ];
        foreach ([
            ['reward_enabled' => true, 'reward_point_amount' => 1],
            ['reward_enabled' => true, 'reward_point_amount' => 0, 'reward_expiration_days' => 180],
            ['reward_enabled' => false, 'reward_point_amount' => 1, 'reward_expiration_days' => 180],
            ['reward_enabled' => true, 'reward_point_amount' => -1, 'reward_expiration_days' => 180],
            ['reward_enabled' => true, 'reward_point_amount' => 1_000_001, 'reward_expiration_days' => 180],
            ['reward_enabled' => true, 'reward_point_amount' => 1, 'reward_expiration_days' => 0],
            ['reward_enabled' => true, 'reward_point_amount' => 1, 'reward_expiration_days' => 3651],
        ] as $invalid) {
            Auth::forgetGuards();
            $this->adminMutation(
                $owner,
                'PUT',
                '/admin/api/v2/identity/line-messaging',
                [...$base, ...$invalid],
                'invalid-reward-'.hash('sha256', json_encode($invalid, JSON_THROW_ON_ERROR))
            )->assertUnprocessable()
                ->assertJsonPath('code', 'LINE_MESSAGING_SETTING_INVALID');
        }
        Cache::store('array')->clear();

        $minimum = [
            ...$base,
            'reward_enabled' => true,
            'reward_point_amount' => 1,
            'reward_expiration_days' => 1,
        ];
        $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            $minimum,
            'reward-minimum'
        )->assertOk()
            ->assertJsonPath('data.reward_enabled', true)
            ->assertJsonPath('data.reward_point_amount', 1)
            ->assertJsonPath('data.reward_expiration_days', 1)
            ->assertJsonPath('data.revision', 2);

        Auth::forgetGuards();
        $maximum = [
            ...$base,
            'expected_revision' => 2,
            'reward_enabled' => true,
            'reward_point_amount' => 1_000_000,
            'reward_expiration_days' => 3650,
        ];
        $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            $maximum,
            'reward-maximum'
        )->assertOk()
            ->assertJsonPath('data.reward_point_amount', 1_000_000)
            ->assertJsonPath('data.reward_expiration_days', 3650)
            ->assertJsonPath('data.revision', 3);

        Auth::forgetGuards();
        $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            $maximum,
            'reward-maximum'
        )->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('data.revision', 3);

        Auth::forgetGuards();
        $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            [...$maximum, 'reward_point_amount' => 999_999],
            'reward-maximum'
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        Auth::forgetGuards();
        $this->adminMutation(
            $owner,
            'PUT',
            '/admin/api/v2/identity/line-messaging',
            [...$maximum, 'expected_revision' => 2],
            'reward-stale'
        )->assertConflict()
            ->assertJsonPath('code', 'LINE_MESSAGING_REVISION_CONFLICT');
    }

    public function test_disabled_reward_records_friendship_without_point_records(): void
    {
        $user = $this->user('line-disabled-reward@example.test');
        $this->lineAccount($user, 'disabled-reward-subject');

        $this->postWebhook($this->followEvent(
            'event-disabled-reward',
            'disabled-reward-subject',
            'reply-disabled-reward'
        ))->assertOk();

        $friendship = LineFriendship::query()
            ->where('user_id', $user->getKey())
            ->sole();
        self::assertNull($friendship->point_operation_id);
        self::assertNull($friendship->rewarded_at);
        self::assertSame(0, PointOperation::query()
            ->where('source_type', 'line_friend')->count());
        self::assertSame(0, PointLot::query()->count());
        self::assertSame(0, PointLedgerEntry::query()->count());
        self::assertFalse(Wallet::query()
            ->where('user_id', $user->getKey())
            ->exists());
        self::assertTrue(DB::table('audit_logs')
            ->where('action_code', 'line.friend.reward.skipped')
            ->where('reason_code', 'reward_disabled')
            ->exists());
    }

    public function test_signed_follow_replies_for_linked_and_pending_users_without_duplication(): void
    {
        LineMessagingSetting::query()->whereKey(1)->update([
            'reward_enabled' => true,
            'reward_point_amount' => 100,
            'reward_expiration_days' => 30,
        ]);
        $user = $this->user('line-friend@example.test');
        $this->lineAccount($user, 'linked-subject');

        $this->postWebhook($this->followEvent('event-linked', 'linked-subject', 'reply-linked'))
            ->assertOk();
        self::assertSame([['reply-linked', '友だち追加が完了しました。']], $this->messaging->sent);
        self::assertSame(100, Wallet::query()->where('user_id', $user->getKey())->sole()->free_balance);
        self::assertSame(1, PointLedgerEntry::query()->count());
        $lot = PointLot::query()->sole();
        self::assertEquals(
            30,
            $lot->granted_at->startOfDay()->diffInDays($lot->expire_at->startOfDay())
        );
        self::assertSame('sent', LineWebhookEvent::query()->sole()->reply_status);

        $this->postWebhook($this->followEvent('event-linked', 'linked-subject', 'reply-linked'))
            ->assertOk();
        self::assertCount(1, $this->messaging->sent);
        self::assertSame(1, PointLedgerEntry::query()->count());

        LineMessagingSetting::query()->whereKey(1)->update([
            'reward_point_amount' => 500,
        ]);
        $this->postWebhook([
            'type' => 'unfollow',
            'timestamp' => now()->addSecond()->getTimestampMs(),
            'source' => ['type' => 'user', 'userId' => 'linked-subject'],
            'webhookEventId' => 'event-linked-unfollow',
            'deliveryContext' => ['isRedelivery' => false],
        ])->assertOk();
        $this->postWebhook($this->followEvent(
            'event-linked-refollow',
            'linked-subject',
            'reply-linked-refollow'
        ))->assertOk();
        self::assertSame(100, Wallet::query()
            ->where('user_id', $user->getKey())->sole()->free_balance);
        self::assertSame(1, PointLedgerEntry::query()->count());

        $this->postWebhook($this->followEvent('event-pending', 'pending-subject', 'reply-pending'))
            ->assertOk();
        self::assertSame(
            ['reply-pending', '/login からLINEログインを完了してください。'],
            $this->messaging->sent[2]
        );
        self::assertSame('pending', LinePendingFollow::query()->sole()->status);
    }

    public function test_invalid_signature_and_reward_failure_never_send_completion_reply(): void
    {
        $payload = ['events' => [$this->followEvent(
            'event-invalid-signature',
            'invalid-signature-subject',
            'invalid-signature-reply'
        )]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->call(
                'POST',
                '/webhooks/v2/line',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_LINE_SIGNATURE' => 'invalid',
                ],
                $body
            )->assertUnauthorized();
        self::assertCount(0, $this->messaging->sent);
        self::assertSame(0, LineWebhookEvent::query()->count());

        LineMessagingSetting::query()->whereKey(1)->update([
            'reward_enabled' => true,
            'reward_point_amount' => 100,
        ]);
        $user = $this->user('line-point-failure@example.test');
        $this->lineAccount($user, 'point-failure-subject');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION v2_test_reject_line_point()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.source_type = 'line_friend' THEN
                    RAISE EXCEPTION 'synthetic point failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER v2_test_reject_line_point
            BEFORE INSERT ON point_operations
            FOR EACH ROW EXECUTE FUNCTION v2_test_reject_line_point();
        SQL);
        $this->postWebhook($this->followEvent(
            'event-point-failure',
            'point-failure-subject',
            'reply-point-failure'
        ))->assertServerError();
        self::assertCount(0, $this->messaging->sent);
        self::assertSame(0, LineFriendship::query()
            ->where('user_id', $user->getKey())->count());
        self::assertSame(0, LineWebhookEvent::query()
            ->where('event_id_hash', app(V2IdentityCorrelation::class)
                ->hash('line-webhook-event|event-point-failure'))->count());
    }

    public function test_missing_message_fails_closed(): void
    {
        LineMessagingSetting::query()->whereKey(1)->delete();
        $this->postWebhook($this->followEvent(
            'event-missing-setting',
            'missing-setting-subject',
            'reply-missing-setting'
        ))->assertServiceUnavailable()
            ->assertJsonPath('code', 'LINE_MESSAGING_UNAVAILABLE');
        self::assertCount(0, $this->messaging->sent);
        self::assertSame(0, LineWebhookEvent::query()->count());
    }

    public function test_reply_failure_keeps_committed_follow(): void
    {
        $this->messaging->result = new V2LineReplyResult(
            false,
            'provider_unavailable'
        );
        $this->postWebhook($this->followEvent(
            'event-reply-failure',
            'reply-failure-subject',
            'reply-failure-token'
        ))->assertOk();
        self::assertSame('failed', LineWebhookEvent::query()->sole()->reply_status);
        self::assertSame('provider_unavailable', LineWebhookEvent::query()
            ->sole()->reply_failure_code);
        self::assertSame('pending', LinePendingFollow::query()->sole()->status);
    }

    public function test_unfollow_updates_friend_state_without_reply_or_sensitive_audit_data(): void
    {
        $subject = 'unfollow-sensitive-subject';
        $replyToken = 'unfollow-sensitive-reply-token';
        $user = $this->user('line-unfollow@example.test');
        $this->lineAccount($user, $subject);
        $this->postWebhook($this->followEvent(
            'event-before-unfollow',
            $subject,
            $replyToken
        ))->assertOk();

        $unfollow = [
            'type' => 'unfollow',
            'timestamp' => now()->addSecond()->getTimestampMs(),
            'source' => ['type' => 'user', 'userId' => $subject],
            'webhookEventId' => 'event-unfollow',
            'deliveryContext' => ['isRedelivery' => false],
        ];
        $this->postWebhook($unfollow)->assertOk();

        self::assertSame('unfollowed', LineFriendship::query()
            ->where('user_id', $user->getKey())
            ->sole()
            ->status);
        self::assertCount(1, $this->messaging->sent);
        self::assertSame(2, LineWebhookEvent::query()->count());
        self::assertSame('skipped', LineWebhookEvent::query()
            ->where('event_type', 'unfollow')
            ->sole()
            ->reply_status);
        $audit = json_encode(
            DB::table('audit_logs')->get()->all(),
            JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString($subject, $audit);
        self::assertStringNotContainsString($replyToken, $audit);
        self::assertStringNotContainsString('test-token', $audit);
    }

    public function test_reply_http_transport_uses_bearer_only_and_never_calls_push_or_broadcast(): void
    {
        $providerResponse = 200;
        Http::fake(function () use (&$providerResponse) {
            if ($providerResponse === 'connection_failure') {
                return Http::failedConnection();
            }

            return Http::response([], $providerResponse);
        });
        $result = app(V2LineMessagingHttpTransport::class)
            ->replyText('synthetic-reply-token', '安全な本文');
        self::assertTrue($result->succeeded);
        Http::assertSent(function ($request): bool {
            self::assertSame(
                'Bearer test-token',
                $request->header('Authorization')[0] ?? null
            );
            self::assertSame('synthetic-reply-token', $request['replyToken']);

            return $request->url() === 'https://api.line.me/v2/bot/message/reply';
        });
        Http::assertNotSent(fn ($request): bool =>
            str_contains($request->url(), '/push')
            || str_contains($request->url(), '/broadcast')
        );

        config(['v2_line.messaging.channel_access_token' => null]);
        $missing = app(V2LineMessagingHttpTransport::class)
            ->replyText('unused-reply-token', '安全な本文');
        self::assertFalse($missing->succeeded);
        self::assertSame('configuration_unavailable', $missing->failureCode);

        config(['v2_line.messaging.channel_access_token' => 'test-token']);
        foreach (
            [
                429 => 'rate_limited',
                500 => 'provider_unavailable',
            ] as $status => $failure
        ) {
            $providerResponse = $status;
            $result = app(V2LineMessagingHttpTransport::class)
                ->replyText('synthetic-reply-token', '安全な本文');
            self::assertFalse($result->succeeded);
            self::assertSame($failure, $result->failureCode);
        }
        $providerResponse = 'connection_failure';
        $timeout = app(V2LineMessagingHttpTransport::class)
            ->replyText('synthetic-reply-token', '安全な本文');
        self::assertFalse($timeout->succeeded);
        self::assertSame('timeout', $timeout->failureCode);
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

    private function postWebhook(array $event)
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
                '/webhooks/v2/line',
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
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => $mfaVerifiedAt ?? now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $created->copy()->addHours(8),
        ]);

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

final class FakeLineMessagingTransport implements V2LineMessagingTransport
{
    /** @var list<array{string, string}> */
    public array $sent = [];
    public V2LineReplyResult $result;

    public function __construct()
    {
        $this->result = new V2LineReplyResult(true);
    }

    public function replyText(
        #[SensitiveParameter] string $replyToken,
        string $message
    ): V2LineReplyResult {
        $this->sent[] = [$replyToken, $message];

        return $this->result;
    }
}
