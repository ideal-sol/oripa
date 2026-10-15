<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ZRankVideoUpgradeTest extends TestCase
{
    public function test_existing_current_and_historical_revisions_backfill_without_pointer_changes(): void
    {
        if (getenv('V2_RANK_VIDEO_UPGRADE_TEST') !== '1') {
            self::markTestSkipped('Requires the explicit disposable rank video upgrade database.');
        }
        self::assertTrue(app()->environment('testing'));
        self::assertSame('oripa_v2_rankvideo_multi_synthetic', DB::connection()->getDatabaseName());
        $migration = '2026_10_15_000086_add_v2_rank_video_sets.php';
        $paths = array_values(array_filter(glob(database_path('migrations-v2/*.php')), fn (string $path): bool => basename($path) !== $migration));
        self::assertSame(0, Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true, '--force' => true]));
        app(V2CatalogFixtureImporter::class)->import(json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR));
        foreach (DB::table('catalog_gacha_rank_video_revisions')->get() as $revision) {
            DB::table('catalog_gacha_rank_video_revisions')->insert([
                'gacha_rank_id' => $revision->gacha_rank_id, 'revision_number' => $revision->revision_number + 1,
                'video_asset_id' => $revision->video_asset_id, 'created_at' => now(),
            ]);
        }
        $before = DB::table('catalog_gacha_rank_video_revisions')->orderBy('id')->get()->toJson();
        $ranks = DB::table('catalog_gacha_ranks')->orderBy('id')->get()->toJson();
        self::assertSame(0, Artisan::call('migrate', ['--path' => 'database/migrations-v2/'.$migration, '--force' => true]));
        $after = DB::table('catalog_gacha_rank_video_revisions')->orderBy('id')->get(['id', 'gacha_rank_id', 'revision_number', 'video_asset_id', 'created_at'])->toJson();
        self::assertSame($before, $after);
        self::assertSame($ranks, DB::table('catalog_gacha_ranks')->orderBy('id')->get()->toJson());
        self::assertGreaterThan(1, DB::table('catalog_gacha_rank_video_items')->count());
        self::assertSame(DB::table('catalog_gacha_rank_video_revisions')->count(), DB::table('catalog_gacha_rank_video_items')->count());
        foreach (DB::table('catalog_gacha_rank_video_revisions')->get() as $revision) {
            self::assertSame(1, (int) $revision->video_count);
            self::assertDatabaseHas('catalog_gacha_rank_video_items', [
                'video_revision_id' => $revision->id, 'video_asset_id' => $revision->video_asset_id,
                'weight_units' => 10000, 'sort_order' => 1,
            ]);
        }
        self::assertSame(0, Artisan::call('migrate', ['--path' => 'database/migrations-v2', '--force' => true]));
    }
}
