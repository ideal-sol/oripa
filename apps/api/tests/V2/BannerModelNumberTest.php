<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\ContentContact\Services\V2BannerImportWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BannerModelNumberTest extends BannerImportTest
{
    private function modelJob(?string $model, string $id = 'CARD-1', string $title = '元のタイトル'): array
    {
        $input = $this->input('');
        $input['content_base64'] = base64_encode("管理ID,タイトル,画像URL,カテゴリ".($model === null ? '' : ',型番')."\n"
            .$id.','.$title.',https://images.example.test/image,'.($model === null ? '' : ','.$model));
        return $this->imports->create($this->context, $input, (string) Str::uuid7());
    }

    public function test_model_lifecycle_preserves_versions_images_and_exchange_classification(): void
    {
        $job = $this->modelJob("  e\u{0301}-001  ");
        app(V2BannerImportWorker::class)->runOnce();
        self::assertSame('é-001', DB::table('content_banners')->value('model_number'));
        self::assertSame('é-001', $this->imports->result($job['id'])['rows'][0]['model_number']);
        $created = json_decode(DB::table('audit_logs')->where('action_code', 'content.banner_created')->value('metadata_redacted'), true);
        self::assertNull($created['model_number_before']);
        self::assertSame('é-001', $created['model_number_after']);
        DB::table('content_banners')->update(['exchange_only' => true]);
        foreach ([[null, 'é-001', 'unchanged'], ['B-002', 'B-002', 'updated'], ['B-002', 'B-002', 'unchanged'], ['', null, 'updated']] as [$input, $expected, $result]) {
            $job = $this->modelJob($input);
            app(V2BannerImportWorker::class)->runOnce();
            self::assertSame(1, $this->imports->result($job['id'])[$result]);
            self::assertSame($expected, DB::table('content_banners')->value('model_number'));
            self::assertDatabaseCount('content_versions', 1);
            self::assertDatabaseCount('catalog_presentation_assets', 1);
            self::assertTrue(DB::table('content_banners')->value('exchange_only'));
            self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        }
        $updated = json_decode(DB::table('audit_logs')->where('action_code', 'content.banner_updated')->orderByDesc('id')->value('metadata_redacted'), true);
        self::assertSame('B-002', $updated['model_number_before']);
        self::assertNull($updated['model_number_after']);
        $this->modelJob(null, 'CARD-2');
        app(V2BannerImportWorker::class)->runOnce();
        self::assertNull(DB::table('content_banners')->where('external_id', 'CARD-2')->value('model_number'));
        $this->modelJob('SAME', 'CARD-1', '変更');
        app(V2BannerImportWorker::class)->runOnce();
        $this->modelJob('SAME', 'CARD-2');
        app(V2BannerImportWorker::class)->runOnce();
        self::assertSame(2, DB::table('content_banners')->where('model_number', 'SAME')->count());
        self::assertDatabaseCount('content_versions', 3);
    }

    public function test_invalid_models_and_normalized_length(): void
    {
        config(['v2_banner_import.header_aliases' => ['Model' => '型番']]);
        $aliased = $this->input('');
        $aliased['content_base64'] = base64_encode("管理ID,タイトル,画像URL,Model\nALIAS-1,商品,https://images.example.test/image,M-1\nALIAS-2,商品,https://images.example.test/image,M-1");
        $aliasJob = $this->imports->create($this->context, $aliased, (string) Str::uuid7());
        self::assertSame(['M-1', 'M-1'], array_column($aliasJob['rows'], 'model_number'));
        foreach ([str_repeat('あ', 65), 'a<b', 'a>b', "a\x01b", "a\tb", "a\u{0085}b"] as $model) {
            try {
                $this->modelJob($model);
                self::fail('Invalid model accepted');
            } catch (V2CatalogException $exception) {
                self::assertSame('CSV_VALIDATION_FAILED', $exception->errorCode);
                self::assertStringContainsString('MODEL_NUMBER_INVALID', json_encode($exception->details));
            }
        }
        $job = $this->modelJob(str_repeat("e\u{0301}", 64));
        self::assertSame(str_repeat('é', 64), $job['rows'][0]['model_number']);
        $migration = require base_path('database/migrations-v2/2026_11_04_000106_add_v2_banner_model_numbers.php');
        try {
            $migration->down();
            self::fail('Forward-only migration must reject rollback');
        } catch (\LogicException $exception) {
            self::assertSame('Banner model numbers require a forward correction migration.', $exception->getMessage());
        }
    }

    public function test_failure_csv_preserves_column_presence_and_normalized_values(): void
    {
        $banner = $this->banner('CARD-1', true);
        $this->banners->publish($this->context, 'banner', $banner['id'], $banner['version_id']);
        DB::table('content_banners')->update(['model_number' => 'KEEP']);
        $public = app(\App\Domain\ContentContact\Services\V2ContentReadService::class)->banners();
        self::assertCount(1, $public['items']);
        self::assertStringNotContainsString('model_number', json_encode($public));
        self::assertStringNotContainsString('KEEP', json_encode($public));
        foreach ([null, '', '  MODEL-1  '] as $model) {
            $job = $this->modelJob($model);
            DB::table('content_banner_import_rows')->where('job_id', DB::table('content_banner_import_jobs')->where('public_id', $job['id'])->value('id'))
                ->update(['status' => 'failed', 'result' => 'failed', 'error_code' => 'SYNTHETIC_FAILURE', 'url_ciphertext' => null]);
            $this->imports->finish((int) DB::table('content_banner_import_jobs')->where('public_id', $job['id'])->value('id'));
            $csv = $this->imports->failureCsv($this->context, $job['id']);
            self::assertSame($model !== null, str_contains($csv, '型番'));
            self::assertStringNotContainsString('images.example.test', $csv);
            $input = $this->input('');
            $input['content_base64'] = base64_encode($csv);
            $again = $this->imports->create($this->context, $input, (string) Str::uuid7());
            app(V2BannerImportWorker::class)->runOnce();
            self::assertSame($model === null ? 'KEEP' : ($model === '' ? null : 'MODEL-1'), DB::table('content_banners')->value('model_number'));
            self::assertSame(1, $this->imports->result($again['id'])['processed']);
        }
    }
}
