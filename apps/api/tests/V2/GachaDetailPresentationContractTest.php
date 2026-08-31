<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GachaDetailPresentationContractTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-29T00:00:00Z');
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_public_detail_exposes_sale_state_without_user_state_in_public_cache(): void
    {
        $this->import(allowedDrawCounts: [1, 5, 10, 100, 1000]);

        $onSale = $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'on_sale');
        self::assertStringContainsString(
            'public',
            (string) $onSale->headers->get('Cache-Control')
        );
        $serialized = $onSale->getContent();
        self::assertIsString($serialized);
        foreach (['user_state', 'eligible', 'daily_limit', 'allowed_draw_counts', 'cta'] as $key) {
            self::assertStringNotContainsString('"'.$key.'"', $serialized);
        }

        CarbonImmutable::setTestNow('2025-12-31T23:59:59Z');
        $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'coming_soon');
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.display.show_price_points', true);

        CarbonImmutable::setTestNow('2027-01-01T00:00:00Z');
        $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'ended');
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.display.show_price_points', false)
            ->assertJsonPath('data.display.show_total_count', false)
            ->assertJsonPath('data.display.show_drawn_count', false);

        CarbonImmutable::setTestNow('2026-07-29T00:00:00Z');
        $adminPublicId = (string) Str::uuid7();
        DB::table('admins')->insert([
            'public_id' => $adminPublicId,
            'email_display' => 'pause-owner@example.test',
            'email_normalized' => 'pause-owner@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => 'owner',
            'state' => 'active',
        ]);
        $gacha = DB::table('catalog_gachas')->firstOrFail();
        DB::table('catalog_gachas')->where('id', $gacha->id)->update([
            'management_status' => 'sales_paused',
            'sales_paused' => true,
            'sales_paused_at' => now(),
            'sales_paused_by_admin_public_id' => $adminPublicId,
            'sales_pause_reason_code' => 'operations_review',
            'sales_last_mutation_request_id' => (string) Str::uuid7(),
            'revision' => (int) $gacha->revision + 1,
        ]);
        $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'paused')
            ->assertJsonPath('data.total_count', 110)
            ->assertJsonPath('data.remaining_count', 110);
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'paused')
            ->assertJsonPath('data.ineligible_reason', 'sales_paused')
            ->assertJsonPath('data.allowed_draw_counts', [])
            ->assertJsonPath('data.display.show_price_points', true)
            ->assertJsonPath('data.display.show_total_count', true)
            ->assertJsonPath('data.display.show_drawn_count', true);

        DB::table('catalog_gachas')->where('id', $gacha->id)->update([
            'management_status' => 'published',
            'sales_paused' => false,
            'sales_resumed_at' => now(),
            'sales_last_mutation_request_id' => (string) Str::uuid7(),
            'revision' => (int) $gacha->revision + 2,
        ]);
        DB::table('gacha_draw_states')->update([
            'status' => 'sold_out',
            'sold_count' => 1000,
            'sold_out_at' => now(),
        ]);
        DB::table('prize_inventories')->update([
            'available_quantity' => 0,
            'withdrawn_quantity' => DB::raw('total_quantity - awarded_count'),
        ]);
        $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'sold_out');
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.display.show_price_points', false)
            ->assertJsonPath('data.display.show_total_count', false)
            ->assertJsonPath('data.display.show_drawn_count', false);

        $inventory = DB::table('prize_inventories')->orderBy('id')->firstOrFail();
        DB::table('prize_inventories')->where('id', $inventory->id)->update([
            'total_quantity' => (int) $inventory->total_quantity + 1,
            'available_quantity' => 1,
        ]);
        $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'on_sale')
            ->assertJsonPath('data.total_count', 111)
            ->assertJsonPath('data.remaining_count', 1);
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'on_sale');
    }

    public function test_public_ranks_include_only_canonical_prize_ranks_and_total_stock_is_configured_total(): void
    {
        $this->import();

        $initial = $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()->json('data');
        self::assertNotEmpty($initial['ranks']);
        $canonicalRank = $initial['ranks'][0];
        self::assertArrayHasKey('rank_id', $canonicalRank);
        self::assertArrayHasKey('rank_name', $canonicalRank);
        self::assertArrayHasKey('lineup_image', $canonicalRank);
        self::assertArrayHasKey('current_video', $canonicalRank);
        self::assertArrayHasKey('display_order', $canonicalRank);
        self::assertFalse($canonicalRank['show_total_stock']);
        self::assertNull($canonicalRank['total_stock']);
        self::assertArrayNotHasKey('available_quantity', $canonicalRank);

        $gacha = DB::table('catalog_gachas')->where('public_id', self::GACHA_ID)->firstOrFail();
        $template = DB::table('catalog_rank_masters as master')
            ->join(
                'catalog_rank_master_revisions as revision',
                'revision.id',
                '=',
                'master.current_revision_id'
            )
            ->first(['revision.*']);
        $videoAssetId = (int) DB::table('catalog_gacha_rank_video_revisions')
            ->value('video_asset_id');
        $now = now()->startOfSecond();
        $videoOnlyMasterId = DB::table('catalog_rank_masters')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'current_revision_id' => null,
            'status' => 'active',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $videoOnlyMasterPublicId = (string) DB::table('catalog_rank_masters')
            ->where('id', $videoOnlyMasterId)->value('public_id');
        $videoOnlyRevisionId = DB::table('catalog_rank_master_revisions')->insertGetId([
            'rank_master_id' => $videoOnlyMasterId,
            'revision_number' => 1,
            'rank_name' => 'Video only Rank',
            'lineup_image_asset_id' => $template->lineup_image_asset_id,
            'result_image_asset_id' => $template->result_image_asset_id,
            'show_total_stock' => false,
            'display_order' => 9999,
            'created_at' => $now,
        ]);
        DB::table('catalog_rank_masters')->where('id', $videoOnlyMasterId)->update([
            'current_revision_id' => $videoOnlyRevisionId,
            'updated_at' => $now,
        ]);
        $videoOnlyGachaRankId = DB::table('catalog_gacha_ranks')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'gacha_id' => $gacha->id,
            'rank_master_id' => $videoOnlyMasterId,
            'current_video_revision_id' => null,
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $videoOnlyRevisionId = DB::table('catalog_gacha_rank_video_revisions')->insertGetId([
            'gacha_rank_id' => $videoOnlyGachaRankId,
            'revision_number' => 1,
            'video_asset_id' => $videoAssetId,
            'created_at' => $now,
        ]);
        DB::table('catalog_gacha_ranks')->where('id', $videoOnlyGachaRankId)->update([
            'current_video_revision_id' => $videoOnlyRevisionId,
            'updated_at' => $now,
        ]);

        $canonicalMaster = DB::table('catalog_rank_masters')
            ->where('public_id', $canonicalRank['rank_id'])->firstOrFail();
        $canonicalRevision = DB::table('catalog_rank_master_revisions')
            ->where('id', $canonicalMaster->current_revision_id)->firstOrFail();
        $stockRevisionId = DB::table('catalog_rank_master_revisions')->insertGetId([
            'rank_master_id' => $canonicalMaster->id,
            'revision_number' => (int) $canonicalRevision->revision_number + 1,
            'rank_name' => $canonicalRevision->rank_name,
            'lineup_image_asset_id' => $canonicalRevision->lineup_image_asset_id,
            'result_image_asset_id' => $canonicalRevision->result_image_asset_id,
            'show_total_stock' => true,
            'display_order' => $canonicalRevision->display_order,
            'created_at' => $now,
        ]);
        DB::table('catalog_rank_masters')->where('id', $canonicalMaster->id)->update([
            'current_revision_id' => $stockRevisionId,
            'revision' => (int) $canonicalMaster->revision + 1,
            'updated_at' => $now,
        ]);
        $expectedStock = (int) DB::table('prize_inventories as inventory')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'inventory.gacha_version_prize_id'
            )
            ->join('catalog_gacha_ranks as gacha_rank', 'gacha_rank.id', '=', 'relation.gacha_rank_id')
            ->where('gacha_rank.gacha_id', $gacha->id)
            ->where('gacha_rank.rank_master_id', $canonicalMaster->id)
            ->sum('inventory.total_quantity');

        $updated = $this->getJson('/api/v2/gachas/'.self::GACHA_ID)
            ->assertOk()->json('data.ranks');
        self::assertFalse(collect($updated)->contains(
            fn (array $rank): bool => $rank['rank_id'] === $videoOnlyMasterPublicId
        ));
        $stockRank = collect($updated)->first(
            fn (array $rank): bool => $rank['rank_id'] === $canonicalRank['rank_id']
        );
        self::assertNotNull($stockRank);
        self::assertTrue($stockRank['show_total_stock']);
        self::assertSame($expectedStock, $stockRank['total_stock']);
        self::assertArrayNotHasKey('available_quantity', $stockRank);
    }

    public function test_anonymous_and_all_user_states_are_private_and_machine_readable(): void
    {
        $this->import(allowedDrawCounts: [1, 5, 10, 100, 1000]);

        $anonymous = $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.user_state', 'unauthenticated')
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.ineligible_reason', 'authentication_required')
            ->assertJsonPath('data.cta.state', 'enabled')
            ->assertJsonPath('data.cta.action', 'login')
            ->assertJsonPath('data.daily_limit.used', null);
        $cacheControl = (string) $anonymous->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('Cookie', $anonymous->headers->get('Vary'));

        $this->authenticate($this->user());
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.user_state', 'authenticated')
            ->assertJsonPath('data.audience', 'all_users')
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.ineligible_reason', null)
            ->assertJsonPath('data.allowed_draw_counts', [1, 5, 10, 100, 1000])
            ->assertJsonPath('data.daily_limit.unlimited', true)
            ->assertJsonPath('data.cta.action', 'draw');
    }

    public function test_first_time_audience_uses_registration_window_and_ignores_draws(): void
    {
        $this->import(audience: 'first_time_users');
        $user = $this->user();
        $this->authenticate($user);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', true);

        $drawId = $this->draw($user, 1);
        self::assertNotSame('', $drawId);
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.eligible', true);

        DB::table('users')->where('id', $user->id)->update([
            'created_at' => now()->subDays(7)->subSecond(),
        ]);
        $user->refresh();
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.ineligible_reason', 'audience_not_eligible');
    }

    public function test_first_time_audience_honors_arbitrary_thirty_day_window(): void
    {
        $this->import(audience: 'first_time_users', firstTimeDays: 30);
        $user = $this->user();
        DB::table('users')->where('id', $user->id)->update([
            'created_at' => now()->subDays(30),
        ]);
        $user->refresh();
        $this->authenticate($user);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', true);
        DB::table('users')->where('id', $user->id)->update([
            'created_at' => now()->subDays(30)->subSecond(),
        ]);
        $user->refresh();
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', false);
    }

    public function test_first_time_audience_expires_after_one_day_window(): void
    {
        $this->import(audience: 'first_time_users', firstTimeDays: 1);
        $user = $this->user();
        DB::table('users')->where('id', $user->id)->update([
            'created_at' => now()->subDay()->subSecond(),
        ]);
        $user->refresh();
        $this->authenticate($user);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', false);
    }

    public function test_line_audience_requires_link_and_confirmed_friendship(): void
    {
        $this->import(audience: 'line_users');
        $user = $this->user();
        $this->authenticate($user);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', false);
        $subjectHash = hash('sha256', 'line-'.$user->public_id);
        DB::table('external_identity_accounts')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'provider' => 'line',
            'issuer' => 'https://access.line.me',
            'subject_hash' => $subjectHash,
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', false);
        DB::table('line_friendships')->insert([
            'public_id' => (string) Str::uuid7(),
            'subject_hash' => $subjectHash,
            'user_id' => $user->id,
            'status' => 'friend',
            'followed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson($this->presentationUrl())
            ->assertOk()->assertJsonPath('data.eligible', true);
    }

    public function test_catalog_keeps_authenticated_ineligible_gacha_private(): void
    {
        $this->import(audience: 'line_users');
        $user = $this->user();
        $this->authenticate($user);

        $response = $this->getJson('/api/v2/gachas')
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.presentation.user_state', 'authenticated')
            ->assertJsonPath('data.0.presentation.eligible', false)
            ->assertJsonPath(
                'data.0.presentation.ineligible_reason',
                'audience_not_eligible'
            );

        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    public function test_daily_limit_allowed_counts_and_jst_reset_match_draw_authority(): void
    {
        CarbonImmutable::setTestNow('2026-07-29T14:59:59Z');
        $this->import(dailyLimit: 10);
        $user = $this->user();
        $this->authenticate($user);
        $this->draw($user, 5);

        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.daily_limit.limit', 10)
            ->assertJsonPath('data.daily_limit.used', 5)
            ->assertJsonPath('data.daily_limit.remaining', 5)
            ->assertJsonPath('data.daily_limit.resets_at', '2026-07-29T15:00:00Z')
            ->assertJsonPath('data.allowed_draw_counts', [1, 5]);

        $this->draw($user, 5);
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.ineligible_reason', 'daily_limit_reached')
            ->assertJsonPath('data.allowed_draw_counts', []);

        CarbonImmutable::setTestNow('2026-07-29T15:00:00Z');
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.daily_limit.used', 0)
            ->assertJsonPath('data.daily_limit.remaining', 10)
            ->assertJsonPath('data.allowed_draw_counts', [1, 5, 10]);
    }

    public function test_gacha_counts_intersect_daily_limit_but_not_remaining_count(): void
    {
        $this->import(
            dailyLimit: 100,
            allowedDrawCounts: [1, 10, 100]
        );
        $user = $this->user();
        $this->authenticate($user);

        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.allowed_draw_counts', [1, 10, 100]);

        $this->draw($user, 10);
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.daily_limit.remaining', 90)
            ->assertJsonPath('data.allowed_draw_counts', [1, 10]);

        DB::table('gacha_draw_states')->update(['sold_count' => 999]);
        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.allowed_draw_counts', [1, 10]);
    }

    public function test_configured_bulk_count_remains_allowed_when_only_remaining_is_lower(): void
    {
        $this->import(allowedDrawCounts: [1, 100, 1000]);
        $this->authenticate($this->user());
        DB::table('gacha_draw_states')->update(['sold_count' => 100]);

        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.allowed_draw_counts', [1, 100, 1000]);
    }

    public function test_single_count_configuration_is_preserved(): void
    {
        $this->import(allowedDrawCounts: [1]);
        $this->authenticate($this->user());

        $this->getJson($this->presentationUrl())
            ->assertOk()
            ->assertJsonPath('data.allowed_draw_counts', [1]);
    }

    private function import(
        int $dailyLimit = 0,
        string $audience = 'all_users',
        int $firstTimeDays = 7,
        array $allowedDrawCounts = [1, 5, 10]
    ): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['versions'][0]['daily_draw_limit'] = $dailyLimit;
        $fixture['versions'][0]['audience_code'] = $audience;
        $fixture['versions'][0]['first_time_eligible_days'] = $firstTimeDays;
        $fixture['versions'][0]['allowed_draw_counts'] = $allowedDrawCounts;
        app(V2CatalogFixtureImporter::class)->import($fixture);
        $index = 0;
        $values = [5_000, 50_000, 150_000, 999_999];
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static function (int $minimum, int $maximum) use (&$index, $values): int {
                    $value = intdiv(
                        $values[$index % count($values)] * $maximum,
                        1_000_000
                    ) + 1;
                    $index++;

                    return min($maximum, max($minimum, $value));
                }
            )
        );
    }

    private function user(): User
    {
        $email = 'presentation-'.Str::uuid().'@example.test';
        $user = User::query()->create([
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            10_000,
            'presentation-points-'.Str::uuid()
        );

        return $user;
    }

    private function authenticate(User $user): void
    {
        Auth::guard('v2_user')->setUser($user);
    }

    private function draw(User $user, int $count): string
    {
        return app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            $count,
            'presentation-draw-'.Str::uuid(),
            (string) Str::uuid7()
        )['id'];
    }

    private function presentationUrl(): string
    {
        return '/api/v2/gacha-presentations/'.self::GACHA_ID;
    }
}
