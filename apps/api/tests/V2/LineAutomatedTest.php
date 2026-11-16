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

    protected function setUp(): void
    {
        parent::setUp();
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

    private function baseline(): void
    {
        $quota = app(V2LineDeliveryQuota::class);
        $quota->confirmBaseline($this->context, $this->bot->id, $this->bot->authentication_revision,
            $quota->month(app(V2LineDeliverySender::class)->clock()), 0, 1000, (string) Str::uuid(),
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
        self::assertSame(2, DB::table('member_rank_promotion_identities')->count());
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
        self::assertSame(1, DB::table('member_rank_promotion_identities')->count());
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
        self::assertSame(0, DB::table('member_rank_promotion_identities')->count());
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

    public function test_stale_revision_and_fresh_mfa_are_enforced_without_exposing_body(): void
    {
        $this->enable('payment', 'Private synthetic body');
        $input = ['enabled' => false, 'category' => 'transactional', 'messages' => [['type' => 'text', 'text' => 'Updated']], 'expected_revision' => 0];
        try { app(V2LineAutomatedSettings::class)->update($this->context, 'payment', $input); self::fail('Stale update accepted'); }
        catch (\App\Domain\Line\Exceptions\V2LineMessagingException $e) { self::assertSame(409, $e->status); }
        DB::table('admin_sessions')->where('admin_id', $this->context->adminId)->update(['mfa_verified_at' => now()->subHour()]);
        $this->expectException(\App\Domain\Identity\Exceptions\V2AuthenticationException::class);
        app(V2LineAutomatedSettings::class)->update($this->context, 'payment', [...$input, 'expected_revision' => 1]);
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
        foreach (DB::table('member_rank_promotion_identities as identity')->join('member_rank_changes as change', 'change.id', '=', 'identity.change_id')->get() as $change) {
            $message = DB::table('outbox_messages')->where('deduplication_key', 'member_rank.promoted:'.$change->public_id)->sole();
            $payload = json_decode($message->payload, true);
            self::assertSame($change->public_id, $payload['change_public_id']);
            self::assertSame(\App\Domain\MemberRank\Services\V2MemberRankSettingsService::CODES[$change->to_level - 1], $payload['to_rank_code']);
        }
    }
}
