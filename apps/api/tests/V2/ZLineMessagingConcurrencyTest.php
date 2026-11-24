<?php

namespace Tests\V2;

use App\Domain\Identity\Contracts\V2LineOidcTransport;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2ExternalIdentityService;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Line\Contracts\V2LineMessagingTransport;
use App\Domain\Line\Services\V2LineFriendService;
use App\Domain\Line\Services\V2LineFriendStateService;
use App\Domain\Line\ValueObjects\V2LineReplyResult;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\LineFriendship;
use App\Models\V2\LineMessagingSetting;
use App\Models\V2\LinePendingFollow;
use App\Models\V2\LineWebhookEvent;
use App\Models\V2\PointLedgerEntry;
use App\Models\V2\User;
use App\Models\V2\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use SensitiveParameter;
use Tests\Support\FakeLineOidcTransport;
use Tests\TestCase;

final class ZLineMessagingConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for LINE webhook concurrency verification.');
        }
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'cache.default' => 'array',
            'v2_identity.sms_verification.phone_hmac_key' =>
                'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        Cache::store('array')->clear();
    }

    public function test_concurrent_redelivery_rewards_and_replies_once(): void
    {
        $runId = bin2hex(random_bytes(6));
        $subject = 'line-follow-concurrent-'.$runId;
        $user = User::query()->create([
            'email_display' => "line-follow-{$runId}@example.test",
            'email_normalized' => "line-follow-{$runId}@example.test",
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid concurrent line follow password'),
            'password_login_enabled' => true,
            'state' => V2UserState::Active,
        ]);
        ExternalIdentityAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'line',
            'issuer' => 'https://access.line.me',
            'subject_hash' => app(V2IdentityCorrelation::class)
                ->hash('line|https://access.line.me|'.$subject),
            'linked_at' => now(),
            'last_authenticated_at' => now(),
        ]);
        LineMessagingSetting::query()->whereKey(1)->update([
            'reward_enabled' => true,
            'reward_point_amount' => 100,
        ]);

        $replyEvidence = sys_get_temp_dir().'/mig058b-line-reply-'.$runId;
        touch($replyEvidence);
        chmod($replyEvidence, 0600);
        $this->app->instance(
            V2LineMessagingTransport::class,
            new ConcurrentLineReplyTransport($replyEvidence)
        );
        $service = app(V2LineFriendService::class);
        $event = [
            'type' => 'follow',
            'timestamp' => now()->getTimestampMs(),
            'source' => ['type' => 'user', 'userId' => $subject],
            'replyToken' => 'synthetic-concurrent-reply-token',
            'webhookEventId' => 'line-concurrent-event-'.$runId,
            'deliveryContext' => ['isRedelivery' => true],
        ];

        $statuses = $this->concurrent(static function () use ($service, $event): string {
            try {
                $service->handleEvents([$event], (string) Str::uuid7());

                return 'succeeded';
            } catch (\Throwable $exception) {
                return $exception::class;
            }
        }, $runId);

        self::assertSame(['succeeded', 'succeeded'], $statuses);
        self::assertSame(1, LineWebhookEvent::query()
            ->where('event_type', 'follow')
            ->where('subject_hash', app(V2IdentityCorrelation::class)
                ->hash('line|https://access.line.me|'.$subject))
            ->count());
        self::assertSame('sent', LineWebhookEvent::query()
            ->where('subject_hash', app(V2IdentityCorrelation::class)
                ->hash('line|https://access.line.me|'.$subject))
            ->sole()
            ->reply_status);
        self::assertSame(1, LineFriendship::query()
            ->where('user_id', $user->getKey())
            ->count());
        self::assertSame(1, PointLedgerEntry::query()
            ->where('user_id', $user->getKey())
            ->count());
        self::assertSame(1, count(file($replyEvidence, FILE_IGNORE_NEW_LINES)));
        unlink($replyEvidence);
    }

    public function test_api_and_webhook_with_previous_hmac_key_grant_once(): void
    {
        $run = bin2hex(random_bytes(6));
        $subject = 'synthetic-api-webhook-'.$run;
        $user = User::query()->create(['email_display' => $run.'@example.test', 'email_normalized' => $run.'@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('valid fixture password'), 'state' => V2UserState::Active]);
        $hash = app(V2IdentityCorrelation::class)->hash('line|https://access.line.me|'.$subject);
        ExternalIdentityAccount::query()->create(['user_id' => $user->id, 'provider' => 'line', 'issuer' => 'https://access.line.me',
            'subject_hash' => $hash, 'linked_at' => now(), 'last_authenticated_at' => now()]);
        config(['v2_identity.sms_verification.phone_hmac_previous_keys' => ['base64:'.base64_encode(str_repeat('p', 32))],
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('q', 32))]);
        LineMessagingSetting::query()->whereKey(1)->update(['reward_enabled' => true, 'reward_point_amount' => 500]);
        $evidence = sys_get_temp_dir().'/line-api-webhook-'.$run;
        touch($evidence);
        $this->app->instance(V2LineMessagingTransport::class, new ConcurrentLineReplyTransport($evidence));
        $service = app(V2LineFriendService::class);
        $at = \Carbon\CarbonImmutable::now()->startOfSecond();
        config(['v2_line.conversion_started_at' => $at->utc()->format('Y-m-d\TH:i:s\Z')]);
        $event = ['type' => 'follow', 'timestamp' => $at->getTimestampMs(), 'source' => ['type' => 'user', 'userId' => $subject],
            'replyToken' => 'synthetic-reply', 'webhookEventId' => 'synthetic-concurrent-'.$run];
        $results = $this->concurrent(static function (int $worker) use ($service, $subject, $user, $at, $event): string {
            try {
                if ($worker === 0) return $service->applyObservation($subject, $user, true, $at, (string) Str::uuid7()) ?? 'succeeded';
                $service->handleEvents([$event], (string) Str::uuid7());
                return 'succeeded';
            } catch (\Throwable $exception) { return $exception::class; }
        }, 'api-webhook-'.$run);
        self::assertSame(['succeeded', 'succeeded'], $results);
        self::assertSame(1, LineFriendship::query()->where('user_id', $user->id)->count());
        self::assertSame($hash, LineFriendship::query()->where('user_id', $user->id)->value('subject_hash'));
        self::assertSame(1, PointLedgerEntry::query()->where('user_id', $user->id)->count());
        self::assertNotNull(LineFriendship::query()->where('user_id', $user->id)->value('point_operation_id'));
        self::assertFalse(DB::table('user_conversion_events')->where('user_id', $user->id)->sole()->suppressed);
        unlink($evidence);
    }

    #[DataProvider('callbackFollowOrders')]
    public function test_first_line_callback_and_signed_follow_serialize_and_record_conversion_once(bool $followFirst): void
    {
        $run = bin2hex(random_bytes(6));
        $subject = 'synthetic-callback-follow-'.$run;
        $callbackUrl = 'https://storefront.example.test/api/v2/auth/external/line/callback';
        $start = CarbonImmutable::now('UTC')->subSeconds(10)->startOfSecond();
        $fixtureSecret = 'synthetic-messaging-secret';
        config([
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_identity.external_identity.line.client_id' => 'line-channel.test',
            'v2_identity.external_identity.line.client_secret' => 'synthetic-line-secret',
            'v2_identity.external_identity.line.redirect_uri' => $callbackUrl,
            'v2_line.messaging.channel_secret' => $fixtureSecret,
            'v2_line.friendship_check_enabled' => false,
            'v2_line.conversion_started_at' => $start->format('Y-m-d\TH:i:s\Z'),
        ]);
        Http::preventStrayRequests();
        $provider = new FakeLineOidcTransport();
        $this->app->instance(V2LineOidcTransport::class, $provider);
        $settings = LineMessagingSetting::query()->findOrFail(1);
        $previousReward = $settings->only(['reward_enabled', 'reward_point_amount']);
        $settings->forceFill(['reward_enabled' => true, 'reward_point_amount' => 125])->save();
        $replyEvidence = tempnam(sys_get_temp_dir(), 'line-cv-reply-');
        self::assertIsString($replyEvidence);
        $this->app->instance(V2LineMessagingTransport::class, new ConcurrentLineReplyTransport($replyEvidence));

        try {
            $user = User::query()->create([
                'email_display' => 'line-callback-'.$run.'@example.test',
                'email_normalized' => 'line-callback-'.$run.'@example.test',
                'email_verified_at' => now(),
                'password_hash' => app(V2PasswordPolicy::class)->hash('valid callback fixture password'),
                'password_login_enabled' => true,
                'state' => V2UserState::Active,
            ]);
            $hash = app(V2IdentityCorrelation::class)->hash('line|https://access.line.me|'.$subject);
            $session = app(V2SessionManager::class)->issue(V2Realm::User, (int) $user->id);
            $request = Request::create('/api/v2/me/external-identities/line', 'POST');
            $request->cookies->set('__Host-oripa_user_session', $session['token']);
            $link = app(V2ExternalIdentityService::class)->startForProvider(
                'line', 'link', '/mypage/line', '192.0.2.70', (string) Str::uuid7(), $user, $request
            );
            parse_str(parse_url($link['authorization_url'], PHP_URL_QUERY), $parameters);
            $provider->expectChallenge($parameters['code_challenge']);
            $provider->claims($parameters['nonce'], $subject);
            $state = app(V2LineFriendStateService::class);
            self::assertFalse($state->forUser($user->id)['is_line_user']);
            self::assertFalse(LineFriendship::query()->where('user_id', $user->id)->exists());
            $events = DB::table('user_conversion_events')->where('user_id', $user->id);
            self::assertFalse($events->exists());

            $callback = function () use ($callbackUrl, $parameters, $session, $link, $user): void {
                $url = $callbackUrl.'?'.http_build_query(['state' => $parameters['state'], 'code' => 'synthetic-code']);
                $this->withCredentials()->withUnencryptedCookies([
                    '__Host-oripa_user_session' => $session['token'],
                    '__Host-oripa_oidc_transaction' => $link['binding_token'],
                ])->getJson($url)
                    ->assertOk()->assertJsonPath('purpose', 'link')->assertJsonPath('user.id', $user->public_id);
            };
            $webhook = function () use ($followFirst, $start, $subject, $run, $fixtureSecret): void {
                $eventAt = $followFirst ? $start->subSecond() : CarbonImmutable::now('UTC');
                $body = json_encode(['events' => [[
                    'type' => 'follow',
                    'timestamp' => $eventAt->getTimestampMs(),
                    'source' => ['type' => 'user', 'userId' => $subject],
                    'replyToken' => 'synthetic-reply',
                    'webhookEventId' => 'callback-follow-'.$run,
                ]]], JSON_THROW_ON_ERROR);
                $this->call('POST', '/webhooks/v2/line', [], [], [], [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_LINE_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, $fixtureSecret, true)),
                ], $body)->assertOk()->assertJsonPath('accepted', true);
            };
            $first = $followFirst ? $webhook : $callback;
            $second = $followFirst ? $callback : $webhook;
            $firstWithAssertions = function () use ($first, $followFirst, $user, $hash, $state, $events): void {
                $first();
                $before = $state->forUser($user->id);
                self::assertSame(! $followFirst, $before['linked']);
                self::assertFalse($before['is_line_user']);
                self::assertFalse(LineFriendship::query()->where('user_id', $user->id)->exists());
                self::assertFalse($events->exists());
                self::assertSame($followFirst ? 'pending' : null,
                    LinePendingFollow::query()->where('subject_hash', $hash)->value('status'));
            };
            self::assertSame(['succeeded', 'succeeded'], $this->overlapCommit($firstWithAssertions, $second, $run));

            self::assertTrue($state->forUser($user->id)['is_line_user']);
            $identity = ExternalIdentityAccount::query()->where('user_id', $user->id)->sole();
            self::assertNull($identity->revoked_at);
            $friend = LineFriendship::query()->where('user_id', $user->id)->sole();
            self::assertSame('friend', $friend->status);
            self::assertNotNull($friend->point_operation_id);
            $event = $events->sole();
            self::assertSame('transition', $event->source);
            self::assertSame('line_friend_added', $event->event_name);
            self::assertFalse($event->suppressed);
            self::assertNull($event->suppressed_reason);
            $occurredAt = CarbonImmutable::parse($event->occurred_at);
            $follow = LineWebhookEvent::query()->where('subject_hash', $hash)->sole();
            if ($followFirst) {
                self::assertTrue($occurredAt->gte($start));
                self::assertTrue($occurredAt->gt($follow->occurred_at));
                self::assertTrue($occurredAt->gte($identity->linked_at));
                self::assertSame('claimed', LinePendingFollow::query()->where('subject_hash', $hash)->sole()->status);
            } else {
                self::assertSame((int) $follow->event_timestamp_ms, $occurredAt->getTimestampMs());
                self::assertFalse(LinePendingFollow::query()->where('subject_hash', $hash)->exists());
            }
            self::assertSame(125, Wallet::query()->where('user_id', $user->id)->sole()->free_balance);
            self::assertSame(1, PointLedgerEntry::query()->where('user_id', $user->id)->count());
            self::assertSame(1, count(file($replyEvidence, FILE_IGNORE_NEW_LINES)));
        } finally {
            LineMessagingSetting::query()->whereKey(1)->update($previousReward);
            unlink($replyEvidence);
        }
    }

    public static function callbackFollowOrders(): array
    {
        return ['callback first' => [false], 'follow first' => [true]];
    }

    /**
     * Keep the first request uncommitted until PostgreSQL reports that the
     * second request is blocked on it. A timing-only start is insufficient.
     *
     * @return list<string>
     */
    private function overlapCommit(callable $first, callable $second, string $scenario): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($sockets);
        try {
            return $this->concurrent(static function (int $worker) use ($sockets, $first, $second): string {
                $socket = $sockets[$worker];
                fclose($sockets[1 - $worker]);
                stream_set_timeout($socket, 15);
                try {
                    if ($worker === 1) {
                        fwrite($socket, DB::scalar('SELECT pg_backend_pid()')."\n");
                        self::assertSame("start\n", fgets($socket));
                        $second();
                    } else {
                        $peer = (int) fgets($socket);
                        self::assertGreaterThan(0, $peer);
                        DB::transaction(static function () use ($socket, $peer, $first): void {
                            $first();
                            fwrite($socket, "start\n");
                            $deadline = hrtime(true) + 10_000_000_000;
                            do {
                                if (DB::scalar('SELECT pg_backend_pid() = ANY(pg_blocking_pids(?))', [$peer])) {
                                    return;
                                }
                                usleep(10_000);
                            } while (hrtime(true) < $deadline);
                            self::fail('The second request did not wait for the first database transaction.');
                        });
                    }
                    return 'succeeded';
                } catch (\Throwable $exception) {
                    return $exception::class.': '.$exception->getMessage();
                }
            }, 'callback-follow-'.$scenario);
        } finally {
            foreach ($sockets as $socket) fclose($socket);
        }
    }

    public function test_concurrent_claim_and_expired_delivery_order_on_the_same_row(): void
    {
        $run = bin2hex(random_bytes(6));
        $user = User::query()->create(['email_display' => $run.'@example.test', 'email_normalized' => $run.'@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('valid fixture password'), 'state' => V2UserState::Active]);
        $id = (string) Str::uuid7();
        DB::table('user_conversion_events')->insert(['public_id' => $id, 'user_id' => $user->id,
            'occurred_at' => now(), 'event_name' => 'line_friend_added', 'source' => 'transition', 'suppressed' => false]);
        $service = app(\App\Domain\Line\Services\V2ConversionEventService::class);
        $claim = static function () use ($service, $user, $id): string {
            try { $service->claim($user->id, $id); return 'claimed'; }
            catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $e) { return $e->errorCode; }
        };
        self::assertSame(['CONVERSION_CLAIM_HELD', 'claimed'], $this->concurrent($claim, 'conversion-claim-'.$run));
        $expired = str_repeat('c', 64);
        DB::table('user_conversion_events')->where('public_id', $id)->update([
            'claim_token_hash' => hash('sha256', $expired), 'claim_expires_at' => now()->subSecond(),
        ]);
        $result = $this->concurrent(static function (int $worker) use ($claim, $service, $user, $id, $expired): string {
            if ($worker === 0) return $claim();
            try { $service->delivered($user->id, $id, $expired); return 'delivered'; }
            catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $e) { return $e->errorCode; }
        }, 'conversion-delivered-'.$run);
        self::assertContains($result, [['CONVERSION_ALREADY_DELIVERED', 'delivered'], ['CONVERSION_TOKEN_MISMATCH', 'claimed']]);
        $row = DB::table('user_conversion_events')->where('public_id', $id)->sole();
        self::assertSame(in_array('delivered', $result, true), $row->delivered_at !== null);
        DB::table('user_conversion_events')->where('public_id', $id)->delete();
    }

    /**
     * @param callable(int): string $operation
     * @return list<string>
     */
    private function concurrent(callable $operation, string $scenario): array
    {
        $directory = sys_get_temp_dir().'/mig058b-'.$scenario;
        mkdir($directory, 0700, true);
        $startAt = microtime(true) + 0.5;
        $children = [];
        foreach ([0, 1] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('LINE webhook concurrency worker could not be created.');
            }
            if ($pid === 0) {
                while (microtime(true) < $startAt) {
                    usleep(1_000);
                }
                DB::disconnect();
                DB::reconnect();
                $result = $operation($worker);
                file_put_contents(
                    "{$directory}/{$worker}.json",
                    json_encode(['result' => $result], JSON_THROW_ON_ERROR),
                    LOCK_EX
                );
                exit(0);
            }
            $children[] = $pid;
        }
        DB::disconnect();
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();

        $statuses = [];
        foreach ([0, 1] as $worker) {
            $result = json_decode(
                file_get_contents("{$directory}/{$worker}.json"),
                true,
                flags: JSON_THROW_ON_ERROR
            );
            $statuses[] = $result['result'];
            unlink("{$directory}/{$worker}.json");
        }
        rmdir($directory);
        sort($statuses);

        return $statuses;
    }
}

final class ConcurrentLineReplyTransport implements V2LineMessagingTransport
{
    public function __construct(private readonly string $evidencePath)
    {
    }

    public function replyText(
        #[SensitiveParameter] string $replyToken,
        string $message
    ): V2LineReplyResult {
        file_put_contents($this->evidencePath, "reply\n", FILE_APPEND | LOCK_EX);

        return new V2LineReplyResult(true);
    }
}
