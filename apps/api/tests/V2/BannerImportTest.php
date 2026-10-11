<?php

namespace Tests\V2;

use App\Domain\ContentContact\Contracts\V2BannerImportResolver;
use App\Domain\ContentContact\Contracts\V2BannerImportTransport;
use App\Domain\ContentContact\Services\V2BannerImportService;
use App\Domain\ContentContact\Services\V2BannerImportWorker;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BannerImportTest extends TestCase
{
    protected V2AdminAuthorizationContext $context;
    protected string $sessionToken;
    protected array $category;
    protected V2BannerImportService $imports;
    protected V2ContentContactAdminService $banners;
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-10-11T03:00:00Z');
        Storage::fake('local');
        config(['filesystems.default' => 'local', 'cache.default' => 'array', 'v2_banner_import.enabled' => true,
            'v2_banner_import.image_hosts' => ['images.example.test'],
            'v2_audit.active_hmac_key_version' => 'v1', 'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo', 'v2_identity.origins.user' => 'https://storefront.example.test']);
        $email = 'import-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create(['email_display' => $email, 'email_normalized' => $email,
            'email_verified_at' => now(), 'password_hash' => app(\App\Domain\Identity\Services\V2PasswordPolicy::class)->hash('synthetic-password'),
            'role' => V2AdminRole::Admin, 'state' => V2AdminState::Active]);
        $this->sessionToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $this->sessionToken);
        DB::table('admin_sessions')->insert(['session_id_hash' => $hash, 'admin_id' => $admin->id,
            'mfa_verified_at' => now(), 'requires_mfa_enrollment' => false, 'created_at' => now(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(7)]);
        $this->context = new V2AdminAuthorizationContext($admin->id, $admin->public_id, $admin->role, $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash), (string) Str::uuid7());
        $this->banners = app(V2ContentContactAdminService::class);
        $this->category = $this->banners->createBannerCategory($this->context, ['name' => 'カード'], (string) Str::uuid7());
        $this->imports = app(V2BannerImportService::class);
        $resolver = $this->createStub(V2BannerImportResolver::class);
        $resolver->method('resolve')->willReturn(['93.184.215.14']);
        $transport = $this->createStub(V2BannerImportTransport::class);
        $transport->method('fetch')->willReturn(base64_decode(self::PNG));
        $this->app->instance(V2BannerImportResolver::class, $resolver);
        $this->app->instance(V2BannerImportTransport::class, $transport);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    protected function input(string $rows, bool $refresh = false): array
    {
        return ['file_name' => 'synthetic.csv', 'content_base64' => base64_encode("管理ID,タイトル,画像URL,カテゴリ\n".$rows),
            'default_category_id' => $this->category['id'], 'refresh_existing_images' => $refresh];
    }

    protected function createJob(string $rows, bool $refresh = false): array
    {
        return $this->imports->create($this->context, $this->input($rows, $refresh), (string) Str::uuid7());
    }

    protected function banner(?string $externalId = 'CARD-1', bool $top = false): array
    {
        $asset = $this->banners->uploadBannerAsset($this->context, ['file_name' => 'synthetic.png',
            'mime_type' => 'image/png', 'content_base64' => self::PNG], (string) Str::uuid7());
        return $this->banners->createManagedBanner($this->context, ['external_id' => $externalId, 'title' => '元のタイトル',
            'category_id' => $this->category['id'], 'asset_id' => $asset['id'], 'show_on_top' => $top,
            'link_url' => $top ? '/gachas' : null], (string) Str::uuid7());
    }

    public function test_create_replay_draft_and_secret_deletion(): void
    {
        $input = $this->input('CARD-1,画像,https://images.example.test/image?signature=synthetic-secret,');
        $key = (string) Str::uuid7();
        $job = $this->imports->create($this->context, $input, $key);
        self::assertSame($job['id'], $this->imports->create($this->context, $input, $key)['id']);
        self::assertDatabaseCount('content_banner_import_jobs', 1);
        $cipher = DB::table('content_banner_import_rows')->value('url_ciphertext');
        self::assertStringNotContainsString('images.example.test', $cipher);
        self::assertStringNotContainsString('synthetic-secret', json_encode($job));
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        $result = $this->imports->result($job['id']);
        self::assertSame('completed', $result['status']);
        self::assertSame(1, $result['created']);
        $banner = $this->banners->managedBannerDetail($this->context, DB::table('content_banners')->value('public_id'));
        self::assertSame('CARD-1', $banner['external_id']);
        self::assertSame('draft', $banner['status']);
        self::assertFalse($banner['show_on_top']);
        self::assertNull(DB::table('content_banner_import_rows')->value('url_ciphertext'));
        self::assertSame(0, app(V2BannerImportWorker::class)->runOnce());
        self::assertDatabaseCount('catalog_presentation_assets', 1);
    }

    public function test_existing_published_top_settings_and_unmanaged_banner_are_preserved(): void
    {
        $unmanaged = $this->banner(null);
        $banner = $this->banner('CARD-1', true);
        $version = DB::table('content_versions')->where('public_id', $banner['version_id'])->first();
        $this->banners->publish($this->context, 'banner', $banner['id'], $version->public_id);
        $before = DB::table('content_banners')->where('public_id', $banner['id'])->first();
        $resolver = $this->createMock(V2BannerImportResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $this->app->instance(V2BannerImportResolver::class, $resolver);
        $job = $this->createJob('CARD-1,変更,not-a-url,');
        self::assertNull(DB::table('content_banner_import_rows')->value('url_ciphertext'));
        self::assertSame(1, app(V2BannerImportWorker::class)->runOnce());
        self::assertSame(1, $this->imports->result($job['id'])['updated']);
        $after = DB::table('content_banners')->where('public_id', $banner['id'])->first();
        self::assertSame($before->status, $after->status);
        self::assertSame($before->published_version_id, $after->published_version_id);
        $latest = DB::table('content_versions')->where('banner_id', $after->id)->orderByDesc('version_number')->first();
        foreach (['link_url', 'show_on_top', 'sort_order', 'publish_start_at', 'publish_end_at'] as $field) {
            self::assertSame($version->$field, $latest->$field, $field);
        }
        self::assertSame('元のタイトル', $this->banners->managedBannerDetail($this->context, $unmanaged['id'])['title']);
        $again = $this->createJob('CARD-1,変更,ignored,');
        app(V2BannerImportWorker::class)->runOnce();
        self::assertSame(1, $this->imports->result($again['id'])['unchanged']);
        self::assertDatabaseCount('catalog_presentation_assets', 2);
    }

    public function test_refresh_with_same_sha_does_not_upload_or_add_version(): void
    {
        $this->banner();
        $job = $this->createJob('CARD-1,元のタイトル,https://images.example.test/image,', true);
        app(V2BannerImportWorker::class)->runOnce();
        self::assertSame(1, $this->imports->result($job['id'])['unchanged']);
        self::assertDatabaseCount('catalog_presentation_assets', 1);
        self::assertDatabaseCount('content_versions', 1);
    }

    public function test_http_authorization_validation_idempotency_and_disabled_boundary(): void
    {
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
        $csrf = str_repeat('a', 64);
        $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->sessionToken)
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin', 'X-XSRF-TOKEN' => $csrf]);
        $path = '/admin/api/v2/banner-management/imports';
        $input = $this->input('CARD-1,画像,https://images.example.test/image,');
        $this->postJson($path, $input)->assertStatus(409);
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())->postJson($path, $this->input('bad/id,画像,,'))->assertUnprocessable()->assertJsonPath('code', 'CSV_VALIDATION_FAILED');
        $this->withHeader('Idempotency-Key', (string) Str::uuid7())->postJson($path, $input)->assertStatus(202)->assertJsonPath('total', 1);
        $this->getJson($path)->assertOk()->assertJsonPath('enabled', true)->assertJsonCount(1, 'items');
        config(['v2_banner_import.enabled' => false]);
        $this->postJson($path, $input)->assertStatus(503)->assertJsonPath('code', 'BANNER_IMPORT_DISABLED');
        $this->getJson($path)->assertOk()->assertJsonPath('enabled', false);
        self::assertDatabaseCount('content_banner_import_jobs', 1);
    }

    public function test_invalid_secret_ttl_and_missing_history_fail_with_domain_errors(): void
    {
        config(['v2_banner_import.url_ttl_seconds' => 1801]);
        try {
            $this->createJob('CARD-1,画像,https://images.example.test/image,');
            self::fail('Unbounded secret retention must not create a job.');
        } catch (\App\Domain\ContentContact\Exceptions\V2ContentContactException $exception) {
            self::assertSame('BANNER_IMPORT_CONFIGURATION_INVALID', $exception->errorCode);
        }
        self::assertDatabaseCount('content_banner_import_jobs', 0);
        $this->expectException(\App\Domain\ContentContact\Exceptions\V2ContentContactException::class);
        $this->expectExceptionMessage('取込履歴が見つかりません。');
        $this->imports->show($this->context, (string) Str::uuid7());
    }
}
