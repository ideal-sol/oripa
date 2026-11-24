<?php

namespace Tests\V2;

use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2IdentityCorrelation;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Line\Contracts\V2LineMessagingTransport;
use App\Domain\Line\Services\V2LineFriendService;
use App\Domain\Line\ValueObjects\V2LineReplyResult;
use App\Models\V2\ExternalIdentityAccount;
use App\Models\V2\LineFriendship;
use App\Models\V2\LineMessagingSetting;
use App\Models\V2\LineWebhookEvent;
use App\Models\V2\PointLedgerEntry;
use App\Models\V2\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
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
