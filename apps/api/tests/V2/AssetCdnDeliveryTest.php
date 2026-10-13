<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2AssetPublicPathResolver;
use App\Domain\Catalog\Services\V2AssetResponseNormalizer;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Catalog\Services\V2LoginCatalogReadService;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Http\Middleware\V2\NormalizeV2AssetResponse;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\Result;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AssetCdnDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-10-09T00:00:00Z');
        Storage::fake('local');
        Storage::fake('s3');
        config(['filesystems.default' => 's3', 'v2_assets.public_base_url' => 'https://cdn.example.test',
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public static function validKeys(): array
    {
        return array_map(static fn (string $prefix): array => [$prefix], [
            'gacha', 'top-banner', 'rank-masters', 'rank-effects',
        ]);
    }

    #[DataProvider('validKeys')]
    public function test_maps_only_supported_object_keys(string $prefix): void
    {
        $resolver = app(V2AssetPublicPathResolver::class);
        $key = 'admin-assets/'.$prefix.'/2026/10/asset-1_v2.webp';
        self::assertSame('/'.$prefix.'/2026/10/asset-1_v2.webp', $resolver->path($key));
        self::assertSame('https://cdn.example.test'.$resolver->path($key), $resolver->url($key));
    }

    public static function invalidKeys(): array
    {
        return array_map(static fn (string $key): array => [$key], [
            '', 'fixture/a.png', 'admin-assets/unknown/a.png', '/admin-assets/gacha/a.png',
            'admin-assets/gacha/', 'admin-assets/gacha//a.png', 'admin-assets/gacha/../a.png',
            'admin-assets/gacha/./a.png', 'admin-assets/gacha/a%2fb.png',
            'admin-assets/gacha/%252e.png', 'admin-assets/gacha/a\\b.png',
            'admin-assets/gacha/a?x=1', "admin-assets/gacha/a\n.png", 'admin-assets/gacha/画像.png',
            'admin-assets/gacha/'.str_repeat('a', 513), 'https://example.test/a.png',
        ]);
    }

    #[DataProvider('invalidKeys')]
    public function test_rejects_unsupported_or_ambiguous_keys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(V2AssetPublicPathResolver::class)->path($key);
    }

    public static function invalidOrigins(): array
    {
        return array_map(static fn (string $origin): array => [$origin], [
            'http://cdn.example.test', 'https://user:password@cdn.example.test',
            'https://cdn.example.test/path', 'https://*.example.test',
            'https://cdn.example.test?query=1', "https://cdn.example.test\n", '//cdn.example.test',
        ]);
    }

    #[DataProvider('invalidOrigins')]
    public function test_rejects_invalid_cdn_configuration(string $origin): void
    {
        config(['v2_assets.public_base_url' => $origin]);
        $this->expectException(InvalidArgumentException::class);
        app(V2AssetPublicPathResolver::class)->enabled();
    }

    public function test_unset_cdn_preserves_local_paths_and_performs_no_lookup(): void
    {
        config(['filesystems.default' => 'local', 'v2_assets.public_base_url' => null]);
        $value = ['asset' => ['id' => 'legacy', 'public_path' => '/admin/api/legacy', 'is_public' => false]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        self::assertSame($value, app(V2AssetResponseNormalizer::class)->normalize($value));
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_cdn_with_local_disk_is_rejected_before_the_controller_runs(): void
    {
        config(['filesystems.default' => 'local']);
        $response = app(NormalizeV2AssetResponse::class)->handle(Request::create('/api/v2/gachas/a/draws', 'POST'),
            static function () { self::fail('Controller must not run with split storage configuration.'); });
        self::assertSame(503, $response->getStatusCode());
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_cdn_origin_is_rejected_before_mutation(string $origin): void
    {
        config(['v2_assets.public_base_url' => $origin]);
        $response = app(NormalizeV2AssetResponse::class)->handle(Request::create('/api/v2/gachas/a/draws', 'POST'),
            static function () { self::fail('Controller must not run with an invalid CDN origin.'); });
        self::assertSame(503, $response->getStatusCode());
    }

    public function test_named_contract_asset_slots_are_normalized_in_one_query(): void
    {
        $asset = $this->assets()[0];
        $snapshot = ['id' => $asset['public_id'], 'path' => '/admin/legacy', 'mime_type' => 'image/png'];
        $data = array_fill_keys(['asset', 'presentation_asset', 'thumbnail', 'image_asset', 'video_asset',
            'lineup_image', 'result_image', 'current_video', 'result_image_snapshot', 'rank_lineup_image', 'video_snapshot'], $snapshot);
        $data += ['ranks' => [['video' => $snapshot]], 'presentation' => ['image' => $snapshot],
            'prize' => ['image' => $snapshot], 'animation' => ['image' => $snapshot, 'video' => $snapshot]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $normalized = app(V2AssetResponseNormalizer::class)->normalize($data);
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $expected = $data;
        array_walk_recursive($expected, static function (&$value, $key) use ($asset): void {
            if ($key === 'path') {
                $value = '/gacha/'.$asset['public_id'].'.png';
            }
        });
        self::assertSame($expected, $normalized);
    }

    public function test_login_gacha_listing_and_detail_normalize_thumbnail_rank_video_and_prizes(): void
    {
        $this->assets();
        $context = $this->adminContext();
        $rankId = DB::table('catalog_rank_masters')->orderBy('id')->value('public_id');
        $catalog = app(V2CatalogMasterMutationService::class);
        $gacha = $catalog->saveComposition($context, 'synthetic-login-create', [
            'gacha_type' => 'login_daily', 'title' => 'Synthetic login', 'description' => null, 'notices' => null,
            'presentation_asset_id' => '0198a001-0000-7000-8000-000000000005',
            'price_points' => 0, 'minimum_exchange_points' => 10,
            'publish_start_at' => '2026-07-01T00:00:00Z', 'publish_end_at' => null,
            'category_id' => null, 'tag_ids' => [], 'total_count' => null, 'daily_draw_limit' => 1,
            'audience_code' => 'all_users', 'first_time_eligible_days' => 7, 'allowed_draw_counts' => [1],
            'ranks' => [['rank_id' => $rankId, 'rank_revision_number' => null,
                'video_asset_id' => '0198a001-0000-7000-8000-000000000006']],
            'prizes' => [['name' => 'Synthetic prize', 'presentation_asset_id' => '0198a001-0000-7000-8000-000000000007',
                'rank_id' => $rankId, 'exchange_points' => 10, 'cost_price' => 0, 'initial_inventory' => 3,
                'shipping_only' => false, 'percentage' => '100']],
        ])['data'];
        $catalog->publishGachaVersionImmediately($context, $gacha['id'], $gacha['current_version']['id'], 'synthetic-login-publish', [
            'expected_revision' => $gacha['current_version']['revision'], 'expected_gacha_revision' => $gacha['revision'],
        ]);
        $user = User::query()->create([
            'display_name' => 'Synthetic login reader', 'email_display' => 'cdn-login@example.test',
            'email_normalized' => 'cdn-login@example.test', 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic test password'),
            'state' => V2UserState::Active,
        ]);
        $service = app(V2LoginCatalogReadService::class);
        $listing = $service->listing();
        self::assertNotEmpty($listing['items']);
        $detail = $service->detail($listing['items'][0]['id'], $user);
        self::assertNotEmpty($detail['data']['ranks']);
        self::assertNotNull($detail['data']['ranks'][0]['video']);
        foreach ([$listing, $detail] as $data) {
            $response = app(NormalizeV2AssetResponse::class)->handle(Request::create('/api/v2/login-gachas'),
                static fn () => response()->json($data));
            self::assertSame(200, $response->getStatusCode());
            $paths = [];
            $normalized = $response->getData(true);
            array_walk_recursive($normalized, static function ($value, $key) use (&$paths): void {
                if ($key === 'path') {
                    $paths[] = $value;
                }
            });
            self::assertNotEmpty($paths);
            foreach ($paths as $path) {
                self::assertStringStartsWith('/gacha/', $path);
            }
        }
    }

    public function test_all_snapshot_shapes_and_admin_fields_use_ids_not_saved_paths_in_one_query(): void
    {
        $assets = $this->assets();
        $asset = $assets[0];
        $copy = (array) DB::table('catalog_presentation_assets')->where('public_id', $asset['public_id'])->first();
        unset($copy['id']);
        $asset['public_id'] = (string) Str::uuid7();
        DB::table('catalog_presentation_assets')->insert([
            ...$copy, 'public_id' => $asset['public_id'], 'is_public' => false, 'archived_at' => now(),
            'storage_identifier' => 'admin-assets/gacha/'.$asset['public_id'].'.png',
            'public_path' => '/api/v2/content/assets/'.$asset['public_id'],
        ]);
        $snapshot = ['id' => $asset['public_id'], 'path' => '/admin/old-path',
            'mime_type' => 'image/png', 'checksum_sha256' => $asset['checksum_sha256'], 'alt_text' => 'Original'];
        $results = [];
        for ($index = 1; $index <= 1000; $index++) {
            $results[] = ['sequence_number' => $index, 'rank' => ['code' => 'S'],
                'result_image_snapshot' => $snapshot, 'rank_lineup_image' => $snapshot,
                'prize' => ['name' => 'Unchanged', 'presentation_asset' => $snapshot],
                'video_snapshot' => [...$snapshot, 'path' => 'https://old.example.test/anything']];
        }
        $response = ['results' => $results, 'presentation' => ['video_snapshot' => $snapshot],
            'user_prize' => ['presentation' => ['image' => $snapshot]],
            'data' => [...$snapshot, 'public_path' => null, 'content_path' => '/admin/old', 'public_url' => 'https://old.example.test/a'],
            'banner' => ['image_url' => '/api/v2/content/assets/old', 'asset' => $snapshot]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $normalized = app(V2AssetResponseNormalizer::class)->normalize($response, true);
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $path = '/gacha/'.$asset['public_id'].'.png';
        self::assertSame($path, $normalized['results'][999]['prize']['presentation_asset']['path']);
        self::assertSame($path, $normalized['results'][999]['video_snapshot']['path']);
        self::assertSame($path, $normalized['data']['public_path']);
        self::assertSame('https://cdn.example.test'.$path, $normalized['data']['public_url']);
        self::assertSame($path, $normalized['banner']['image_url']);
        self::assertSame($path, $normalized['user_prize']['presentation']['image']['path']);
        self::assertSame(range(1, 1000), array_column($normalized['results'], 'sequence_number'));
        self::assertSame($response['results'][999]['rank'], $normalized['results'][999]['rank']);
        self::assertSame('/admin/old-path', $response['results'][0]['result_image_snapshot']['path']);
    }

    public function test_fixture_and_missing_asset_ids_fail_closed_without_generating_urls(): void
    {
        $assets = $this->assets(false);
        foreach ([$assets[0]['public_id'], (string) Str::uuid7()] as $id) {
            $response = app(NormalizeV2AssetResponse::class)->handle(Request::create('/api/v2/gachas'),
                static fn () => response()->json(['asset' => ['id' => $id, 'path' => '/old', 'mime_type' => 'image/png']]));
            self::assertSame(503, $response->getStatusCode());
            self::assertStringNotContainsString('/old', $response->getContent());
        }
    }

    public function test_http_catalog_draw_reads_replay_history_and_user_prizes_leave_db_unchanged(): void
    {
        $assets = $this->assets();
        $gachaId = DB::table('catalog_gachas')->value('public_id');
        $user = User::query()->create([
            'display_name' => 'Synthetic CDN reader', 'email_display' => 'cdn-reader@example.test',
            'email_normalized' => 'cdn-reader@example.test', 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic test password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree($user->id, 10000, 'cdn-test-points');
        $created = app(V2DrawService::class)->create($user, $gachaId, 1, 'cdn-test-draw-key', (string) Str::uuid7());
        $before = $this->storedState();
        Auth::guard('v2_user')->setUser($user);
        foreach (['/api/v2/gachas', '/api/v2/gachas/'.$gachaId, '/api/v2/draw-requests/'.$created['id'],
            '/api/v2/me/draws', '/api/v2/me/prizes'] as $uri) {
            $response = $this->getJson($uri)->assertOk();
            $json = json_encode($response->json(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('/api/v2/content/assets/', $json);
            self::assertStringNotContainsString('/admin/', $json);
            self::assertStringContainsString('/gacha/', $json);
        }
        $csrf = str_repeat('a', 64);
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        $replayed = $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson('/api/v2/gachas/'.$gachaId.'/draws', ['draw_count' => 1], [
                'Origin' => 'https://storefront.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => 'cdn-test-draw-key',
            ])->assertOk()->assertJsonPath('idempotent_replay', true);
        self::assertStringContainsString('/gacha/', json_encode($replayed->json(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        self::assertSame($before, $this->storedState());
        self::assertSame($assets[0]['public_path'], DB::table('catalog_presentation_assets')->where('public_id', $assets[0]['public_id'])->value('public_path'));
    }

    public static function supportedMimeTypes(): array
    {
        return array_map(static fn (string $mime): array => [$mime], [
            'image/gif', 'image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm', 'video/quicktime',
        ]);
    }

    #[DataProvider('supportedMimeTypes')]
    public function test_real_s3_adapter_sends_key_bytes_content_type_without_acl_offline(string $mime): void
    {
        $commands = [];
        $disk = Storage::build([
            ...config('filesystems.disks.s3'), 'driver' => 's3', 'bucket' => 'synthetic-test-bucket',
            'region' => 'ap-northeast-1', 'credentials' => false,
            'handler' => static function ($command, $request) use (&$commands) {
                $commands[] = $command;
                self::assertFalse($request->hasHeader('x-amz-acl'));

                return Create::promiseFor(new Result(['ETag' => 'synthetic']));
            },
        ]);
        $bytes = "synthetic-asset\x00bytes";
        $key = 'admin-assets/rank-effects/2026/10/offline-asset.bin';
        self::assertTrue($disk->put($key, $bytes, ['ContentType' => $mime]));
        self::assertCount(1, $commands);
        self::assertSame('PutObject', $commands[0]->getName());
        self::assertSame($key, $commands[0]['Key']);
        self::assertSame($mime, $commands[0]['ContentType']);
        self::assertArrayNotHasKey('ACL', $commands[0]->toArray());
        self::assertSame($bytes, (string) $commands[0]['Body']);
        self::assertSame(strlen($bytes), $commands[0]['Body']->getSize());
        self::assertSame(hash('sha256', $bytes), hash('sha256', (string) $commands[0]['Body']));
        self::assertSame('private', config('filesystems.disks.s3.visibility'));
    }

    public function test_stock_sdk_reintroduces_private_acl_when_the_option_is_removed(): void
    {
        foreach ([['ACL' => 'bucket-owner-full-control'], [], ['ACL' => null]] as $options) {
            $commands = [];
            $disk = app('filesystem')->createS3Driver([
                ...config('filesystems.disks.s3'), 'bucket' => 'synthetic-test-bucket',
                'region' => 'ap-northeast-1', 'credentials' => false, 'options' => $options,
                'handler' => static function ($command, $request) use (&$commands, $options) {
                    $commands[] = $command;
                    self::assertSame($options['ACL'] ?? 'private', $request->getHeaderLine('x-amz-acl'));

                    return Create::promiseFor(new Result(['ETag' => 'synthetic']));
                },
            ]);
            self::assertTrue($disk->put('admin-assets/gacha/unchanged.png', 'synthetic', ['ContentType' => 'image/png']));
            self::assertCount(1, $commands);
            self::assertSame($options['ACL'] ?? 'private', $commands[0]['ACL']);
        }
    }

    public function test_multipart_video_removes_initiation_acl_and_uploads_each_byte_once(): void
    {
        $commands = [];
        $parts = [];
        $key = 'admin-assets/rank-effects/2026/10/large-video.mp4';
        $disk = Storage::build([
            ...config('filesystems.disks.s3'), 'bucket' => 'synthetic-test-bucket',
            'region' => 'ap-northeast-1', 'credentials' => false,
            'handler' => static function ($command, $request) use (&$commands, &$parts, $key) {
                $name = $command->getName();
                $commands[] = $name;
                self::assertSame($key, $command['Key']);
                self::assertArrayNotHasKey('ACL', $command->toArray());
                self::assertFalse($request->hasHeader('x-amz-acl'));
                if ($name === 'CreateMultipartUpload') {
                    self::assertSame('video/mp4', $command['ContentType']);

                    return Create::promiseFor(new Result(['UploadId' => 'synthetic-upload']));
                }
                if ($name === 'UploadPart') {
                    self::assertArrayNotHasKey($command['PartNumber'], $parts);
                    $parts[$command['PartNumber']] = (string) $command['Body'];
                }

                return Create::promiseFor(new Result(['ETag' => 'synthetic', 'Location' => 'https://synthetic.example.test/video']));
            },
        ]);
        $bytes = str_repeat('synthetic-video-', 1200000);
        self::assertGreaterThan(16 * 1024 * 1024, strlen($bytes));
        self::assertTrue($disk->put($key, $bytes, ['ContentType' => 'video/mp4']));
        $counts = array_count_values($commands);
        self::assertSame(1, $counts['CreateMultipartUpload']);
        self::assertSame(1, $counts['CompleteMultipartUpload']);
        self::assertArrayNotHasKey('PutObject', $counts);
        self::assertCount(3, $counts);
        ksort($parts);
        self::assertSame($bytes, implode('', $parts));
        self::assertSame(hash('sha256', $bytes), hash('sha256', implode('', $parts)));
    }

    public function test_real_s3_adapter_read_and_delete_do_not_request_acl_operations(): void
    {
        $commands = [];
        $key = 'admin-assets/gacha/readable.png';
        $disk = Storage::build([
            ...config('filesystems.disks.s3'), 'bucket' => 'synthetic-test-bucket',
            'region' => 'ap-northeast-1', 'credentials' => false,
            'handler' => static function ($command) use (&$commands, $key) {
                $commands[] = $command->getName();
                self::assertSame($key, $command['Key']);

                return Create::promiseFor(new Result(['Body' => Utils::streamFor('synthetic-bytes'), 'ContentLength' => 15]));
            },
        ]);
        self::assertTrue($disk->exists($key));
        self::assertSame('synthetic-bytes', $disk->get($key));
        self::assertTrue($disk->delete($key));
        self::assertSame(['HeadObject', 'GetObject', 'DeleteObject'], $commands);
    }

    public function test_invalid_uuid_returns_503_without_publishing_the_old_path(): void
    {
        $response = app(NormalizeV2AssetResponse::class)->handle(Request::create('/api/v2/gachas'),
            static fn () => response()->json(['asset' => ['id' => 'not-an-asset-id',
                'path' => '/admin/old', 'mime_type' => 'image/png']]));
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('/admin/old', $response->getContent());
    }

    public function test_supported_key_without_a_file_returns_200_without_an_origin_lookup(): void
    {
        $asset = $this->assets()[0];
        Storage::disk('s3')->assertMissing($asset['storage_identifier']);
        $response = app(NormalizeV2AssetResponse::class)->handle(Request::create('/api/v2/gachas'),
            static fn () => response()->json(['asset' => ['id' => $asset['public_id'],
                'path' => '/admin/old', 'mime_type' => 'image/png']]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/gacha/'.$asset['public_id'].'.png', $response->getData(true)['asset']['path']);
        Storage::disk('s3')->assertMissing($asset['storage_identifier']);
    }

    public static function nonAssetResponses(): array
    {
        return [
            'document' => [['document' => ['path' => '/help', 'mime_type' => 'text/html']]],
            'image metadata' => [['document' => ['path' => '/help', 'mime_type' => 'image/png']]],
            'checksum' => [['document' => ['path' => '/help', 'checksum_sha256' => str_repeat('a', 64)]]],
            'public path' => [['document' => ['id' => 'not-an-asset', 'public_path' => '/help']]],
            'public url' => [['document' => ['id' => (string) Str::uuid(), 'public_url' => 'https://example.test/help']]],
            'root document' => [['id' => (string) Str::uuid(), 'path' => '/help', 'mime_type' => 'image/png']],
            'root non-asset route' => [['id' => (string) Str::uuid(), 'public_url' => 'https://example.test/help',
                'mime_type' => 'image/png', 'byte_size' => 123]],
        ];
    }

    #[DataProvider('nonAssetResponses')]
    public function test_non_asset_json_is_not_normalized_or_queried(array $data): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = app(NormalizeV2AssetResponse::class)->handle(Request::create('/api/v2/example'),
            static fn () => response()->json($data));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($data, $response->getData(true));
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_normal_assets_and_non_asset_metadata_can_coexist(): void
    {
        $asset = $this->assets()[0];
        $document = ['path' => '/help', 'mime_type' => 'text/html'];
        $response = app(NormalizeV2AssetResponse::class)->handle(Request::create('/api/v2/gachas'),
            static fn () => response()->json(['items' => [[
                'presentation_asset' => ['id' => $asset['public_id'], 'path' => '/admin/old', 'mime_type' => 'image/png'],
                'document' => $document,
            ]]]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/gacha/'.$asset['public_id'].'.png', $response->getData(true)['items'][0]['presentation_asset']['path']);
        self::assertSame($document, $response->getData(true)['items'][0]['document']);
    }

    public function test_s3_timeouts_reach_the_guzzle_handler_without_extra_operations(): void
    {
        $calls = [];
        $bytes = "synthetic-banner\x00bytes";
        $key = 'admin-assets/top-banner/2026/10/offline.png';
        $http = new Client(['handler' => HandlerStack::create(static function ($request, array $options) use (&$calls, $bytes, $key) {
            $calls[] = $request->getMethod();
            self::assertSame('/'.$key, $request->getUri()->getPath());
            self::assertFalse($request->hasHeader('x-amz-acl'));
            foreach (['connect_timeout' => 5, 'timeout' => 20, 'read_timeout' => 20] as $name => $expected) {
                self::assertSame($expected, $options[$name] ?? null);
            }
            if ($request->getMethod() === 'PUT') {
                self::assertSame('image/png', $request->getHeaderLine('Content-Type'));
                self::assertSame($bytes, (string) $request->getBody());
            }

            return Create::promiseFor(new Response(200, ['Content-Type' => 'image/png', 'Content-Length' => strlen($bytes)],
                $request->getMethod() === 'GET' ? $bytes : ''));
        })]);
        $settings = config('filesystems.disks.s3');
        foreach (['key', 'secret', 'token', 'credentials', 'profile'] as $credential) {
            self::assertArrayNotHasKey($credential, $settings);
        }
        $disk = Storage::build([...$settings, 'bucket' => 'synthetic-test-bucket', 'region' => 'ap-northeast-1',
            'credentials' => false, 'http_handler' => new GuzzleHandler($http)]);
        self::assertTrue($disk->put($key, $bytes, ['ContentType' => 'image/png']));
        self::assertTrue($disk->fileExists($key));
        self::assertSame($bytes, $disk->get($key));
        $stream = $disk->readStream($key);
        self::assertIsResource($stream);
        self::assertSame($bytes, stream_get_contents($stream));
        fclose($stream);
        self::assertSame(['PUT', 'HEAD', 'GET', 'GET'], $calls);
    }

    public static function retryControls(): array
    {
        return ['configured zero retries' => [0, 1], 'positive retry control' => [1, 2]];
    }

    #[DataProvider('retryControls')]
    public function test_s3_retryable_failure_respects_the_retry_limit_without_delete(int $retries, int $attempts): void
    {
        $calls = [];
        $http = new Client(['handler' => HandlerStack::create(static function ($request) use (&$calls) {
            $calls[] = $request->getMethod();

            return Create::promiseFor(new Response(503, ['Content-Type' => 'application/xml'],
                '<Error><Code>SlowDown</Code><Message>Synthetic failure</Message></Error>'));
        })]);
        self::assertSame(0, config('filesystems.disks.s3.retries'));
        $disk = Storage::build([...config('filesystems.disks.s3'), 'bucket' => 'synthetic-test-bucket',
            'region' => 'ap-northeast-1', 'credentials' => false, 'retries' => $retries, 'http_handler' => new GuzzleHandler($http)]);
        try {
            $disk->put('admin-assets/top-banner/2026/10/failure.png', 'synthetic', ['ContentType' => 'image/png']);
            self::fail('The failed upload must not be reported as successful.');
        } catch (\League\Flysystem\UnableToWriteFile) {
            self::assertSame(array_fill(0, $attempts, 'PUT'), $calls);
        }
    }

    public static function adminAssetResources(): array
    {
        return [
            'asset list' => ['/admin/api/v2/catalog/presentation-assets', true],
            'asset detail' => ['/admin/api/v2/catalog/presentation-assets/0198a001-0000-7000-8000-000000000005', false],
            'rank effect list' => ['/admin/api/v2/catalog/rank-effects', true],
            'rank effect detail' => ['/admin/api/v2/catalog/rank-effects/0198a001-0000-7000-8000-000000000005', false],
        ];
    }

    #[DataProvider('adminAssetResources')]
    public function test_admin_asset_resource_context_preserves_normalization_and_fail_closed(string $uri, bool $list): void
    {
        $asset = $this->assets()[0];
        $data = ['id' => $asset['public_id'], 'public_path' => null, 'content_path' => '/admin/old',
            'media_type' => 'image', 'mime_type' => 'image/png'];
        $envelope = static fn (array $item): array => ['data' => $list ? ['items' => [$item]] : $item];
        $response = app(NormalizeV2AssetResponse::class)->handle(Request::create($uri),
            static fn () => response()->json($envelope($data)));
        self::assertSame(200, $response->getStatusCode());
        $normalized = $list ? $response->getData(true)['data']['items'][0] : $response->getData(true)['data'];
        self::assertSame('/gacha/'.$asset['public_id'].'.png', $normalized['public_path']);
        self::assertSame($normalized['public_path'], $normalized['content_path']);
        $invalid = app(NormalizeV2AssetResponse::class)->handle(Request::create($uri),
            static fn () => response()->json($envelope([...$data, 'id' => 'invalid'])));
        self::assertSame(503, $invalid->getStatusCode());
        self::assertStringNotContainsString('/admin/old', $invalid->getContent());
    }

    public static function canonicalBannerDisks(): array
    {
        return ['local' => ['local'], 's3' => ['s3']];
    }

    #[DataProvider('canonicalBannerDisks')]
    public function test_new_canonical_banner_upload_and_replay_keep_storage_and_response_consistent(string $disk): void
    {
        config(['filesystems.default' => $disk, 'v2_assets.public_base_url' => $disk === 's3' ? 'https://cdn.example.test' : null,
            'v2_identity.origins.user' => 'https://storefront.example.test']);
        $context = $this->adminContext();
        $service = app(V2ContentContactAdminService::class);
        $input = ['file_name' => 'banner.png', 'mime_type' => 'image/png',
            'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='];
        $upload = fn () => app(NormalizeV2AssetResponse::class)->handle(Request::create('/admin/api/v2/banner-management/assets', 'POST'),
            fn () => response()->json(['data' => $service->uploadBannerAsset($context, $input, 'synthetic-cdn-banner')], 201));
        $response = $upload();
        self::assertSame(201, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $stored = DB::table('catalog_presentation_assets')->sole();
        $key = 'admin-assets/top-banner/2026/10/'.$data['id'].'.png';
        self::assertSame($key, $stored->storage_identifier);
        self::assertSame('/api/v2/content/assets/'.$data['id'], $stored->public_path);
        self::assertSame(base64_decode($input['content_base64']), Storage::disk($disk)->get($key));
        self::assertSame(hash('sha256', base64_decode($input['content_base64'])), $stored->checksum_sha256);
        self::assertSame(strlen(base64_decode($input['content_base64'])), $stored->byte_size);
        self::assertSame('image/png', $stored->mime_type);
        $expectedUrl = $disk === 's3' ? 'https://cdn.example.test/top-banner/2026/10/'.$data['id'].'.png'
            : 'https://storefront.example.test/api/v2/content/assets/'.$data['id'];
        self::assertSame($expectedUrl, $data['public_url']);
        $replay = $upload();
        self::assertSame(201, $replay->getStatusCode());
        self::assertTrue($replay->getData(true)['data']['idempotent_replay']);
        self::assertSame($data['id'], $replay->getData(true)['data']['id']);
        self::assertSame($expectedUrl, $replay->getData(true)['data']['public_url']);
        self::assertSame(1, DB::table('catalog_presentation_assets')->count());
        self::assertCount(1, Storage::disk($disk)->allFiles());
        $category = $service->createBannerCategory($context, ['name' => 'Synthetic'], 'synthetic-cdn-category');
        $banner = $service->createManagedBanner($context,
            ['category_id' => $category['id'], 'title' => 'Synthetic banner', 'asset_id' => $data['id']], 'synthetic-cdn-banner-create');
        $preview = app(NormalizeV2AssetResponse::class)->handle(Request::create('/admin/api/v2/banner-management/banners'),
            static fn () => response()->json(['data' => $banner]));
        self::assertSame(200, $preview->getStatusCode());
        self::assertSame($expectedUrl, $preview->getData(true)['data']['asset']['public_url']);
    }

    public static function drawProjectionCases(): array
    {
        return ['supported' => [true], 'unsupported' => [false]];
    }

    #[DataProvider('drawProjectionCases')]
    public function test_draw_response_projection_and_replay_do_not_change_committed_economics(bool $supported): void
    {
        $this->assets($supported);
        $gachaId = DB::table('catalog_gachas')->value('public_id');
        $user = User::query()->create([
            'display_name' => 'Synthetic failed presentation', 'email_display' => 'cdn-failure@example.test',
            'email_normalized' => 'cdn-failure@example.test', 'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic test password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree($user->id, 10000, 'cdn-failure-points');
        Auth::guard('v2_user')->setUser($user);
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        $csrf = str_repeat('a', 64);
        $post = fn () => $this->withCredentials()->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->postJson('/api/v2/gachas/'.$gachaId.'/draws', ['draw_count' => 1], [
                'Origin' => 'https://storefront.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => 'cdn-failure-draw-key',
            ]);
        $walletBefore = DB::table('wallets')->where('user_id', $user->id)->first();
        $response = $post()->assertStatus($supported ? 200 : 503);
        if (! $supported) {
            $response->assertJsonPath('code', 'ASSET_DELIVERY_UNAVAILABLE');
        }
        self::assertSame(1, DB::table('draw_requests')->count());
        self::assertSame('completed', DB::table('draw_requests')->value('status'));
        self::assertSame(1, DB::table('draw_results')->count());
        $stored = json_decode(DB::table('draw_requests')->value('response_data'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $stored['executed_count']);
        self::assertSame(100, $stored['point_cost_total']);
        self::assertSame($walletBefore->free_balance - 100 + $stored['point_back_total'],
            DB::table('wallets')->where('user_id', $user->id)->value('free_balance'));
        if ($supported) {
            foreach (['requested_count', 'executed_count', 'point_cost_total', 'point_consumption',
                'wallet_after', 'rank_counts', 'point_back_total', 'probability_version'] as $field) {
                self::assertEquals($stored[$field], $response->json($field));
            }
            self::assertEquals(app(V2AssetResponseNormalizer::class)->normalize($stored)['presentation'], $response->json('presentation'));
        }
        $after = $this->storedState();
        $replay = $post()->assertStatus($supported ? 200 : 503);
        if ($supported) {
            $replay->assertJsonPath('idempotent_replay', true);
            self::assertEquals($response->json('results'), $replay->json('results'));
            self::assertSame(array_column($response->json('results'), 'sequence_number'),
                array_column($replay->json('results'), 'sequence_number'));
        }
        self::assertSame($after, $this->storedState());
        $id = DB::table('draw_requests')->value('public_id');
        foreach (['/api/v2/draw-requests/'.$id, '/api/v2/me/draws', '/api/v2/me/prizes'] as $uri) {
            $this->getJson($uri)->assertStatus($supported ? 200 : 503);
        }
        self::assertSame($after, $this->storedState());
    }

    public function test_malformed_snapshot_identity_cannot_leak_an_old_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(V2AssetResponseNormalizer::class)->normalize(['asset' => ['path' => '/admin/old', 'mime_type' => 'image/png']]);
    }

    public function test_one_thousand_distinct_assets_are_resolved_in_one_select(): void
    {
        $assets = $this->assets();
        $template = (array) DB::table('catalog_presentation_assets')->where('public_id', $assets[0]['public_id'])->first();
        unset($template['id']);
        $rows = [];
        $results = [];
        for ($index = 0; $index < 1000; $index++) {
            $id = (string) Str::uuid7();
            $rows[] = [...$template, 'public_id' => $id, 'storage_identifier' => 'admin-assets/gacha/'.$id.'.png',
                'public_path' => '/api/v2/content/assets/'.$id];
            $results[] = ['sequence_number' => $index + 1, 'result_image_snapshot' => [
                'id' => $id, 'path' => '/admin/legacy/'.$index, 'mime_type' => 'image/png',
            ]];
        }
        DB::table('catalog_presentation_assets')->insert($rows);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $normalized = app(V2AssetResponseNormalizer::class)->normalize(['results' => $results]);
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        self::assertCount(1000, $normalized['results']);
        foreach ($normalized['results'] as $index => $result) {
            self::assertSame($results[$index]['result_image_snapshot']['id'], $result['result_image_snapshot']['id']);
            self::assertSame($index + 1, $result['sequence_number']);
            self::assertSame('/gacha/'.$result['result_image_snapshot']['id'].'.png', $result['result_image_snapshot']['path']);
        }
    }

    public function test_base_url_is_canonicalized_without_changing_object_key_case(): void
    {
        config(['v2_assets.public_base_url' => 'https://CDN.example.test:443/']);
        self::assertSame('https://cdn.example.test/gacha/File.PNG',
            app(V2AssetPublicPathResolver::class)->url('admin-assets/gacha/File.PNG'));
    }

    private function adminContext(): V2AdminAuthorizationContext
    {
        $admin = Admin::query()->create([
            'email_display' => 'cdn-admin@example.test', 'email_normalized' => 'cdn-admin@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic password'),
            'role' => V2AdminRole::Admin, 'state' => V2AdminState::Active,
        ]);
        $session = hash('sha256', 'synthetic-admin-session');
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $session, 'admin_id' => $admin->id, 'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false, 'created_at' => now()->subHour(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(7), 'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext($admin->id, $admin->public_id, $admin->role, $session,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($session), (string) Str::uuid7());
    }

    private function assets(bool $cdnKeys = true): array
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($cdnKeys) {
            $keys = [];
            foreach ($fixture['assets'] as $asset) {
                $keys[$asset['storage_identifier']] = 'admin-assets/gacha/'.$asset['public_id'].'.png';
            }
            array_walk_recursive($fixture, static function (&$value) use ($keys): void {
                if (is_string($value) && isset($keys[$value])) {
                    $value = $keys[$value];
                }
            });
        }
        app(V2CatalogFixtureImporter::class)->import($fixture);

        return $fixture['assets'];
    }

    private function storedState(): string
    {
        return json_encode(array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()->all(),
            ['draw_requests', 'draw_results', 'user_prizes', 'catalog_presentation_assets', 'wallets']), JSON_THROW_ON_ERROR);
    }
}
