<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PrizeImportTest extends TestCase
{
    private V2CatalogMasterMutationService $service;
    private V2AdminAuthorizationContext $context;
    private array $gacha;
    private string $versionId;
    private array $rank;
    private array $banner;
    private array $bannerInput;
    private string $sessionToken;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('V2_PRIZE_IMPORT_CONCURRENCY_TEST') === '1') {
            self::assertTrue(app()->environment('testing'));
            self::assertMatchesRegularExpression('/^oripa_v2_mig[0-9]{3}(?:_|$)/', DB::connection()->getDatabaseName());
            self::assertSame(0, DB::table('catalog_gachas')->count(), 'Use an empty isolated task database.');
        }
        DB::beginTransaction();
        Storage::fake('local');
        config(['cache.default' => 'array', 'filesystems.default' => 'local']);
        app(V2CatalogFixtureImporter::class)->import(json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, 512, JSON_THROW_ON_ERROR));
        $this->context = $this->context();
        $this->service = app(V2CatalogMasterMutationService::class);
        $this->gacha = $this->service->createGachaCore($this->context, (string) Str::uuid7(), [
            'title' => 'CSV Draft', 'category_id' => '0198a001-0000-7000-8000-000000000001',
            'tag_ids' => [], 'price_points' => 100, 'total_count' => 1000, 'daily_draw_limit' => 0,
            'audience_code' => 'all_users', 'presentation_asset_id' => '0198a001-0000-7000-8000-000000000005',
            'publish_start_at' => '2026-08-20T00:00:00Z', 'publish_end_at' => '2027-08-20T00:00:00Z', 'description' => null, 'notices' => null,
        ])['data'];
        $this->versionId = $this->gacha['current_version']['id'];
        $this->rank = $this->service->createRankMaster($this->context, (string) Str::uuid7(), [
            'rank_name' => 'CSV賞', 'lineup_image' => $this->image(), 'result_image' => $this->image(),
        ])['data'];
        $this->service->setGachaRankVideo($this->context, $this->gacha['id'], $this->rank['id'], (string) Str::uuid7(), [
            'video_asset_id' => '0198a001-0000-7000-8000-000000000006',
        ]);
        $content = app(V2ContentContactAdminService::class);
        $category = $content->createBannerCategory($this->context, ['name' => 'CSV Library'], (string) Str::uuid7());
        $asset = $content->uploadBannerAsset($this->context, $this->image(), (string) Str::uuid7());
        $this->bannerInput = ['title' => 'Library name', 'category_id' => $category['id'], 'asset_id' => $asset['id'], 'external_id' => 'CARD-001'];
        $this->banner = $content->createManagedBanner($this->context, $this->bannerInput, (string) Str::uuid7());
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_preview_is_read_only_create_replay_and_history_are_canonical(): void
    {
        $before = $this->snapshot();
        $input = $this->input();
        $plan = $this->preview($input);
        self::assertSame($before, $this->snapshot());
        self::assertSame($plan, $this->preview($input));
        self::assertSame(['create' => 1, 'update' => 0, 'unchanged' => 0], $plan['summary']);
        $key = (string) Str::uuid7();
        $request = [...$input, 'plan_checksum' => $plan['plan_checksum']];
        $result = $this->apply($request, $key);
        $prize = DB::table('catalog_prizes')->where('external_id', 'CARD-001')->firstOrFail();
        self::assertSame('Library name', $prize->display_name);
        self::assertSame($this->bannerInput['asset_id'], DB::table('catalog_presentation_assets')->where('id', $prize->presentation_asset_id)->value('public_id'));
        self::assertTrue((bool) $prize->is_visible);
        $after = $this->snapshot();
        $replay = $this->apply($request, $key);
        self::assertTrue($replay['idempotent_replay']);
        $expected = $result['data'];
        $actual = $replay['data'];
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertSame($after['catalog_prizes'], $this->snapshot()['catalog_prizes']);
        $history = $this->service->prizeImportHistory($this->context, $this->gacha['id'], $this->versionId, null);
        self::assertCount(1, $history['items']);
        self::assertSame('prizes.csv', $history['items'][0]['file_name']);
        self::assertSame($this->context->adminPublicId, $history['items'][0]['actor_public_id']);
        self::assertStringNotContainsString($input['content_base64'], json_encode(DB::table('audit_logs')->get()));
        $this->assertError('IDEMPOTENCY_KEY_REUSED', fn () => $this->apply([...$request, 'file_name' => 'other.csv'], $key));
    }

    public function test_economics_quantity_optional_fields_and_warnings(): void
    {
        $this->import();
        $before = DB::table('catalog_prizes')->where('external_id', 'CARD-001')->firstOrFail();
        $input = $this->input('CARD-001,CSV賞,2,31,41');
        $plan = $this->preview($input);
        self::assertSame(['exchange_points', 'cost_price'], array_column($plan['rows'][0]['changes'], 'field'));
        $this->apply([...$input, 'plan_checksum' => $plan['plan_checksum']]);
        $after = DB::table('catalog_prizes')->where('id', $before->id)->firstOrFail();
        foreach (['display_name', 'presentation_asset_id', 'gacha_rank_id'] as $field) {
            self::assertSame($before->$field, $after->$field);
        }
        $input = $this->input('CARD-001,CSV賞,0,31,41,TRUE,7,Different', ',発送のみ,表示順,カード名');
        $plan = $this->preview($input);
        self::assertSame(['ZERO_QUANTITY', 'CARD_NAME_MISMATCH'], array_column($plan['warnings'], 'code'));
        $result = $this->apply([...$input, 'plan_checksum' => $plan['plan_checksum']]);
        $adjustment = DB::table('prize_inventory_adjustments')->latest('id')->firstOrFail();
        self::assertSame('CSV取込（'.$result['data']['id'].'）', $adjustment->reason);
        self::assertSame(1, (int) $adjustment->after_lock_version);
        self::assertSame(0, (int) $adjustment->after_available_quantity);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'catalog.inventory.adjusted']);
        $unchanged = $this->preview($this->input('CARD-001,CSV賞,0,31,41'));
        self::assertSame(1, $unchanged['summary']['unchanged']);
        self::assertDatabaseHas('catalog_gacha_version_prizes', ['prize_id' => $before->id, 'sort_order' => 7, 'shipping_only' => true]);
    }

    #[DataProvider('invalidRows')]
    public function test_validation_errors_never_mutate(string $row, string $code, string $headers = ''): void
    {
        $before = $this->snapshot();
        $this->assertError('CSV_VALIDATION_FAILED', fn () => $this->preview($this->input($row, $headers)), $code);
        self::assertSame($before, $this->snapshot());
    }

    public static function invalidRows(): array
    {
        return [
            ['bad id,CSV賞,2,3,4', 'EXTERNAL_ID_INVALID'],
            ["CARD-001,CSV賞,2,3,4\nCARD-001,CSV賞,2,3,4", 'EXTERNAL_ID_DUPLICATED'],
            ['MISSING,CSV賞,2,3,4', 'LIBRARY_ENTRY_NOT_FOUND'], ['CARD-001,不存在,2,3,4', 'RANK_NOT_FOUND'],
            ['CARD-001,CSV賞,1001,3,4', 'INVENTORY_TOTAL_EXCEEDED'],
            ['CARD-001,CSV賞,"1,000",3,4', 'VALUE_INVALID'], ['CARD-001,CSV賞,1.5,3,4', 'VALUE_INVALID'],
            ['CARD-001,CSV賞,２,3,4', 'VALUE_INVALID'], ['CARD-001,CSV賞,2,¥100,4', 'VALUE_INVALID'],
            ['CARD-001,CSV賞,2,3,-1', 'VALUE_INVALID'], ['CARD-001,CSV賞,2,3,+1', 'VALUE_INVALID'],
            ['CARD-001,CSV賞,2,3,4,', 'VALUE_INVALID', ',発送のみ'],
            ['CARD-001,CSV賞,2,3,4,0', 'VALUE_INVALID', ',表示順'],
        ];
    }

    public function test_missing_null_and_orphan_prizes(): void
    {
        $this->import();
        $before = $this->snapshot();
        $this->assertError('CSV_VALIDATION_FAILED', fn () => $this->preview($this->input('')), 'PRIZE_MISSING_FROM_CSV');
        self::assertSame($before, $this->snapshot());
        DB::table('catalog_prizes')->where('external_id', 'CARD-001')->update(['external_id' => null, 'revision' => DB::raw('revision + 1')]);
        $this->assertError('CSV_VALIDATION_FAILED', fn () => $this->preview($this->input()), 'PRIZE_MISSING_FROM_CSV');
        $relation = DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $this->version()->id)->first();
        DB::table('prize_inventories')->where('gacha_version_prize_id', $relation->id)->delete();
        DB::table('catalog_gacha_version_prizes')->where('id', $relation->id)->delete();
        self::assertSame(1, $this->preview($this->input())['summary']['create']);
    }

    public function test_stale_library_rank_inventory_and_revision(): void
    {
        $this->import();
        foreach (['library', 'rank', 'inventory', 'version'] as $change) {
            DB::beginTransaction();
            $input = $this->input();
            $plan = $this->preview($input);
            if ($change === 'library') {
                app(V2ContentContactAdminService::class)->updateManagedBanner($this->context, $this->banner['id'], [...$this->bannerInput, 'title' => 'Changed'], (string) Str::uuid7());
            } elseif ($change === 'rank') {
                $this->service->updateRankMaster($this->context, $this->rank['id'], (string) Str::uuid7(), [
                    'expected_revision' => $this->rank['revision'], 'rank_name' => 'Changed', 'show_total_stock' => false, 'status' => 'active',
                ]);
            } elseif ($change === 'inventory') {
                DB::table('prize_inventories')->whereIn('gacha_version_prize_id', DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $this->version()->id)->select('id'))->increment('lock_version');
            } else {
                DB::table('catalog_gacha_versions')->where('id', $this->version()->id)->increment('revision');
            }
            $before = $this->snapshot();
            $this->assertError($change === 'version' ? 'CATALOG_REVISION_CONFLICT' : 'CATALOG_PRIZE_IMPORT_PLAN_STALE', fn () => $this->apply([...$input, 'plan_checksum' => $plan['plan_checksum']]));
            self::assertSame($before, $this->snapshot());
            DB::rollBack();
        }
    }

    #[DataProvider('rollbackStages')]
    public function test_mid_apply_database_failure_rolls_back_every_row_and_success_evidence(bool $update): void
    {
        $content = app(V2ContentContactAdminService::class);
        $content->createManagedBanner($this->context, [...$this->bannerInput, 'external_id' => 'CARD-002'], (string) Str::uuid7());
        $input = $this->input("CARD-001,CSV賞,2,3,4\nCARD-002,CSV賞,2,3,4");
        if ($update) {
            $this->apply([...$input, 'plan_checksum' => $this->preview($input)['plan_checksum']]);
            $input = $this->input("CARD-001,CSV賞,3,5,6\nCARD-002,CSV賞,3,5,6");
        }
        $plan = $this->preview($input);
        DB::unprepared("CREATE FUNCTION prize_import_test_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.external_id = 'CARD-002' THEN RAISE EXCEPTION 'injected import failure'; END IF; RETURN NEW; END $$; CREATE TRIGGER prize_import_test_failure BEFORE INSERT OR UPDATE ON catalog_prizes FOR EACH ROW EXECUTE FUNCTION prize_import_test_failure()");
        $before = $this->snapshot();
        try {
            $this->apply([...$input, 'plan_checksum' => $plan['plan_checksum']]);
            self::fail('Injected failure did not abort');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('injected import failure', $error->getMessage());
            self::assertSame($before, $this->snapshot());
        }
    }

    public static function rollbackStages(): array
    {
        return [[false], [true]];
    }

    public function test_multiple_quantity_updates_have_distinct_adjustment_keys_and_replay_once(): void
    {
        app(V2ContentContactAdminService::class)->createManagedBanner($this->context, [...$this->bannerInput, 'external_id' => 'CARD-002'], (string) Str::uuid7());
        $initial = $this->input("CARD-001,CSV賞,2,3,4\nCARD-002,CSV賞,2,3,4");
        $this->apply([...$initial, 'plan_checksum' => $this->preview($initial)['plan_checksum']]);
        $input = $this->input("CARD-001,CSV賞,3,3,4\nCARD-002,CSV賞,3,3,4");
        $request = [...$input, 'plan_checksum' => $this->preview($input)['plan_checksum']];
        $key = (string) Str::uuid7();
        $result = $this->apply($request, $key);
        $adjustments = DB::table('prize_inventory_adjustments')->where('reason', 'CSV取込（'.$result['data']['id'].'）')->get();
        self::assertCount(2, $adjustments);
        self::assertCount(2, $adjustments->pluck('idempotency_key')->unique());
        self::assertTrue($this->apply($request, $key)['idempotent_replay']);
        self::assertSame(2, DB::table('prize_inventory_adjustments')->where('reason', 'CSV取込（'.$result['data']['id'].'）')->count());
        self::assertSame($input['expected_version_revision'] + 1, (int) $this->version()->revision);
    }

    public function test_rank_not_ready_case_sensitive_library_and_error_cap(): void
    {
        $this->assertError('CSV_VALIDATION_FAILED', fn () => $this->preview($this->input('card-001,CSV賞,2,3,4')), 'LIBRARY_ENTRY_NOT_FOUND');
        app(V2ContentContactAdminService::class)->createManagedBanner($this->context, [...$this->bannerInput, 'external_id' => 'card-001'], (string) Str::uuid7());
        $input = $this->input("CARD-001,CSV賞,2,3,4\ncard-001,CSV賞,2,3,4");
        self::assertSame(2, $this->preview($input)['summary']['create']);
        $gachaRank = DB::table('catalog_gacha_ranks')->where('gacha_id', DB::table('catalog_gachas')->where('public_id', $this->gacha['id'])->value('id'))->first();
        $this->service->unsetGachaRankVideo($this->context, $this->gacha['id'], $this->rank['id'], (string) Str::uuid7(), ['expected_revision' => (int) $gachaRank->revision]);
        $this->assertError('CSV_VALIDATION_FAILED', fn () => $this->preview($this->input()), 'RANK_NOT_READY');
        try {
            $this->preview($this->input(str_repeat("invalid id,不存在,-1,3,4\n", 250)));
            self::fail('Expected validation');
        } catch (V2CatalogException $error) {
            self::assertCount(200, $error->details['errors']);
            self::assertGreaterThan(200, $error->details['error_count']);
        }
    }

    public function test_first_publication_and_type_boundaries(): void
    {
        foreach (['login_daily', 'signup_once'] as $type) {
            $composition = app(\App\Domain\Catalog\Services\V2GachaCompositionService::class);
            $payload = app(\App\Domain\Catalog\Services\V2GachaCopyService::class)->projection('0198a001-0000-7000-8000-000000000011', true);
            $payload = [...$payload, 'gacha_type' => $type, 'price_points' => 0,
                'minimum_exchange_points' => $type === 'login_daily' ? 0 : null, 'category_id' => null,
                'tag_ids' => [], 'total_count' => null, 'daily_draw_limit' => $type === 'login_daily' ? 1 : 0,
                'first_time_eligible_days' => 7, 'allowed_draw_counts' => [1], 'publish_start_at' => '2026-07-01T00:00:00Z'];
            foreach ($payload['prizes'] as &$prize) {
                $prize['percentage'] = '50';
                $prize['external_id'] = null;
            }
            unset($prize);
            $gacha = $composition->save($composition->validate($payload), null, null, $this->context);
            $version = DB::table('catalog_gacha_versions')->where('gacha_id', $gacha->id)->firstOrFail();
            $this->assertError('CATALOG_MUTATION_INVALID', fn () => $this->service->previewPrizeImport(
                $this->context, $gacha->public_id, $version->public_id, [...$this->input(), 'expected_version_revision' => (int) $version->revision]));
        }
        DB::beginTransaction();
        DB::table('catalog_gachas')->where('public_id', $this->gacha['id'])->update(['first_published_at' => now(), 'revision' => DB::raw('revision + 1')]);
        $this->assertError('CATALOG_GACHA_POST_PUBLISH_FIELD_IMMUTABLE', fn () => $this->preview($this->input()));
        DB::rollBack();
        DB::beginTransaction();
        DB::table('catalog_gachas')->where('public_id', $this->gacha['id'])->update(['archived_at' => now(), 'state' => 'disabled', 'revision' => DB::raw('revision + 1')]);
        $this->assertError('CATALOG_RESOURCE_ARCHIVED', fn () => $this->preview($this->input()));
        DB::rollBack();
    }

    public function test_rank_change_image_name_warnings_and_existing_values_preserved(): void
    {
        $this->import();
        $prize = DB::table('catalog_prizes')->where('external_id', 'CARD-001')->firstOrFail();
        $image = DB::table('catalog_presentation_assets')->where('public_id', '0198a001-0000-7000-8000-000000000005')->value('id');
        DB::table('catalog_prizes')->where('id', $prize->id)->update(['display_name' => 'Existing', 'presentation_asset_id' => $image, 'revision' => DB::raw('revision + 1')]);
        DB::table('catalog_gacha_version_prizes')->where('prize_id', $prize->id)->update(['display_name' => 'Existing', 'presentation_asset_id' => $image]);
        $input = $this->input('CARD-001,CSV賞,2,10,11');
        $plan = $this->preview($input);
        self::assertSame(['PRIZE_NAME_MISMATCH', 'PRIZE_IMAGE_MISMATCH'], array_column($plan['warnings'], 'code'));
        $this->apply([...$input, 'plan_checksum' => $plan['plan_checksum']]);
        self::assertDatabaseHas('catalog_prizes', ['id' => $prize->id, 'display_name' => 'Existing', 'presentation_asset_id' => $image]);
        $other = $this->service->createRankMaster($this->context, (string) Str::uuid7(), ['rank_name' => '別賞', 'lineup_image' => $this->image(), 'result_image' => $this->image()])['data'];
        $this->service->setGachaRankVideo($this->context, $this->gacha['id'], $other['id'], (string) Str::uuid7(), ['video_asset_id' => '0198a001-0000-7000-8000-000000000006']);
        $this->assertError('CSV_VALIDATION_FAILED', fn () => $this->preview($this->input('CARD-001,別賞,2,3,4')), 'RANK_CHANGE_NOT_SUPPORTED');
    }

    public function test_scheduled_draft_import_synchronizes_schedule_and_outbox(): void
    {
        $this->import();
        $version = $this->version();
        $gacha = DB::table('catalog_gachas')->where('public_id', $this->gacha['id'])->firstOrFail();
        $this->service->scheduleGachaVersionPublish($this->context, $this->gacha['id'], $this->versionId, (string) Str::uuid7(), [
            'scheduled_for' => now()->addDay()->toIso8601String(), 'expected_revision' => (int) $version->revision, 'expected_gacha_revision' => (int) $gacha->revision,
        ]);
        $input = $this->input('CARD-001,CSV賞,3,4,5');
        $result = $this->apply([...$input, 'plan_checksum' => $this->preview($input)['plan_checksum']]);
        self::assertDatabaseHas('catalog_gacha_publish_schedules', ['gacha_id' => $gacha->id, 'status' => 'scheduled', 'expected_version_revision' => $result['data']['gacha_version_revision']]);
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $this->versionId, 'event_type' => 'catalog.master.prizes_imported']);
    }

    public function test_draft_without_operational_inventory_uses_snapshot_and_canonical_adjustment(): void
    {
        $this->import();
        $relation = DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $this->version()->id)->firstOrFail();
        DB::table('prize_inventories')->where('gacha_version_prize_id', $relation->id)->delete();
        $input = $this->input('CARD-001,CSV賞,3,3,4');
        $this->apply([...$input, 'plan_checksum' => $this->preview($input)['plan_checksum']]);
        self::assertDatabaseHas('prize_inventory_adjustments', ['before_total_quantity' => 2, 'after_total_quantity' => 3, 'before_lock_version' => 0, 'after_lock_version' => 1]);
    }

    public function test_quantity_change_preserves_withdrawals_and_never_creates_negative_availability(): void
    {
        $this->import();
        $relation = DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $this->version()->id)->firstOrFail();
        DB::table('prize_inventories')->where('gacha_version_prize_id', $relation->id)
            ->update(['available_quantity' => 1, 'withdrawn_quantity' => 1, 'lock_version' => 1]);
        $input = $this->input('CARD-001,CSV賞,3,3,4');
        $this->apply([...$input, 'plan_checksum' => $this->preview($input)['plan_checksum']]);
        self::assertDatabaseHas('prize_inventories', ['gacha_version_prize_id' => $relation->id,
            'total_quantity' => 3, 'available_quantity' => 2, 'withdrawn_quantity' => 1, 'lock_version' => 2]);
        $before = $this->snapshot();
        $this->assertError('CSV_VALIDATION_FAILED', fn () => $this->preview($this->input('CARD-001,CSV賞,0,3,4')), 'VALUE_INVALID');
        self::assertSame($before, $this->snapshot());
    }

    public function test_http_contract_permission_csrf_and_idempotency_boundaries(): void
    {
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
        $root = '/admin/api/v2/catalog/gachas/'.$this->gacha['id'].'/versions/'.$this->versionId.'/prize-imports';
        $csrf = str_repeat('a', 64);
        $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->sessionToken)
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf]);
        $input = $this->input();
        $plan = $this->postJson($root.'/preview', $input)->assertOk()->assertJsonPath('summary.create', 1)->json();
        $this->postJson($root, [...$input, 'plan_checksum' => $plan['plan_checksum']])->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->postJson($root.'/preview', $this->input('CARD-001,CSV賞,1.5,3,4'))->assertUnprocessable()
            ->assertJsonPath('code', 'CSV_VALIDATION_FAILED')->assertJsonPath('errors.0.code', 'VALUE_INVALID');
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())->postJson($root, [...$input, 'plan_checksum' => $plan['plan_checksum']])
            ->assertOk()->assertJsonPath('data.summary.create', 1);
        $this->getJson($root)->assertOk()->assertJsonCount(1, 'items');
        $this->withHeader('X-XSRF-TOKEN', 'invalid')->postJson($root.'/preview', $this->input())->assertForbidden();
        DB::table('admins')->where('id', $this->context->adminId)->update(['role' => 'operator']);
        $this->context = new V2AdminAuthorizationContext($this->context->adminId, $this->context->adminPublicId,
            V2AdminRole::Operator, $this->context->sessionIdHash, $this->context->sessionCorrelationHash, $this->context->requestId);
        try {
            $this->preview($this->input());
            self::fail('Operator mutation allowed');
        } catch (\App\Domain\Identity\Exceptions\V2AuthenticationException $error) {
            self::assertSame('AUTHORIZATION_DENIED', $error->errorCode);
        }
    }

    public function test_1000_row_performance_and_bounded_resolution_queries(): void
    {
        $banner = DB::table('content_banners')->where('public_id', $this->banner['id'])->firstOrFail();
        $version = DB::table('content_versions')->where('banner_id', $banner->id)->firstOrFail();
        $link = DB::table('content_version_assets')->where('content_version_id', $version->id)->firstOrFail();
        $banners = [];
        $csv = [];
        for ($index = 0; $index < 1000; $index++) {
            $copy = (array) $banner;
            unset($copy['id']);
            $copy['public_id'] = (string) Str::uuid7();
            $copy['external_id'] = 'PERF-'.$index;
            $copy['code'] = 'perf-'.str_replace('-', '', $copy['public_id']);
            $banners[] = $copy;
            $csv[] = $copy['external_id'].',CSV賞,1,3,4';
        }
        foreach (array_chunk($banners, 100) as $chunk) DB::table('content_banners')->insert($chunk);
        $versions = [];
        foreach (DB::table('content_banners')->where('external_id', 'like', 'PERF-%')->get() as $row) {
            $copy = (array) $version;
            unset($copy['id']);
            $copy['public_id'] = (string) Str::uuid7();
            $copy['banner_id'] = $row->id;
            $versions[] = $copy;
        }
        foreach (array_chunk($versions, 100) as $chunk) DB::table('content_versions')->insert($chunk);
        $links = [];
        foreach (DB::table('content_versions')->whereIn('public_id', array_column($versions, 'public_id'))->get() as $row) {
            $copy = (array) $link;
            unset($copy['id']);
            $copy['content_version_id'] = $row->id;
            $links[] = $copy;
        }
        foreach (array_chunk($links, 100) as $chunk) DB::table('content_version_assets')->insert($chunk);
        $input = $this->input(implode("\n", $csv));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = microtime(true);
        $plan = $this->preview($input);
        $previewSeconds = microtime(true) - $started;
        $previewQueries = count(DB::getQueryLog());
        self::assertLessThanOrEqual(30, $previewQueries);
        DB::flushQueryLog();
        $started = microtime(true);
        $result = $this->apply([...$input, 'plan_checksum' => $plan['plan_checksum']]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $applySeconds = microtime(true) - $started;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $queryTimings = [];
        foreach ($queries as $query) {
            preg_match('/(?:from|into|update) "([^"]+)"/i', $query['query'], $table);
            $group = $table[1] ?? 'other';
            $queryTimings[$group] = ($queryTimings[$group] ?? 0) + $query['time'];
        }
        $resolution = array_filter($queries, static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select') && preg_match('/"(?:catalog_prizes|catalog_rank_masters|content_banners|content_versions)"/', $query['query']));
        self::assertLessThanOrEqual(12, count($resolution));
        self::assertSame(1000, $result['data']['summary']['create']);
        self::assertSame(1000, DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $this->version()->id)->count());
        fwrite(STDOUT, json_encode(['f3_performance' => ['rows' => 1000, 'preview_seconds' => $previewSeconds, 'apply_seconds' => $applySeconds,
            'preview_queries' => $previewQueries, 'apply_queries' => count($queries), 'resolution_queries' => count($resolution),
            'query_milliseconds' => $queryTimings]], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_real_concurrent_apply_serializes_one_winner_and_one_revision_conflict(): void
    {
        if (getenv('V2_PRIZE_IMPORT_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the explicit isolated Prize import concurrency run.');
        }
        self::assertTrue(function_exists('pcntl_fork'));
        $input = $this->input();
        $request = [...$input, 'plan_checksum' => $this->preview($input)['plan_checksum']];
        DB::commit();
        DB::disconnect();
        $start = microtime(true) + 0.5;
        $workers = [];
        foreach ([1, 2] as $worker) {
            $path = tempnam(sys_get_temp_dir(), 'prize-import-race-');
            $process = pcntl_fork();
            self::assertNotSame(-1, $process);
            if ($process === 0) {
                while (microtime(true) < $start) usleep(1000);
                DB::reconnect();
                DB::statement("SET statement_timeout = '15s'");
                try {
                    $this->apply($request);
                    $outcome = 'completed';
                } catch (V2CatalogException $error) {
                    $outcome = $error->errorCode;
                } catch (\Throwable $error) {
                    $outcome = get_class($error);
                }
                file_put_contents($path, $outcome);
                DB::disconnect();
                exit(0);
            }
            $workers[] = [$process, $path];
        }
        $outcomes = [];
        foreach ($workers as [$process, $path]) {
            pcntl_waitpid($process, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $outcomes[] = file_get_contents($path);
            unlink($path);
        }
        DB::reconnect();
        sort($outcomes);
        self::assertSame(['CATALOG_REVISION_CONFLICT', 'completed'], $outcomes);
        self::assertSame(1, DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $this->version()->id)->count());
        self::assertSame(1, DB::table('audit_logs')->where('action_code', 'catalog.gacha.prizes.imported')->count());
        self::assertSame($input['expected_version_revision'] + 1, (int) $this->version()->revision);
    }

    private function input(string $rows = 'CARD-001,CSV賞,2,3,4', string $headers = ''): array
    {
        return ['file_name' => 'prizes.csv', 'content_base64' => base64_encode("管理ID,ランク,枚数,交換ポイント,原価".$headers."\n".$rows), 'expected_version_revision' => (int) $this->version()->revision];
    }

    private function preview(array $input): array
    {
        return $this->service->previewPrizeImport($this->context, $this->gacha['id'], $this->versionId, $input);
    }

    private function apply(array $input, ?string $key = null): array
    {
        return $this->service->applyPrizeImport($this->context, $this->gacha['id'], $this->versionId, $key ?? (string) Str::uuid7(), $input);
    }

    private function import(): void
    {
        $input = $this->input();
        $this->apply([...$input, 'plan_checksum' => $this->preview($input)['plan_checksum']]);
    }

    private function version(): object
    {
        return DB::table('catalog_gacha_versions')->where('public_id', $this->versionId)->firstOrFail();
    }

    private function assertError(string $code, callable $callback, ?string $detail = null): void
    {
        try {
            $callback();
            self::fail('Expected '.$code);
        } catch (V2CatalogException $error) {
            self::assertSame($code, $error->errorCode);
            if ($detail !== null) self::assertContains($detail, array_column($error->details['errors'], 'code'));
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['catalog_gachas', 'catalog_gacha_versions', 'catalog_prizes', 'catalog_gacha_version_prizes', 'prize_inventories', 'prize_inventory_adjustments', 'audit_logs', 'idempotency_records', 'outbox_messages', 'catalog_gacha_publish_schedules'] as $table) {
            $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()));
        }
        return $result;
    }

    private function image(): array
    {
        return ['file_name' => 'sample.png', 'mime_type' => 'image/png', 'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='];
    }

    private function context(): V2AdminAuthorizationContext
    {
        $admin = Admin::query()->create(['email_display' => 'import@example.test', 'email_normalized' => 'import@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => V2AdminRole::Owner, 'state' => V2AdminState::Active]);
        $sessions = app(\App\Domain\Identity\Services\V2SessionPolicy::class);
        $this->sessionToken = $sessions->issueOpaqueSessionId();
        $hash = $sessions->hashSessionId($this->sessionToken);
        DB::table('admin_sessions')->insert(['session_id_hash' => $hash, 'admin_id' => $admin->id, 'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false, 'created_at' => now()->subHour(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(7), 'revoked_at' => null]);
        return new V2AdminAuthorizationContext((int) $admin->id, $admin->public_id, $admin->role, $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash), (string) Str::uuid7());
    }
}
