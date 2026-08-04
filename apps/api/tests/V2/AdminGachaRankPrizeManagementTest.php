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
                'exchange_points' => 8500,
                'cost_price' => 5200,
                'is_active' => false,
                'expected_revision' => 1,
                'expected_version_revision' => 3,
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
