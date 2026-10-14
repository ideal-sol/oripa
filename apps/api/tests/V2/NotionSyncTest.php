<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CardInventoryService;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Integrations\Notion\NotionClient;
use App\Domain\Integrations\Notion\NotionClock;
use App\Domain\Integrations\Notion\NotionException;
use App\Domain\Integrations\Notion\NotionSchema;
use App\Domain\Integrations\Notion\NotionSyncService;
use App\Domain\Integrations\Notion\NotionSyncWorker;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NotionSyncTest extends TestCase
{
    private string $schemaName;
    private mixed $searchPath;
    private V2AdminAuthorizationContext $context;
    private string $sessionToken;
    private const SOURCE = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(0, DB::transactionLevel());
        $this->schemaName = 'notion_test_'.bin2hex(random_bytes(8));
        $this->searchPath = config('database.connections.pgsql.search_path');
        DB::statement('CREATE SCHEMA '.$this->schemaName);
        config(['database.connections.pgsql.search_path' => $this->schemaName]);
        DB::purge();
        $this->artisan('migrate', ['--path' => 'database/migrations-v2', '--force' => true])->assertExitCode(0);
        config(['v2_notion.enabled' => true, 'v2_notion.token' => 'synthetic-notion-test-value',
            'v2_notion.data_source_id' => self::SOURCE,
            'v2_audit.active_hmac_key_version' => 'v1', 'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo']);
        $this->app->instance(NotionClock::class, new class extends NotionClock {
            public function wait(float $seconds, callable $heartbeat): void { $heartbeat(); }
        });
        Http::preventStrayRequests();
        $admin = Admin::query()->create(['email_display' => 'notion@example.test', 'email_normalized' => 'notion@example.test',
            'email_verified_at' => now(), 'password_hash' => app(\App\Domain\Identity\Services\V2PasswordPolicy::class)->hash('synthetic-password'),
            'role' => V2AdminRole::Admin, 'state' => V2AdminState::Active]);
        $this->sessionToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $this->sessionToken);
        DB::table('admin_sessions')->insert(['session_id_hash' => $hash, 'admin_id' => $admin->id,
            'mfa_verified_at' => now(), 'requires_mfa_enrollment' => false, 'created_at' => now(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(7)]);
        $this->context = new V2AdminAuthorizationContext($admin->id, $admin->public_id, $admin->role, $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash), (string) Str::uuid7());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        config(['database.connections.pgsql.search_path' => $this->searchPath]);
        DB::purge();
        DB::statement('DROP SCHEMA '.$this->schemaName.' CASCADE');
        parent::tearDown();
    }

    public function test_f5_snapshot_difference_cache_filters_and_warning_use_the_canonical_values(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR);
        app(V2CatalogFixtureImporter::class)->import($fixture);
        DB::table('catalog_prizes')->update(['external_id' => 'CARD-1', 'revision' => DB::raw('revision + 1')]);
        $export = app(V2CardInventoryService::class)->snapshotExport();
        try { $expected = json_decode(fgets($export['stream']), true, flags: JSON_THROW_ON_ERROR); }
        finally { fclose($export['stream']); }
        $page = $this->page('CARD-1');
        $this->fake([$page]);
        $run = $this->synchronize();
        self::assertSame('completed', $run['status']);
        self::assertSame(1, $run['updated_count']);
        Http::assertSent(function ($request) use ($expected): bool {
            return $request->method() === 'PATCH' && $request['properties']['確保必要数']['number'] === $expected['required_quantity'];
        });
        self::assertNull(DB::table('notion_inventory_cache')->value('available'));
        self::assertSame(12.0, (float) DB::table('notion_inventory_cache')->value('on_hand'));
        DB::table('notion_inventory_cache')->update(['available' => -2]);
        $cards = app(V2CardInventoryService::class)->cards($this->context, ['negative_only' => true, 'limit' => 1]);
        self::assertSame('CARD-1', $cards['items'][0]['external_id']);
        self::assertSame(-2.0, $cards['items'][0]['notion_available']);
        $version = DB::table('catalog_gacha_versions')->first();
        $warnings = app(V2CardInventoryService::class)->importWarnings($version, [[
            'externalId' => 'CARD-1', 'after' => ['quantity' => 100000], 'inventory' => null, 'relation' => null,
        ]]);
        self::assertSame('NOTION_STOCK_INSUFFICIENT', $warnings[0]['code']);
        DB::table('notion_inventory_cache')->insert(['external_id' => 'NEW-CARD', 'page_id' => (string) Str::uuid7(),
            'on_hand' => 1, 'available' => 1, 'read_at' => now(), 'run_id' => DB::table('notion_sync_runs')->value('id')]);
        $newWarnings = app(V2CardInventoryService::class)->importWarnings($version, [[
            'externalId' => 'NEW-CARD', 'after' => ['quantity' => 100000], 'inventory' => null, 'relation' => null,
        ]]);
        self::assertSame('NOTION_STOCK_INSUFFICIENT', $newWarnings[0]['code']);
    }

    public function test_zero_writeback_unchanged_duplicates_invalid_and_null_are_distinct(): void
    {
        $null = $this->page('NULL');
        $null['properties']['手持ち枚数']['number'] = null;
        $this->fake([$this->page('CHANGED', 10), $this->page('SAME'), $this->page('DUP', 5),
            $this->page('DUP', 8), $this->page(''), $this->page(' invalid id '), $null]);
        $run = $this->synchronize();
        self::assertSame('completed', $run['status']);
        self::assertSame(1, $run['updated_count']);
        self::assertSame(2, $run['unchanged_count']);
        self::assertSame(1, $run['duplicate_count']);
        self::assertSame(['DUP'], $run['duplicate_ids']);
        self::assertSame(2, $run['invalid_count']);
        self::assertNull(DB::table('notion_inventory_cache')->where('external_id', 'NULL')->value('on_hand'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && array_keys($request->data()) === ['properties']
            && array_keys($request['properties']) === ['確保必要数', '企画割当']);
    }

    #[DataProvider('incompleteQueries')]
    public function test_incomplete_query_writes_nothing(array $override): void
    {
        $this->fake([$this->page('CARD', 7)], $override);
        $run = $this->synchronize();
        self::assertSame('failed', $run['status']);
        self::assertSame('NOTION_QUERY_INCOMPLETE', $run['error_code']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
        self::assertDatabaseCount('notion_inventory_cache', 0);
    }

    public static function incompleteQueries(): array
    {
        return [[['request_status' => ['type' => 'incomplete']]], [['has_more' => true, 'next_cursor' => null]],
            [['request_status' => null]], [['has_more' => false, 'next_cursor' => 'unexpected']]];
    }

    public function test_schema_mismatch_stops_all_writes_including_late_page_mismatch(): void
    {
        $bad = $this->page('BAD', 3);
        $bad['properties']['空き在庫']['formula']['type'] = 'string';
        $this->fake([$this->page('GOOD', 4), $bad]);
        self::assertSame('NOTION_SCHEMA_MISMATCH', $this->synchronize()['error_code']);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
    }

    public function test_types_rich_text_unique_id_formulas_rollup_and_mapping_collisions(): void
    {
        $schema = $this->schema();
        $page = $this->page('CARD');
        $schema['properties']['管理ID']['type'] = 'unique_id';
        $page['properties']['管理ID'] = ['id' => 'external_id', 'type' => 'unique_id', 'unique_id' => ['number' => 42, 'prefix' => 'CARD']];
        $schema['properties']['手持ち枚数']['type'] = 'rollup';
        $page['properties']['手持ち枚数'] = ['id' => 'on_hand', 'type' => 'rollup', 'rollup' => ['type' => 'incomplete']];
        $parser = app(NotionSchema::class);
        $parsed = $parser->page($page, $parser->validate($schema));
        self::assertSame('CARD-42', $parsed['external_id']);
        self::assertNull($parsed['on_hand']);
        $page['properties']['手持ち枚数']['rollup'] = ['type' => 'number', 'number' => 17];
        self::assertSame(17, $parser->page($page, $parser->validate($schema))['on_hand']);
        $schema['properties']['管理ID']['type'] = 'rich_text';
        $page['properties']['管理ID'] = ['id' => 'external_id', 'type' => 'rich_text', 'rich_text' => [['plain_text' => ' CARD'], ['plain_text' => '-42 ']]];
        self::assertSame('CARD-42', $parser->page($page, $parser->validate($schema))['external_id']);
        $schema['properties']['管理ID']['type'] = 'formula';
        $page['properties']['管理ID'] = ['id' => 'external_id', 'type' => 'formula', 'formula' => ['type' => 'string', 'string' => 'CARD-42']];
        self::assertSame('CARD-42', $parser->page($page, $parser->validate($schema))['external_id']);
        $schema['properties']['確保必要数']['id'] = 'on_hand';
        $this->expectException(NotionException::class);
        $parser->validate($schema);
    }

    public function test_off_configuration_and_business_rollback_have_no_side_effects(): void
    {
        Http::fake();
        config(['v2_notion.enabled' => false]);
        self::assertNull(app(NotionSyncService::class)->enqueue('business'));
        self::assertSame(0, app(NotionSyncWorker::class)->runOnce());
        config(['v2_notion.enabled' => true]);
        DB::beginTransaction();
        app(NotionSyncService::class)->afterBusinessCommit();
        DB::rollBack();
        self::assertDatabaseCount('notion_sync_runs', 0);
        DB::transaction(function (): void {
            app(NotionSyncService::class)->afterBusinessCommit();
            app(NotionSyncService::class)->afterBusinessCommit();
        });
        self::assertDatabaseCount('notion_sync_runs', 1);
        Http::assertNothingSent();
    }

    public function test_idempotency_different_keys_periodic_and_worker_lock_coalesce(): void
    {
        $service = app(NotionSyncService::class);
        $first = $service->create($this->context, 'first');
        self::assertSame($first['id'], $service->create($this->context, 'first')['id']);
        self::assertSame($first['id'], $service->create($this->context, 'second')['id']);
        self::assertSame($first['id'], $service->enqueue('periodic'));
        self::assertSame(2, DB::table('idempotency_records')->where('resource_public_id', $first['id'])->count());
        config(['database.connections.notion_competitor' => config('database.connections.pgsql')]);
        try {
            DB::connection('notion_competitor')->select("SELECT pg_advisory_lock(hashtextextended('v2_notion_worker', 0))");
            self::assertSame(0, app(NotionSyncWorker::class)->runOnce());
        } finally { DB::purge('notion_competitor'); }
        self::assertDatabaseCount('notion_sync_runs', 1);
    }

    public function test_expired_worker_keeps_successes_and_backoff_then_recovers_with_new_snapshot(): void
    {
        $id = app(NotionSyncService::class)->enqueue('business');
        DB::table('notion_sync_runs')->where('public_id', $id)->update(['status' => 'processing',
            'lease_hash' => str_repeat('a', 64), 'lease_expires_at' => now()->subSecond(), 'updated_count' => 3]);
        self::assertSame(0, app(NotionSyncWorker::class)->runOnce());
        self::assertSame('failed', app(NotionSyncService::class)->result($id)['status']);
        self::assertSame(3, app(NotionSyncService::class)->result($id)['updated_count']);
        CarbonImmutable::setTestNow(now()->addSeconds(301));
        $this->fake([]);
        self::assertSame(1, app(NotionSyncWorker::class)->runOnce());
        self::assertSame(2, DB::table('notion_sync_runs')->count());
    }

    public function test_retry_after_and_unknown_patch_result_are_read_back_without_duplicate_patch(): void
    {
        $page = $this->page('CARD', 3);
        $reads = 0;
        $patches = 0;
        $waits = new class extends NotionClock {
            public array $waits = [];
            public function wait(float $seconds, callable $heartbeat): void { $this->waits[] = $seconds; $heartbeat(); }
        };
        $this->app->instance(NotionClock::class, $waits);
        Http::fake(function ($request) use ($page, &$reads, &$patches) {
            self::assertSame(0, DB::transactionLevel());
            if ($request->method() === 'PATCH') { $patches++; return Http::response(['message' => 'synthetic-notion-test-value'], 503); }
            if (str_contains($request->url(), '/pages/')) { return Http::response($this->page('CARD', 0, $page['id'])); }
            if (str_ends_with($request->url(), '/query')) { return Http::response($this->queryResponse([$page])); }
            $reads++;
            return $reads === 1 ? Http::response([], 429, ['Retry-After' => '120']) : Http::response($this->schema());
        });
        self::assertSame('completed', $this->synchronize()['status']);
        self::assertSame(1, $patches);
        self::assertGreaterThan(119, max($waits->waits));
        self::assertStringNotContainsString('synthetic-notion-test-value', json_encode(DB::table('audit_logs')->get()));
    }

    public function test_partial_failure_is_durable_and_retry_only_changes_remaining_pages(): void
    {
        $bad = $this->page('BAD', 1);
        $good = $this->page('GOOD', 1);
        $this->fake([$bad, $good], [], $bad['id']);
        $first = $this->synchronize();
        self::assertSame('failed', $first['status']);
        self::assertSame(1, $first['updated_count']);
        self::assertSame(1, $first['failed_count']);
        self::assertNull(app(NotionSyncService::class)->status($this->context)['last_success_at']);
        $this->fake([$bad, $this->page('GOOD', 0, $good['id'])]);
        self::assertSame(1, $this->synchronize()['updated_count']);
    }

    public function test_operator_can_read_but_cannot_request_and_missing_key_is_rejected(): void
    {
        $base = '/admin/api/v2/integrations/notion';
        $cookie = config('v2_identity.sessions.admin.cookie');
        $this->withCredentials()->withUnencryptedCookie($cookie, $this->sessionToken)->getJson($base.'/status')->assertOk()
            ->assertJsonMissing(['token' => 'synthetic-notion-test-value']);
        try { app(NotionSyncService::class)->create($this->context, ''); self::fail('Missing key accepted'); }
        catch (NotionException $error) { self::assertSame('IDEMPOTENCY_KEY_REQUIRED', $error->errorCode); }
        DB::table('admins')->where('id', $this->context->adminId)->update(['role' => 'operator']);
        $operator = new V2AdminAuthorizationContext($this->context->adminId, $this->context->adminPublicId,
            V2AdminRole::Operator, $this->context->sessionIdHash, $this->context->sessionCorrelationHash, $this->context->requestId);
        try { app(NotionSyncService::class)->create($operator, 'operator'); self::fail('Operator accepted'); }
        catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $error) { self::assertSame(403, $error->status); }
        self::assertDatabaseCount('notion_sync_runs', 0);
    }

    public function test_manual_http_contract_is_nonretryable_and_preserves_browser_security(): void
    {
        Http::fake();
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
        $root = '/admin/api/v2/integrations/notion/sync-runs';
        $csrf = str_repeat('a', 64);
        $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->sessionToken)
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf]);
        $this->postJson($root)->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->withHeader('Idempotency-Key', 'manual-http');
        config(['v2_notion.token' => null]);
        $this->postJson($root)->assertStatus(503)->assertJsonPath('code', 'NOTION_CONFIGURATION_INVALID')
            ->assertJsonPath('retryable', false);
        config(['v2_notion.token' => 'synthetic-notion-test-value']);
        $run = $this->postJson($root)->assertStatus(202)->assertJsonPath('status', 'queued')->json();
        $this->postJson($root)->assertStatus(202)->assertJsonPath('id', $run['id']);
        $this->getJson($root.'/'.$run['id'])->assertOk()->assertJsonPath('id', $run['id']);
        $this->getJson($root)->assertOk()->assertJsonCount(1, 'items');
        $this->withHeader('X-XSRF-TOKEN', 'invalid')->postJson($root)->assertForbidden();
        $this->withHeader('X-XSRF-TOKEN', $csrf);
        DB::table('admins')->where('id', $this->context->adminId)->update(['role' => 'operator']);
        $this->postJson($root)->assertForbidden();
        self::assertDatabaseCount('notion_sync_runs', 1);
        Http::assertNothingSent();
    }

    public function test_missing_ids_and_periodic_interval_require_a_complete_query(): void
    {
        DB::table('content_banners')->insert(['public_id' => (string) Str::uuid7(), 'code' => 'MISSING',
            'external_id' => 'MISSING', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $this->fake([]);
        $result = $this->synchronize();
        self::assertSame(['MISSING'], $result['missing_ids']);
        self::assertSame(1, $result['missing_count']);
        self::assertNull(app(NotionSyncService::class)->enqueue('periodic'));
        CarbonImmutable::setTestNow(now()->addMinutes(16));
        $periodic = app(NotionSyncService::class)->enqueue('periodic');
        self::assertNotNull($periodic);
        $this->fake([], ['request_status' => ['type' => 'incomplete']]);
        self::assertSame(1, app(NotionSyncWorker::class)->runOnce());
        self::assertSame([], app(NotionSyncService::class)->result($periodic)['missing_ids']);
    }

    public function test_retry_budget_529_network_failure_and_blocked_rate_limit(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) { throw new \Illuminate\Http\Client\ConnectionException('synthetic-notion-test-value'); }
            return Http::response([], 529, ['Retry-After' => '130']);
        });
        try { app(NotionClient::class)->schema(); self::fail('Retry exhaustion accepted'); }
        catch (NotionException $error) { self::assertSame('NOTION_RATE_LIMITED', $error->errorCode); }
        self::assertSame(6, $calls);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['additional_data' => ['rate_limit_reason' => 'public_api_request_blocked']], 429));
        try { app(NotionClient::class)->schema(); self::fail('Blocked request accepted'); }
        catch (NotionException $error) { self::assertSame('NOTION_RATE_LIMITED', $error->errorCode); }
        Http::assertSentCount(1);
    }

    public function test_redirects_and_forbidden_properties_never_send_credentials_elsewhere(): void
    {
        Http::fake(fn () => Http::response([], 302, ['Location' => 'https://untrusted.example.test']));
        try { app(NotionClient::class)->schema(); self::fail('Redirect accepted'); }
        catch (NotionException $error) { self::assertSame('NOTION_REQUEST_FAILED', $error->errorCode); }
        Http::assertSentCount(1);
        foreach (['手持ち枚数', '空き在庫', '管理ID', 'in_trash', 'unrelated'] as $property) {
            try { app(NotionClient::class)->patch((string) Str::uuid7(), [$property => ['number' => 1]]); self::fail('Forbidden PATCH accepted'); }
            catch (NotionException $error) { self::assertSame('NOTION_SCHEMA_MISMATCH', $error->errorCode); }
        }
        Http::assertSentCount(1);
    }

    public function test_stale_worker_cannot_overwrite_results_or_cache_after_lease_loss(): void
    {
        $run = app(NotionSyncService::class)->enqueue('manual');
        Http::fake(function () use ($run) {
            DB::table('notion_sync_runs')->where('public_id', $run)->update(['lease_hash' => str_repeat('b', 64)]);
            return Http::response($this->schema());
        });
        try { app(NotionSyncWorker::class)->runOnce(); self::fail('Stale worker completed'); }
        catch (NotionException $error) { self::assertSame('NOTION_LEASE_LOST', $error->errorCode); }
        self::assertSame('processing', app(NotionSyncService::class)->result($run)['status']);
        self::assertDatabaseCount('notion_inventory_cache', 0);
        Http::assertSentCount(1);
    }

    public function test_long_text_id_is_completed_through_property_pagination_and_trash_is_skipped(): void
    {
        $page = $this->page('placeholder', 1);
        $page['properties']['管理ID']['title'] = array_fill(0, 25, ['plain_text' => 'A']);
        $trash = $this->page('TRASH', 5);
        $trash['in_trash'] = true;
        Http::fake(function ($request) use ($page, $trash) {
            if (str_contains($request->url(), '/properties/')) {
                return Http::response(['object' => 'list', 'results' => [['type' => 'title', 'title' => ['plain_text' => 'COMPLETE']]],
                    'has_more' => false, 'next_cursor' => null]);
            }
            if ($request->method() === 'PATCH') { return Http::response([]); }
            return Http::response(str_ends_with($request->url(), '/query') ? $this->queryResponse([$page, $trash]) : $this->schema());
        });
        self::assertSame(1, $this->synchronize()['updated_count']);
        self::assertSame('COMPLETE', DB::table('notion_inventory_cache')->value('external_id'));
        Http::assertSentCount(4);
    }

    public function test_forward_migration_refuses_down_without_changing_ledger(): void
    {
        $before = DB::table('migrations')->count();
        $migration = require database_path('migrations-v2/2026_10_14_000085_create_v2_notion_inventory_sync.php');
        try { $migration->down(); self::fail('Destructive rollback accepted'); }
        catch (\LogicException $error) { self::assertSame('Notion sync history requires a forward correction migration.', $error->getMessage()); }
        self::assertSame($before, DB::table('migrations')->count());
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasTable('notion_sync_runs'));
    }

    public function test_draw_and_exchange_commit_replay_rollback_and_enqueue_failure_remain_independent(): void
    {
        CarbonImmutable::setTestNow('2026-07-30T00:00:00Z');
        Http::fake();
        app(V2CatalogFixtureImporter::class)->import(json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR));
        $user = \Tests\V2\Fixtures\SmsVerifiedUser::create(['email_display' => 'notion-prize@example.test',
            'email_normalized' => 'notion-prize@example.test', 'email_verified_at' => now(),
            'password_hash' => app(\App\Domain\Identity\Services\V2PasswordPolicy::class)->hash('synthetic-password'),
            'state' => \App\Domain\Identity\Enums\V2UserState::Active]);
        app(\App\Domain\Point\Services\V2PointService::class)->grantFree($user->id, 100000, 'notion-draw-points');
        $this->app->instance(\App\Domain\Draw\Services\V2CryptographicRandomSource::class,
            new \App\Domain\Draw\Services\V2CryptographicRandomSource(static fn (int $minimum, int $maximum): int => $minimum));
        foreach (['first', 'second'] as $key) {
            app(\App\Domain\Draw\Services\V2DrawService::class)->create($user,
                '0198a001-0000-7000-8000-000000000011', 1, 'notion-draw-'.$key, (string) Str::uuid7());
        }
        self::assertDatabaseCount('notion_sync_runs', 0);
        $prizes = DB::table('user_prizes')->where('user_id', $user->id)->orderBy('id')->pluck('public_id');
        $shipping = app(\App\Domain\PrizeShipping\Services\V2PrizeShippingService::class);
        DB::beginTransaction();
        $shipping->exchange($user, [$prizes[0]], 'notion-exchange-rollback', (string) Str::uuid7());
        DB::rollBack();
        self::assertDatabaseCount('notion_sync_runs', 0);
        self::assertSame('stored', DB::table('user_prizes')->where('public_id', $prizes[0])->value('status'));
        $shipping->exchange($user, [$prizes[0]], 'notion-exchange-commit', (string) Str::uuid7());
        self::assertDatabaseCount('notion_sync_runs', 1);
        DB::table('notion_sync_runs')->update(['status' => 'completed', 'completed_at' => now()]);
        self::assertTrue($shipping->exchange($user, [$prizes[0]], 'notion-exchange-commit', (string) Str::uuid7())['idempotent_replay']);
        self::assertDatabaseCount('notion_sync_runs', 1);
        DB::statement('ALTER TABLE notion_sync_runs RENAME TO notion_sync_runs_unavailable');
        try {
            $shipping->exchange($user, [$prizes[1]], 'notion-exchange-no-queue', (string) Str::uuid7());
            self::assertSame('converted', DB::table('user_prizes')->where('public_id', $prizes[1])->value('status'));
        } finally { DB::statement('ALTER TABLE notion_sync_runs_unavailable RENAME TO notion_sync_runs'); }
        Http::assertNothingSent();
    }

    public function test_thousand_pages_with_hundred_updates_measures_real_limiter(): void
    {
        $this->app->forgetInstance(NotionClock::class);
        $pages = [];
        for ($index = 0; $index < 1000; $index++) {
            $pages[] = $this->page('PERF-'.$index, $index < 100 ? 1 : 0);
            DB::table('content_banners')->insert(['public_id' => (string) Str::uuid7(), 'code' => 'PERF-'.$index,
                'external_id' => 'PERF-'.$index, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        }
        $queries = 0;
        $requests = 0;
        Http::fake(function ($request) use ($pages, &$queries, &$requests) {
            $requests++;
            self::assertSame(0, DB::transactionLevel());
            if ($request->method() === 'PATCH') { return Http::response([]); }
            if (str_ends_with($request->url(), '/query')) {
                self::assertSame(100, $request['page_size']);
                self::assertSame($queries === 0 ? null : (string) $queries, $request['start_cursor'] ?? null);
                $offset = $queries++ * 100;
                return Http::response(array_replace($this->queryResponse(array_slice($pages, $offset, 100)),
                    ['has_more' => $queries < 10, 'next_cursor' => $queries < 10 ? (string) $queries : null]));
            }
            return Http::response($this->schema());
        });
        $started = microtime(true);
        $run = $this->synchronize();
        $elapsed = microtime(true) - $started;
        self::assertSame('completed', $run['status']);
        self::assertSame(100, $run['updated_count']);
        self::assertSame(1000, $run['read_count']);
        self::assertDatabaseCount('notion_sync_snapshot', 1000);
        self::assertGreaterThanOrEqual(110 / 3, $elapsed);
        self::assertLessThan(120, $elapsed);
        fwrite(STDERR, json_encode(['notion_mock_performance' => ['pages' => 1000, 'updates' => 100,
            'requests' => $requests, 'seconds' => $elapsed, 'peak_bytes' => memory_get_peak_usage(true)]])."\n");
    }

    private function synchronize(): array
    {
        $run = app(NotionSyncService::class)->create($this->context, (string) Str::uuid7());
        self::assertSame(1, app(NotionSyncWorker::class)->runOnce());

        return app(NotionSyncService::class)->result($run['id']);
    }

    private function schema(): array
    {
        $properties = [];
        foreach (config('v2_notion.properties') as $field => $name) {
            if ($name === null || $name === '') { continue; }
            $properties[$name] = ['id' => $field, 'type' => match ($field) {
                'external_id' => 'title', 'available' => 'formula', 'synced_at' => 'date', default => 'number',
            }];
        }

        return ['object' => 'data_source', 'id' => self::SOURCE, 'in_trash' => false, 'properties' => $properties];
    }

    private function page(string $externalId, int $quantity = 0, ?string $id = null): array
    {
        $properties = $this->schema()['properties'];
        foreach ($properties as $name => &$property) {
            $property += match ($property['type']) {
                'title' => ['title' => [['plain_text' => $externalId]]],
                'formula' => ['formula' => ['type' => 'number', 'number' => -2]],
                default => ['number' => $name === '手持ち枚数' ? 12 : ($name === '当選保管中' ? 0 : $quantity)],
            };
        }
        unset($property);

        return ['object' => 'page', 'id' => $id ?? (string) Str::uuid7(), 'parent' => ['data_source_id' => self::SOURCE],
            'in_trash' => false, 'properties' => $properties];
    }

    private function queryResponse(array $pages): array
    {
        return ['object' => 'list', 'results' => $pages, 'has_more' => false, 'next_cursor' => null,
            'request_status' => ['type' => 'complete']];
    }

    private function fake(array $pages, array $override = [], ?string $failPage = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($pages, $override, $failPage) {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame('https', parse_url($request->url(), PHP_URL_SCHEME));
            self::assertSame('api.notion.com', parse_url($request->url(), PHP_URL_HOST));
            self::assertSame(443, parse_url($request->url(), PHP_URL_PORT) ?? 443);
            self::assertStringStartsWith('/v1/', parse_url($request->url(), PHP_URL_PATH));
            self::assertSame(['2026-03-11'], $request->header('Notion-Version'));
            if ($request->method() === 'PATCH') {
                self::assertSame(['properties'], array_keys($request->data()));
                self::assertArrayNotHasKey('手持ち枚数', $request['properties']);
                self::assertArrayNotHasKey('空き在庫', $request['properties']);
                self::assertArrayNotHasKey('管理ID', $request['properties']);
                return Http::response([], str_ends_with($request->url(), '/'.$failPage) ? 400 : 200);
            }
            return Http::response(str_ends_with($request->url(), '/query') ? array_replace($this->queryResponse($pages), $override) : $this->schema());
        });
    }
}
