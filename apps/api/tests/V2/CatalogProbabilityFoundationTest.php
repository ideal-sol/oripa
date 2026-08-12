<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogProbabilityFoundationTest extends TestCase
{
    private const TABLES = [
        'catalog_categories',
        'catalog_tags',
        'catalog_ranks',
        'catalog_presentation_assets',
        'catalog_rank_assets',
        'catalog_prizes',
        'catalog_gachas',
        'catalog_gacha_tags',
        'catalog_gacha_versions',
        'catalog_gacha_version_tags',
        'catalog_gacha_version_prizes',
        'catalog_probability_versions',
        'catalog_probability_stages',
        'catalog_probability_entries',
        'catalog_minimum_guarantees',
        'catalog_import_runs',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-28T00:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_catalog_schema_is_v2_only_and_strict(): void
    {
        foreach (self::TABLES as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing V2 Catalog table: {$table}");
            self::assertFalse(Schema::hasColumn($table, 'tenant_id'));
        }
        self::assertTrue(Schema::hasColumn('catalog_prizes', 'cost_price'));
        self::assertFalse(Schema::hasColumn('catalog_probability_entries', 'no_prize'));
        self::assertTrue(Schema::hasColumn('catalog_gachas', 'public_id'));
        self::assertTrue(Schema::hasColumn('catalog_gachas', 'public_code'));
        self::assertTrue(Schema::hasColumn('catalog_gachas', 'management_status'));
        self::assertTrue(Schema::hasColumn('catalog_gacha_versions', 'category_id'));
        self::assertTrue(Schema::hasColumn('catalog_gacha_versions', 'first_time_eligible_days'));
        self::assertTrue(Schema::hasColumn('catalog_gacha_versions', 'allowed_draw_counts'));
        self::assertTrue(Schema::hasColumn('catalog_presentation_assets', 'storage_identifier'));
        self::assertTrue(Schema::hasColumn('catalog_presentation_assets', 'checksum_sha256'));
        self::assertSame(
            'O',
            DB::table('pg_trigger')
                ->where('tgname', 'catalog_gachas_protect_draft_mutation')
                ->value('tgenabled')
        );
    }

    public function test_fixture_import_order_checksum_and_replay_are_deterministic(): void
    {
        $fixture = $this->fixture();
        $first = app(V2CatalogFixtureImporter::class)->import($fixture);
        $counts = $this->catalogCounts();
        $second = app(V2CatalogFixtureImporter::class)->import($fixture);

        self::assertFalse($first['replay']);
        self::assertTrue($second['replay']);
        self::assertSame($first['public_id'], $second['public_id']);
        self::assertSame($first['checksum'], $second['checksum']);
        self::assertSame(29, $first['imported_count']);
        self::assertSame($counts, $this->catalogCounts());
        self::assertSame(1, DB::table('catalog_import_runs')->count());
        self::assertSame(7, (int) DB::table('catalog_gacha_versions')->value('first_time_eligible_days'));
        self::assertSame(
            [1, 5, 10],
            json_decode(
                (string) DB::table('catalog_gacha_versions')->value('allowed_draw_counts'),
                true,
                8,
                JSON_THROW_ON_ERROR
            )
        );
        self::assertSame(
            $fixture['assets'][0]['checksum_sha256'],
            DB::table('catalog_presentation_assets')
                ->where('storage_identifier', 'fixture/catalog/gacha-main.txt')
                ->value('checksum_sha256')
        );
    }

    public function test_public_catalog_resolves_canonical_code_and_legacy_uuid(): void
    {
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        $gacha = DB::table('catalog_gachas')->first(['public_id', 'public_code']);

        self::assertNotNull($gacha);
        self::assertSame('Ab3Def7Gh9J', $gacha->public_code);

        $catalog = app(V2CatalogReadService::class);
        self::assertSame($gacha->public_id, $catalog->getByPublicId($gacha->public_code)['id']);
        self::assertSame($gacha->public_id, $catalog->getByPublicId($gacha->public_id)['id']);
        $this->getJson('/api/v2/gachas/'.$gacha->public_code)
            ->assertOk()
            ->assertJsonPath('data.id', $gacha->public_id);
        $this->getJson('/api/v2/gacha-presentations/'.$gacha->public_code)
            ->assertOk()
            ->assertJsonPath('data.gacha_id', $gacha->public_id);
    }

    public function test_probability_stage_below_one_million_ppm_is_rejected(): void
    {
        $fixture = $this->fixture();
        $fixture['probability_versions'][0]['stages'][0]['entries'][0]['probability_ppm']--;

        $this->expectException(V2CatalogException::class);
        app(V2CatalogFixtureImporter::class)->import($fixture);
    }

    public function test_probability_stage_above_one_million_ppm_is_rejected(): void
    {
        $fixture = $this->fixture();
        $fixture['probability_versions'][0]['stages'][0]['entries'][0]['probability_ppm']++;

        $this->expectException(V2CatalogException::class);
        app(V2CatalogFixtureImporter::class)->import($fixture);
    }

    public function test_invalid_result_type_and_negative_master_values_are_rejected(): void
    {
        $fixture = $this->fixture();
        $fixture['probability_versions'][0]['stages'][0]['entries'][0]['result_type'] =
            'no_prize';

        $this->expectException(V2CatalogException::class);
        app(V2CatalogFixtureImporter::class)->import($fixture);
    }

    public function test_published_gacha_probability_and_children_are_immutable(): void
    {
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        $versionId = DB::table('catalog_gacha_versions')->value('id');
        $probabilityId = DB::table('catalog_probability_versions')->value('id');
        $stageId = DB::table('catalog_probability_stages')->value('id');

        foreach ([
            fn () => DB::table('catalog_gacha_versions')->where('id', $versionId)
                ->update(['title' => 'mutated']),
            fn () => DB::table('catalog_probability_versions')->where('id', $probabilityId)
                ->update(['snapshot_sha256' => str_repeat('2', 64)]),
            fn () => DB::table('catalog_probability_entries')
                ->where('probability_stage_id', $stageId)
                ->update(['probability_ppm' => 1]),
        ] as $mutation) {
            DB::statement('SAVEPOINT catalog_immutable');
            try {
                $mutation();
                self::fail('Published Catalog data must be immutable.');
            } catch (QueryException) {
                DB::statement('ROLLBACK TO SAVEPOINT catalog_immutable');
                self::assertTrue(true);
            }
        }
    }

    public function test_duplicate_codes_slugs_and_invalid_publication_period_are_rejected(): void
    {
        app(V2CatalogFixtureImporter::class)->import($this->fixture());

        DB::statement('SAVEPOINT catalog_duplicate');
        try {
            DB::table('catalog_categories')->insert([
                'public_id' => '0198a001-0000-7000-8000-000000000099',
                'code' => 'cards',
                'slug' => 'other',
                'display_name' => 'Duplicate',
            ]);
            self::fail('Duplicate Category code must fail.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT catalog_duplicate');
        }

        $this->expectException(QueryException::class);
        DB::table('catalog_gacha_versions')->insert([
            'public_id' => '0198a001-0000-7000-8000-000000000098',
            'gacha_id' => DB::table('catalog_gachas')->value('id'),
            'version_number' => 2,
            'status' => 'draft',
            'title' => 'Invalid period',
            'price_points' => 100,
            'total_count' => 100,
            'publish_start_at' => '2026-08-01T00:00:00Z',
            'publish_end_at' => '2026-07-01T00:00:00Z',
        ]);
    }

    public function test_public_api_exposes_only_published_period_and_aggregate_probability(): void
    {
        app(V2CatalogFixtureImporter::class)->import($this->fixture());

        $categories = $this->getJson('/api/v2/gacha-categories')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public, stale-while-revalidate=600')
            ->assertJsonPath('data.0.slug', 'cards');
        self::assertNotEmpty($categories->headers->get('X-Request-Id'));

        $this->getJson('/api/v2/gachas?limit=20&category=cards&tag=featured')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '0198a001-0000-7000-8000-000000000011')
            ->assertJsonPath('data.0.drawn_count', 5)
            ->assertJsonPath('data.0.presentation.sale_state', 'on_sale')
            ->assertJsonPath('data.0.presentation.eligible', false)
            ->assertJsonPath(
                'data.0.presentation.ineligible_reason',
                'authentication_required'
            )
            ->assertJsonPath('data.0.presentation.display.show_price_points', true)
            ->assertJsonPath('data.0.remaining_count', 995)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);

        $response = $this->getJson('/api/v2/gachas/by-slug/fixture-catalog')
            ->assertOk()
            ->assertJsonPath(
                'data.probability_stages.0.rank_probabilities.0.total_ppm',
                10000
            )
            ->assertJsonPath('data.probability_stages.0.point_back_total_ppm', 100000)
            ->assertJsonPath(
                'data.probability_stages.0.minimum_guarantee.total_ppm',
                800000
            )
            ->assertJsonPath('data.probability_stages.0.is_current', true)
            ->assertJsonPath(
                'data.presentation_asset.checksum_sha256',
                '0605cbbe5fcd83f57adc97efe4eb39efc5639b28f6fc48e097dc4a9ba68d86c8'
            )
            ->assertJsonPath(
                'data.presentation_asset.path',
                '/api/v2/content/assets/0198a001-0000-7000-8000-000000000005'
            );
        $json = (string) $response->getContent();
        foreach ([
            'individual_ppm',
            'probability_ppm',
            'cost_price',
            'storage_identifier',
            'snapshot_sha256',
            'gacha_internal_id',
            'tenant_id',
        ] as $prohibited) {
            self::assertStringNotContainsString($prohibited, $json);
        }
    }

    public function test_draft_future_and_expired_versions_are_not_public(): void
    {
        $future = $this->fixture();
        $future['versions'][0]['publish_start_at'] = '2026-08-01T00:00:00Z';
        $future['versions'][0]['publish_end_at'] = '2026-09-01T00:00:00Z';
        app(V2CatalogFixtureImporter::class)->import($future);
        $this->getJson('/api/v2/gachas')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.presentation.sale_state', 'coming_soon')
            ->assertJsonPath('data.0.presentation.display.show_price_points', true);
        $this->getJson('/api/v2/gachas/by-slug/fixture-catalog')
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'coming_soon');
    }

    public function test_expired_version_is_not_public(): void
    {
        $expired = $this->fixture();
        $expired['versions'][0]['publish_start_at'] = '2025-01-01T00:00:00Z';
        $expired['versions'][0]['publish_end_at'] = '2026-01-01T00:00:00Z';
        app(V2CatalogFixtureImporter::class)->import($expired);
        $this->getJson('/api/v2/gachas')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.presentation.sale_state', 'ended')
            ->assertJsonPath('data.0.presentation.display.show_price_points', false)
            ->assertJsonPath('data.0.presentation.display.show_total_count', false)
            ->assertJsonPath('data.0.presentation.display.show_drawn_count', false);
        $this->getJson('/api/v2/gachas/by-slug/fixture-catalog')
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'ended');
    }

    public function test_invalid_cursor_uses_rfc_9457_problem_details(): void
    {
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        $this->getJson('/api/v2/gachas?cursor=not-valid')
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'INVALID_CURSOR')
            ->assertJsonPath('retryable', false);
    }

    public function test_read_service_has_no_draw_inventory_or_mutation_authority(): void
    {
        $source = file_get_contents(
            app_path('Domain/Catalog/Services/V2CatalogReadService.php')
        );
        self::assertIsString($source);
        foreach (['random_int(', 'lockForUpdate(', 'point_ledger', 'payment', 'INSERT '] as $term) {
            self::assertStringNotContainsString($term, $source);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return array<string, int>
     */
    private function catalogCounts(): array
    {
        $counts = [];
        foreach (array_diff(self::TABLES, ['catalog_import_runs']) as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
