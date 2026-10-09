<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2AssetPublicPathResolver;
use App\Domain\Catalog\Services\V2AssetResponseNormalizer;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Http\Middleware\V2\NormalizeV2AssetResponse;
use App\Models\V2\User;
use Aws\Result;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
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
            'user_prize' => ['image' => $snapshot],
            'admin' => [...$snapshot, 'public_path' => null, 'content_path' => '/admin/old', 'public_url' => 'https://old.example.test/a'],
            'banner' => ['image_url' => '/api/v2/content/assets/old', 'asset' => $snapshot]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $normalized = app(V2AssetResponseNormalizer::class)->normalize($response);
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $path = '/gacha/'.$asset['public_id'].'.png';
        self::assertSame($path, $normalized['results'][999]['prize']['presentation_asset']['path']);
        self::assertSame($path, $normalized['results'][999]['video_snapshot']['path']);
        self::assertSame($path, $normalized['admin']['public_path']);
        self::assertSame('https://cdn.example.test'.$path, $normalized['admin']['public_url']);
        self::assertSame($path, $normalized['banner']['image_url']);
        self::assertSame($path, $normalized['user_prize']['image']['path']);
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
    public function test_real_s3_adapter_sends_key_bytes_content_type_and_non_public_acl_offline(string $mime): void
    {
        $commands = [];
        $disk = Storage::build([
            ...config('filesystems.disks.s3'), 'driver' => 's3', 'bucket' => 'synthetic-test-bucket',
            'region' => 'ap-northeast-1', 'credentials' => false,
            'handler' => static function ($command) use (&$commands) {
                $commands[] = $command;

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
        self::assertSame('bucket-owner-full-control', $commands[0]['ACL']);
        self::assertSame($bytes, (string) $commands[0]['Body']);
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
