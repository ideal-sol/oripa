<?php

namespace Tests\V2;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\ContentContact\Services\V2BannerImportCsv;
use Illuminate\Support\Facades\DB;

final class BannerImportCsvTest extends BannerImportTest
{
    public function test_invalid_csv_does_not_write_jobs_assets_banners_or_idempotency(): void
    {
        $before = DB::table('idempotency_records')->count();
        try { $this->createJob("CARD-1,画像,https://images.example.test/image,\nCARD-1,重複,https://images.example.test/image,"); self::fail('Duplicate accepted'); }
        catch (V2CatalogException $exception) {
            self::assertSame('EXTERNAL_ID_DUPLICATED', $exception->details['errors'][0]['code']);
            self::assertSame(3, $exception->details['errors'][0]['row']);
        }
        self::assertDatabaseCount('idempotency_records', $before);
        self::assertDatabaseCount('content_banner_import_jobs', 0);
        self::assertDatabaseCount('catalog_presentation_assets', 0);
        self::assertDatabaseCount('content_banners', 0);
    }

    public function test_bom_alias_quotes_trim_unknown_columns_and_physical_lines(): void
    {
        config(['v2_banner_import.header_aliases' => ['ID' => '管理ID']]);
        $input = $this->input('');
        $input['content_base64'] = base64_encode("\xEF\xBB\xBFID,タイトル,未知\r\n A-1 ,\"複数\n行\" ,ignore\r\n");
        try { app(V2BannerImportCsv::class)->parse($input); self::fail('Whitespace after closing quote accepted'); }
        catch (V2CatalogException $exception) { self::assertSame('CSV_VALIDATION_FAILED', $exception->errorCode); }
        $input['content_base64'] = base64_encode("\xEF\xBB\xBFID,タイトル,未知\r\n A-1 ,\"複数\n行\",ignore\r\nB-2,\"引用\"\"符\",unknown");
        $parsed = app(V2BannerImportCsv::class)->parse($input);
        self::assertSame('A-1', $parsed['rows'][0]['values']['管理ID']);
        self::assertSame("複数\n行", $parsed['rows'][0]['values']['タイトル']);
        self::assertSame(4, $parsed['rows'][1]['row']);
        self::assertSame('引用"符', $parsed['rows'][1]['values']['タイトル']);
        self::assertArrayNotHasKey('未知', $parsed['rows'][0]['values']);
    }

    public function test_limits_and_duplicate_header_fail_closed(): void
    {
        foreach (["管理ID,管理ID,タイトル\nA,A,Title", "管理ID,タイトル\n\xff,Title",
            "管理ID,タイトル\n".str_repeat("A,Title\n", 201), "", str_repeat('a', 2097153)] as $csv) {
            $input = $this->input('');
            $input['content_base64'] = base64_encode($csv);
            try { app(V2BannerImportCsv::class)->parse($input); self::fail('Invalid CSV accepted'); }
            catch (V2CatalogException $exception) { self::assertSame('CSV_VALIDATION_FAILED', $exception->errorCode); }
        }
        foreach ([1, 199, 200] as $count) {
            $input = $this->input('');
            $input['content_base64'] = base64_encode("管理ID,タイトル\n".str_repeat("A,Title\n", $count));
            self::assertCount($count, app(V2BannerImportCsv::class)->parse($input)['rows']);
        }
    }

    public function test_oversized_submissions_reject_before_business_writes(): void
    {
        $before = DB::table('idempotency_records')->count();
        foreach ([201, 1000] as $count) {
            try { $this->createJob(str_repeat("CARD-1,画像,https://images.example.test/image,\n", $count)); self::fail('Oversized CSV accepted'); }
            catch (V2CatalogException $exception) {
                self::assertSame(422, $exception->status);
                self::assertSame('CSV_VALIDATION_FAILED', $exception->errorCode);
            }
        }
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
        $csrf = str_repeat('a', 64);
        $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->sessionToken)
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf]);
        foreach ([201, 1000] as $count) {
            $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid7())
                ->postJson('/admin/api/v2/banner-management/imports', $this->input(str_repeat("CARD-1,画像,https://images.example.test/image,\n", $count)))
                ->assertUnprocessable()->assertJsonPath('code', 'CSV_VALIDATION_FAILED');
        }
        self::assertDatabaseCount('idempotency_records', $before);
        foreach (['content_banner_import_jobs', 'content_banner_import_rows', 'catalog_presentation_assets', 'content_banners', 'content_banner_asset_operations'] as $table) {
            self::assertDatabaseCount($table, 0);
        }
    }

    public function test_one_199_and_200_rows_are_accepted_with_stable_submission_replay(): void
    {
        foreach ([1, 199, 200] as $count) {
            $rows = implode("\n", array_map(static fn (int $index): string => 'CARD-'.$index.',画像,https://images.example.test/image,', range(1, $count)));
            $input = $this->input($rows);
            $key = (string) \Illuminate\Support\Str::uuid7();
            $job = $this->imports->create($this->context, $input, $key);
            self::assertSame($count, $job['total']);
            self::assertSame($job['id'], $this->imports->create($this->context, $input, $key)['id']);
        }
        self::assertDatabaseCount('content_banner_import_jobs', 3);
        self::assertDatabaseCount('content_banner_import_rows', 400);
        self::assertDatabaseCount('content_banner_asset_operations', 0);
        self::assertDatabaseCount('catalog_presentation_assets', 0);
    }

    public function test_error_output_is_capped_at_two_hundred_without_values_or_urls(): void
    {
        try { $this->createJob(str_repeat("bad/id,,http://forbidden.example.test/synthetic-secret,unknown\n", 200)); self::fail('Invalid rows accepted'); }
        catch (V2CatalogException $exception) {
            self::assertCount(200, $exception->details['errors']);
            self::assertSame(800, $exception->details['error_count']);
            self::assertStringNotContainsString('forbidden.example.test', json_encode($exception->details));
            self::assertStringNotContainsString('synthetic-secret', json_encode($exception->details));
        }
        self::assertDatabaseCount('content_banner_import_jobs', 0);
    }
}
