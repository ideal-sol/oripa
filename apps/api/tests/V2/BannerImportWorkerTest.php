<?php

namespace Tests\V2;

use App\Domain\ContentContact\Contracts\V2BannerImportExecution;
use App\Domain\ContentContact\Contracts\V2BannerImportResolver;
use App\Domain\ContentContact\Contracts\V2BannerImportTransport;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2BannerImportCanonicalAdapter;
use App\Domain\ContentContact\Services\V2BannerImportWorker;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class BannerImportWorkerTest extends BannerImportTest
{
    public function test_logout_and_expired_session_do_not_revoke_committed_job(): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        DB::table('admin_sessions')->where('session_id_hash', $this->context->sessionIdHash)->update([
            'revoked_at' => now(),
        ]);
        CarbonImmutable::setTestNow(now()->addMinutes(16));
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame(1, $this->imports->result($job['id'])['created']);
        $audit = DB::table('audit_logs')->where('action_code', 'content.banner_created')->first();
        self::assertSame($this->context->adminPublicId, $audit->actor_public_id);
        self::assertNull($audit->session_correlation_hash);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('revokedAdmins')]
    public function test_current_admin_state_and_permission_stop_unprocessed_rows(array $change): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        DB::table('admins')->where('id', $this->context->adminId)->update($change);
        $resolver = $this->createMock(V2BannerImportResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $this->app->instance(V2BannerImportResolver::class, $resolver);
        self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        $result = $this->imports->result($job['id']);
        self::assertSame('failed', $result['status']);
        self::assertSame('BANNER_IMPORT_AUTHORIZATION_REVOKED', $result['rows'][0]['error_code']);
        self::assertNull(DB::table('content_banner_import_rows')->value('url_ciphertext'));
        self::assertDatabaseCount('catalog_presentation_assets', 0);
    }

    public static function revokedAdmins(): array
    {
        return [[['state' => 'suspended']], [['state' => 'disabled']], [['role' => 'operator']]];
    }

    public function test_operator_cannot_submit(): void
    {
        DB::table('admins')->where('id', $this->context->adminId)->update(['role' => 'operator']);
        $this->context = new \App\Domain\Identity\Contracts\V2AdminAuthorizationContext(
            $this->context->adminId, $this->context->adminPublicId, \App\Domain\Identity\Enums\V2AdminRole::Operator,
            $this->context->sessionIdHash, $this->context->sessionCorrelationHash, $this->context->requestId
        );
        try { $this->createJob('CARD-1,画像,https://images.example.test/image,'); self::fail('Operator accepted'); }
        catch (V2AuthenticationException $exception) { self::assertSame(403, $exception->status); }
        self::assertDatabaseCount('content_banner_import_jobs', 0);
    }

    public function test_expired_lease_reclaims_and_old_worker_is_fenced(): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        $worker = app(V2BannerImportWorker::class);
        $first = $worker->claim();
        self::assertNull($worker->claim());
        config(['database.connections.banner_import_competitor' => config('database.connections.pgsql')]);
        try {
            $other = DB::connection('banner_import_competitor')->selectOne("SELECT pg_try_advisory_xact_lock(hashtextextended('v2_banner_import_claim', 0)) AS acquired");
            self::assertFalse($other->acquired);
        } finally { DB::purge('banner_import_competitor'); }
        CarbonImmutable::setTestNow(now()->addSeconds(121));
        $second = $worker->claim();
        self::assertNotSame($first['token'], $second['token']);
        try { $worker->runClaim($first['id'], $first['token']); self::fail('Stale worker accepted'); }
        catch (V2ContentContactException $exception) { self::assertSame('BANNER_IMPORT_LEASE_LOST', $exception->errorCode); }
        self::assertSame(1, $worker->runClaim($second['id'], $second['token']));
        self::assertSame(1, $this->imports->result($job['id'])['created']);
        self::assertDatabaseCount('catalog_presentation_assets', 1);
    }

    public function test_asset_is_reused_after_banner_failure_and_url_expiry(): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        $adapter = $this->getMockBuilder(V2BannerImportCanonicalAdapter::class)->setConstructorArgs([$this->banners])->onlyMethods(['save'])->getMock();
        $adapter->expects(self::once())->method('save')->willThrowException(new \RuntimeException('synthetic banner failure'));
        $this->app->instance(V2BannerImportCanonicalAdapter::class, $adapter);
        app(V2BannerImportWorker::class)->runOnce();
        $row = DB::table('content_banner_import_rows')->first();
        self::assertSame('asset_registered', $row->status);
        self::assertSame(1, $row->attempts);
        self::assertNotNull($row->asset_public_id);
        self::assertNull($row->url_ciphertext);
        self::assertDatabaseCount('content_banners', 0);
        $this->app->forgetInstance(V2BannerImportCanonicalAdapter::class);
        CarbonImmutable::setTestNow(now()->addMinutes(31));
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame(1, $this->imports->result($job['id'])['created']);
        self::assertDatabaseCount('catalog_presentation_assets', 1);
    }

    public function test_fetch_failure_continues_other_rows_and_retry_is_bounded(): void
    {
        $job = $this->createJob("CARD-1,=formula,https://images.example.test/fail,\nCARD-2,正常,https://images.example.test/ok,");
        $transport = $this->createStub(V2BannerImportTransport::class);
        $transport->method('fetch')->willReturnCallback(static function (string $url): string {
            if (str_contains($url, '/fail')) throw new \RuntimeException('synthetic transport secret');
            return base64_decode(self::PNG);
        });
        $this->app->instance(V2BannerImportTransport::class, $transport);
        $worker = app(V2BannerImportWorker::class);
        self::assertSame(1, $worker->runOnce());
        for ($attempt = 0; $attempt < 2; $attempt++) {
            CarbonImmutable::setTestNow(now()->addSeconds(31));
            $worker->runOnce();
        }
        $result = $this->imports->result($job['id']);
        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['failed']);
        self::assertSame(3, DB::table('content_banner_import_rows')->where('external_id', 'CARD-1')->value('attempts'));
        $csv = $this->imports->failureCsv($this->context, $job['id']);
        self::assertStringNotContainsString('images.example.test', $csv);
        self::assertStringContainsString("'=formula", $csv);
        self::assertStringNotContainsString('synthetic transport secret', json_encode($result));
        self::assertNull(DB::table('content_banner_import_rows')->where('external_id', 'CARD-1')->value('url_ciphertext'));
    }

    public function test_abandoned_urls_expire_and_result_retention_is_thirty_days(): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        CarbonImmutable::setTestNow(now()->addMinutes(31));
        $this->imports->expireSecrets();
        self::assertNull(DB::table('content_banner_import_rows')->value('url_ciphertext'));
        app(V2BannerImportWorker::class)->runOnce();
        self::assertSame('BANNER_IMPORT_URL_EXPIRED', $this->imports->result($job['id'])['rows'][0]['error_code']);
        CarbonImmutable::setTestNow(now()->addDays(30));
        $this->imports->expireSecrets();
        self::assertDatabaseCount('content_banner_import_jobs', 0);
        self::assertDatabaseCount('content_banner_import_rows', 0);
    }

    public function test_disabled_boundaries_have_no_asset_or_banner_side_effects(): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        $worker = app(V2BannerImportWorker::class);
        $claim = $worker->claim();
        $execution = V2BannerImportExecution::fromLease(DB::table('content_banner_import_rows')->value('public_id'), $claim['token']);
        config(['v2_banner_import.enabled' => 'true']);
        self::assertSame(0, $worker->runOnce());
        self::assertSame(['enabled' => false, 'items' => []], $this->imports->listing($this->context));
        foreach ([fn () => $worker->claim(), fn () => $worker->runClaim($claim['id'], $claim['token']),
            fn () => app(V2BannerImportCanonicalAdapter::class)->save($execution),
            fn () => $this->createJob('OTHER,画像,https://images.example.test/image,')] as $operation) {
            try { $operation(); self::fail('Disabled operation accepted'); }
            catch (V2ContentContactException $exception) { self::assertSame('BANNER_IMPORT_DISABLED', $exception->errorCode); }
        }
        self::assertDatabaseCount('content_banner_import_jobs', 1);
        self::assertDatabaseCount('catalog_presentation_assets', 0);
        self::assertDatabaseCount('content_banners', 0);
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_concurrent_registration_never_replaces_image_when_refresh_is_off(): void
    {
        $job = $this->createJob('CARD-1,CSVタイトル,https://images.example.test/image,');
        $transport = $this->createStub(V2BannerImportTransport::class);
        $transport->method('fetch')->willReturn("GIF89a\x01\0\x01\0\x80\0\0\0\0\0\xff\xff\xff");
        $this->app->instance(V2BannerImportTransport::class, $transport);
        $banner = $this->banner();
        app(V2BannerImportWorker::class)->runOnce();
        $after = $this->banners->managedBannerDetail($this->context, $banner['id']);
        self::assertSame($banner['asset']['id'], $after['asset']['id']);
        self::assertSame('CSVタイトル', $after['title']);
        self::assertSame(1, $this->imports->result($job['id'])['updated']);
        self::assertSame('deferred_canonical_review', DB::table('content_banner_import_rows')->value('compensation_status'));
        self::assertDatabaseCount('content_banners', 1);
    }

    public function test_canonical_storage_failure_rolls_back_asset_and_retries_row(): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        $disk = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        app(V2BannerImportWorker::class)->runOnce();
        self::assertDatabaseCount('catalog_presentation_assets', 0);
        self::assertDatabaseCount('content_banners', 0);
        $row = DB::table('content_banner_import_rows')->first();
        self::assertSame('pending', $row->status);
        self::assertSame(1, $row->attempts);
        self::assertNull($row->asset_public_id);
    }

    public function test_refresh_on_replaces_draft_image_without_changing_published_version(): void
    {
        $banner = $this->banner('CARD-1', true);
        $this->banners->publish($this->context, 'banner', $banner['id'], $banner['version_id']);
        $published = DB::table('content_banners')->where('public_id', $banner['id'])->value('published_version_id');
        $transport = $this->createStub(V2BannerImportTransport::class);
        $transport->method('fetch')->willReturn("GIF89a\x01\0\x01\0\x80\0\0\0\0\0\xff\xff\xff");
        $this->app->instance(V2BannerImportTransport::class, $transport);
        $job = $this->createJob('CARD-1,元のタイトル,https://images.example.test/image,', true);
        app(V2BannerImportWorker::class)->runOnce();
        $after = $this->banners->managedBannerDetail($this->context, $banner['id']);
        self::assertNotSame($banner['asset']['id'], $after['asset']['id']);
        self::assertSame('published', $after['status']);
        self::assertTrue($after['show_on_top']);
        self::assertSame($published, DB::table('content_banners')->where('public_id', $banner['id'])->value('published_version_id'));
        self::assertSame(1, $this->imports->result($job['id'])['updated']);
        self::assertDatabaseCount('catalog_presentation_assets', 2);
    }

    public function test_archived_banner_is_not_updated_or_reused(): void
    {
        $archived = $this->banner();
        DB::table('content_banners')->where('public_id', $archived['id'])->update(['status' => 'archived']);
        $job = $this->createJob('CARD-1,新規,https://images.example.test/image,');
        app(V2BannerImportWorker::class)->runOnce();
        self::assertSame(1, $this->imports->result($job['id'])['created']);
        self::assertSame('archived', DB::table('content_banners')->where('public_id', $archived['id'])->value('status'));
        self::assertSame(1, DB::table('content_banners')->where('external_id', 'CARD-1')->where('status', 'draft')->count());
        self::assertDatabaseCount('content_banners', 2);
    }

    public function test_committed_terminal_row_is_not_reexecuted_after_worker_response_loss(): void
    {
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        $worker = app(V2BannerImportWorker::class);
        $claim = $worker->claim();
        $worker->runClaim($claim['id'], $claim['token']);
        DB::table('content_banner_import_jobs')->where('public_id', $job['id'])->update(['status' => 'queued', 'completed_at' => null]);
        self::assertSame(0, $worker->runOnce());
        self::assertSame('completed', $this->imports->result($job['id'])['status']);
        self::assertDatabaseCount('catalog_presentation_assets', 1);
        self::assertDatabaseCount('content_banners', 1);
    }

    public function test_real_processes_claim_one_job_once(): void
    {
        if (getenv('V2_BANNER_IMPORT_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires the dedicated isolated Banner import concurrency run.');
        }
        self::assertTrue(function_exists('pcntl_fork'));
        $job = $this->createJob('CARD-1,画像,https://images.example.test/image,');
        DB::commit();
        DB::disconnect();
        $start = microtime(true) + 0.5;
        $processes = [];
        foreach (range(1, 2) as $index) {
            $path = tempnam(sys_get_temp_dir(), 'banner-claim-');
            $process = pcntl_fork();
            self::assertNotSame(-1, $process);
            if ($process === 0) {
                while (microtime(true) < $start) usleep(1000);
                DB::reconnect();
                DB::statement("SET statement_timeout = '15s'");
                try {
                    $claim = app(V2BannerImportWorker::class)->claim();
                    file_put_contents($path, $claim === null ? 'not_claimed' : 'claimed');
                } catch (\Throwable $exception) {
                    file_put_contents($path, get_class($exception));
                }
                DB::disconnect();
                exit(0);
            }
            $processes[] = [$process, $path];
        }
        $outcomes = [];
        foreach ($processes as [$process, $path]) {
            pcntl_waitpid($process, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $outcomes[] = file_get_contents($path);
            unlink($path);
        }
        DB::reconnect();
        sort($outcomes);
        self::assertSame(['claimed', 'not_claimed'], $outcomes);
        self::assertSame(1, DB::table('content_banner_import_jobs')->value('lease_generation'));
        CarbonImmutable::setTestNow(now()->addSeconds(121));
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame(1, $this->imports->result($job['id'])['created']);
        self::assertDatabaseCount('catalog_presentation_assets', 1);
    }

    public function test_object_write_before_outer_commit_failure_is_flagged_not_claimed_compensated(): void
    {
        $this->createJob('CARD-1,画像,https://images.example.test/image,');
        $adapter = new class($this->banners) extends V2BannerImportCanonicalAdapter {
            public function register(V2BannerImportExecution $execution, #[\SensitiveParameter] array $image): void
            {
                parent::register($execution, $image);
                throw new \RuntimeException('synthetic failure before outer commit');
            }
        };
        $this->app->instance(V2BannerImportCanonicalAdapter::class, $adapter);
        app(V2BannerImportWorker::class)->runOnce();
        self::assertDatabaseCount('catalog_presentation_assets', 0);
        self::assertDatabaseCount('content_banners', 0);
        self::assertCount(1, Storage::disk('local')->allFiles());
        $row = DB::table('content_banner_import_rows')->first();
        self::assertNull($row->asset_public_id);
        self::assertSame('deferred_canonical_review', $row->compensation_status);
        self::assertSame('pending', $row->status);
    }

    public function test_import_constraints_keep_their_exact_definition_after_recreation(): void
    {
        foreach (['content_banner_import_jobs' => 'banner_import_job_values',
            'content_banner_import_rows' => 'banner_import_row_values'] as $table => $constraint) {
            $definition = DB::selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?', [$constraint])->definition;
            DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT '.$constraint);
            DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$constraint.' '.$definition);
            $restored = DB::selectOne('SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?', [$constraint])->definition;
            self::assertSame($definition, $restored);
        }
    }
}
