<?php

namespace Tests\V2;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminBannerManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Storage::fake('local');
        CarbonImmutable::setTestNow('2026-08-05T03:00:00Z');
        config([
            'cache.default' => 'array',
            'filesystems.default' => 'local',
            'v2_identity.origins.user' => 'https://storefront.example.test',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_category_asset_banner_crud_filter_and_replay_are_canonical(): void
    {
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Admin);
        $categoryKey = 'banner-category-'.Str::uuid7();
        $category = $service->createBannerCategory(
            $context,
            ['name' => 'トップ'],
            $categoryKey
        );
        self::assertFalse($category['idempotent_replay']);
        self::assertTrue($service->createBannerCategory(
            $context,
            ['name' => 'トップ'],
            $categoryKey
        )['idempotent_replay']);

        $assetKey = 'banner-asset-'.Str::uuid7();
        $asset = $service->uploadBannerAsset(
            $context,
            $this->imageInput('banner.png'),
            $assetKey
        );
        self::assertSame('image/png', $asset['mime_type']);
        $expectedPublicUrl =
            'https://storefront.example.test/api/v2/content/assets/'.$asset['id'];
        self::assertSame(
            $expectedPublicUrl,
            $asset['public_url']
        );
        self::assertStringNotContainsString('admin.', $asset['public_url']);
        $content = $service->bannerAssetContent($context, $asset['id']);
        self::assertSame('image/png', $content['mime_type']);
        self::assertNotSame('', $content['content']);
        $publicResponse = $this->get('/api/v2/content/assets/'.$asset['id'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        self::assertStringContainsString('public', $publicResponse->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=31536000', $publicResponse->headers->get('Cache-Control'));
        self::assertStringContainsString('immutable', $publicResponse->headers->get('Cache-Control'));
        self::assertDatabaseHas('catalog_presentation_assets', [
            'public_id' => $asset['id'],
            'public_path' => '/api/v2/content/assets/'.$asset['id'],
            'is_public' => true,
        ]);
        DB::table('idempotency_records')
            ->where('resource_public_id', $asset['id'])
            ->update(['response_data' => json_encode(['data' => [
                'id' => $asset['id'],
                'public_url' => '/admin/api/v2/banner-management/assets/'.$asset['id'].'/content',
                'mime_type' => 'image/png',
                'byte_size' => $asset['byte_size'],
            ]], JSON_THROW_ON_ERROR)]);
        $assetReplay = $service->uploadBannerAsset(
            $context,
            $this->imageInput('banner.png'),
            $assetKey
        );
        self::assertSame($expectedPublicUrl, $assetReplay['public_url']);
        self::assertTrue($assetReplay['idempotent_replay']);

        $createKey = 'banner-create-'.Str::uuid7();
        $input = [
            'category_id' => $category['id'],
            'title' => 'メインバナー',
            'asset_id' => $asset['id'],
            'show_on_top' => true,
            'link_url' => '/gachas',
        ];
        $created = $service->createManagedBanner($context, $input, $createKey);
        self::assertSame('draft', $created['status']);
        self::assertSame($expectedPublicUrl, $created['asset']['public_url']);
        self::assertSame('トップ', $created['category']['name']);
        self::assertTrue($created['show_on_top']);
        self::assertSame('/gachas', $created['link_url']);
        self::assertTrue($service->createManagedBanner(
            $context,
            $input,
            $createKey
        )['idempotent_replay']);
        self::assertDatabaseCount('content_banners', 1);
        self::assertDatabaseCount('content_versions', 1);

        $filtered = $service->managedBanners($context, null, 20, $category['id']);
        self::assertCount(1, $filtered['items']);
        self::assertSame($expectedPublicUrl, $filtered['items'][0]['asset']['public_url']);
        self::assertSame($created['id'], $filtered['items'][0]['id']);
        self::assertCount(1, $service->managedBanners(
            $context, null, 20, null, 'draft'
        )['items']);
        self::assertCount(0, $service->managedBanners(
            $context, null, 20, null, 'published'
        )['items']);

        $updated = $service->updateManagedBanner($context, $created['id'], [
            'category_id' => $category['id'],
            'title' => '更新バナー',
            'asset_id' => null,
            'show_on_top' => false,
            'link_url' => 'javascript:ignored()',
        ], 'banner-update-'.Str::uuid7());
        self::assertSame(2, $updated['version_number']);
        self::assertSame($asset['id'], $updated['asset']['id']);
        self::assertFalse($updated['show_on_top']);
        self::assertNull($updated['link_url']);

        $deleted = $service->deleteManagedBanner(
            $context,
            $created['id'],
            'banner-delete-'.Str::uuid7()
        );
        self::assertTrue($deleted['deleted']);
        self::assertTrue($deleted['asset_retained']);
        self::assertDatabaseHas('content_banners', [
            'public_id' => $created['id'],
            'status' => 'archived',
        ]);
        self::assertDatabaseHas('catalog_presentation_assets', ['public_id' => $asset['id']]);
        self::assertDatabaseCount('content_versions', 2);
        self::assertSame([], $service->managedBanners($context, null, 20, null)['items']);
    }

    public function test_validation_permission_and_idempotency_conflicts_fail_closed(): void
    {
        $service = app(V2ContentContactAdminService::class);
        $admin = $this->context(V2AdminRole::Admin);
        $key = 'banner-category-conflict-'.Str::uuid7();
        $service->createBannerCategory($admin, ['name' => '検索'], $key);

        try {
            $service->createBannerCategory($admin, ['name' => '別名'], $key);
            self::fail('A reused key with another payload must fail.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('BANNER_IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }

        try {
            $service->uploadBannerAsset($admin, [
                'file_name' => 'bad.svg',
                'mime_type' => 'image/svg+xml',
                'content_base64' => base64_encode('<svg/>'),
            ], 'banner-bad-asset-'.Str::uuid7());
            self::fail('Unsupported images must fail.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('BANNER_ASSET_INVALID', $exception->errorCode);
        }

        $category = $service->createBannerCategory(
            $admin,
            ['name' => 'URL検証'],
            'banner-category-url-'.Str::uuid7()
        );
        $asset = $service->uploadBannerAsset(
            $admin,
            $this->imageInput('url.png'),
            'banner-url-asset-'.Str::uuid7()
        );
        foreach ([null, 'javascript:alert(1)', '//evil.example/path'] as $linkUrl) {
            try {
                $service->createManagedBanner($admin, [
                    'category_id' => $category['id'],
                    'title' => '危険URL',
                    'asset_id' => $asset['id'],
                    'show_on_top' => true,
                    'link_url' => $linkUrl,
                ], 'banner-url-invalid-'.Str::uuid7());
                self::fail('Top Banners must require a safe click URL.');
            } catch (V2ContentContactException $exception) {
                self::assertContains($exception->errorCode, [
                    'BANNER_TOP_LINK_REQUIRED',
                    'CONTENT_LINK_INVALID',
                ]);
            }
        }

        try {
            $service->createBannerCategory(
                $this->context(V2AdminRole::Operator),
                ['name' => '拒否'],
                'banner-denied-'.Str::uuid7()
            );
            self::fail('Operator must not mutate banners.');
        } catch (V2AuthenticationException $exception) {
            self::assertSame('AUTHORIZATION_DENIED', $exception->errorCode);
        }
    }

    public function test_routes_expose_only_the_requested_banner_management_mutations(): void
    {
        $methods = collect(app('router')->getRoutes())
            ->filter(static fn ($route): bool => str_starts_with(
                $route->uri(),
                'admin/api/v2/banner-management'
            ))
            ->flatMap(static fn ($route) => collect($route->methods())
                ->reject(static fn (string $method): bool => $method === 'HEAD')
                ->map(static fn (string $method): string => $method.' '.$route->uri()))
            ->values();

        self::assertContains('GET admin/api/v2/banner-management/categories', $methods);
        self::assertContains('POST admin/api/v2/banner-management/categories', $methods);
        self::assertContains('POST admin/api/v2/banner-management/assets', $methods);
        self::assertContains(
            'GET admin/api/v2/banner-management/assets/{assetId}/content',
            $methods
        );
        self::assertContains('GET admin/api/v2/banner-management/banners', $methods);
        self::assertContains('POST admin/api/v2/banner-management/banners', $methods);
        self::assertContains('PUT admin/api/v2/banner-management/banners/{bannerId}', $methods);
        self::assertContains('DELETE admin/api/v2/banner-management/banners/{bannerId}', $methods);
    }

    public function test_external_id_normalization_conflicts_archive_reuse_filter_and_audit(): void
    {
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Admin);
        $category = $service->createBannerCategory($context, ['name' => 'External IDs'], (string) Str::uuid7());
        $asset = $service->uploadBannerAsset($context, $this->imageInput('external.png'), (string) Str::uuid7());
        $input = ['title' => 'External banner', 'category_id' => $category['id'], 'asset_id' => $asset['id']];
        $empty = $service->createManagedBanner($context, $input, (string) Str::uuid7());
        self::assertNull($empty['external_id']);
        $first = $service->createManagedBanner($context, [...$input, 'external_id' => ' CARD-0001 '], (string) Str::uuid7());
        self::assertSame('CARD-0001', $first['external_id']);
        self::assertSame('CARD-0001', $service->managedBannerDetail($context, $first['id'])['external_id']);
        self::assertSame([$first['id']], array_column($service->managedBanners($context, null, 20, null, null, 'CARD-0001')['items'], 'id'));
        self::assertSame([], $service->managedBanners($context, null, 20, null, null, 'CARD')['items']);
        $case = $service->createManagedBanner($context, [...$input, 'external_id' => 'card-0001'], (string) Str::uuid7());
        self::assertSame('card-0001', $case['external_id']);
        foreach ([null, $empty['id']] as $updateId) {
            try {
                $updateId === null
                    ? $service->createManagedBanner($context, [...$input, 'external_id' => 'CARD-0001'], (string) Str::uuid7())
                    : $service->updateManagedBanner($context, $updateId, [...$input, 'external_id' => 'CARD-0001'], (string) Str::uuid7());
                self::fail('A duplicate active Banner external ID must be rejected.');
            } catch (V2ContentContactException $exception) {
                self::assertSame(409, $exception->status);
                self::assertSame('BANNER_EXTERNAL_ID_CONFLICT', $exception->errorCode);
            }
        }
        self::assertSame('CARD-0001', $service->updateManagedBanner($context, $first['id'], $input, (string) Str::uuid7())['external_id']);
        self::assertSame('abc.DEF_123-xyz', $service->updateManagedBanner($context, $first['id'], [...$input, 'external_id' => 'abc.DEF_123-xyz'], (string) Str::uuid7())['external_id']);
        self::assertNull($service->updateManagedBanner($context, $first['id'], [...$input, 'external_id' => ''], (string) Str::uuid7())['external_id']);
        $service->updateManagedBanner($context, $first['id'], [...$input, 'external_id' => 'CARD-0001'], (string) Str::uuid7());
        self::assertNull($service->updateManagedBanner($context, $first['id'], [...$input, 'external_id' => null], (string) Str::uuid7())['external_id']);
        $service->updateManagedBanner($context, $first['id'], [...$input, 'external_id' => 'CARD-0001'], (string) Str::uuid7());
        $service->deleteManagedBanner($context, $first['id'], (string) Str::uuid7());
        self::assertSame('CARD-0001', $service->createManagedBanner($context, [...$input, 'external_id' => 'CARD-0001'], (string) Str::uuid7())['external_id']);
        $audit = DB::table('audit_logs')->where('target_public_id', $first['id'])
            ->where('action_code', 'content.banner_updated')->orderByDesc('id')->firstOrFail();
        self::assertSame(['external_id' => null], json_decode($audit->before_redacted, true));
        self::assertSame(['external_id' => 'CARD-0001'], json_decode($audit->after_redacted, true));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('externalIdInputs')]
    public function test_external_id_validation(mixed $value, ?string $normalized, bool $valid): void
    {
        $service = app(V2ContentContactAdminService::class);
        $context = $this->context(V2AdminRole::Admin);
        $category = $service->createBannerCategory($context, ['name' => 'External validation'], (string) Str::uuid7());
        $asset = $service->uploadBannerAsset($context, $this->imageInput('validation.png'), (string) Str::uuid7());
        try {
            $result = $service->createManagedBanner($context, [
                'title' => 'Validation', 'category_id' => $category['id'], 'asset_id' => $asset['id'], 'external_id' => $value,
            ], (string) Str::uuid7());
            self::assertTrue($valid);
            self::assertSame($normalized, $result['external_id']);
        } catch (V2ContentContactException $exception) {
            self::assertFalse($valid);
            self::assertSame(422, $exception->status);
            self::assertSame('BANNER_EXTERNAL_ID_INVALID', $exception->errorCode);
        }
    }

    public function test_external_id_columns_are_nullable_and_only_banners_have_partial_uniqueness(): void
    {
        foreach (['content_banners', 'catalog_prizes'] as $table) {
            $column = DB::table('information_schema.columns')->where('table_schema', 'public')
                ->where('table_name', $table)->where('column_name', 'external_id')->firstOrFail();
            self::assertSame('YES', $column->is_nullable);
            self::assertSame(64, (int) $column->character_maximum_length);
            self::assertSame('C', $column->collation_name);
        }
        $bannerIndex = DB::table('pg_indexes')->where('indexname', 'content_banners_external_id_active_unique')->value('indexdef');
        self::assertStringContainsString('UNIQUE INDEX', $bannerIndex);
        self::assertStringContainsString("(status)::text <> 'archived'::text", $bannerIndex);
        $prizeIndex = DB::table('pg_indexes')->where('indexname', 'catalog_prizes_external_id_index')->value('indexdef');
        self::assertStringNotContainsString('UNIQUE', $prizeIndex);
        self::assertStringContainsString('(external_id)', $prizeIndex);
    }

    public static function externalIdInputs(): array
    {
        return [
            ['CARD-0001', 'CARD-0001', true], [' abc.DEF_123-xyz ', 'abc.DEF_123-xyz', true],
            ['', null, true], [null, null, true], ['   ', null, true],
            [str_repeat('a', 64), str_repeat('a', 64), true], [str_repeat('a', 65), null, false],
            ['日本語', null, false], ['ＡＢＣ１２３', null, false], ['CARD 001', null, false],
            ['CARD/001', null, false], ['CARD@001', null, false], [123, null, false], [[], null, false],
        ];
    }

    /** @return array<string, string> */
    private function imageInput(string $name): array
    {
        return [
            'file_name' => $name,
            'mime_type' => 'image/png',
            'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ];
    }

    private function context(V2AdminRole $role): V2AdminAuthorizationContext
    {
        $email = 'banner-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now()->subHour(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(7),
            'revoked_at' => null,
        ]);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash),
            (string) Str::uuid7()
        );
    }
}
