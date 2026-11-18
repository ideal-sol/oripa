<?php

namespace Tests\V2;

use App\Domain\Line\Services\V2LineAutomatedSettings;
use App\Domain\Line\Services\V2LineAutomatedWorker;
use App\Domain\Line\Services\V2LineDeliveryQuota;
use App\Domain\Line\Services\V2LineDeliverySender;
use App\Domain\Line\Services\V2LineRecoveredFriendRegistry;
use App\Models\V2\ExternalIdentityAccount;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class LineAutomatedTest extends TestCase
{
    use MemberRankAccountingFixture;

    private object $context;
    private object $bot;
    private string $schema;
    private array $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(app()->environment('testing'));
        $this->schema = 'd4_synthetic_'.bin2hex(random_bytes(8));
        $this->originalConnection = config('database.connections.pgsql');
        DB::statement('CREATE SCHEMA '.$this->schema);
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => 'database/migrations-v2', '--force' => true]);
        $this->configureRankFixture();
        Carbon::setTestNow(); CarbonImmutable::setTestNow();
        DB::beginTransaction();
        $this->enableRankFixture();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'v2_line.automated_worker_enabled' => true]);
        Http::preventStrayRequests();
        $this->context = $this->rankAdmin();
        DB::table('line_bots')->where('is_active', true)->update(['is_active' => false]);
        $id = DB::table('line_bots')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Synthetic D4',
            'channel_id' => '123456789', 'channel_secret_ciphertext' => Crypt::encryptString('synthetic-only'),
            'is_active' => true, 'connection_status' => 'succeeded', 'connected_at' => now()]);
        $this->bot = DB::table('line_bots')->where('id', $id)->first();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        DB::purge('pgsql');
        config(['database.connections.pgsql' => $this->originalConnection]);
        DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        Carbon::setTestNow(); CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function enable(string $event, string $text = 'Hello %username%'): void
    {
        app(V2LineAutomatedSettings::class)->update($this->context, $event, ['enabled' => true,
            'category' => in_array($event, ['payment', 'shipping'], true) ? 'transactional' : 'promotional',
            'messages' => [['type' => 'text', 'text' => $text]], 'expected_revision' => 0]);
        $this->rankClock(CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS now')->now)->addSeconds(5)->toIso8601String());
    }

    private function linked(): object
    {
        $user = $this->rankUser();
        app(V2LineRecoveredFriendRegistry::class)->record($this->bot->id, 1, 'U'.str_repeat('1', 32), now()->getTimestampMs());
        ExternalIdentityAccount::query()->create(['user_id' => $user->id, 'provider' => 'line', 'issuer' => 'https://access.line.me',
            'subject_hash' => DB::table('line_recovered_friends')->where('line_bot_id', $this->bot->id)->value('subject_hash'),
            'linked_at' => now(), 'last_authenticated_at' => now()]);
        return $user;
    }

    private function baseline(int $limit = 1000): void
    {
        $quota = app(V2LineDeliveryQuota::class);
        $quota->confirmBaseline($this->context, $this->bot->id, $this->bot->authentication_revision,
            $quota->month(app(V2LineDeliverySender::class)->clock()), 0, $limit, (string) Str::uuid(),
            '課金対象送信の一元管理・正確な基準値・未確定の外部送信なしを確認しました');
    }

    public function test_defaults_off_and_unsupported_shipping_tag_is_preserved_without_relaxing_d3(): void
    {
        $settings = app(V2LineAutomatedSettings::class);
        self::assertSame([false, false, false, false], array_column($settings->read($this->context)['data'], 'enabled'));
        $this->enable('shipping', '%shipping_url%');
        self::assertSame('%shipping_url%', $settings->read($this->context)['data'][0]['messages'][0]['text']);
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Domain\Line\Services\V2LineDeliveryMessages::class)->validate([['type' => 'text', 'text' => '%shipping_url%']]);
    }

    public function test_two_purchases_keep_first_identity_and_two_distinct_obligations_with_shared_quota(): void
    {
        $this->enable('payment'); $this->enable('first_purchase'); $this->baseline();
        $user = $this->linked();
        $first = $this->settleRankPayment($user, 10000);
        $second = $this->settleRankPayment($user, 20000);
        $worker = app(V2LineAutomatedWorker::class);
        $worker->run('synthetic-d4'); $worker->run('synthetic-d4-replay');
        self::assertSame(3, DB::table('line_delivery_batches')->count());
        self::assertSame(3, DB::table('line_quota_charges')->count());
        self::assertSame(1, DB::table('line_automated_outcomes')->where('event', 'first_purchase')->where('status', 'queued')->count());
        self::assertDatabaseHas('payments', ['id' => $first->id, 'status' => 'succeeded']);
        self::assertDatabaseHas('payments', ['id' => $second->id, 'status' => 'succeeded']);
        self::assertSame(2, DB::table('member_rank_changes')->whereColumn('to_level', '>', 'from_level')->count());
        Http::assertNothingSent();
    }

    public function test_quota_failure_keeps_obligation_and_never_rolls_back_payment_or_rank(): void
    {
        $this->enable('payment'); $this->enable('first_purchase');
        $user = $this->linked(); $p = $this->settleRankPayment($user, 10000);
        app(V2LineAutomatedWorker::class)->run('synthetic-quota');
        self::assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'succeeded']);
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $p->public_id, 'status' => 'pending', 'last_error_code' => 'line_automated_handoff_retry']);
        self::assertSame(0, DB::table('line_delivery_batches')->count());
        self::assertSame(0, DB::table('line_automated_outcomes')->whereIn('event', ['payment', 'first_purchase'])->count());
        self::assertSame(1, DB::table('member_rank_changes')->whereColumn('to_level', '>', 'from_level')->count());
        Http::assertNothingSent();
    }

    public function test_old_event_is_not_backfilled_and_default_off_worker_does_not_claim(): void
    {
        $user = $this->linked(); $p = $this->settleRankPayment($user, 10000);
        $this->enable('payment');
        config(['v2_line.automated_worker_enabled' => false]);
        self::assertSame(0, app(V2LineAutomatedWorker::class)->run('disabled'));
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $p->public_id, 'attempts' => 0]);
        config(['v2_line.automated_worker_enabled' => true]);
        app(V2LineAutomatedWorker::class)->run('enabled');
        self::assertSame(0, DB::table('line_delivery_batches')->count());
        self::assertDatabaseHas('line_automated_outcomes', ['event' => 'payment', 'reason_code' => 'disabled_at_event']);
    }

    public function test_rank_event_and_identity_roll_back_with_owning_payment_transaction(): void
    {
        $user = $this->rankUser();
        DB::beginTransaction();
        $this->settleRankPayment($user, 10000);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'member_rank.promoted')->count());
        DB::rollBack();
        self::assertSame(0, DB::table('member_rank_changes')->whereColumn('to_level', '>', 'from_level')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'member_rank.promoted')->count());
        self::assertSame(0, DB::table('member_rank_changes')->count());
    }

    public function test_unknown_tags_media_limits_and_transactional_category_are_rejected(): void
    {
        $service = app(V2LineAutomatedSettings::class);
        foreach ([['type' => 'text', 'text' => '%unknown%'], ['type' => 'text', 'text' => str_repeat('😀', 2501)],
            ['type' => 'image', 'originalContentUrl' => 'https://example.test/image', 'previewImageUrl' => 'https://example.test/image'],
            ['type' => 'text', 'text' => '%shipping_url%']] as $message) {
            try { $service->validate('payment', [$message]); self::fail('Invalid notification accepted'); }
            catch (\App\Domain\Line\Exceptions\V2LineMessagingException $e) { self::assertSame(422, $e->status); }
        }
        $service->validate('payment', [['type' => 'text', 'text' => '50%オフ %username%']]);
        $this->expectException(\App\Domain\Line\Exceptions\V2LineMessagingException::class);
        $service->update($this->context, 'payment', ['enabled' => true, 'category' => 'promotional', 'messages' => [['type' => 'text', 'text' => 'Hello']], 'expected_revision' => 0]);
    }

    public function test_stale_revision_is_enforced_and_stale_mfa_does_not_block_updates(): void
    {
        $this->enable('payment', 'Private synthetic body');
        $input = ['enabled' => false, 'category' => 'transactional', 'messages' => [['type' => 'text', 'text' => 'Updated']], 'expected_revision' => 0];
        try { app(V2LineAutomatedSettings::class)->update($this->context, 'payment', $input); self::fail('Stale update accepted'); }
        catch (\App\Domain\Line\Exceptions\V2LineMessagingException $e) { self::assertSame(409, $e->status); }
        DB::table('admin_sessions')->where('admin_id', $this->context->adminId)->update(['mfa_verified_at' => now()->subHour()]);
        $result = app(V2LineAutomatedSettings::class)->update($this->context, 'payment', [...$input, 'expected_revision' => 1]);
        self::assertSame(2, $result['data'][1]['revision']);
    }

    public function test_setting_edits_do_not_change_already_queued_content(): void
    {
        $this->enable('payment', 'Original'); $this->baseline();
        $this->settleRankPayment($this->linked(), 10000);
        app(V2LineAutomatedWorker::class)->run('before-edit');
        $batch = DB::table('line_delivery_batches')->sole();
        app(V2LineAutomatedSettings::class)->update($this->context, 'payment', ['enabled' => false, 'category' => 'transactional',
            'messages' => [['type' => 'text', 'text' => 'Changed']], 'expected_revision' => 1]);
        self::assertSame((array) $batch, (array) DB::table('line_delivery_batches')->sole());
        self::assertSame('Original', json_decode(Crypt::decryptString($batch->messages_ciphertext), true)[0]['text']);
        Http::assertNothingSent();
    }

    public function test_unlinked_members_have_no_individual_push_and_no_quota_reservation(): void
    {
        $this->enable('payment'); $this->baseline();
        $this->settleRankPayment($this->rankUser(), 10000);
        app(V2LineAutomatedWorker::class)->run('unlinked');
        self::assertDatabaseHas('line_automated_outcomes', ['event' => 'payment', 'reason_code' => 'recipient_unavailable']);
        self::assertSame(0, DB::table('line_quota_charges')->count());
        Http::assertNothingSent();
    }

    public function test_manual_rank_points_and_transfer_emit_only_actual_promotions(): void
    {
        $user = $this->rankUser();
        $service = app(\App\Domain\MemberRank\Services\V2MemberRankAdminService::class);
        $write = function (string $operation, array $input) use ($user, $service): void {
            $preview = $service->preview($this->context, $user->public_id, $operation, $input);
            $service->write($this->context, $user->public_id, $operation, [...$input,
                'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid());
        };
        $write('points', ['delta' => 10000, 'reason' => 'Synthetic adjustment']);
        $write('rank', ['rank' => 'gold', 'kind' => 'support', 'reason' => 'Synthetic promotion', 'skip_count' => 0, 'grant_bonus' => false]);
        $write('rank', ['rank' => 'gold', 'kind' => 'support', 'reason' => 'Synthetic unchanged', 'skip_count' => 0, 'grant_bonus' => false]);
        $write('rank', ['rank' => 'silver', 'kind' => 'support', 'reason' => 'Synthetic demotion', 'skip_count' => 0, 'grant_bonus' => false]);
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'member_rank.promoted')->count());
        $other = $this->rankUser();
        app(\App\Domain\MemberRank\Services\V2MemberRankTransferService::class)->write($this->context, $other->public_id,
            ['status' => 'transferred', 'equivalent_level' => 4, 'points' => 100000, 'reason' => 'Synthetic transfer', 'expected_revision' => 0], (string) Str::uuid());
        self::assertSame(3, DB::table('outbox_messages')->where('event_type', 'member_rank.promoted')->count());
        foreach (DB::table('member_rank_changes')->whereColumn('to_level', '>', 'from_level')->get() as $change) {
            $message = DB::table('outbox_messages')->where('deduplication_key', 'member_rank.promoted:'.$change->public_id)->sole();
            $payload = json_decode($message->payload, true);
            self::assertSame($change->public_id, $payload['change_public_id']);
            self::assertSame(\App\Domain\MemberRank\Services\V2MemberRankSettingsService::CODES[$change->to_level - 1], $payload['to_rank_code']);
        }
    }

    public function test_later_purchase_processed_first_does_not_take_first_purchase_notification(): void
    {
        $this->enable('payment'); $this->enable('first_purchase'); $this->baseline();
        $user = $this->linked();
        $first = $this->settleRankPayment($user, 10000);
        $this->settleRankPayment($user, 20000);
        $event = DB::table('outbox_messages')->where('deduplication_key', 'payment.succeeded:'.$first->public_id)->sole();
        DB::table('outbox_messages')->where('id', $event->id)->update(['available_at' => now()->addHour()]);
        app(V2LineAutomatedWorker::class)->run('later-first');
        self::assertSame(0, DB::table('line_automated_outcomes')->where('event', 'first_purchase')->where('status', 'queued')->count());
        DB::table('outbox_messages')->where('id', $event->id)->update(['available_at' => now()]);
        app(V2LineAutomatedWorker::class)->run('first-later');
        self::assertDatabaseHas('line_automated_outcomes', ['outbox_id' => $event->id, 'event' => 'first_purchase', 'status' => 'queued']);
        self::assertSame(3, DB::table('line_delivery_batches')->count());
        Http::assertNothingSent();
    }

    public function test_quota_hold_is_durable_and_does_not_ack_event_or_erase_domain_success(): void
    {
        $this->enable('payment');
        $p = $this->settleRankPayment($this->linked(), 10000);
        DB::table('outbox_messages')->where('deduplication_key', 'payment.succeeded:'.$p->public_id)->update(['attempts' => 4]);
        app(V2LineAutomatedWorker::class)->run('last-attempt');
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $p->public_id, 'status' => 'failed', 'last_error_code' => 'line_automated_handoff_hold']);
        self::assertDatabaseHas('payments', ['id' => $p->id, 'status' => 'succeeded']);
        self::assertSame(0, DB::table('line_delivery_batches')->count());
        app(V2LineAutomatedWorker::class)->run('no-automatic-revival');
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $p->public_id, 'attempts' => 5, 'status' => 'failed']);
        Http::assertNothingSent();
    }

    public function test_second_notification_quota_failure_rolls_back_first_handoff_without_losing_event(): void
    {
        $this->enable('payment'); $this->enable('first_purchase'); $this->baseline(1);
        $p = $this->settleRankPayment($this->linked(), 10000);
        app(V2LineAutomatedWorker::class)->run('partial-quota');
        self::assertSame(0, DB::table('line_delivery_batches')->count());
        self::assertSame(0, DB::table('line_quota_charges')->count());
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $p->public_id, 'status' => 'pending']);
        self::assertSame(0, DB::table('line_automated_outcomes')->whereIn('event', ['payment', 'first_purchase'])->count());
        Http::assertNothingSent();
    }

    public function test_expired_lease_is_reclaimed_and_ack_failure_rolls_back_queue_atomically(): void
    {
        $this->enable('payment'); $this->baseline();
        $p = $this->settleRankPayment($this->linked(), 10000);
        DB::table('outbox_messages')->where('deduplication_key', 'payment.succeeded:'.$p->public_id)->update([
            'status' => 'processing', 'locked_by' => 'crashed-worker', 'locked_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->subMinute(), 'attempts' => 1]);
        DB::unprepared("CREATE FUNCTION d4_test_ack_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.status = 'delivered' AND NEW.event_type = 'payment.succeeded' THEN RAISE EXCEPTION 'synthetic_ack_failure'; END IF; RETURN NEW; END; \$\$;
            CREATE TRIGGER d4_test_ack_failure BEFORE UPDATE ON outbox_messages FOR EACH ROW EXECUTE FUNCTION d4_test_ack_failure();");
        app(V2LineAutomatedWorker::class)->run('reclaimed-worker');
        self::assertSame(0, DB::table('line_delivery_batches')->count());
        self::assertSame(0, DB::table('line_quota_charges')->count());
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $p->public_id, 'status' => 'pending', 'attempts' => 2]);
        // Remove only the test-owned failpoint; no product guard is disabled.
        DB::unprepared('DROP TRIGGER d4_test_ack_failure ON outbox_messages; DROP FUNCTION d4_test_ack_failure();');
        DB::table('outbox_messages')->where('deduplication_key', 'payment.succeeded:'.$p->public_id)->update(['available_at' => now()]);
        app(V2LineAutomatedWorker::class)->run('recovered-worker');
        app(V2LineAutomatedWorker::class)->run('duplicate-worker');
        self::assertSame(1, DB::table('line_delivery_batches')->count());
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $p->public_id, 'status' => 'delivered']);
        Http::assertNothingSent();
    }

    public function test_template_and_outcome_history_reject_mutation_delete_and_truncate(): void
    {
        $this->enable('payment');
        $this->settleRankPayment($this->rankUser(), 10000);
        app(V2LineAutomatedWorker::class)->run('immutable-history');
        foreach (['line_automated_template_versions', 'line_automated_outcomes'] as $table) {
            $before = DB::table($table)->get()->toJson();
            foreach (["UPDATE $table SET event = event", "DELETE FROM $table", "TRUNCATE $table CASCADE"] as $sql) {
                try { DB::transaction(fn () => DB::statement($sql)); self::fail('History mutation accepted'); }
                catch (\Illuminate\Database\QueryException $e) { self::assertStringContainsString('immutable', $e->getMessage()); }
                self::assertSame($before, DB::table($table)->get()->toJson());
            }
        }
    }

    public function test_concurrent_success_writers_and_consumers_keep_one_first_purchase_and_three_charges(): void
    {
        $this->enable('payment'); $this->enable('first_purchase'); $this->baseline();
        $user = $this->linked();
        [, $first] = $this->rankPayment($user, 10000);
        [, $second] = $this->rankPayment($user, 20000);
        // This test owns a disposable schema: commit fixtures so separate processes see them.
        DB::commit();
        $this->concurrent([
            fn () => app(\App\Domain\Payment\V2\Services\V2PaymentService::class)->confirmSucceeded($first->id),
            fn () => app(\App\Domain\Payment\V2\Services\V2PaymentService::class)->confirmSucceeded($second->id),
        ]);
        $this->concurrent([
            fn () => app(V2LineAutomatedWorker::class)->run('concurrent-d4-a'),
            fn () => app(V2LineAutomatedWorker::class)->run('concurrent-d4-b'),
        ]);
        app(V2LineAutomatedWorker::class)->run('concurrent-d4-replay');
        self::assertSame(2, DB::table('payment_point_grants')->count());
        self::assertSame(3, DB::table('line_delivery_batches')->count());
        self::assertSame(3, DB::table('line_quota_charges')->count());
        self::assertSame(1, DB::table('line_automated_outcomes')->where('event', 'first_purchase')->where('status', 'queued')->count());
        $paymentId = DB::table('payment_point_grants')->orderBy('id')->value('payment_id');
        $paymentUuid = DB::table('payments')->where('id', $paymentId)->value('public_id');
        $outboxId = DB::table('outbox_messages')->where('deduplication_key', 'payment.succeeded:'.$paymentUuid)->value('id');
        self::assertDatabaseHas('line_automated_outcomes', ['outbox_id' => $outboxId, 'event' => 'first_purchase', 'status' => 'queued']);
        Http::assertNothingSent();
    }

    /** Execute synthetic domain calls with separate DB connections and a common start barrier. */
    private function concurrent(array $actions): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'The isolated PHP test fixture requires pcntl.');
        DB::disconnect();
        $children = [];
        foreach ($actions as $action) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            self::assertIsArray($sockets);
            $pid = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                fclose($sockets[0]);
                pcntl_alarm(30);
                fread($sockets[1], 1);
                DB::reconnect();
                try {
                    DB::statement("SET lock_timeout = '15s'");
                    $action();
                    Http::assertNothingSent();
                    fwrite($sockets[1], 'PASS');
                    $exit = 0;
                } catch (\Throwable $error) {
                    fwrite($sockets[1], get_class($error));
                    $exit = 1;
                }
                DB::disconnect();
                fclose($sockets[1]);
                exit($exit);
            }
            fclose($sockets[1]);
            $children[] = [$pid, $sockets[0]];
        }
        foreach ($children as [, $socket]) fwrite($socket, '1');
        $results = [];
        foreach ($children as [$pid, $socket]) {
            pcntl_waitpid($pid, $status);
            $results[] = [pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1, stream_get_contents($socket)];
            fclose($socket);
        }
        DB::reconnect();
        self::assertSame(array_fill(0, count($actions), [0, 'PASS']), $results);
    }
}
