<?php

namespace Tests\V2;

use App\Domain\Line\Enums\V2LineDeliveryPurpose as Purpose;
use App\Domain\Line\Enums\V2LineRecipientGroup as Group;
use App\Domain\Line\Services\V2LineDeliveryService;
use App\Domain\Line\Services\V2LineDeliverySender;
use App\Domain\Line\Services\V2LineDeliveryQuota;
use App\Domain\Line\Services\V2LineRecipientResolver;
use App\Domain\Line\Services\V2LineRecoveredFriendRegistry;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LineDeliveryTest extends TestCase
{
    private object $bot;
    private string $schema;
    private array $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(app()->environment('testing'));
        $this->schema = 'd3_synthetic_'.bin2hex(random_bytes(8));
        $this->originalConnection = config('database.connections.pgsql');
        DB::statement('CREATE SCHEMA '.$this->schema);
        config(['database.connections.pgsql.search_path' => $this->schema]);
        DB::purge('pgsql');
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => 'database/migrations-v2', '--force' => true]);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cache.default' => 'array',
            'v2_identity.sms_verification.phone_hmac_key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        Http::preventStrayRequests();
        DB::table('line_bots')->where('is_active', true)->update(['is_active' => false]);
        $id = DB::table('line_bots')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Synthetic D3',
            'channel_id' => (string) random_int(100000000, 999999999), 'channel_secret_ciphertext' => Crypt::encryptString('synthetic-only'),
            'is_active' => true, 'connection_status' => 'succeeded', 'connected_at' => now()]);
        $this->bot = DB::table('line_bots')->where('id', $id)->first();
        $this->baseline(0, 1000);
    }

    protected function tearDown(): void
    {
        DB::disconnect('d3_observer');
        DB::purge('pgsql');
        config(['database.connections.pgsql' => $this->originalConnection]);
        DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
        parent::tearDown();
    }

    private function batch(bool $linked = false): int
    {
        app(V2LineRecoveredFriendRegistry::class)->record($this->bot->id, 1, 'U'.str_repeat('1', 32), now()->getTimestampMs());
        if ($linked) {
            $email = Str::uuid().'@example.test';
            $user = \App\Models\V2\User::query()->create(['email_display' => $email, 'email_normalized' => $email,
                'email_verified_at' => now(), 'password_hash' => '$argon2id$synthetic', 'password_login_enabled' => true, 'state' => 'active', 'display_name' => 'Original synthetic name']);
            \App\Models\V2\ExternalIdentityAccount::query()->create(['user_id' => $user->id, 'provider' => 'line',
                'issuer' => 'https://access.line.me', 'subject_hash' => DB::table('line_recovered_friends')->value('subject_hash'),
                'linked_at' => now(), 'last_authenticated_at' => now()]);
        }
        $ref = iterator_to_array(app(V2LineRecipientResolver::class)->resolve(Group::AllFriends, Purpose::Transactional))[0];
        return app(V2LineDeliveryService::class)->enqueuePush($ref, Purpose::Transactional,
            [['type' => 'text', 'text' => 'Hello %username% %points%']], 'automated', (string) Str::uuid());
    }

    private function baseline(int $used, int $limit, ?string $month = null, ?object $bot = null): void
    {
        $bot ??= $this->bot;
        $quota = app(V2LineDeliveryQuota::class);
        $quota->confirmBaseline($this->admin(), $bot->id, $bot->authentication_revision,
            $month ?? $quota->month(app(V2LineDeliverySender::class)->clock()), $used, $limit, (string) Str::uuid(),
            '課金対象送信の一元管理・正確な基準値・未確定の外部送信なしを確認しました');
    }

    private function newBot(?int $limit = null, int $used = 0): void
    {
        DB::table('line_bots')->where('is_active', true)->update(['is_active' => false]);
        $id = DB::table('line_bots')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Synthetic quota BOT',
            'channel_id' => (string) random_int(100000000, 999999999), 'channel_secret_ciphertext' => Crypt::encryptString('synthetic-only'),
            'is_active' => true, 'connection_status' => 'succeeded', 'connected_at' => now()]);
        $this->bot = DB::table('line_bots')->where('id', $id)->first();
        if ($limit !== null) $this->baseline($used, $limit);
    }

    private function quotaHttp(int $limit, int $used, int $status = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*/oauth2/v3/token' => Http::response(['access_token' => 'synthetic-token', 'expires_in' => 900]),
            '*/quota' => Http::response(['type' => 'limited', 'value' => $limit]), '*/consumption' => Http::response(['totalUsage' => $used]),
            '*/push' => Http::response([], $status), '*/multicast' => Http::response([], $status)]);
    }

    private function clockAt(string $instant): void
    {
        // Only this test's throwaway schema shadows the clock; no runtime clock/config changes.
        $value = \Carbon\CarbonImmutable::parse($instant)->toIso8601String();
        DB::unprepared("CREATE OR REPLACE FUNCTION {$this->schema}.clock_timestamp() RETURNS timestamptz AS 'SELECT ''{$value}''::timestamptz' LANGUAGE sql;");
        DB::statement('SET search_path TO '.$this->schema.', pg_catalog');
    }

    public function test_quota_reflected_and_lagging_usage_are_not_added_twice(): void
    {
        $this->newBot(200);
        for ($i = 0; $i < 100; $i++) app(V2LineRecoveredFriendRegistry::class)->record($this->bot->id, 1, 'U'.md5('quota-'.$i), now()->getTimestampMs());
        $this->quotaHttp(200, 0);
        $delivery = $this->scheduled();
        app(V2LineDeliveryService::class)->resolve($delivery);
        $batch = DB::table('line_delivery_batches')->where('delivery_id', $delivery)->sole();
        app(V2LineDeliverySender::class)->send($batch->id);
        self::assertSame('accepted', DB::table('line_quota_charges')->where('batch_id', $batch->id)->value('state'));
        foreach ([0, 100] as $usage) {
            $this->quotaHttp(200, $usage);
            self::assertSame(100, app(V2LineDeliveryQuota::class)->remaining($this->bot));
        }
        $id = $this->batch();
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame('accepted', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        self::assertSame(101, app(V2LineDeliveryQuota::class)->reserved($this->bot->id));
        $this->quotaHttp(200, 200);
        self::assertSame(0, app(V2LineDeliveryQuota::class)->remaining($this->bot));
        self::assertTrue(DB::table('line_quota_accounts')->where('line_bot_id', $this->bot->id)->value('halted'));
    }

    public function test_quota_exact_opening_balance_and_unverified_bot_fail_closed(): void
    {
        $this->newBot(); $this->quotaHttp(200, 100);
        try { $this->batch(); self::fail('Unverified baseline allowed enqueue'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('quota', $e->getMessage()); }
        self::assertSame(0, DB::table('line_delivery_batches')->where('line_bot_id', $this->bot->id)->count());
        $this->baseline(100, 200);
        self::assertSame(100, app(V2LineDeliveryQuota::class)->remaining($this->bot));
        $id = $this->batch(); app(V2LineDeliverySender::class)->send($id);
        self::assertSame(99, app(V2LineDeliveryQuota::class)->remaining($this->bot));
        try { $this->baseline(0, 200); self::fail('Existing baseline rewritten'); }
        catch (\Illuminate\Database\QueryException $e) { self::assertSame('23505', $e->errorInfo[0]); }
        $this->newBot(200, 200);
        $delivery = $this->scheduled();
        app(V2LineRecoveredFriendRegistry::class)->record($this->bot->id, 1, 'U'.str_repeat('a', 32), now()->getTimestampMs());
        app(V2LineDeliveryService::class)->resolve($delivery);
        self::assertSame('blocked_quota', DB::table('line_deliveries')->where('id', $delivery)->value('status'));
    }

    public function test_quota_unknown_and_same_key_retries_keep_one_charge_after_commit_failure(): void
    {
        $id = $this->batch(); $this->fake([0, 409]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame('unknown', DB::table('line_quota_charges')->where('batch_id', $id)->value('state'));
        self::assertSame(1, app(V2LineDeliveryQuota::class)->reserved($this->bot->id));
        DB::table('line_delivery_batches')->where('id', $id)->update(['not_before' => now()->subSecond()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame('accepted', DB::table('line_quota_charges')->where('batch_id', $id)->value('state'));
        self::assertSame(1, app(V2LineDeliveryQuota::class)->reserved($this->bot->id));
        foreach ([fn () => DB::table('line_quota_charges')->where('batch_id', $id)->delete(),
            fn () => DB::table('line_quota_charges')->where('batch_id', $id)->update(['state' => 'reserved']),
            fn () => DB::table('line_quota_periods')->where('line_bot_id', $this->bot->id)->update(['opening_used' => 1])] as $invalid) {
            try { DB::transaction($invalid); self::fail('Quota history erased'); }
            catch (\Illuminate\Database\QueryException $e) { self::assertSame('P0001', $e->errorInfo[0]); }
        }
    }

    public function test_quota_parallel_reservation_and_all_paid_sources_share_capacity(): void
    {
        $this->newBot(1);
        config(['database.connections.d3_observer' => config('database.connections.pgsql')]);
        $observer = DB::connection('d3_observer'); $observer->statement("SET lock_timeout = '100ms'");
        DB::beginTransaction();
        $id = $this->batch();
        $ref = iterator_to_array(app(V2LineRecipientResolver::class)->resolve(Group::AllFriends, Purpose::Transactional))[0];
        DB::setDefaultConnection('d3_observer');
        try {
            app(V2LineDeliveryService::class)->enqueuePush($ref, Purpose::Transactional, [['type' => 'text', 'text' => 'chat']], 'chat', (string) Str::uuid());
            self::fail('Concurrent reservation bypassed singleton');
        } catch (\Illuminate\Database\QueryException $e) { self::assertSame('55P03', $e->errorInfo[0]); }
        finally { DB::setDefaultConnection('pgsql'); DB::commit(); }
        try {
            app(V2LineDeliveryService::class)->enqueuePush($ref, Purpose::Transactional, [['type' => 'text', 'text' => 'chat']], 'chat', (string) Str::uuid());
            self::fail('Chat stole broadcast/automated capacity');
        } catch (\RuntimeException $e) { self::assertStringContainsString('quota', $e->getMessage()); }
        self::assertSame(1, DB::table('line_quota_charges')->where('batch_id', $id)->count());
        $this->quotaHttp(1, 0);
        $delivery = $this->scheduled(); app(V2LineDeliveryService::class)->resolve($delivery);
        self::assertSame('blocked_quota', DB::table('line_deliveries')->where('id', $delivery)->value('status'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/push') || str_ends_with($r->url(), '/multicast'));
    }

    public function test_quota_month_boundary_retry_covers_both_jst_months_without_new_key(): void
    {
        $this->clockAt('2026-11-30T23:59:50+09:00');
        $this->newBot(2); $this->baseline(0, 2, '2026-12-01');
        $id = $this->batch(); $requests = [];
        $this->fake([0, 409], function ($r) use (&$requests): void { $requests[] = [$r->body(), $r->header('X-Line-Retry-Key')]; });
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame(['2026-11-01', '2026-12-01'], DB::table('line_quota_charges')->where('batch_id', $id)->orderBy('month')->pluck('month')->all());
        $this->clockAt('2026-12-01T00:01:00+09:00');
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame($requests[0], $requests[1]);
        self::assertSame(2, DB::table('line_quota_charges')->where('batch_id', $id)->where('state', 'accepted')->count());
        self::assertSame(1, app(V2LineDeliveryQuota::class)->available($this->bot->id, '2026-12-01'));
        self::assertSame('2026-12-01', app(V2LineDeliveryQuota::class)->month(\Carbon\CarbonImmutable::parse('2026-11-30T15:00:00Z')));
    }

    public function test_quota_month_end_campaign_automatically_covers_next_month(): void
    {
        $this->clockAt('2026-11-30T23:59:50+09:00'); $this->newBot(200);
        app(V2LineRecoveredFriendRegistry::class)->record($this->bot->id, 1, 'U'.str_repeat('3', 32), now()->getTimestampMs());
        $this->quotaHttp(200, 0); $delivery = $this->scheduled();
        app(V2LineDeliveryService::class)->resolve($delivery);
        $batch = DB::table('line_delivery_batches')->where('delivery_id', $delivery)->sole();
        app(V2LineDeliverySender::class)->send($batch->id);
        self::assertSame('completed', DB::table('line_deliveries')->where('id', $delivery)->value('status'));
        self::assertSame('system', DB::table('line_quota_periods')->where('line_bot_id', $this->bot->id)->where('month', '2026-12-01')->value('baseline_origin'));
        self::assertNull(DB::table('line_quota_periods')->where('line_bot_id', $this->bot->id)->where('month', '2026-12-01')->value('verified_by_admin_id'));
        self::assertNotNull(DB::table('line_delivery_batches')->where('id', $batch->id)->value('first_attempted_at'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/push') || str_ends_with($r->url(), '/multicast'));
    }

    public function test_quota_unknown_carries_into_preconfirmed_month_and_after_retry_expiry(): void
    {
        $this->clockAt('2026-11-16T12:00:00+09:00'); $this->newBot(2); $this->baseline(0, 2, '2026-12-01');
        $id = $this->batch(); $this->fake([0]); app(V2LineDeliverySender::class)->send($id);
        self::assertSame(1, DB::table('line_quota_charges')->where('batch_id', $id)->count());
        $this->clockAt('2026-12-01T00:00:00+09:00');
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame('unknown', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        self::assertSame(1, app(V2LineDeliveryQuota::class)->available($this->bot->id, '2026-12-01'));
        $next = $this->batch();
        self::assertSame('unknown', DB::table('line_quota_charges')->where('batch_id', $id)->where('month', '2026-12-01')->value('state'));
        self::assertSame(0, app(V2LineDeliveryQuota::class)->available($this->bot->id, '2026-12-01'));
        $this->baseline(0, 2, '2027-01-01');
        self::assertSame('unknown', DB::table('line_quota_charges')->where('batch_id', $id)->where('month', '2027-01-01')->value('state'));
        self::assertSame(1, DB::table('line_quota_charges')->where('batch_id', $next)->count());
    }

    public function test_quota_external_send_halts_bot_and_future_baseline_cannot_resume_current_month(): void
    {
        $id = $this->batch(); $quota = app(V2LineDeliveryQuota::class);
        $quota->halt($this->admin(), $this->bot->id, (string) Str::uuid());
        app(V2LineDeliverySender::class)->send($id); Http::assertNothingSent();
        try { $this->baseline(0, 1000); self::fail('Same-month recovery accepted'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('halted', $e->getMessage()); }
        $next = $quota->month(app(V2LineDeliverySender::class)->clock()->addMonthNoOverflow());
        $this->baseline(0, 1000, $next);
        app(V2LineDeliverySender::class)->send($id); Http::assertNothingSent();
        $this->newBot();
        self::assertSame(0, $quota->available($this->bot->id, $quota->month(app(V2LineDeliverySender::class)->clock())));
        try { $this->batch(); self::fail('New BOT inherited quota'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('quota', $e->getMessage()); }
        $quota->halt($this->admin(), $this->bot->id, (string) Str::uuid());
        self::assertTrue(DB::table('line_quota_accounts')->where('line_bot_id', $this->bot->id)->value('halted'));
        try { $this->baseline(0, 1000); self::fail('Unverified BOT incident forgotten'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('halted', $e->getMessage()); }
    }

    public function test_quota_lowered_limit_after_resolution_preserves_c1(): void
    {
        $this->newBot(2);
        foreach (['4', '5'] as $part) app(V2LineRecoveredFriendRegistry::class)->record($this->bot->id, 1, 'U'.str_repeat($part, 32), now()->getTimestampMs());
        $this->quotaHttp(2, 0); $delivery = $this->scheduled(); app(V2LineDeliveryService::class)->resolve($delivery);
        $this->quotaHttp(1, 0);
        app(V2LineDeliverySender::class)->send(DB::table('line_delivery_batches')->where('delivery_id', $delivery)->value('id'));
        self::assertSame('blocked_quota', DB::table('line_deliveries')->where('id', $delivery)->value('status'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/push') || str_ends_with($r->url(), '/multicast'));
    }

    public function test_quota_baseline_accepts_stale_mfa_and_does_not_adopt_legacy_attempts(): void
    {
        $this->newBot(); $context = $this->admin();
        DB::table('admin_sessions')->where('session_id_hash', $context->sessionIdHash)->update(['mfa_verified_at' => now()->subDay()]);
        app(V2LineDeliveryQuota::class)->confirmBaseline($context, $this->bot->id, 1, '2026-11-01', 0, 200, (string) Str::uuid(),
            '課金対象送信の一元管理・正確な基準値・未確定の外部送信なしを確認しました');
        self::assertTrue(DB::table('line_quota_accounts')->where('line_bot_id', $this->bot->id)->exists());
        $this->newBot();
        DB::table('line_delivery_batches')->insert(['public_id' => (string) Str::uuid(), 'line_bot_id' => $this->bot->id,
            'authentication_revision' => 1, 'category' => 'transactional', 'method' => 'push', 'source_type' => 'automated',
            'source_key' => (string) Str::uuid(), 'retry_key' => (string) Str::uuid(), 'messages_ciphertext' => Crypt::encryptString('synthetic'),
            'payload_ciphertext' => Crypt::encryptString('legacy'), 'first_attempted_at' => now(), 'not_before' => now(), 'status' => 'unknown', 'attempts' => 1]);
        try { $this->baseline(1, 200); self::fail('Legacy exposure adopted without proof'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('Unaccounted', $e->getMessage()); }
        self::assertFalse(DB::table('line_quota_accounts')->where('line_bot_id', $this->bot->id)->exists());
    }

    public function test_quota_delete_and_truncate_guards_preserve_balances_and_unknown_history(): void
    {
        $botId = $this->bot->id;
        $batch = $this->batch(); $this->fake([0]); app(V2LineDeliverySender::class)->send($batch);
        self::assertSame('unknown', DB::table('line_quota_charges')->where('batch_id', $batch)->value('state'));
        $quota = app(V2LineDeliveryQuota::class);
        $month = $quota->month(app(V2LineDeliverySender::class)->clock());
        $remaining = $quota->available($botId, $month);
        self::assertSame(99, $remaining); // Fresh provider cap100 is authoritative over prior1000.
        // No period FK protects this independently halted account.
        $this->newBot(); $quota->halt($this->admin(), $this->bot->id, (string) Str::uuid());
        $haltedBotId = $this->bot->id;
        self::assertFalse(DB::table('line_quota_periods')->where('line_bot_id', $haltedBotId)->exists());
        $tables = ['line_quota_accounts', 'line_quota_periods', 'line_quota_charges'];
        $snapshot = function () use ($tables): string {
            $rows = [];
            foreach ($tables as $table) $rows[$table] = DB::table($table)->orderBy('line_bot_id')->get()->all();
            return json_encode($rows, JSON_THROW_ON_ERROR);
        };
        $before = $snapshot();
        $statements = [
            'DELETE FROM line_quota_charges' => 'P0001',
            'DELETE FROM line_quota_periods' => 'P0001',
            'DELETE FROM line_quota_accounts' => 'P0001',
            'DELETE FROM line_quota_accounts WHERE line_bot_id = '.$haltedBotId => 'P0001',
            'TRUNCATE line_quota_charges' => 'P0001',
            // PostgreSQL first refuses omitted FK dependents; explicit lists exercise the parent trigger.
            'TRUNCATE line_quota_periods' => '0A000',
            'TRUNCATE line_quota_accounts' => '0A000',
            'TRUNCATE line_quota_periods, line_quota_charges' => 'P0001',
            'TRUNCATE line_quota_accounts, line_quota_periods, line_quota_charges' => 'P0001',
            'TRUNCATE line_quota_charges CASCADE' => 'P0001',
            'TRUNCATE line_quota_periods CASCADE' => 'P0001',
            'TRUNCATE line_quota_accounts CASCADE' => 'P0001',
            'TRUNCATE line_quota_accounts RESTART IDENTITY CASCADE' => 'P0001',
        ];
        // Exercise both the fixture owner and a non-owner with genuine DELETE/TRUNCATE grants.
        // Role creation/grants are in this transaction and disappear on rollback.
        $role = 'd3_quota_guard_'.bin2hex(random_bytes(8));
        DB::beginTransaction();
        try {
            DB::statement('CREATE ROLE '.$role.' NOLOGIN NOSUPERUSER');
            DB::statement('GRANT USAGE ON SCHEMA '.$this->schema.' TO '.$role);
            DB::statement('GRANT SELECT, DELETE, TRUNCATE ON '.implode(', ', $tables).' TO '.$role);
            foreach ([false, true] as $restricted) {
                if ($restricted) DB::statement('SET LOCAL ROLE '.$role);
                foreach ($tables as $table) self::assertTrue(DB::selectOne("SELECT has_table_privilege(current_user, ?, 'TRUNCATE') AS allowed", [$table])->allowed);
                foreach ($statements as $sql => $state) {
                    try { DB::transaction(fn () => DB::statement($sql), 1); self::fail('Quota history was erased: '.$sql); }
                    catch (\Illuminate\Database\QueryException $e) {
                        self::assertSame($state, $e->errorInfo[0], $sql);
                        if ($state === 'P0001') self::assertStringContainsString('LINE quota history is retained', $e->getMessage());
                    }
                    self::assertSame($before, $snapshot(), $sql);
                }
                if ($restricted) DB::statement('RESET ROLE');
            }
        } finally { DB::rollBack(); }
        self::assertSame($before, $snapshot());
        self::assertSame($remaining, $quota->available($botId, $month));
        self::assertSame('unknown', DB::table('line_quota_charges')->where('batch_id', $batch)->value('state'));
        self::assertTrue(DB::table('line_quota_accounts')->where('line_bot_id', $haltedBotId)->value('halted'));
    }

    public function test_monthly_auto_initialization_is_idempotent_and_retains_human_history(): void
    {
        $this->clockAt('2026-11-20T12:00:00+09:00'); $this->newBot(200, 7);
        $old = DB::table('line_quota_periods')->where('line_bot_id', $this->bot->id)->sole();
        $this->clockAt('2026-12-01T00:00:01+09:00'); $this->quotaHttp(150, 0);
        $q = app(V2LineDeliveryQuota::class);
        $q->refreshForAdmission(); $q->refreshForAdmission();
        self::assertEquals($old, DB::table('line_quota_periods')->where('line_bot_id', $this->bot->id)->where('month','2026-11-01')->sole());
        $new = DB::table('line_quota_periods')->where('line_bot_id',$this->bot->id)->where('month','2026-12-01')->sole();
        self::assertSame('system',$new->baseline_origin); self::assertNull($new->verified_by_admin_id);
        self::assertSame(0,$new->opening_used); self::assertSame(150,$new->effective_limit);
        self::assertSame(1,DB::table('audit_logs')->where('action_code','line.quota.period.generated')->count());
        $batch=$this->batch(); $this->fake([200]); app(V2LineDeliverySender::class)->send($batch);
        self::assertSame('accepted',DB::table('line_delivery_batches')->where('id',$batch)->value('status'));
    }

    public function test_monthly_auto_initialization_preserves_halt_generation_and_unknown_exposure(): void
    {
        $this->clockAt('2026-11-20T12:00:00+09:00'); $this->newBot(2);
        $batch=$this->batch(); $this->fake([0]); app(V2LineDeliverySender::class)->send($batch);
        $this->clockAt('2026-12-01T00:00:01+09:00'); $this->quotaHttp(2,0);
        $q=app(V2LineDeliveryQuota::class);
        self::assertSame(1,$q->unknown($this->bot->id,'2026-12-01'));
        $q->refreshForAdmission();
        self::assertSame(1,$q->unknown($this->bot->id,'2026-12-01'));
        self::assertSame(1,$q->available($this->bot->id,'2026-12-01'));
        self::assertSame('unknown',DB::table('line_quota_charges')->where('batch_id',$batch)->where('month','2026-12-01')->value('state'));
        $q->halt($this->admin(),$this->bot->id,(string)Str::uuid());
        $this->clockAt('2027-01-01T00:00:01+09:00'); $q->refreshForAdmission();
        self::assertFalse(DB::table('line_quota_periods')->where('line_bot_id',$this->bot->id)->where('month','2027-01-01')->exists());
        self::assertSame(0,$q->available($this->bot->id,'2027-01-01'));
        $this->newBot(2); DB::table('line_bots')->where('id',$this->bot->id)->update(['authentication_revision'=>2,'revision'=>2]);
        $this->clockAt('2027-02-01T00:00:01+09:00'); $q->refreshForAdmission();
        self::assertSame(0,$q->available($this->bot->id,'2027-02-01'));
        self::assertFalse(DB::table('line_quota_periods')->where('line_bot_id',$this->bot->id)->where('month','2027-02-01')->exists());
    }

    public function test_monthly_initializers_serialize_and_provider_failure_cannot_create_a_baseline(): void
    {
        $this->clockAt('2026-11-20T12:00:00+09:00'); $this->newBot(200);
        $this->clockAt('2026-12-01T00:00:01+09:00');
        $q = app(V2LineDeliveryQuota::class);
        Http::fake(['*'=>Http::response([],503)]);
        try { $q->refreshForAdmission(); self::fail('Unavailable quota accepted'); } catch (\RuntimeException) {}
        self::assertFalse(DB::table('line_quota_periods')->where('line_bot_id',$this->bot->id)->where('month','2026-12-01')->exists());
        $this->quotaHttp(150,0);
        config(['database.connections.d3_observer'=>config('database.connections.pgsql')]);
        $observer=DB::connection('d3_observer'); $observer->statement("SET lock_timeout = '100ms'");
        $observer->statement('SET search_path TO '.$this->schema.', pg_catalog');
        DB::beginTransaction();
        app(\App\Domain\Line\Services\V2LineMessagingSettingService::class)->setting(true);
        DB::setDefaultConnection('d3_observer');
        try { $q->refreshForAdmission(); self::fail('Initializer bypassed singleton'); }
        catch (\Illuminate\Database\QueryException $e) { self::assertSame('55P03',$e->errorInfo[0]); }
        finally { DB::setDefaultConnection('pgsql'); DB::commit(); }
        $q->refreshForAdmission();
        DB::setDefaultConnection('d3_observer');
        try { $q->refreshForAdmission(); } finally { DB::setDefaultConnection('pgsql'); }
        self::assertSame(1,DB::table('line_quota_periods')->where('line_bot_id',$this->bot->id)->where('month','2026-12-01')->count());
        self::assertSame(1,DB::table('audit_logs')->where('action_code','line.quota.period.generated')->count());
    }

    private function fake(array $codes, ?\Closure $check = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        $index = 0;
        Http::fake(function ($request) use ($codes, $check, &$index) {
            if (str_ends_with($request->url(), '/oauth2/v3/token')) return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 900]);
            if (str_ends_with($request->url(), '/quota')) return Http::response(['type' => 'limited', 'value' => 100]);
            if (str_ends_with($request->url(), '/consumption')) return Http::response(['totalUsage' => 0]);
            if ($check !== null) $check($request);
            $code = $codes[min($index++, count($codes) - 1)];
            if ($code === 0) throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout');
            return Http::response([], $code, ['x-line-request-id' => 'synthetic-request', 'x-line-accepted-request-id' => 'synthetic-original']);
        });
    }

    private function admin(): \App\Domain\Identity\Contracts\V2AdminAuthorizationContext
    {
        $public = (string) Str::uuid(); $email = $public.'@example.test'; $hash = hash('sha256', $public);
        $id = DB::table('admins')->insertGetId(['public_id' => $public, 'email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(), 'password_hash' => '$argon2id$synthetic', 'role' => 'owner', 'state' => 'active']);
        DB::table('admin_sessions')->insert(\Tests\Support\V2TimestampFixture::attributes(['session_id_hash' => $hash, 'admin_id' => $id,
            'mfa_verified_at' => now(), 'requires_mfa_enrollment' => false, 'created_at' => now()->subSecond(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(8)]));
        return new \App\Domain\Identity\Contracts\V2AdminAuthorizationContext($id, $public, \App\Domain\Identity\Enums\V2AdminRole::Owner, $hash, hash('sha256', $hash), (string) Str::uuid());
    }

    public function test_commit_before_send_and_identical_retry_after_timeout(): void
    {
        $id = $this->batch(); $requests = [];
        config(['database.connections.d3_observer' => config('database.connections.'.config('database.default'))]);
        $this->fake([0, 409], function ($request) use ($id, &$requests): void {
            $b = DB::connection('d3_observer')->table('line_delivery_batches')->where('id', $id)->first();
            self::assertSame('unknown', DB::connection('d3_observer')->table('line_quota_charges')->where('batch_id', $id)->value('state'));
            self::assertNotNull($b->payload_ciphertext);
            self::assertNotNull($b->first_attempted_at);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{14}4[0-9a-f-]{21}$/', $b->retry_key);
            self::assertSame($b->retry_key, $request->header('X-Line-Retry-Key')[0]);
            self::assertGreaterThan(0, DB::transactionLevel());
            $requests[] = [$request->body(), $request->header('X-Line-Retry-Key')];
        });
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame('retrying', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        DB::table('line_delivery_batches')->where('id', $id)->update(['not_before' => now()->subSecond()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame($requests[0], $requests[1]);
        $b = DB::table('line_delivery_batches')->where('id', $id)->first();
        self::assertSame('accepted', $b->status);
        self::assertSame('synthetic-original', $b->request_id);
        self::assertStringNotContainsString('Hello', $b->payload_ciphertext);
        self::assertStringNotContainsString('U'.str_repeat('1', 32), json_encode(DB::table('line_delivery_recipients')->where('batch_id', $id)->get()));
        DB::disconnect('d3_observer');
    }

    public function test_401_refresh_is_bounded_and_uses_identical_bytes_and_key(): void
    {
        $id = $this->batch(); $requests = [];
        $this->fake([401, 401], function ($r) use (&$requests): void { $requests[] = [$r->body(), $r->header('X-Line-Retry-Key')]; });
        app(V2LineDeliverySender::class)->send($id);
        self::assertCount(2, $requests);
        self::assertSame($requests[0], $requests[1]);
        self::assertSame('bot_auth_failed', DB::table('line_delivery_batches')->where('id', $id)->value('reason_code'));
        app(V2LineDeliverySender::class)->send($id);
        self::assertCount(2, $requests);
    }

    public function test_http_status_classification_and_backoff(): void
    {
        foreach ([200 => 'accepted', 400 => 'failed', 429 => 'retrying', 500 => 'retrying'] as $code => $state) {
            $id = $this->batch(); $this->fake([$code]);
            app(V2LineDeliverySender::class)->send($id);
            $b = DB::table('line_delivery_batches')->where('id', $id)->first();
            self::assertSame($state, $b->status);
            if ($state === 'retrying') self::assertGreaterThan(20, \Carbon\CarbonImmutable::now()->diffInSeconds(\Carbon\CarbonImmutable::parse($b->not_before)));
        }
    }

    public function test_timeout_after_401_renewal_remains_uncertain_and_reuses_original_request(): void
    {
        $id = $this->batch(); $requests = [];
        $this->fake([401, 0, 409], function ($r) use (&$requests): void { $requests[] = [$r->body(), $r->header('X-Line-Retry-Key')]; });
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame('retrying', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        DB::table('line_delivery_batches')->where('id', $id)->update(['not_before' => now()->subSecond()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertCount(3, $requests);
        self::assertSame($requests[0], $requests[1]);
        self::assertSame($requests[0], $requests[2]);
        self::assertSame('accepted', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
    }

    public function test_result_commit_failure_replays_only_committed_request(): void
    {
        $id = $this->batch(); $requests = [];
        DB::unprepared("CREATE OR REPLACE FUNCTION d3_test_commit_failure() RETURNS trigger AS \$\$ BEGIN IF NEW.id = {$id} AND NEW.status = 'accepted' THEN RAISE EXCEPTION 'synthetic commit failure'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE CONSTRAINT TRIGGER d3_test_commit_failure AFTER UPDATE ON line_delivery_batches DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION d3_test_commit_failure();");
        $this->fake([200, 409], function ($r) use (&$requests): void { $requests[] = [$r->body(), $r->header('X-Line-Retry-Key')]; });
        try {
            app(V2LineDeliverySender::class)->send($id);
            self::fail('Commit failure not injected.');
        } catch (\PDOException) {
            self::assertSame('sending', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
            self::assertSame('unknown', DB::table('line_quota_charges')->where('batch_id', $id)->value('state'));
            self::assertSame(1, DB::table('line_quota_charges')->where('batch_id', $id)->count());
        } finally {
            DB::unprepared('DROP TRIGGER d3_test_commit_failure ON line_delivery_batches; DROP FUNCTION d3_test_commit_failure();');
        }
        DB::table('line_delivery_batches')->where('id', $id)->update(['lease_until' => now()->subSecond()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertCount(2, $requests);
        self::assertSame($requests[0], $requests[1]);
        self::assertSame('accepted', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
    }

    public function test_nested_transaction_is_rejected_before_any_http(): void
    {
        $id = $this->batch();
        DB::beginTransaction();
        try {
            app(V2LineDeliverySender::class)->send($id);
            self::fail('Uncommitted dispatch accepted.');
        } catch (\LogicException) {
            Http::assertNothingSent();
        } finally { DB::rollBack(); }
    }

    public function test_paused_client_loses_send_locks_and_recovers_only_the_original_request(): void
    {
        $id = $this->batch(); $requests = []; $released = false;
        config(['database.connections.d3_observer' => config('database.connections.pgsql')]);
        $observer = DB::connection('d3_observer');
        $this->fake([200, 409], function ($r) use ($observer, &$requests, &$released): void {
            $requests[] = [$r->body(), $r->header('X-Line-Retry-Key')];
            self::assertSame('20s', DB::selectOne('SHOW transaction_timeout')->transaction_timeout);
            if (count($requests) !== 1) return;
            $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            // Fault injection: provider accepted, but the client process stops
            // progressing before it records the response. No real HTTP/worker.
            usleep(21_000_000);
            self::assertSame(0, $observer->table('pg_stat_activity')->where('pid', $pid)->count());
            $observer->transaction(function () use ($observer): void {
                $observer->statement("SET LOCAL lock_timeout = '100ms'");
                self::assertNotNull($observer->table('line_messaging_settings')->where('id', 1)->lockForUpdate()->first());
            });
            $released = true;
        });
        try { app(V2LineDeliverySender::class)->send($id); self::fail('Expired transaction committed.'); }
        catch (\PDOException) { self::assertTrue($released); }
        DB::purge('pgsql');
        self::assertSame('sending', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        DB::table('line_delivery_batches')->where('id', $id)->update(['lease_until' => now()->subSecond()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame($requests[0], $requests[1]);
        self::assertSame('accepted', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
    }

    public function test_switch_and_unfollow_before_first_send_skip_without_http(): void
    {
        $id = $this->batch();
        DB::table('line_bots')->where('id', $this->bot->id)->update(['revision' => 2, 'authentication_revision' => 2]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame('skipped', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        Http::assertNothingSent();
    }

    public function test_payload_commit_failure_sends_nothing(): void
    {
        $id = $this->batch();
        DB::unprepared("CREATE OR REPLACE FUNCTION d3_test_prepare_failure() RETURNS trigger AS \$\$ BEGIN IF NEW.id = {$id} AND NEW.status = 'sending' THEN RAISE EXCEPTION 'synthetic prepare commit failure'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql; CREATE CONSTRAINT TRIGGER d3_test_prepare_failure AFTER UPDATE ON line_delivery_batches DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION d3_test_prepare_failure();");
        try { app(V2LineDeliverySender::class)->send($id); self::fail('Commit unexpectedly succeeded.'); }
        catch (\PDOException) {
            Http::assertNothingSent();
            self::assertNull(DB::table('line_delivery_batches')->where('id', $id)->value('payload_ciphertext'));
            self::assertSame('reserved', DB::table('line_quota_charges')->where('batch_id', $id)->value('state'));
        }
    }

    public function test_singleton_member_identity_and_batch_locks_span_http_and_release_promptly(): void
    {
        $id = $this->batch(true);
        $user = DB::table('line_delivery_recipients')->where('batch_id', $id)->value('user_id');
        config(['database.connections.d3_observer' => config('database.connections.pgsql')]);
        $observer = DB::connection('d3_observer'); $blocked = [];
        $this->fake([200], function () use ($observer, $user, $id, &$blocked): void {
            // These are the exact row fences used by BOT management, webhook/linking,
            // member suspension, identity revocation and competing send claimants.
            foreach (['line_messaging_settings' => 1, 'users' => $user,
                'external_identity_accounts' => DB::table('external_identity_accounts')->where('user_id', $user)->value('id'),
                'line_delivery_batches' => $id] as $table => $rowId) {
                $observer->beginTransaction();
                try {
                    $observer->statement("SET LOCAL lock_timeout = '100ms'");
                    $observer->table($table)->where('id', $rowId)->lockForUpdate()->first();
                    self::fail('Concurrent mutation fence was not held.');
                } catch (\Illuminate\Database\QueryException $e) {
                    self::assertSame('55P03', $e->errorInfo[0]); $blocked[] = $table;
                } finally { $observer->rollBack(); }
            }
        });
        $start = microtime(true);
        app(V2LineDeliverySender::class)->send($id);
        self::assertCount(4, $blocked);
        self::assertLessThan(3, microtime(true) - $start);
        $observer->transaction(function () use ($observer): void {
            $observer->statement("SET LOCAL lock_timeout = '100ms'");
            self::assertNotNull($observer->table('line_messaging_settings')->where('id', 1)->lockForUpdate()->first());
        });
    }

    public function test_retry_freezes_member_expansion_and_an_active_lease_prevents_reclaim(): void
    {
        $id = $this->batch(true); $bodies = [];
        $this->fake([500, 200], function ($r) use (&$bodies): void { $bodies[] = $r->body(); });
        app(V2LineDeliverySender::class)->send($id);
        DB::table('users')->update(['display_name' => 'Changed synthetic name']);
        DB::table('line_delivery_batches')->where('id', $id)->update(['not_before' => now()->subSecond(), 'lease_owner' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertCount(1, $bodies);
        DB::table('line_delivery_batches')->where('id', $id)->update(['lease_until' => now()->subSecond()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame($bodies[0], $bodies[1]);
        self::assertStringContainsString('Original synthetic name', $bodies[1]);
    }

    public function test_expired_retry_and_changed_member_fail_closed_without_new_key(): void
    {
        $id = $this->batch(true); $key = DB::table('line_delivery_batches')->where('id', $id)->value('retry_key');
        DB::table('line_delivery_batches')->where('id', $id)->update(['status' => 'sending', 'attempts' => 1,
            'payload_ciphertext' => Crypt::encryptString('synthetic expired envelope'), 'first_attempted_at' => now()->subHours(23)->subSecond(),
            'lease_owner' => (string) Str::uuid(), 'lease_until' => now()->subSecond()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame('unknown', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        self::assertSame($key, DB::table('line_delivery_batches')->where('id', $id)->value('retry_key'));
        Http::assertNothingSent();
    }

    public function test_frozen_identity_guards_and_suspended_member_stop_retry_without_replacement(): void
    {
        $id = $this->batch(true); $requests = [];
        $this->fake([500], function ($r) use (&$requests): void { $requests[] = $r->body(); });
        app(V2LineDeliverySender::class)->send($id);
        $batch = DB::table('line_delivery_batches')->where('id', $id)->first();
        foreach ([['retry_key' => (string) Str::uuid()], ['payload_ciphertext' => Crypt::encryptString('changed')],
            ['first_attempted_at' => now()->addMinute()], ['line_bot_id' => $batch->line_bot_id + 1]] as $change) {
            try {
                DB::transaction(fn () => DB::table('line_delivery_batches')->where('id', $id)->update($change));
                self::fail('Frozen request was mutable.');
            } catch (\Illuminate\Database\QueryException $e) { self::assertSame('P0001', $e->errorInfo[0]); }
        }
        $user = DB::table('line_delivery_recipients')->where('batch_id', $id)->value('user_id');
        DB::table('users')->where('id', $user)->update(['state' => 'suspended']);
        DB::table('line_delivery_batches')->where('id', $id)->update(['not_before' => now()->subSecond()]);
        app(V2LineDeliverySender::class)->send($id);
        self::assertCount(1, $requests);
        self::assertSame('unknown', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        self::assertSame($batch->retry_key, DB::table('line_delivery_batches')->where('id', $id)->value('retry_key'));
    }

    private function scheduled(): int
    {
        return DB::table('line_deliveries')->insertGetId(['public_id' => (string) Str::uuid(), 'category' => 'promotional',
            'recipient_group' => 'all_friends', 'messages_ciphertext' => Crypt::encryptString(json_encode([['type' => 'text', 'text' => 'Synthetic broadcast']])),
            'pacing' => json_encode(new \App\Domain\Line\ValueObjects\V2LineDeliveryPacing), 'status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
    }

    public function test_insufficient_quota_creates_no_batch_and_sends_nothing(): void
    {
        app(V2LineRecoveredFriendRegistry::class)->record($this->bot->id, 1, 'U'.str_repeat('2', 32), now()->getTimestampMs());
        Http::fake(['*/oauth2/v3/token' => Http::response(['access_token' => 'synthetic-token', 'expires_in' => 900]),
            '*/quota' => Http::response(['type' => 'limited', 'value' => 0]), '*/consumption' => Http::response(['totalUsage' => 0])]);
        $id = $this->scheduled();
        app(V2LineDeliveryService::class)->resolve($id);
        self::assertSame('blocked_quota', DB::table('line_deliveries')->where('id', $id)->value('status'));
        self::assertSame(0, DB::table('line_delivery_batches')->where('delivery_id', $id)->count());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/push') || str_ends_with($r->url(), '/multicast'));
    }

    public function test_501_recipients_are_two_multicast_batches_and_quota_reservations_are_conservative(): void
    {
        for ($i = 0; $i < 501; $i++) app(V2LineRecoveredFriendRegistry::class)->record($this->bot->id, 1, 'U'.md5('synthetic-'.$i), now()->getTimestampMs());
        Http::fake(['*/oauth2/v3/token' => Http::response(['access_token' => 'synthetic-token', 'expires_in' => 900]),
            '*/quota' => Http::response(['type' => 'limited', 'value' => 600]), '*/consumption' => Http::response(['totalUsage' => 0])]);
        $first = $this->scheduled(); $second = $this->scheduled();
        app(V2LineDeliveryService::class)->resolve($first);
        $batches = DB::table('line_delivery_batches')->where('delivery_id', $first)->orderBy('position')->get();
        self::assertCount(2, $batches);
        self::assertSame('multicast', $batches[0]->method);
        self::assertSame(500, DB::table('line_delivery_recipients')->where('batch_id', $batches[0]->id)->count());
        self::assertSame(1, DB::table('line_delivery_recipients')->where('batch_id', $batches[1]->id)->count());
        app(V2LineDeliveryService::class)->resolve($second);
        self::assertSame('blocked_quota', DB::table('line_deliveries')->where('id', $second)->value('status'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/multicast'));
        $sizes = [];
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$sizes) {
            if (str_ends_with($request->url(), '/oauth2/v3/token')) return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 900]);
            if (str_ends_with($request->url(), '/quota')) return Http::response(['type' => 'limited', 'value' => 600]);
            if (str_ends_with($request->url(), '/consumption')) return Http::response(['totalUsage' => 0]);
            self::assertSame('https://api.line.me/v2/bot/message/multicast', $request->url());
            $sizes[] = count(json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)['to']);
            return Http::response([], 200);
        });
        $started = microtime(true);
        foreach ($batches as $batch) app(V2LineDeliverySender::class)->send($batch->id);
        self::assertSame([500, 1], $sizes);
        self::assertSame('completed', DB::table('line_deliveries')->where('id', $first)->value('status'));
        self::assertLessThan(15, microtime(true) - $started);
    }

    public function test_management_snapshot_without_fresh_mfa_and_explicit_manual_resend(): void
    {
        $context = $this->admin(); $service = app(V2LineDeliveryService::class);
        DB::table('admin_sessions')->where('session_id_hash', $context->sessionIdHash)->update(['mfa_verified_at' => null]);
        $id = $service->save($context, null, 0, Purpose::Promotional, Group::AllFriends, [['type' => 'text', 'text' => 'Synthetic']], null);
        $service->updateDefaults($context, 1, new \App\Domain\Line\ValueObjects\V2LineDeliveryPacing(2, 7, 3));
        self::assertSame(10, $service->pacing(DB::table('line_deliveries')->where('id', $id)->value('pacing'))->pushEvery);
        $service->cancel($context, $id, 1);
        self::assertSame('cancelled', DB::table('line_deliveries')->where('id', $id)->value('status'));
        $batch = $this->batch(); $this->fake([400]); app(V2LineDeliverySender::class)->send($batch);
        $requestKey = (string) Str::uuid();
        try { $service->resend($context, $batch, '', $requestKey); self::fail('Missing confirmation accepted.'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        $new = $service->resend($context, $batch, 'すでに届いている可能性があります', $requestKey);
        self::assertSame($new, $service->resend($context, $batch, 'すでに届いている可能性があります', $requestKey));
        self::assertSame($batch, DB::table('line_delivery_batches')->where('id', $new)->value('replaces_batch_id'));
        self::assertNotSame(DB::table('line_delivery_batches')->where('id', $batch)->value('retry_key'), DB::table('line_delivery_batches')->where('id', $new)->value('retry_key'));
        DB::table('admin_sessions')->where('session_id_hash', $context->sessionIdHash)->update(['mfa_verified_at' => now()->subDay()]);
        $service->updateDefaults($context, 2, new \App\Domain\Line\ValueObjects\V2LineDeliveryPacing);
        self::assertSame(3, DB::table('line_delivery_settings')->value('revision'));
    }

    public function test_actual_bot_webhook_link_and_user_state_writers_wait_for_dispatch(): void
    {
        $context = $this->admin(); $id = $this->batch(true);
        $userId = DB::table('line_delivery_recipients')->where('batch_id', $id)->value('user_id');
        $user = \App\Models\V2\User::findOrFail($userId);
        $hash = DB::table('line_recovered_friends')->value('subject_hash');
        DB::table('line_pending_follows')->insert(['public_id' => (string) Str::uuid(), 'line_bot_id' => $this->bot->id,
            'subject_hash' => $hash, 'subject_ciphertext' => Crypt::encryptString('U'.str_repeat('1', 32)), 'status' => 'pending', 'followed_at' => now()]);
        config(['database.connections.d3_observer' => config('database.connections.pgsql')]);
        $observer = DB::connection('d3_observer'); $observer->statement("SET lock_timeout = '100ms'");
        $blocked = 0;
        $this->fake([200], function () use ($context, $user, $hash, $id, &$blocked): void {
            DB::setDefaultConnection('d3_observer');
            try {
                $operations = [
                    fn () => app(\App\Domain\Line\Services\V2LineBotManagement::class)->mutate($context, 'activation', $this->bot->public_id, (string) Str::uuid(), ['expected_revision' => 1]),
                    fn () => app(\App\Domain\Line\Services\V2LineFriendService::class)->handleEvents([['type' => 'unfollow', 'timestamp' => now()->getTimestampMs(), 'source' => ['type' => 'user', 'userId' => 'U'.str_repeat('1', 32)], 'webhookEventId' => 'synthetic-concurrent']], (string) Str::uuid(), $this->bot->id),
                    fn () => DB::transaction(fn () => app(\App\Domain\Line\Services\V2LineFriendService::class)->reconcileIdentity($hash, $user, (string) Str::uuid(), false)),
                    fn () => app(\App\Domain\Identity\Services\V2AdminUserStateService::class)->update($context, $user->public_id, ['status' => 'suspended', 'expected_revision' => 1, 'reason' => 'Synthetic concurrency'], (string) Str::uuid()),
                    fn () => app(V2LineDeliverySender::class)->send($id),
                ];
                foreach ($operations as $operation) {
                    try { $operation(); self::fail('Concurrent writer unexpectedly succeeded.'); }
                    catch (\Illuminate\Database\QueryException $e) { self::assertSame('55P03', $e->errorInfo[0]); $blocked++; }
                }
            } finally { DB::setDefaultConnection('pgsql'); }
        });
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame(5, $blocked);
        self::assertSame('accepted', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
        self::assertSame('active', DB::table('users')->where('id', $userId)->value('state'));
    }

    public function test_http_deadlines_and_quota_429_are_safe(): void
    {
        $id = $this->batch(); $sends = 0;
        Http::fake(function ($request, $options) use (&$sends) {
            self::assertFalse($options['allow_redirects']);
            if (str_ends_with($request->url(), '/oauth2/v3/token')) return Http::response(['access_token' => 'synthetic-token', 'expires_in' => 900]);
            if (str_ends_with($request->url(), '/quota')) {
                self::assertSame(0, DB::transactionLevel());
                return Http::response(['type' => 'limited', 'value' => 1]);
            }
            if (str_ends_with($request->url(), '/consumption')) return Http::response(['totalUsage' => $sends === 0 ? 0 : 1]);
            $sends++;
            self::assertLessThanOrEqual(10, $options['timeout']);
            self::assertLessThanOrEqual(5, $options['connect_timeout']);
            return Http::response(['message' => 'untrusted provider prose is ignored'], 429);
        });
        app(V2LineDeliverySender::class)->send($id);
        self::assertSame(1, $sends);
        self::assertSame('quota_exceeded', DB::table('line_delivery_batches')->where('id', $id)->value('reason_code'));
        self::assertSame('failed', DB::table('line_delivery_batches')->where('id', $id)->value('status'));
    }
}
