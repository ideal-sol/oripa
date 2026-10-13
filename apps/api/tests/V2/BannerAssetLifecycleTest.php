<?php

namespace Tests\V2;

use App\Domain\ContentContact\Services\V2BannerImportAssetOperationService;
use App\Domain\ContentContact\Services\V2BannerImportWorker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;

final class BannerAssetLifecycleTest extends BannerImportTest
{
    public function test_late_rows_in_200_row_job_expire_without_refetch_and_successes_remain(): void
    {
        $rows = implode("\n", array_map(static fn (int $index): string => 'CARD-'.$index.',画像,https://images.example.test/image,', range(1, 200)));
        $job = $this->createJob($rows);
        $transport = $this->createMock(\App\Domain\ContentContact\Contracts\V2BannerImportTransport::class);
        $transport->expects(self::exactly(180))->method('fetch')->willReturnCallback(static function (): string {
            CarbonImmutable::setTestNow(now()->addSeconds(10));
            return base64_decode(self::PNG);
        });
        $this->app->instance(\App\Domain\ContentContact\Contracts\V2BannerImportTransport::class, $transport);
        $this->guardedStorage();
        self::assertSame(180, app(V2BannerImportWorker::class)->runOnce());
        $result = $this->imports->result($job['id']);
        self::assertSame(180, $result['created']);
        self::assertSame(20, $result['failed']);
        self::assertSame('BANNER_IMPORT_URL_EXPIRED', $result['rows'][199]['error_code']);
        self::assertSame(0, DB::table('content_banner_import_rows')->whereNotNull('url_ciphertext')->count());
        self::assertDatabaseCount('catalog_presentation_assets', 180);
    }

    public function test_normal_admin_upload_retains_its_existing_failure_cleanup(): void
    {
        $disk = Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            $this->banners->uploadBannerAsset($this->context, ['file_name' => 'synthetic.png', 'mime_type' => 'image/png',
                'content_base64' => self::PNG], (string) \Illuminate\Support\Str::uuid7());
            self::fail('Failed upload accepted');
        } catch (\RuntimeException $exception) {
            self::assertSame('Banner asset storage failed.', $exception->getMessage());
        }
        self::assertDatabaseCount('catalog_presentation_assets', 0);
        self::assertDatabaseCount('content_banner_asset_operations', 0);
    }

    public function test_sdk_handler_only_storage_round_trip_checks_body_not_etag(): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        $objects = [];
        $commands = [];
        $handler = function ($command) use (&$objects, &$commands) {
            $name = $command->getName();
            $commands[] = $name;
            $key = $command['Key'];
            if ($name === 'PutObject') {
                $objects[$key] = (string) $command['Body'];
                return new \GuzzleHttp\Promise\FulfilledPromise(new \Aws\Result(['ETag' => 'not-a-checksum']));
            }
            if (! isset($objects[$key])) {
                return new \GuzzleHttp\Promise\RejectedPromise(new \Aws\S3\Exception\S3Exception('synthetic missing', $command,
                    ['response' => new \GuzzleHttp\Psr7\Response(404)]));
            }
            self::assertContains($name, ['HeadObject', 'GetObject']);
            return new \GuzzleHttp\Promise\FulfilledPromise(new \Aws\Result([
                'ContentLength' => strlen($objects[$key]), 'ContentType' => 'image/png', 'ETag' => 'not-a-checksum',
                'Body' => \GuzzleHttp\Psr7\Utils::streamFor($objects[$key]),
            ]));
        };
        $settings = ['driver' => 's3', 'bucket' => 'synthetic-banner-test', 'region' => 'us-east-1',
            'http' => ['connect_timeout' => 5, 'timeout' => 20, 'read_timeout' => 20], 'retries' => 0];
        $client = new \Aws\S3\S3Client([...$settings, 'version' => 'latest', 'credentials' => ['key' => 'synthetic', 'secret' => 'synthetic'], 'handler' => $handler]);
        $adapter = new \League\Flysystem\AwsS3V3\AwsS3V3Adapter($client, $settings['bucket']);
        Storage::set('synthetic_s3', new \Illuminate\Filesystem\AwsS3V3Adapter(new \League\Flysystem\Filesystem($adapter), $adapter, $settings, $client));
        config(['filesystems.default' => 'synthetic_s3', 'filesystems.disks.synthetic_s3' => $settings]);
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame(1, array_count_values($commands)['PutObject']);
        self::assertContains('GetObject', $commands);
        self::assertNotContains('DeleteObject', $commands);
        self::assertNotContains('DeleteObjects', $commands);
        self::assertSame(hash('sha256', base64_decode(self::PNG)), DB::table('catalog_presentation_assets')->value('checksum_sha256'));
    }

    public function test_late_old_put_never_causes_competing_put_and_old_finalize_is_fenced(): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        [$disk, $guard] = $this->guardedStorage();
        $guard->shouldReceive('put')->once()->andReturnUsing(function (string $key, string $bytes, array $options) use ($disk): bool {
            CarbonImmutable::setTestNow(now()->addSeconds(121));
            self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
            self::assertSame('missing', DB::table('content_banner_asset_operations')->value('manual_review_reason'));
            self::assertDatabaseCount('catalog_presentation_assets', 0);
            return $disk->put($key, $bytes, $options);
        });
        self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        self::assertDatabaseCount('catalog_presentation_assets', 0);
        CarbonImmutable::setTestNow(now()->addSeconds(31));
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame(1, $this->imports->result($job['id'])['created']);
        self::assertSame(1, DB::table('content_banner_asset_operations')->value('put_attempts'));
    }

    public function test_prepared_intent_resumes_with_same_identity_after_stop_before_put(): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        [$disk, $guard] = $this->guardedStorage();
        $guard->shouldReceive('put')->once()->andReturnUsing(fn (string $key, string $bytes, array $options): bool => $disk->put($key, $bytes, $options));
        DB::unprepared("CREATE FUNCTION synthetic_stop_before_put() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.status = 'writing' THEN RAISE EXCEPTION 'synthetic stop'; END IF; RETURN NEW; END; \$\$; CREATE TRIGGER synthetic_stop_before_put BEFORE UPDATE ON content_banner_asset_operations FOR EACH ROW EXECUTE FUNCTION synthetic_stop_before_put();");
        self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        $intent = DB::table('content_banner_asset_operations')->first();
        self::assertSame('prepared', $intent->status);
        self::assertSame([], $disk->allFiles());
        DB::statement('DROP TRIGGER synthetic_stop_before_put ON content_banner_asset_operations');
        CarbonImmutable::setTestNow(now()->addSeconds(31));
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        $after = DB::table('content_banner_asset_operations')->first();
        self::assertSame($intent->public_id, $after->public_id);
        self::assertSame($intent->asset_public_id, $after->asset_public_id);
        self::assertSame($intent->object_key, $after->object_key);
    }

    public function test_resolved_ledger_survives_history_purge_and_cannot_be_deleted(): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        $this->guardedStorage();
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        CarbonImmutable::setTestNow(now()->addDays(31));
        $this->imports->expireSecrets();
        self::assertDatabaseCount('content_banner_import_jobs', 0);
        self::assertDatabaseCount('content_banner_asset_operations', 1);
        self::assertSame('attached', DB::table('content_banner_asset_operations')->value('status'));
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('content_banner_asset_operations')->delete();
    }

    private function guardedStorage(): array
    {
        $disk = Storage::disk('local');
        $guard = Mockery::mock($disk);
        $guard->shouldNotReceive('delete');
        $guard->shouldNotReceive('deleteDirectory');
        Storage::shouldReceive('disk')->with('local')->andReturn($guard);
        return [$disk, $guard];
    }

    public function test_put_observes_committed_intent_from_independent_connection_and_no_outer_transaction(): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        [$disk, $guard] = $this->guardedStorage();
        config(['database.connections.banner_witness' => config('database.connections.pgsql')]);
        $guard->shouldReceive('put')->once()->andReturnUsing(function (string $key, string $bytes, array $options) use ($disk): bool {
            self::assertSame(0, DB::transactionLevel());
            $intent = DB::connection('banner_witness')->table('content_banner_asset_operations')->first();
            self::assertNotNull($intent);
            self::assertSame($key, $intent->object_key);
            self::assertSame('writing', $intent->status);
            self::assertSame(hash('sha256', $bytes), $intent->checksum_sha256);
            self::assertSame(strlen($bytes), $intent->byte_size);
            self::assertSame($options['ContentType'], $intent->mime_type);
            return $disk->put($key, $bytes, $options);
        });
        try {
            self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
            self::assertSame('attached', DB::table('content_banner_asset_operations')->value('status'));
        } finally {
            DB::purge('banner_witness');
        }
    }

    public function test_outer_transaction_or_missing_destination_generation_never_puts(): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        [, $guard] = $this->guardedStorage();
        $guard->shouldNotReceive('put');
        $worker = app(V2BannerImportWorker::class);
        $claim = $worker->claim();
        $row = DB::table('content_banner_import_rows')->first();
        DB::beginTransaction();
        try {
            app(V2BannerImportAssetOperationService::class)->recover($row->public_id, $claim['token']);
            self::fail('Nested transaction accepted');
        } catch (\LogicException) {
            self::assertDatabaseCount('content_banner_asset_operations', 0);
        } finally {
            DB::rollBack();
        }
        config(['v2_banner_import.storage_generation' => null]);
        self::assertSame(0, $worker->runClaim($job['id'], $claim['token']));
        self::assertDatabaseCount('content_banner_asset_operations', 0);
        self::assertDatabaseCount('catalog_presentation_assets', 0);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('putOutcomes')]
    public function test_unknown_put_is_tracked_without_reput_or_delete(bool $stored): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        [$disk, $guard] = $this->guardedStorage();
        $guard->shouldReceive('put')->once()->andReturnUsing(function (string $key, string $bytes, array $options) use ($disk, $stored): bool {
            if ($stored) {
                $disk->put($key, $bytes, $options);
            }
            throw new \RuntimeException('synthetic secret URL must not escape');
        });
        self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        $operation = DB::table('content_banner_asset_operations')->first();
        self::assertSame('manual_review', $operation->status);
        self::assertSame('put_unknown', $operation->manual_review_reason);
        CarbonImmutable::setTestNow(now()->addMinutes(31));
        self::assertSame($stored ? 1 : 0, app(V2BannerImportWorker::class)->runOnce());
        CarbonImmutable::setTestNow(now()->addSeconds(31));
        app(V2BannerImportWorker::class)->runOnce();
        self::assertSame($operation->asset_public_id, DB::table('content_banner_asset_operations')->value('asset_public_id'));
        self::assertSame(1, DB::table('content_banner_asset_operations')->value('put_attempts'));
        self::assertSame($stored ? 1 : 0, $this->imports->result($job['id'])['created']);
        self::assertStringNotContainsString('synthetic secret', json_encode(DB::table('audit_logs')->get()));
        self::assertNull(DB::table('content_banner_import_rows')->value('url_ciphertext'));
    }

    public static function putOutcomes(): array
    {
        return [[true], [false]];
    }

    public function test_lease_loss_after_put_fences_old_finalize_and_recovers_without_url(): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        [$disk, $guard] = $this->guardedStorage();
        $guard->shouldReceive('put')->once()->andReturnUsing(function (string $key, string $bytes, array $options) use ($disk): bool {
            $disk->put($key, $bytes, $options);
            CarbonImmutable::setTestNow(now()->addMinutes(31));
            return true;
        });
        self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        self::assertDatabaseCount('catalog_presentation_assets', 0);
        self::assertSame('writing', DB::table('content_banner_asset_operations')->value('status'));
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame(2, DB::table('content_banner_asset_operations')->value('lease_generation'));
        self::assertDatabaseCount('catalog_presentation_assets', 1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inspectionFailures')]
    public function test_mismatch_or_unreadable_object_is_not_overwritten_or_deleted(string $failure): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        [$disk, $guard] = $this->guardedStorage();
        $guard->shouldReceive('put')->once()->andReturnUsing(function (string $key, string $bytes, array $options) use ($disk): bool {
            $disk->put($key, $bytes, $options);
            throw new \RuntimeException('synthetic lost response');
        });
        app(V2BannerImportWorker::class)->runOnce();
        $operation = DB::table('content_banner_asset_operations')->first();
        if ($failure === 'mismatch') {
            $disk->put($operation->object_key, 'synthetic foreign object');
        } else {
            $guard->shouldReceive('fileExists')->andThrow(new \RuntimeException($failure));
        }
        CarbonImmutable::setTestNow(now()->addSeconds(31));
        self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame($failure === 'mismatch' ? 'mismatch' : 'read_unknown', DB::table('content_banner_asset_operations')->value('manual_review_reason'));
        self::assertDatabaseCount('catalog_presentation_assets', 0);
        self::assertTrue($disk->fileExists($operation->object_key));
    }

    public static function inspectionFailures(): array
    {
        return [['mismatch'], ['403'], ['timeout']];
    }

    public function test_disabled_purge_retains_unresolved_ledger_and_109_shared_objects(): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        [$disk, $guard] = $this->guardedStorage();
        foreach (range(1, 109) as $number) {
            $disk->put('synthetic-shared/'.$number, 'shared');
        }
        $guard->shouldReceive('put')->once()->andThrow(new \RuntimeException('synthetic unknown Put'));
        app(V2BannerImportWorker::class)->runOnce();
        config(['v2_banner_import.enabled' => false]);
        CarbonImmutable::setTestNow(now()->addMinutes(31));
        $this->artisan('v2:content:work-banner-imports', ['--purge-only' => true])->assertExitCode(0);
        self::assertNull(DB::table('content_banner_import_rows')->value('url_ciphertext'));
        self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        self::assertDatabaseCount('content_banner_import_jobs', 1);
        CarbonImmutable::setTestNow(now()->addDays(31));
        $this->imports->expireSecrets();
        self::assertDatabaseCount('content_banner_import_jobs', 0);
        self::assertDatabaseCount('content_banner_asset_operations', 1);
        self::assertCount(109, $disk->allFiles());
        self::assertNotNull(DB::table('content_banner_asset_operations')->value('manual_review_reason'));
    }

    public function test_graceful_stop_finishes_current_row_and_resumes_remaining_job(): void
    {
        $job = $this->createJob("CARD-1,画像,https://images.example.test/image,\nCARD-2,画像,https://images.example.test/image,");
        [$disk, $guard] = $this->guardedStorage();
        $worker = app(V2BannerImportWorker::class);
        $guard->shouldReceive('put')->twice()->andReturnUsing(function (string $key, string $bytes, array $options) use ($disk, $worker): bool {
            $worker->requestStop();
            return $disk->put($key, $bytes, $options);
        });
        self::assertSame(1, $worker->runOnce());
        self::assertNull($worker->claim());
        self::assertSame('queued', $this->imports->result($job['id'])['status']);
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame(2, $this->imports->result($job['id'])['created']);
    }
}
