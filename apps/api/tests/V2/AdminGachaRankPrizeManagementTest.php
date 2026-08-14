<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    private const PUBLISHED_VERSION_ID = '0198a001-0000-7000-8000-000000000012';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        app(V2CatalogFixtureImporter::class)->import($fixture);
        config(['v2_identity.origins.admin' => 'https://admin.example.test']);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_owner_manages_draft_ranks_and_prizes_with_canonical_inventory(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $core = $this->mutate(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/core',
            $this->coreInput()
        )->assertCreated()->json('data');
        $gachaId = $core['id'];
        $versionId = $core['current_version']['id'];

        Auth::forgetGuards();
        $rank = $this->mutate(
            $token,
            'POST',
            "/admin/api/v2/catalog/gachas/{$gachaId}/versions/{$versionId}/ranks",
            [
                'code' => 'ss',
                'name' => 'SSランク',
                'description' => '最上位ランク',
                'image_asset_id' => self::IMAGE_ASSET_ID,
                'video_asset_id' => self::VIDEO_ASSET_ID,
                'expected_version_revision' => 1,
            ],
            'rank-create-canonical'
        )->assertCreated()
            ->assertJsonPath('data.description', '最上位ランク')
            ->assertJsonPath('data.image_asset.id', self::IMAGE_ASSET_ID)
            ->assertJsonPath('data.video_asset.id', self::VIDEO_ASSET_ID)
            ->json('data');

        Auth::forgetGuards();
        $this->mutate(
            $token,
            'POST',
            "/admin/api/v2/catalog/gachas/{$gachaId}/versions/{$versionId}/ranks",
            [
                'code' => 'ss',
                'name' => 'SSランク',
                'description' => '最上位ランク',
                'image_asset_id' => self::IMAGE_ASSET_ID,
                'video_asset_id' => self::VIDEO_ASSET_ID,
                'expected_version_revision' => 1,
            ],
            'rank-create-canonical'
        )->assertCreated()->assertJsonPath('idempotent_replay', true);

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson("/admin/api/v2/catalog/gachas/{$gachaId}/versions/{$versionId}/ranks")
            ->assertOk()
            ->assertJsonPath('version_revision', 2)
            ->assertJsonPath('items.0.id', $rank['id']);

        Auth::forgetGuards();
        $prize = $this->mutate(
            $token,
            'POST',
            "/admin/api/v2/catalog/gachas/{$gachaId}/versions/{$versionId}/prizes",
            [
                'rank_id' => $rank['id'],
                'presentation_asset_id' => self::PRIZE_ASSET_ID,
                'name' => 'SS景品',
                'total_inventory' => 10,
                'exchange_points' => 8000,
                'cost_price' => 5000,
                'is_active' => true,
                'expected_version_revision' => 2,
            ]
        )->assertCreated()
            ->assertJsonPath('data.cost_price', 5000)
            ->json('data');

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson("/admin/api/v2/catalog/gachas/{$gachaId}/versions/{$versionId}/prizes")
            ->assertOk()
            ->assertJsonPath('version_revision', 3)
            ->assertJsonPath('items.0.id', $prize['id'])
            ->assertJsonPath('items.0.total_inventory', 10)
            ->assertJsonPath('items.0.available_inventory', 10)
            ->assertJsonPath('items.0.awarded_inventory', 0)
            ->assertJsonPath('items.0.withdrawn_inventory', 0)
            ->assertJsonPath('items.0.inventory_revision', 0)
            ->assertJsonMissingPath('items.0.internal_id');

        Auth::forgetGuards();
        $this->mutate(
            $token,
            'PUT',
            "/admin/api/v2/catalog/gachas/{$gachaId}/versions/{$versionId}/prizes/{$prize['id']}",
            [
                'rank_id' => $rank['id'],
                'presentation_asset_id' => self::PRIZE_ASSET_ID,
                'name' => 'SS景品 改訂',
                'total_inventory' => 12,
                'available_inventory' => 12,
                'exchange_points' => 8500,
                'cost_price' => 5200,
                'is_active' => false,
                'expected_revision' => 1,
                'expected_version_revision' => 3,
                'expected_inventory_revision' => 0,
                'inventory_reason' => 'Draft inventory correction',
            ]
        )->assertOk()
            ->assertJsonPath('data.name', 'SS景品 改訂')
            ->assertJsonPath('data.cost_price', 5200)
            ->assertJsonPath('data.is_visible', false);
    }

    public function test_operator_and_published_version_mutations_are_rejected(): void
    {
        $operator = $this->createAdminSession(V2AdminRole::Operator);
        $publishedRevision = (int) DB::table('catalog_gacha_versions')
            ->where('public_id', self::PUBLISHED_VERSION_ID)->value('revision');
        $payload = [
            'code' => 'blocked-rank',
            'name' => 'Blocked',
            'description' => null,
            'image_asset_id' => null,
            'video_asset_id' => null,
            'expected_version_revision' => $publishedRevision,
        ];
        $this->mutate(
            $operator,
            'POST',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID.'/versions/'
                .self::PUBLISHED_VERSION_ID.'/ranks',
            $payload
        )->assertForbidden();

        Auth::forgetGuards();
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $this->mutate(
            $owner,
            'POST',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID.'/versions/'
                .self::PUBLISHED_VERSION_ID.'/ranks',
            $payload
        )->assertConflict()->assertJsonPath('code', 'CATALOG_GACHA_VERSION_IMMUTABLE');
    }

    public function test_published_inventory_adjustment_is_occ_idempotent_and_audited(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $versionRevision = (int) DB::table('catalog_gacha_versions')
            ->where('public_id', self::PUBLISHED_VERSION_ID)
            ->value('revision');
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::PUBLISHED_GACHA_ID)
            ->firstOrFail();
        $stateBefore = DB::table('gacha_draw_states')
            ->where('id', $gacha->active_draw_state_id)
            ->firstOrFail();
        $collection = $this->asAdmin($owner)
            ->getJson('/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID
                .'/versions/'.self::PUBLISHED_VERSION_ID.'/prizes')
            ->assertOk()
            ->json();
        $prize = $collection['items'][0];
        $payload = [
            'rank_id' => $prize['rank']['id'],
            'presentation_asset_id' => $prize['presentation_asset']['id'] ?? null,
            'name' => $prize['name'],
            'total_inventory' => $prize['total_inventory'] + 5,
            'available_inventory' => $prize['available_inventory'] + 2,
            'exchange_points' => $prize['exchange_points'],
            'cost_price' => $prize['cost_price'],
            'is_active' => $prize['is_visible'],
            'expected_revision' => $prize['revision'],
            'expected_version_revision' => $versionRevision,
            'expected_inventory_revision' => $prize['inventory_revision'],
            'inventory_reason' => 'Operational stock reconciliation',
        ];
        $uri = '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID
            .'/versions/'.self::PUBLISHED_VERSION_ID.'/prizes/'.$prize['id'];
        $key = 'published-inventory-adjustment-key';

        $this->mutate($owner, 'PUT', $uri, $payload, $key)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', false);
        Auth::forgetGuards();
        $this->mutate($owner, 'PUT', $uri, $payload, $key)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $inventory = DB::table('prize_inventories as inventory')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'inventory.gacha_version_prize_id'
            )
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('prize.public_id', $prize['id'])
            ->firstOrFail(['inventory.*']);
        self::assertSame($payload['total_inventory'], (int) $inventory->total_quantity);
        self::assertSame($payload['available_inventory'], (int) $inventory->available_quantity);
        self::assertSame(
            (int) $inventory->total_quantity,
            (int) $inventory->awarded_count
                + (int) $inventory->available_quantity
                + (int) $inventory->withdrawn_quantity
        );
        self::assertSame(1, (int) $inventory->lock_version);
        self::assertSame(1, DB::table('prize_inventory_adjustments')->count());
        self::assertSame(1, DB::table('audit_logs')
            ->where('action_code', 'catalog.inventory.adjusted')->count());
        self::assertDatabaseHas('prize_inventory_adjustments', [
            'idempotency_key' => $key,
            'reason' => 'Operational stock reconciliation',
            'before_lock_version' => 0,
            'after_lock_version' => 1,
        ]);
        $stateAfter = DB::table('gacha_draw_states')
            ->where('id', $gacha->active_draw_state_id)
            ->firstOrFail();
        self::assertSame((int) $stateBefore->id, (int) $stateAfter->id);
        self::assertSame($stateBefore->status, $stateAfter->status);
        self::assertSame((int) $stateBefore->sold_count, (int) $stateAfter->sold_count);
        self::assertSame((int) $stateBefore->lock_version, (int) $stateAfter->lock_version);
        self::assertSame(
            $versionRevision,
            (int) DB::table('catalog_gacha_versions')
                ->where('public_id', self::PUBLISHED_VERSION_ID)
                ->value('revision')
        );
        $currentPrizeRevision = (int) DB::table('catalog_prizes')
            ->where('public_id', $prize['id'])
            ->value('revision');

        Auth::forgetGuards();
        $this->mutate(
            $owner,
            'PUT',
            $uri,
            [...$payload, 'expected_revision' => $currentPrizeRevision],
            'published-inventory-stale-key'
        )->assertConflict()
            ->assertJsonPath('code', 'CATALOG_PRIZE_INVENTORY_REVISION_CONFLICT');

        Auth::forgetGuards();
        $this->mutate($owner, 'PUT', $uri, [
            ...$payload,
            'available_inventory' => -1,
            'expected_inventory_revision' => 1,
        ], 'published-inventory-negative-key')->assertUnprocessable();

        DB::table('prize_inventories')->where('id', $inventory->id)->update([
            'awarded_count' => DB::raw('awarded_count + 1'),
            'available_quantity' => DB::raw('available_quantity - 1'),
            'lock_version' => 2,
        ]);
        Auth::forgetGuards();
        $this->mutate($owner, 'PUT', $uri, [
            ...$payload,
            'expected_revision' => $currentPrizeRevision,
            'total_inventory' => 0,
            'available_inventory' => 0,
            'expected_inventory_revision' => 2,
        ], 'published-inventory-below-awarded-key')->assertConflict()
            ->assertJsonPath('code', 'CATALOG_PRIZE_INVENTORY_CONFLICT');
    }

    public function test_published_presentation_update_keeps_inventory_without_adjustment_metadata(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $versionRevision = (int) DB::table('catalog_gacha_versions')
            ->where('public_id', self::PUBLISHED_VERSION_ID)
            ->value('revision');
        $collection = $this->asAdmin($owner)
            ->getJson('/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID
                .'/versions/'.self::PUBLISHED_VERSION_ID.'/prizes')
            ->assertOk()
            ->json();
        $prize = $collection['items'][0];
        $inventoryBefore = DB::table('prize_inventories as inventory')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'inventory.gacha_version_prize_id'
            )
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('prize.public_id', $prize['id'])
            ->firstOrFail(['inventory.*']);

        $this->mutate(
            $owner,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID
                .'/versions/'.self::PUBLISHED_VERSION_ID.'/prizes/'.$prize['id'],
            [
                'rank_id' => $prize['rank']['id'],
                'presentation_asset_id' => $prize['presentation_asset']['id'] ?? null,
                'name' => $prize['name'].' Presentation',
                'total_inventory' => $prize['total_inventory'],
                'exchange_points' => $prize['exchange_points'],
                'cost_price' => $prize['cost_price'],
                'is_active' => $prize['is_visible'],
                'expected_revision' => $prize['revision'],
                'expected_version_revision' => $versionRevision,
            ]
        )->assertOk()->assertJsonPath('data.name', $prize['name'].' Presentation');

        $inventoryAfter = DB::table('prize_inventories')
            ->where('id', $inventoryBefore->id)
            ->firstOrFail();
        self::assertSame((array) $inventoryBefore, (array) $inventoryAfter);
        self::assertSame(0, DB::table('prize_inventory_adjustments')->count());
        self::assertSame(0, DB::table('audit_logs')
            ->where('action_code', 'catalog.inventory.adjusted')->count());
    }

    public function test_database_guard_protects_new_rank_fields_of_published_versions(): void
    {
        $rankId = DB::table('catalog_ranks')->where('code', 'S')->value('id');
        $this->expectException(QueryException::class);
        DB::table('catalog_ranks')->where('id', $rankId)->update([
            'description' => 'direct sql bypass',
            'revision' => DB::raw('revision + 1'),
        ]);
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
