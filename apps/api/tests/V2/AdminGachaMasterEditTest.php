<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2GachaPublicCodeGenerator;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGachaMasterEditTest extends TestCase
{
    private const CATEGORY_ID = '0198a001-0000-7000-8000-000000000001';
    private const TAG_ID = '0198a001-0000-7000-8000-000000000002';
    private const ASSET_ID = '0198a001-0000-7000-8000-000000000005';
    private const PUBLISHED_GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PUBLISHED_VERSION_ID = '0198a001-0000-7000-8000-000000000012';
    private const PUBLISHED_GACHA_CODE = 'Ab3Def7Gh9J';

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
        config([
            'filesystems.default' => 'local',
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_thumbnail_upload_and_new_gacha_issue_canonical_public_code(): void
    {
        $token = $this->createAdminSession();
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        self::assertIsString($png);

        $asset = $this->mutate(
            $token,
            'POST',
            '/admin/api/v2/catalog/gacha-thumbnails',
            [
                'file_name' => 'direct-thumbnail.png',
                'mime_type' => 'image/png',
                'content_base64' => base64_encode($png),
            ],
            'mig061r-thumbnail'
        )->assertCreated()
            ->assertJsonPath('data.mime_type', 'image/png')
            ->assertJsonMissingPath('data.storage_identifier')
            ->json('data');
        self::assertStringStartsWith(
            '/admin/api/v2/catalog/presentation-assets/',
            $asset['public_path']
        );

        Auth::forgetGuards();
        $created = $this->mutate(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/core',
            $this->coreInput($asset['id']),
            'mig061r-create'
        )->assertCreated()
            ->assertJsonPath('data.current_version.presentation_asset.id', $asset['id'])
            ->json('data');

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9]{11}\z/', $created['public_code']);
        self::assertTrue(Str::isUuid($created['id']));

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas/'.$created['public_code'])
            ->assertOk()->assertJsonPath('data.id', $created['id']);
        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas/'.$created['id'])
            ->assertOk()->assertJsonPath('data.public_code', $created['public_code']);
    }

    public function test_master_edit_updates_existing_draft_and_keeps_thumbnail_when_unchanged(): void
    {
        $token = $this->createAdminSession();
        $created = $this->mutate(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/core',
            $this->coreInput(self::ASSET_ID),
            'mig061r-draft-create'
        )->assertCreated()->json('data');

        Auth::forgetGuards();
        $updated = $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.$created['public_code'],
            [
                ...$this->coreInput(self::ASSET_ID),
                'title' => '編集後Master',
                'total_count' => 1200,
                'daily_draw_limit' => 25,
                'audience_code' => 'first_time_users',
                'first_time_eligible_days' => 14,
                'expected_revision' => $created['revision'],
                'expected_version_revision' => $created['current_version']['revision'],
            ],
            'mig061r-draft-edit'
        )->assertOk()->json('data');

        self::assertSame('編集後Master', $updated['current_version']['title']);
        self::assertSame(self::ASSET_ID, $updated['current_version']['presentation_asset']['id']);
        self::assertSame('first_time_users', $updated['current_version']['audience_code']);
        self::assertSame(14, $updated['current_version']['first_time_eligible_days']);
        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $created['current_version']['id'],
            'status' => 'draft',
            'title' => '編集後Master',
            'total_count' => 1200,
        ]);
    }

    public function test_published_master_edit_clones_draft_without_mutating_published_version(): void
    {
        $token = $this->createAdminSession();
        $before = DB::table('catalog_gacha_versions')
            ->where('public_id', self::PUBLISHED_VERSION_ID)->firstOrFail();
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', self::PUBLISHED_GACHA_ID)->value('revision');

        $payload = [
            ...$this->coreInput(self::ASSET_ID),
            'title' => '公開後の編集Draft',
            'total_count' => (int) $before->total_count,
            'expected_revision' => $gachaRevision,
            'expected_version_revision' => (int) $before->revision,
        ];
        $updated = $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_CODE,
            $payload,
            'mig061r-published-edit'
        )->assertOk()
            ->assertJsonPath('data.public_code', self::PUBLISHED_GACHA_CODE)
            ->assertJsonPath('data.current_version.status', 'draft')
            ->json('data');

        self::assertSame('公開後の編集Draft', $updated['current_version']['title']);
        $published = DB::table('catalog_gacha_versions')
            ->where('public_id', self::PUBLISHED_VERSION_ID)->firstOrFail();
        self::assertSame($before->title, $published->title);
        self::assertSame((int) $before->revision, (int) $published->revision);
        self::assertSame(1, DB::table('catalog_gacha_versions')
            ->where('gacha_id', $published->gacha_id)->where('status', 'draft')->count());

        Auth::forgetGuards();
        $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_CODE,
            $payload,
            'mig061r-published-edit'
        )->assertOk()->assertJsonPath('idempotent_replay', true);
        self::assertSame(1, DB::table('catalog_gacha_versions')
            ->where('gacha_id', $published->gacha_id)->where('status', 'draft')->count());
    }

    public function test_total_count_conflict_and_public_code_constraints_fail_closed(): void
    {
        $token = $this->createAdminSession();
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::PUBLISHED_GACHA_ID)->firstOrFail();
        $version = DB::table('catalog_gacha_versions')
            ->where('public_id', self::PUBLISHED_VERSION_ID)->firstOrFail();
        DB::table('catalog_gachas')->where('id', $gacha->id)->update([
            'sold_count' => 2,
            'revision' => (int) $gacha->revision + 1,
        ]);
        $gacha->revision = (int) $gacha->revision + 1;

        $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_CODE,
            [
                ...$this->coreInput(self::ASSET_ID),
                'total_count' => 1,
                'expected_revision' => (int) $gacha->revision,
                'expected_version_revision' => (int) $version->revision,
            ],
            'mig061r-invalid-total'
        )->assertConflict()->assertJsonPath('code', 'CATALOG_GACHA_TOTAL_COUNT_CONFLICT');

        $this->expectException(QueryException::class);
        DB::table('catalog_gachas')->where('id', $gacha->id)->update([
            'public_code' => 'invalid-code',
        ]);
    }

    public function test_public_code_generator_retries_collision(): void
    {
        $candidates = [self::PUBLISHED_GACHA_CODE, 'Z9y8X7w6V5u'];
        $generated = app(V2GachaPublicCodeGenerator::class)->unique(
            static function () use (&$candidates): string {
                return array_shift($candidates);
            }
        );

        self::assertSame('Z9y8X7w6V5u', $generated);
    }

    /** @return array<string, mixed> */
    private function coreInput(string $assetId): array
    {
        return [
            'title' => 'MIG-061R Draft',
            'category_id' => self::CATEGORY_ID,
            'tag_ids' => [self::TAG_ID],
            'price_points' => 100,
            'total_count' => 1000,
            'daily_draw_limit' => 0,
            'audience_code' => 'all_users',
            'first_time_eligible_days' => 7,
            'presentation_asset_id' => $assetId,
            'publish_start_at' => '2026-08-24T00:00:00Z',
            'publish_end_at' => '2027-08-24T00:00:00Z',
            'description' => '説明',
            'notices' => '注意事項',
        ];
    }

    private function mutate(
        string $token,
        string $method,
        string $uri,
        array $payload,
        string $key
    ) {
        $csrf = str_repeat('a', 64);
        $request = $this->asAdmin($token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => $key,
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

    private function createAdminSession(): string
    {
        $email = 'owner-'.Str::uuid7().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid gacha master edit password'),
            'role' => V2AdminRole::Owner->value,
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
