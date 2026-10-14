<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CardInventoryService;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\V2TimestampFixture;
use Tests\TestCase;

final class CardInventoryTest extends TestCase
{
    private V2AdminAuthorizationContext $context;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-30T00:00:00Z');
        Storage::fake('local');
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR);
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = 5000;
        $fixture['gacha_prizes'][0]['initial_inventory'] = 500;
        $fixture['gacha_prizes'][1]['initial_inventory'] = 4500;
        app(V2CatalogFixtureImporter::class)->import($fixture);
        DB::table('catalog_prizes')->update(['external_id' => 'CARD-001', 'revision' => DB::raw('revision + 1')]);
        $this->createAdminSession(V2AdminRole::Operator);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_totals_api_details_and_no_business_writes(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void { $queries[] = $query->sql; });
        $list = $this->service()->cards($this->context);
        self::assertCount(1, $list['items']);
        self::assertSame(5000, $list['items'][0]['allocation']);
        self::assertSame(5000, $list['items'][0]['required_quantity']);
        self::assertNull($list['items'][0]['notion_on_hand']);
        self::assertNull($list['items'][0]['asset']);
        self::assertLessThanOrEqual(3, count($queries));
        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression('/\b(insert|update|delete|for update|for share)\b/i', $query);
        }
        $detail = $this->service()->card($this->context, 'CARD-001');
        self::assertSame($list['items'][0], $detail['data']);
        self::assertCount(1, $detail['breakdown']);
        self::assertSame(5000, $detail['breakdown'][0]['unwon']);
        foreach (V2AdminRole::cases() as $role) {
            $this->createAdminSession($role);
            $this->api('/inventory/cards')->assertOk()->assertJsonPath('items.0.external_id', 'CARD-001');
            $this->api('/inventory/cards/CARD-001')->assertOk();
            $this->api('/inventory/unlinked-prizes')->assertOk();
        }
    }

    #[DataProvider('managementStates')]
    public function test_management_selection(string $status, bool $archived, int $expected): void
    {
        if ($archived || $status === 'scheduled') {
            DB::table('catalog_gachas')->update(['management_status' => 'unpublished', 'revision' => DB::raw('revision + 1')]);
            $this->additionalDraft($status, $archived);
            self::assertSame($expected, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);

            return;
        }
        DB::table('catalog_gachas')->update([
            'management_status' => $status, 'archived_at' => $archived ? now() : null,
            'revision' => DB::raw('revision + 1'),
        ]);
        if (in_array($status, ['draft', 'scheduled'], true)) {
            $this->draftVersion(600);
        }
        self::assertSame($expected, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);
    }

    public static function managementStates(): array
    {
        return [
            ['published', false, 5000], ['sales_paused', false, 5000],
            ['unpublished', false, 0], ['published', true, 0],
            ['draft', false, 600], ['scheduled', false, 600], ['draft', true, 0],
        ];
    }

    public function test_next_draft_old_prizes_and_withdrawn_inventory_are_not_added(): void
    {
        $this->draftVersion(600);
        DB::table('prize_inventories')->where('id', DB::table('prize_inventories')->min('id'))->update([
            'available_quantity' => DB::raw('available_quantity - 3'),
            'withdrawn_quantity' => 3,
        ]);
        self::assertSame(4997, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);
    }

    public function test_published_missing_inventory_fails_closed(): void
    {
        DB::table('prize_inventories')->delete();
        $this->expectException(V2CatalogException::class);
        $this->expectExceptionMessage('The selected inventory is inconsistent.');
        $this->service()->cards($this->context);
    }

    public function test_sale_end_does_not_release_published_allocation(): void
    {
        CarbonImmutable::setTestNow('2027-02-01T00:00:00Z');
        $this->createAdminSession(V2AdminRole::Operator);
        self::assertSame(5000, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);
    }

    public function test_return_to_draft_uses_missing_inventory_fallback(): void
    {
        DB::table('catalog_gachas')->update(['management_status' => 'unpublished', 'revision' => DB::raw('revision + 1')]);
        self::assertSame(0, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);
        $this->draftVersion(600);
        DB::table('catalog_gachas')->update(['management_status' => 'draft', 'revision' => DB::raw('revision + 1')]);
        self::assertSame(600, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);
    }

    #[DataProvider('ownershipStates')]
    public function test_ownership_statuses_and_qa_exclusion(string $status, int $stored, int $shipped, int $returned): void
    {
        $this->draw();
        DB::table('user_prizes')->update(['status' => $status, 'exchanged_point_amount' => $status === 'converted' ? 0 : null]);
        $card = $this->service()->card($this->context, 'CARD-001');
        self::assertSame($stored, $card['data']['stored']);
        self::assertSame($shipped, $card['data']['shipped']);
        self::assertSame($returned, $card['data']['returned']);
        self::assertSame(4999 + $stored, $card['data']['required_quantity']);
        self::assertSame(1, $card['status_counts']->{$status});
        DB::table('user_prizes')->update(['status' => 'converted', 'exchanged_point_amount' => 0]);
        $qaUser = $this->draw(true);
        DB::table('user_prizes')->where('user_id', $qaUser->id)->update(['status' => $status, 'exchanged_point_amount' => $status === 'converted' ? 0 : null]);
        $qa = $this->service()->card($this->context, 'CARD-001');
        self::assertSame(0, $qa['data']['stored']);
        self::assertSame(0, $qa['data']['shipped']);
        self::assertSame(0, $qa['data']['returned']);
    }

    public static function ownershipStates(): array
    {
        return [
            ['stored', 1, 0, 0], ['exchange_processing', 1, 0, 0], ['shipping_requested', 1, 0, 0],
            ['packing', 1, 0, 0], ['hold', 1, 0, 0], ['converted', 0, 0, 0], ['expired', 0, 0, 0],
            ['shipped', 0, 1, 0], ['delivered', 0, 1, 0], ['return_requested', 0, 1, 0], ['returned', 0, 0, 1],
        ];
    }

    public function test_draw_and_exchange_transitions_preserve_then_reduce_required_quantity(): void
    {
        $before = $this->service()->card($this->context, 'CARD-001')['data'];
        $user = $this->draw();
        $after = $this->service()->card($this->context, 'CARD-001')['data'];
        self::assertSame($before['required_quantity'], $after['required_quantity']);
        self::assertSame($before['allocation'] - 1, $after['allocation']);
        app(V2PrizeShippingService::class)->exchange($user, [DB::table('user_prizes')->value('public_id')], 'f5-exchange-synthetic-0001', (string) Str::uuid7());
        self::assertSame(4999, $this->service()->card($this->context, 'CARD-001')['data']['required_quantity']);
    }

    public function test_unlinked_ownership_survives_unpublication(): void
    {
        DB::table('catalog_prizes')->update(['external_id' => null, 'revision' => DB::raw('revision + 1')]);
        $this->draw();
        DB::table('catalog_gachas')->update(['management_status' => 'unpublished', 'revision' => DB::raw('revision + 1')]);
        $unlinked = $this->service()->unlinkedPrizes($this->context);
        self::assertSame(1, $unlinked['total_count']);
        self::assertSame(1, $unlinked['items'][0]['quantity']);
        self::assertSame(0, $unlinked['items'][0]['allocation']);
        self::assertSame([], $this->service()->cards($this->context)['items']);
    }

    public function test_cursor_filters_validation_and_not_connected_filter(): void
    {
        DB::table('catalog_prizes')->where('id', DB::table('catalog_prizes')->max('id'))->update(['external_id' => 'CARD-002', 'revision' => DB::raw('revision + 1')]);
        $first = $this->service()->cards($this->context, ['limit' => 1]);
        $second = $this->service()->cards($this->context, ['limit' => 1, 'cursor' => $first['next_cursor']]);
        self::assertSame('CARD-001', $first['items'][0]['external_id']);
        self::assertSame('CARD-002', $second['items'][0]['external_id']);
        self::assertNull($second['next_cursor']);
        self::assertSame([], $this->service()->cards($this->context, ['negative_only' => true])['items']);
        self::assertCount(1, $this->service()->cards($this->context, ['external_id' => '002', 'reserved_only' => true])['items']);
        $this->api('/inventory/cards?cursor=tampered')->assertStatus(422);
        $this->api('/inventory/cards?limit=1&name=changed&cursor='.urlencode($first['next_cursor']))->assertStatus(422);
        $this->api('/inventory/cards/invalid!')->assertStatus(422);
        $this->api('/inventory/cards/UNKNOWN')->assertNotFound();
        $this->api('/inventory/cards?reserved_only=invalid')->assertStatus(422);
        $this->api('/inventory/cards?limit=101')->assertStatus(422);
    }

    public function test_unauthenticated_api_and_service_cannot_read(): void
    {
        $this->getJson('/admin/api/v2/inventory/cards')->assertUnauthorized();
        $context = new V2AdminAuthorizationContext(999999, (string) Str::uuid7(), V2AdminRole::Owner, str_repeat('f', 64), str_repeat('e', 64), (string) Str::uuid7());
        $this->expectException(\App\Domain\Identity\Exceptions\V2AuthenticationException::class);
        $this->service()->cards($context);
    }

    public function test_cross_gacha_allocation_and_removed_or_orphan_prizes(): void
    {
        $gachaId = $this->additionalDraft();
        self::assertSame(5600, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);
        $prize = (array) DB::table('catalog_prizes')->where('gacha_id', $gachaId)->first();
        $prizeId = $prize['id'];
        unset($prize['id']);
        DB::table('catalog_prizes')->insert([...$prize, 'public_id' => (string) Str::uuid7(), 'code' => 'orphan-'.Str::uuid7()]);
        self::assertSame(5600, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);
        DB::table('catalog_gacha_version_prizes')->where('prize_id', $prizeId)->delete();
        DB::table('catalog_prizes')->where('id', $prizeId)->update(['archived_at' => now(), 'is_visible' => false, 'revision' => DB::raw('revision + 1')]);
        self::assertSame(5000, $this->service()->card($this->context, 'CARD-001')['data']['allocation']);
        self::assertSame(0, $this->service()->unlinkedPrizes($this->context)['total_count']);
    }

    public function test_library_only_identity_latest_name_and_canonical_image(): void
    {
        $bannerId = DB::table('content_banners')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'code' => 'inventory-banner', 'status' => 'draft',
            'external_id' => 'BANNER-001', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = ['public_id' => (string) Str::uuid7(), 'banner_id' => $bannerId, 'version_number' => 1,
            'content_checksum' => hash('sha256', 'synthetic-card-library'), 'created_by_admin_id' => $this->context->adminId,
            'title' => 'Old card title', 'status' => 'draft', 'publish_start_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('content_versions')->insert($version);
        $versionId = DB::table('content_versions')->insertGetId([...$version, 'public_id' => (string) Str::uuid7(), 'version_number' => 2, 'title' => 'Latest card title']);
        $asset = DB::table('catalog_presentation_assets')->where('media_type', 'image')->where('is_public', true)->first();
        DB::table('content_version_assets')->insert(['content_version_id' => $versionId, 'presentation_asset_id' => $asset->id,
            'usage_type' => 'image', 'created_at' => now(), 'updated_at' => now()]);
        $result = $this->service()->cards($this->context, ['name' => 'Latest']);
        self::assertCount(1, $result['items']);
        self::assertSame(0, $result['items'][0]['required_quantity']);
        self::assertSame('Latest card title', $result['items'][0]['name']);
        self::assertSame('/api/v2/content/assets/'.$asset->public_id, $result['items'][0]['asset']['public_path']);
        self::assertStringNotContainsString('storage_identifier', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame([], $this->service()->cards($this->context, ['name' => 'Old card'])['items']);
    }

    public function test_one_thousand_management_ids_are_bounded_and_page_without_gaps(): void
    {
        $this->loadIdentities();
        $started = hrtime(true);
        $seen = [];
        $cursor = null;
        do {
            $page = $this->service()->cards($this->context, ['external_id' => 'LOAD-', 'limit' => 100, 'cursor' => $cursor]);
            self::assertLessThanOrEqual(100, count($page['items']));
            foreach ($page['items'] as $card) {
                self::assertArrayNotHasKey($card['external_id'], $seen);
                $seen[$card['external_id']] = true;
            }
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        self::assertCount(1000, $seen);
        fwrite(STDERR, json_encode(['f5_1000_ids_ms' => (hrtime(true) - $started) / 1000000,
            'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_public_catalog_never_exposes_inventory_management_fields(): void
    {
        $response = $this->getJson('/api/v2/gachas')->assertOk();
        foreach (['external_id', 'required_quantity', 'notion_on_hand', 'allocation'] as $field) {
            self::assertStringNotContainsString('"'.$field.'"', $response->getContent());
        }
    }

    public function test_snapshot_export_remains_consistent_during_a_committed_draw(): void
    {
        if (getenv('V2_F5_SNAPSHOT_TEST') !== '1') {
            self::markTestSkipped('Run separately against the dedicated empty F5 snapshot database.');
        }
        self::assertSame('oripa_v2_f5_snapshot_synthetic', DB::connection()->getDatabaseName());
        $this->loadIdentities();
        DB::table('catalog_prizes')->update(['external_id' => 'ZZ-CARD', 'revision' => DB::raw('revision + 1')]);
        $user = $this->draw();
        DB::commit();
        $triggered = false;
        $queries = [];
        DB::listen(function ($query) use (&$triggered, &$queries, $user): void {
            $queries[] = $query->sql;
            if ($triggered || ! str_starts_with($query->sql, 'WITH draft_versions')) {
                return;
            }
            $triggered = true;
            $program = <<<'PHP'
                require 'vendor/autoload.php';
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                Carbon\CarbonImmutable::setTestNow('2026-07-30T00:00:00Z');
                $app->instance(App\Domain\Draw\Services\V2CryptographicRandomSource::class,
                    new App\Domain\Draw\Services\V2CryptographicRandomSource(static fn (int $minimum, int $maximum): int => $minimum));
                $user = App\Models\V2\User::where('public_id', $argv[1])->firstOrFail();
                app(App\Domain\Draw\Services\V2DrawService::class)->create($user,
                    '0198a001-0000-7000-8000-000000000011', 1, 'f5-concurrent-draw-0001', (string) Illuminate\Support\Str::uuid7());
                PHP;
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $program, $user->public_id], base_path());
            $process->mustRun();
        });
        try {
            $export = $this->service()->snapshotExport();
            self::assertTrue($triggered);
            self::assertSame(0, DB::transactionLevel());
            $count = 0;
            while (($line = fgets($export['stream'])) !== false) {
                $card = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if ($card['external_id'] === 'ZZ-CARD') {
                    self::assertSame(4999, $card['allocation']);
                    self::assertSame(1, $card['stored']);
                }
                $count++;
            }
            fclose($export['stream']);
            self::assertSame(1001, $count);
            foreach ($queries as $query) {
                self::assertDoesNotMatchRegularExpression('/\b(insert|update|delete|for update|for share)\b/i', $query);
            }
            self::assertSame(2, $this->service()->card($this->context, 'ZZ-CARD')['data']['stored']);
        } finally {
            DB::beginTransaction();
        }
    }

    private function loadIdentities(): void
    {
        for ($batch = 0; $batch < 10; $batch++) {
            $rows = [];
            for ($offset = 0; $offset < 100; $offset++) {
                $externalId = sprintf('LOAD-%04d', $batch * 100 + $offset);
                $rows[] = ['public_id' => (string) Str::uuid7(), 'code' => $externalId, 'external_id' => $externalId,
                    'status' => 'draft', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('content_banners')->insert($rows);
        }
    }

    private function draftVersion(int $quantity): void
    {
        $original = (array) DB::table('catalog_gacha_versions')->first();
        unset($original['id']);
        $original = [...$original, 'public_id' => (string) Str::uuid7(), 'version_number' => 2, 'status' => 'draft', 'published_probability_version_id' => null, 'published_at' => null];
        $versionId = DB::table('catalog_gacha_versions')->insertGetId($original);
        $relation = (array) DB::table('catalog_gacha_version_prizes')->first();
        unset($relation['id']);
        DB::table('catalog_gacha_version_prizes')->insert([...$relation, 'gacha_version_id' => $versionId, 'initial_inventory' => $quantity]);
    }

    private function draw(bool $qa = false): \App\Models\V2\User
    {
        $user = \Tests\V2\Fixtures\SmsVerifiedUser::create([
            'email_display' => 'inventory-'.Str::uuid7().'@example.test',
            'email_normalized' => 'inventory-'.Str::uuid7().'@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic inventory password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree($user->id, 100000, 'inventory-points-'.Str::uuid7());
        $this->app->instance(V2CryptographicRandomSource::class, new V2CryptographicRandomSource(static fn (int $minimum, int $maximum): int => $minimum));
        if ($qa) {
            $this->createAdminSession(V2AdminRole::Owner);
            $service = app(\App\Domain\QaDraw\Services\V2QaDrawAdminService::class);
            $service->saveMode($this->context, $user->public_id, 'Synthetic inventory QA');
            $service->createPlan($this->context, $user->public_id, '0198a001-0000-7000-8000-000000000011',
                'Synthetic inventory QA', 'Synthetic inventory QA', null, now()->addHours(2)->toIso8601String(), [
                    ['prize_id' => '0198a001-0000-7000-8000-000000000009', 'quantity' => 1, 'sort_order' => 1,
                        'fixed_image_asset_id' => null, 'fixed_video_asset_id' => null],
                ]);
        }
        app(V2DrawService::class)->create($user, '0198a001-0000-7000-8000-000000000011', 1, 'inventory-draw-'.Str::uuid7(), (string) Str::uuid7());

        return $user;
    }

    private function api(string $path): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();

        return $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->token)->getJson('/admin/api/v2'.$path);
    }

    private function additionalDraft(string $status = 'draft', bool $archived = false): int
    {
        $master = (array) DB::table('catalog_gachas')->orderBy('id')->first();
        unset($master['id']);
        $suffix = (string) Str::uuid7();
        $gachaId = DB::table('catalog_gachas')->insertGetId([
            ...$master, 'public_id' => $suffix, 'code' => $suffix, 'slug' => $suffix,
            'public_code' => 'F'.substr(str_replace('-', '', $suffix), -10),
            'management_status' => $status, 'state' => $archived ? 'disabled' : 'draft',
            'archived_at' => $archived ? now() : null, 'published_version_id' => null,
            'active_draw_state_id' => null, 'first_published_at' => null, 'sold_count' => 0,
            'scheduled_start_at' => $status === 'scheduled' ? '2027-01-01T00:00:00Z' : null,
        ]);
        $rank = (array) DB::table('catalog_gacha_ranks')->orderBy('id')->first();
        unset($rank['id']);
        $rankId = DB::table('catalog_gacha_ranks')->insertGetId([...$rank, 'public_id' => (string) Str::uuid7(), 'gacha_id' => $gachaId, 'current_video_revision_id' => null]);
        $video = (array) DB::table('catalog_gacha_rank_video_revisions')->where('id', $rank['current_video_revision_id'])->first();
        unset($video['id']);
        $videoId = DB::table('catalog_gacha_rank_video_revisions')->insertGetId([...$video, 'gacha_rank_id' => $rankId]);
        DB::table('catalog_gacha_ranks')->where('id', $rankId)->update(['current_video_revision_id' => $videoId, 'revision' => DB::raw('revision + 1')]);
        $prize = (array) DB::table('catalog_prizes')->orderBy('id')->first();
        unset($prize['id']);
        $prizeId = DB::table('catalog_prizes')->insertGetId([...$prize, 'public_id' => (string) Str::uuid7(), 'code' => $suffix, 'gacha_id' => $gachaId, 'gacha_rank_id' => $rankId]);
        $version = (array) DB::table('catalog_gacha_versions')->orderBy('id')->first();
        unset($version['id']);
        $versionId = DB::table('catalog_gacha_versions')->insertGetId([...$version,
            'public_id' => (string) Str::uuid7(), 'gacha_id' => $gachaId, 'status' => 'draft',
            'published_probability_version_id' => null, 'published_at' => null,
        ]);
        $relation = (array) DB::table('catalog_gacha_version_prizes')->orderBy('id')->first();
        unset($relation['id']);
        DB::table('catalog_gacha_version_prizes')->insert([...$relation,
            'gacha_version_id' => $versionId, 'prize_id' => $prizeId, 'gacha_rank_id' => $rankId,
            'initial_inventory' => 600, 'published_rank_revision_id' => null, 'published_video_revision_id' => null,
        ]);

        return $gachaId;
    }

    private function service(): V2CardInventoryService
    {
        return app(V2CardInventoryService::class);
    }

    private function createAdminSession(V2AdminRole $role): void
    {
        $publicId = (string) Str::uuid7();
        $email = $publicId.'@example.test';
        $adminId = DB::table('admins')->insertGetId([
            'public_id' => $publicId, 'email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic inventory password'),
            'role' => $role->value, 'state' => 'active',
        ]);
        $policy = app(V2SessionPolicy::class);
        $this->token = $policy->issueOpaqueSessionId();
        $hash = $policy->hashSessionId($this->token);
        DB::table('admin_sessions')->insert(V2TimestampFixture::attributes([
            'session_id_hash' => $hash, 'admin_id' => $adminId, 'mfa_verified_at' => now(), 'requires_mfa_enrollment' => false,
            'created_at' => now()->subSecond(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->subSecond()->addHours(8),
        ]));
        $this->context = new V2AdminAuthorizationContext($adminId, $publicId, $role, $hash, str_repeat('a', 64), (string) Str::uuid7());
    }
}
