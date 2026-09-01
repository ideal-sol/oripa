<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGachaRankPrizeManagementTest extends TestCase
{
    private const CATEGORY_ID = '0198a001-0000-7000-8000-000000000001';
    private const TAG_ID = '0198a001-0000-7000-8000-000000000002';
    private const IMAGE_ASSET_ID = '0198a001-0000-7000-8000-000000000005';
    private const VIDEO_ASSET_ID = '0198a001-0000-7000-8000-000000000006';
    private const PRIZE_ASSET_ID = '0198a001-0000-7000-8000-000000000007';
    private const PUBLISHED_GACHA_ID = '0198a001-0000-7000-8000-000000000011';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Storage::fake('local');
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        app(V2CatalogFixtureImporter::class)->import($fixture);
        config([
            'filesystems.default' => 'local',
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_rank_master_defaults_revisions_reorder_and_unused_inactive_are_canonical(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $this->mutate($owner, 'POST', '/admin/api/v2/catalog/ranks', [
            'rank_name' => '画像不足',
            'lineup_image' => $this->imageInput(),
        ])->assertUnprocessable();

        $first = $this->createRankMaster($owner, 'Sランク');
        $second = $this->createRankMaster($owner, 'Aランク');
        self::assertFalse($first['show_total_stock']);
        self::assertSame('active', $first['status']);
        self::assertSame(1, $first['revision']);
        self::assertSame(1, $first['revision_number']);
        self::assertDatabaseCount('catalog_rank_master_revisions', 2 + $this->fixtureRankCount());

        Auth::forgetGuards();
        $firstUpdated = $this->mutate($owner, 'PUT', '/admin/api/v2/catalog/ranks/'.$first['id'], [
            'expected_revision' => 1,
            'rank_name' => 'Sランク 更新',
            'show_total_stock' => true,
            'status' => 'active',
        ])->assertOk()->json('data');
        self::assertSame('Sランク 更新', $firstUpdated['rank_name']);
        self::assertTrue($firstUpdated['show_total_stock']);
        self::assertSame(2, $firstUpdated['revision']);
        self::assertSame(2, $firstUpdated['revision_number']);

        Auth::forgetGuards();
        $reordered = $this->mutate($owner, 'PUT', '/admin/api/v2/catalog/ranks/reorder', [
            'items' => [
                ['rank_id' => $first['id'], 'expected_revision' => 2, 'display_order' => 990],
                ['rank_id' => $second['id'], 'expected_revision' => 1, 'display_order' => 991],
            ],
        ])->assertOk()->json('data');
        $reorderedFirst = collect($reordered['items'])->first(
            fn (array $item): bool => $item['id'] === $first['id']
        );
        self::assertNotNull($reorderedFirst);
        self::assertSame(990, $reorderedFirst['display_order']);
        self::assertSame(3, $reorderedFirst['revision']);

        Auth::forgetGuards();
        $inactive = $this->mutate($owner, 'PUT', '/admin/api/v2/catalog/ranks/'.$second['id'], [
            'expected_revision' => 2,
            'rank_name' => $second['rank_name'],
            'show_total_stock' => false,
            'status' => 'inactive',
        ])->assertOk()->json('data');
        self::assertSame('inactive', $inactive['status']);
        self::assertFalse($inactive['has_usage']);

        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'admin/api/v2/catalog/ranks'));
        self::assertFalse($routes->contains(fn ($route): bool => in_array(
            'DELETE',
            $route->methods(),
            true
        )));

        $firstInternalId = (int) DB::table('catalog_rank_masters')
            ->where('public_id', $first['id'])->value('id');
        $this->expectException(QueryException::class);
        DB::table('catalog_rank_masters')->where('id', $firstInternalId)->delete();
    }

    public function test_rank_master_image_assets_persist_and_revisions_remain_immutable(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $created = $this->createRankMaster($owner, '画像Revision');
        $master = DB::table('catalog_rank_masters')
            ->where('public_id', $created['id'])->firstOrFail();
        $firstRevision = DB::table('catalog_rank_master_revisions')
            ->where('id', $master->current_revision_id)->firstOrFail();
        $lineupAsset = DB::table('catalog_presentation_assets')
            ->where('id', $firstRevision->lineup_image_asset_id)->firstOrFail();
        $resultAsset = DB::table('catalog_presentation_assets')
            ->where('id', $firstRevision->result_image_asset_id)->firstOrFail();

        self::assertNotSame($lineupAsset->public_id, $resultAsset->public_id);
        self::assertSame($lineupAsset->public_id, $created['lineup_image']['id']);
        self::assertSame($resultAsset->public_id, $created['result_image']['id']);
        self::assertSame('image', $lineupAsset->media_type);
        self::assertSame('image/png', $lineupAsset->mime_type);
        self::assertTrue((bool) $lineupAsset->is_public);
        Storage::disk('local')->assertExists($lineupAsset->storage_identifier);
        Storage::disk('local')->assertExists($resultAsset->storage_identifier);

        $this->asAdmin($owner)
            ->get('/admin/api/v2/catalog/presentation-assets/'.$lineupAsset->public_id.'/content')
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->asAdmin($owner)
            ->get('/admin/api/v2/catalog/presentation-assets/'.$resultAsset->public_id.'/content')
            ->assertOk()->assertHeader('Content-Type', 'image/png');

        Auth::forgetGuards();
        $updated = $this->mutate(
            $owner,
            'PUT',
            '/admin/api/v2/catalog/ranks/'.$created['id'],
            [
                'expected_revision' => 1,
                'rank_name' => $created['rank_name'],
                'lineup_image' => $this->imageInput('replacement-lineup.png'),
                'show_total_stock' => false,
                'status' => 'active',
            ]
        )->assertOk()->json('data');

        $secondRevision = DB::table('catalog_rank_master_revisions')
            ->where('id', DB::table('catalog_rank_masters')
                ->where('public_id', $created['id'])->value('current_revision_id'))
            ->firstOrFail();
        $persistedFirstRevision = DB::table('catalog_rank_master_revisions')
            ->where('id', $firstRevision->id)->firstOrFail();

        self::assertSame(2, $updated['revision_number']);
        self::assertNotSame($created['lineup_image']['id'], $updated['lineup_image']['id']);
        self::assertSame($created['result_image']['id'], $updated['result_image']['id']);
        self::assertSame($firstRevision->lineup_image_asset_id, $persistedFirstRevision->lineup_image_asset_id);
        self::assertSame($firstRevision->result_image_asset_id, $persistedFirstRevision->result_image_asset_id);
        self::assertNotSame($firstRevision->lineup_image_asset_id, $secondRevision->lineup_image_asset_id);
        self::assertSame($firstRevision->result_image_asset_id, $secondRevision->result_image_asset_id);

        $replacement = DB::table('catalog_presentation_assets')
            ->where('id', $secondRevision->lineup_image_asset_id)->firstOrFail();
        Storage::disk('local')->assertExists($replacement->storage_identifier);
        Storage::disk('local')->assertExists($lineupAsset->storage_identifier);
    }

    public function test_active_master_union_is_lazy_and_video_revisions_reuse_registry_assets(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $legacyRankAssetCount = DB::table('catalog_rank_assets')->count();
        $gacha = $this->createGacha($owner, 'Lazy Gacha');
        $first = $this->createRankMaster($owner, 'Lazy S');
        $second = $this->createRankMaster($owner, 'Lazy A');

        Auth::forgetGuards();
        $before = $this->gachaRankRow($owner, $gacha['id'], $first['id']);
        self::assertNull($before['gacha_rank_id']);
        self::assertNull($before['current_video']);
        self::assertTrue($before['can_unset_video']);
        self::assertSame(0, DB::table('catalog_gacha_ranks')->where('gacha_id', function ($query) use ($gacha): void {
            $query->select('id')->from('catalog_gachas')->where('public_id', $gacha['id']);
        })->count());

        Auth::forgetGuards();
        $firstVideo = $this->setRankVideo($owner, $gacha['id'], $first['id']);
        self::assertSame(self::VIDEO_ASSET_ID, $firstVideo['current_video']['id']);
        self::assertSame(1, $firstVideo['video_revision_number']);
        self::assertSame(1, $firstVideo['revision']);
        self::assertDatabaseCount('catalog_gacha_rank_video_revisions', $this->fixtureGachaRankCount() + 1);

        Auth::forgetGuards();
        $sameVideo = $this->setRankVideo(
            $owner,
            $gacha['id'],
            $first['id'],
            ['expected_revision' => 1]
        );
        self::assertSame(1, $sameVideo['video_revision_number']);
        self::assertSame(1, $sameVideo['revision']);

        Auth::forgetGuards();
        $secondVideo = $this->setRankVideo($owner, $gacha['id'], $second['id']);
        self::assertSame(self::VIDEO_ASSET_ID, $secondVideo['current_video']['id']);
        self::assertSame(1, $secondVideo['video_revision_number']);
        self::assertSame(2, DB::table('catalog_gacha_ranks')
            ->where('gacha_id', function ($query) use ($gacha): void {
                $query->select('id')->from('catalog_gachas')->where('public_id', $gacha['id']);
            })->count());
        self::assertSame($legacyRankAssetCount, DB::table('catalog_rank_assets')->count());

        $gachaInternalId = (int) DB::table('catalog_gachas')->where('public_id', $gacha['id'])->value('id');
        $firstMasterId = (int) DB::table('catalog_rank_masters')->where('public_id', $first['id'])->value('id');
        $this->expectException(QueryException::class);
        DB::table('catalog_gacha_ranks')->insert([
            'public_id' => (string) Str::uuid7(),
            'gacha_id' => $gachaInternalId,
            'rank_master_id' => $firstMasterId,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_prize_requires_video_uses_gacha_rank_and_cannot_cross_or_change_rank(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $gacha = $this->createGacha($owner, 'Prize Gacha');
        $versionId = $gacha['current_version']['id'];
        $rank = $this->createRankMaster($owner, 'Prize S');

        $createUri = $this->rankPrizeUri($gacha['id'], $versionId, $rank['id']);
        $this->mutate($owner, 'POST', $createUri, $this->prizeInput(1))
            ->assertConflict()
            ->assertJsonPath('code', 'CATALOG_GACHA_RANK_VIDEO_REQUIRED');

        Auth::forgetGuards();
        $gachaRank = $this->setRankVideo($owner, $gacha['id'], $rank['id']);
        Auth::forgetGuards();
        $prize = $this->mutate($owner, 'POST', $createUri, $this->prizeInput(1))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Canonical Prize')
            ->json('data');

        $storedPrize = DB::table('catalog_prizes')->where('public_id', $prize['id'])->firstOrFail();
        $storedGachaRank = DB::table('catalog_gacha_ranks')->where('public_id', $gachaRank['id'])->firstOrFail();
        self::assertNull($storedPrize->rank_id);
        self::assertSame((int) $storedGachaRank->id, (int) $storedPrize->gacha_rank_id);
        self::assertDatabaseHas('catalog_gacha_version_prizes', [
            'prize_id' => $storedPrize->id,
            'gacha_rank_id' => $storedGachaRank->id,
            'rank_id' => null,
        ]);

        Auth::forgetGuards();
        $this->mutate(
            $owner,
            'POST',
            '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/ranks/'.$rank['id'].'/video/unset',
            ['expected_revision' => $gachaRank['revision']]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_GACHA_RANK_VIDEO_REQUIRED');

        $otherRank = $this->createRankMaster($owner, 'Prize A');
        Auth::forgetGuards();
        $this->setRankVideo($owner, $gacha['id'], $otherRank['id']);
        Auth::forgetGuards();
        $this->mutate(
            $owner,
            'PUT',
            $this->rankPrizeUri($gacha['id'], $versionId, $otherRank['id']).'/'.$prize['id'],
            [
                ...$this->prizeInput(2),
                'expected_revision' => 1,
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_PRIZE_RANK_IMMUTABLE');

        $otherGacha = $this->createGacha($owner, 'Cross Gacha');
        Auth::forgetGuards();
        $this->mutate(
            $owner,
            'PUT',
            $this->rankPrizeUri($otherGacha['id'], $otherGacha['current_version']['id'], $rank['id'])
                .'/'.$prize['id'],
            [
                ...$this->prizeInput(1),
                'expected_revision' => 1,
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_PRIZE_RANK_IMMUTABLE');

        $otherGachaInternalId = (int) DB::table('catalog_gachas')
            ->where('public_id', $otherGacha['id'])->value('id');
        $this->expectException(QueryException::class);
        DB::table('catalog_prizes')->insert([
            'public_id' => (string) Str::uuid7(),
            'code' => 'cross-'.Str::uuid7(),
            'gacha_id' => $otherGachaInternalId,
            'rank_id' => null,
            'gacha_rank_id' => $storedGachaRank->id,
            'display_name' => 'Cross Gacha Prize',
            'display_price' => 0,
            'exchange_points' => 0,
            'is_visible' => true,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rank_usage_prevents_inactive_after_prize_or_publication_forever(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $gacha = $this->createGacha($owner, 'Usage Gacha');
        $prizeRank = $this->createRankMaster($owner, 'Usage Prize Rank');
        Auth::forgetGuards();
        $this->setRankVideo($owner, $gacha['id'], $prizeRank['id']);
        Auth::forgetGuards();
        $this->mutate(
            $owner,
            'POST',
            $this->rankPrizeUri($gacha['id'], $gacha['current_version']['id'], $prizeRank['id']),
            $this->prizeInput(1)
        )->assertCreated();

        Auth::forgetGuards();
        $this->updateRankStatus($owner, $prizeRank, 'inactive')
            ->assertConflict()->assertJsonPath('code', 'CATALOG_RANK_IN_USE');

        $publishedRank = $this->createRankMaster($owner, 'Usage Published Rank');
        Auth::forgetGuards();
        $publishedGachaRank = $this->setRankVideo($owner, $gacha['id'], $publishedRank['id']);
        $publishedGachaRankId = (int) DB::table('catalog_gacha_ranks')
            ->where('public_id', $publishedGachaRank['id'])->value('id');
        DB::table('catalog_gacha_ranks')->where('id', $publishedGachaRankId)->update([
            'first_published_at' => now(),
            'revision' => 2,
            'updated_at' => now(),
        ]);

        Auth::forgetGuards();
        $this->updateRankStatus($owner, $publishedRank, 'inactive')
            ->assertConflict()->assertJsonPath('code', 'CATALOG_RANK_IN_USE');
        self::assertDatabaseHas('catalog_gacha_ranks', [
            'id' => $publishedGachaRankId,
        ]);
    }

    public function test_published_gacha_video_change_keeps_inventory_and_draw_state_unchanged(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $rows = $this->asAdmin($owner)
            ->getJson('/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID.'/ranks')
            ->assertOk()->json('items');
        $row = collect($rows)->first(fn (array $item): bool => $item['gacha_rank_id'] !== null
            && $item['current_video'] !== null
            && ! $item['can_unset_video']);
        self::assertNotNull($row);

        $replacement = $this->mutate($owner, 'POST', '/admin/api/v2/catalog/rank-effects', [
            'title' => '公開中変更用動画',
            'asset_type' => 'video',
            'is_active' => true,
            ...$this->videoInput('published-replacement.mp4'),
        ])->assertCreated()->json('data');
        $inventoryBefore = DB::table('prize_inventories')->orderBy('id')
            ->get()->map(static fn (object $entry): array => (array) $entry)->all();
        $drawStateBefore = DB::table('gacha_draw_states as state')
            ->join('catalog_gachas as gacha', 'gacha.active_draw_state_id', '=', 'state.id')
            ->where('gacha.public_id', self::PUBLISHED_GACHA_ID)
            ->first(['state.*']);

        Auth::forgetGuards();
        $changed = $this->setRankVideo(
            $owner,
            self::PUBLISHED_GACHA_ID,
            $row['rank']['id'],
            [
                'video_asset_id' => $replacement['id'],
                'expected_revision' => $row['gacha_rank_revision'],
            ]
        );
        self::assertSame($replacement['id'], $changed['current_video']['id']);
        self::assertSame($row['video_revision_number'] + 1, $changed['video_revision_number']);
        self::assertSame($row['gacha_rank_revision'] + 1, $changed['revision']);
        self::assertSame($inventoryBefore, DB::table('prize_inventories')->orderBy('id')
            ->get()->map(static fn (object $entry): array => (array) $entry)->all());
        $drawStateAfter = DB::table('gacha_draw_states')->where('id', $drawStateBefore->id)->firstOrFail();
        self::assertSame((array) $drawStateBefore, (array) $drawStateAfter);
    }

    /** @return array<string, mixed> */
    private function createGacha(string $token, string $title): array
    {
        return $this->mutate($token, 'POST', '/admin/api/v2/catalog/gachas/core', [
            ...$this->coreInput(),
            'title' => $title,
        ])->assertCreated()->json('data');
    }

    /** @return array<string, mixed> */
    private function createRankMaster(string $token, string $rankName): array
    {
        return $this->mutate($token, 'POST', '/admin/api/v2/catalog/ranks', [
            'rank_name' => $rankName,
            'lineup_image' => $this->imageInput($rankName.'-lineup.png'),
            'result_image' => $this->imageInput($rankName.'-result.png'),
        ])->assertCreated()->json('data');
    }

    /** @return array<string, mixed> */
    private function setRankVideo(
        string $token,
        string $gachaId,
        string $rankId,
        array $input = []
    ): array {
        return $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.$gachaId.'/ranks/'.$rankId.'/video',
            ['video_asset_id' => self::VIDEO_ASSET_ID, ...$input]
        )->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function gachaRankRow(string $token, string $gachaId, string $rankId): array
    {
        $items = $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas/'.$gachaId.'/ranks')
            ->assertOk()->json('items');
        $row = collect($items)->first(
            fn (array $item): bool => $item['rank']['id'] === $rankId
        );
        self::assertNotNull($row);

        return $row;
    }

    private function updateRankStatus(string $token, array $rank, string $status)
    {
        return $this->mutate($token, 'PUT', '/admin/api/v2/catalog/ranks/'.$rank['id'], [
            'expected_revision' => $rank['revision'],
            'rank_name' => $rank['rank_name'],
            'show_total_stock' => $rank['show_total_stock'],
            'status' => $status,
        ]);
    }

    private function rankPrizeUri(string $gachaId, string $versionId, string $rankId): string
    {
        return '/admin/api/v2/catalog/gachas/'.$gachaId.'/versions/'.$versionId
            .'/ranks/'.$rankId.'/prizes';
    }

    /** @return array<string, mixed> */
    private function prizeInput(int $versionRevision): array
    {
        return [
            'presentation_asset_id' => self::PRIZE_ASSET_ID,
            'name' => 'Canonical Prize',
            'total_inventory' => 10,
            'exchange_points' => 8000,
            'cost_price' => 5000,
            'is_active' => true,
            'expected_version_revision' => $versionRevision,
        ];
    }

    /** @return array<string, mixed> */
    private function coreInput(): array
    {
        return [
            'title' => 'Rank Prize Draft',
            'category_id' => self::CATEGORY_ID,
            'tag_ids' => [self::TAG_ID],
            'price_points' => 100,
            'total_count' => 1000,
            'daily_draw_limit' => 0,
            'audience_code' => 'all_users',
            'presentation_asset_id' => self::IMAGE_ASSET_ID,
            'publish_start_at' => '2026-08-20T00:00:00Z',
            'publish_end_at' => '2027-08-20T00:00:00Z',
            'description' => null,
            'notices' => null,
        ];
    }

    /** @return array<string, string> */
    private function imageInput(string $fileName = 'rank.png'): array
    {
        return [
            'file_name' => $fileName,
            'mime_type' => 'image/png',
            'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ];
    }

    /** @return array<string, string> */
    private function videoInput(string $fileName = 'effect.mp4'): array
    {
        return [
            'file_name' => $fileName,
            'mime_type' => 'video/mp4',
            'content_base64' => base64_encode(hex2bin(
                '00000018667479706d703432000000006d70343269736f6d'
            )),
        ];
    }

    private function fixtureRankCount(): int
    {
        return (int) DB::table('catalog_rank_masters')->count() - 2;
    }

    private function fixtureGachaRankCount(): int
    {
        return (int) DB::table('catalog_gacha_ranks')->count() - 1;
    }

    private function mutate(
        string $token,
        string $method,
        string $uri,
        array $payload,
        ?string $key = null
    ) {
        $csrf = str_repeat('a', 64);
        $request = $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => $key ?? (string) Str::uuid7(),
            ]);

        return $method === 'PUT'
            ? $request->putJson($uri, $payload)
            : $request->postJson($uri, $payload);
    }

    private function asAdmin(string $token): static
    {
        return $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token);
    }

    private function createAdminSession(V2AdminRole $role): string
    {
        $email = $role->value.'-'.Str::uuid7().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid rank prize test password'),
            'role' => $role->value,
            'state' => 'active',
        ]);
        $token = app(V2SessionPolicy::class)->issueOpaqueSessionId();
        $created = now()->subSecond();
        DB::table('admin_sessions')->insert([
            'session_id_hash' => app(V2SessionPolicy::class)->hashSessionId($token),
            'admin_id' => $adminId,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => $created,
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => $created->copy()->addHours(8),
        ]);

        return $token;
    }
}
