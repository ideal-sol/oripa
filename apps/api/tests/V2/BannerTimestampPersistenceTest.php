<?php

namespace Tests\V2;

use App\Domain\Audit\V2\Services\V2AuditHasher;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\ContentContact\Services\V2ContentReadService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2Realm;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionManager;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use App\Models\V2\ContentVersion;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BannerTimestampPersistenceTest extends TestCase
{
    private CarbonImmutable $instant;
    private V2ContentContactAdminService $service;
    private V2AdminAuthorizationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('Asia/Tokyo', config('app.timezone'));
        self::assertSame('Asia/Tokyo', date_default_timezone_get());
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertSame(DB::connection()->getConfig('timezone'), DB::selectOne('SHOW TIME ZONE')->TimeZone);
        DB::beginTransaction();
        Storage::fake('local');
        $this->instant = CarbonImmutable::parse('2026-09-17T10:28:20+09:00');
        Carbon::setTestNow($this->instant);
        config(['cache.default' => 'array', 'v2_identity.origins.user' => 'https://storefront.example.test']);
        $email = 'banner-timestamp-'.Str::uuid7().'@example.test';
        $admin = Admin::query()->create([
            'email_display' => $email, 'email_normalized' => $email,
            'password_hash' => app(V2PasswordPolicy::class)->hash('synthetic timestamp password'),
            'role' => V2AdminRole::Admin, 'state' => V2AdminState::Active,
        ]);
        $session = app(V2SessionManager::class)->issue(V2Realm::Admin, $admin->id, true);
        $sessionHash = app(V2SessionPolicy::class)->hashSessionId($session['token']);
        $this->context = new V2AdminAuthorizationContext(
            $admin->id, $admin->public_id, $admin->role, $sessionHash,
            app(V2AuditHasher::class)->correlation($sessionHash), (string) Str::uuid7()
        );
        $this->service = app(V2ContentContactAdminService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_managed_banner_create_update_and_dependencies_store_the_actual_instant(): void
    {
        $category = $this->service->createBannerCategory($this->context, ['name' => 'Timestamp'], 'category-'.Str::uuid7());
        $asset = $this->asset();
        $input = [
            'title' => 'Timestamp banner', 'category_id' => $category['id'], 'asset_id' => $asset,
            'show_on_top' => true, 'link_url' => '/gachas',
        ];
        $banner = $this->service->createManagedBanner($this->context, $input, 'create-'.Str::uuid7());
        $parent = DB::table('content_banners')->where('public_id', $banner['id'])->first();
        $version = ContentVersion::query()->where('banner_id', $parent->id)->firstOrFail();
        foreach (['created_at', 'updated_at'] as $column) {
            $this->assertInstant($parent->{$column}, $this->instant);
            $this->assertInstant($version->{$column}, $this->instant);
            $this->assertInstant(DB::table('content_banner_categories')->where('public_id', $category['id'])->value($column), $this->instant);
            $this->assertInstant(DB::table('catalog_presentation_assets')->where('public_id', $asset)->value($column), $this->instant);
            $this->assertInstant(DB::table('content_version_assets')->where('content_version_id', $version->id)->value($column), $this->instant);
        }
        $this->assertInstant($version->publish_start_at, $this->instant);
        self::assertNull($version->publish_end_at);
        self::assertSame('2026-09-17T01:28:20.000000Z', $version->toArray()['publish_start_at']);

        $historical = $this->instant->addHours(9);
        DB::table('content_banners')->where('id', $parent->id)->update(['created_at' => V2DatabaseTimestamp::format($historical)]);
        Carbon::setTestNow($this->instant->addSeconds(10));
        $updated = $this->service->updateManagedBanner($this->context, $banner['id'], [...$input, 'title' => 'Updated'], 'update-'.Str::uuid7());
        $parent = DB::table('content_banners')->where('id', $parent->id)->first();
        $this->assertInstant($parent->created_at, $historical);
        $this->assertInstant($parent->updated_at, $this->instant->addSeconds(10));
        $this->assertInstant($updated['updated_at'], $this->instant->addSeconds(10));
        $this->assertInstant($version->fresh()->created_at, $this->instant);
        $latest = ContentVersion::query()->where('banner_id', $parent->id)->orderByDesc('version_number')->firstOrFail();
        $this->assertInstant($latest->publish_start_at, $this->instant->addSeconds(10));
        $this->assertInstant($latest->created_at, $this->instant->addSeconds(10));
        $this->service->publish($this->context, 'banner', $banner['id'], $latest->public_id);
        $this->assertInstant($latest->fresh()->published_at, $this->instant->addSeconds(10));
    }

    public function test_publication_is_start_inclusive_and_end_exclusive_for_the_same_instant(): void
    {
        $content = $this->service->createContent($this->context, 'banner', [
            'code' => 'boundary-'.Str::uuid7(), 'title' => 'Boundary', 'show_on_top' => true,
            'link_url' => '/gachas', 'asset_id' => $this->asset(),
            'publish_start_at' => '2026-09-17T10:28:20+09:00',
            'publish_end_at' => '2026-09-17T01:29:20Z',
        ]);
        $version = ContentVersion::query()->where('public_id', $content['versions'][0]['id'])->firstOrFail();
        $this->assertInstant($version->publish_start_at, $this->instant);
        $this->assertInstant($version->publish_end_at, $this->instant->addMinute());
        $this->assertInstant(DB::table('content_banners')->where('public_id', $content['id'])->value('created_at'), $this->instant);
        $this->service->publish($this->context, 'banner', $content['id'], $version->public_id);
        foreach ([-1 => false, 0 => true, 59 => true, 60 => false] as $seconds => $visible) {
            Carbon::setTestNow($this->instant->addSeconds($seconds));
            $items = app(V2ContentReadService::class)->banners()['items'];
            self::assertSame($visible, in_array($content['id'], array_column($items, 'id'), true));
            if ($visible) {
                $item = collect($items)->firstWhere('id', $content['id']);
                $this->assertInstant($item['publish_start_at'], $this->instant);
                self::assertMatchesRegularExpression('/T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $item['publish_start_at']);
            }
        }
    }

    public function test_naive_input_null_and_model_date_casts_keep_existing_semantics(): void
    {
        $input = [
            'code' => 'naive-'.Str::uuid7(), 'title' => 'Naive',
            'publish_start_at' => '2026-09-17 10:28:20', 'publish_end_at' => null,
        ];
        $content = $this->service->createContent($this->context, 'banner', $input);
        $version = ContentVersion::query()->where('public_id', $content['versions'][0]['id'])->firstOrFail();
        $this->assertInstant($version->publish_start_at, $this->instant);
        self::assertNull($version->publish_end_at);
        $version->forceFill([
            'publish_start_at' => $this->instant->utc()->toIso8601String(),
            'publish_end_at' => $this->instant->addMinute(),
        ])->save();
        $this->assertInstant($version->fresh()->publish_start_at, $this->instant);
        $this->assertInstant($version->fresh()->publish_end_at, $this->instant->addMinute());
        $version->forceFill(['publish_end_at' => null])->save();
        self::assertNull($version->fresh()->publish_end_at);
        try {
            $this->service->createContent($this->context, 'banner', [...$input, 'code' => 'invalid-'.Str::uuid7(), 'publish_start_at' => null]);
            self::fail('A null publication start must remain invalid.');
        } catch (V2ContentContactException $exception) {
            self::assertSame('CONTENT_PUBLISH_PERIOD_INVALID', $exception->errorCode);
        }
    }

    private function asset(): string
    {
        return $this->service->uploadBannerAsset($this->context, [
            'file_name' => 'timestamp.png', 'mime_type' => 'image/png',
            'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ], 'asset-'.Str::uuid7())['id'];
    }

    private function assertInstant(DateTimeInterface|string $actual, DateTimeInterface $expected): void
    {
        self::assertSame($expected->getTimestamp(), CarbonImmutable::parse($actual)->getTimestamp());
    }
}
