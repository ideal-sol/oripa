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

final class AdminGachaDraftManagementTest extends TestCase
{
    private const CATEGORY_ID = '0198a001-0000-7000-8000-000000000001';
    private const TAG_ID = '0198a001-0000-7000-8000-000000000002';
    private const ASSET_ID = '0198a001-0000-7000-8000-000000000005';
    private const PRIZE_ID = '0198a001-0000-7000-8000-000000000009';
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

    public function test_gacha_master_create_update_archive_and_replay_are_canonical(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $input = $this->gachaInput('admin-draft-gacha');
        $key = 'gacha-create-canonical-key';
        $created = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas',
            $input,
            $key
        )->assertCreated()
            ->assertJsonPath('data.code', $input['code'])
            ->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.tags.0.id', self::TAG_ID)
            ->assertJsonPath('idempotent_replay', false);
        $gachaId = $created->json('data.id');
        self::assertTrue(Str::isUuid($gachaId));

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas',
            $input,
            $key
        )->assertCreated()
            ->assertJsonPath('data.id', $gachaId)
            ->assertJsonPath('idempotent_replay', true)
            ->assertHeader('Idempotency-Replayed', 'true');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas',
            [...$input, 'slug' => 'different-slug'],
            $key
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas?state=draft&archive=active&sort=code')
            ->assertOk()
            ->assertJsonFragment(['id' => $gachaId])
            ->assertJsonMissingPath('items.0.internal_id');

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas/'.$gachaId)
            ->assertOk()
            ->assertJsonPath('data.has_draw_history', false)
            ->assertHeader('Cache-Control', 'no-store, private');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.$gachaId,
            [
                'expected_revision' => 1,
                'category_id' => self::CATEGORY_ID,
                'tag_ids' => [],
            ]
        )->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonCount(0, 'data.tags');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.$gachaId,
            [
                'expected_revision' => 1,
                'category_id' => self::CATEGORY_ID,
                'tag_ids' => [],
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_REVISION_CONFLICT');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/'.$gachaId.'/archive',
            ['expected_revision' => 2]
        )->assertOk()
            ->assertJsonPath('data.state', 'disabled')
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.revision', 3);
    }

    public function test_draft_create_clone_update_discard_preserves_source_and_relations(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        $gacha = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas',
            $this->gachaInput('draft-version-gacha')
        )->assertCreated()->json('data');

        Auth::forgetGuards();
        $draft = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions',
            $this->versionInput('Draft Version')
        )->assertCreated()
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.prizes.0.prize.id', self::PRIZE_ID)
            ->json('data');

        Auth::forgetGuards();
        $clone = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions/'.$draft['id'].'/clone',
            []
        )->assertCreated()
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.cloned_from_version.id', $draft['id'])
            ->assertJsonPath('data.published_probability_version', null)
            ->json('data');

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions?status=draft')
            ->assertOk()
            ->assertJsonCount(2, 'items');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions/'.$clone['id'],
            [
                ...$this->versionInput('Updated Draft'),
                'expected_revision' => 1,
            ]
        )->assertOk()
            ->assertJsonPath('data.title', 'Updated Draft')
            ->assertJsonPath('data.revision', 2);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions/'.$clone['id'].'/archive',
            ['expected_revision' => 2]
        )->assertOk()
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.revision', 3);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions/'.$clone['id'],
            [
                ...$this->versionInput('Must Fail'),
                'expected_revision' => 3,
            ]
        )->assertConflict()->assertJsonPath('code', 'CATALOG_RESOURCE_ARCHIVED');

        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $draft['id'],
            'title' => 'Draft Version',
            'revision' => 1,
            'archived_at' => null,
        ]);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.cloned',
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => $clone['id'],
            'event_type' => 'catalog.master.discarded',
        ]);
    }

    public function test_published_reference_and_input_validation_fail_closed(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $published = $this->asAdmin($token)
            ->getJson(
                '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID.
                '/versions/'.self::PUBLISHED_VERSION_ID
            )->assertOk()->json('data');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID.
            '/versions/'.self::PUBLISHED_VERSION_ID,
            [
                ...$this->versionInput('Published Must Not Change'),
                'expected_revision' => $published['revision'],
            ]
        )->assertConflict()
            ->assertJsonPath('code', 'CATALOG_GACHA_VERSION_IMMUTABLE');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID.'/archive',
            ['expected_revision' => 2]
        )->assertConflict()
            ->assertJsonPath('code', 'CATALOG_PUBLISHED_REFERENCE_CONFLICT');

        $gacha = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas',
            $this->gachaInput('validation-draft-gacha')
        )->assertCreated()->json('data');
        foreach ([
            [...$this->versionInput('Duplicate Prize'), 'prizes' => [
                $this->prizeInput(10),
                $this->prizeInput(20),
            ]],
            [...$this->versionInput('Duplicate Sort'), 'prizes' => [
                $this->prizeInput(10),
                [
                    'prize_id' => '0198a001-0000-7000-8000-000000000010',
                    'initial_inventory' => 20,
                    'sort_order' => 10,
                ],
            ]],
            [
                ...$this->versionInput('Invalid Period'),
                'publish_start_at' => '2027-01-01T00:00:00Z',
                'publish_end_at' => '2026-01-01T00:00:00Z',
            ],
        ] as $invalid) {
            Auth::forgetGuards();
            $this->mutatingRequest(
                $token,
                'POST',
                '/admin/api/v2/catalog/gachas/'.$gacha['id'].'/versions',
                $invalid
            )->assertUnprocessable()->assertJsonPath('code', 'CATALOG_MUTATION_INVALID');
        }
    }

    public function test_operator_is_read_only_and_mutation_surfaces_cannot_be_bypassed(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Operator);
        $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas')
            ->assertOk()
            ->assertJsonFragment(['id' => self::PUBLISHED_GACHA_ID]);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas',
            $this->gachaInput('operator-must-not-create')
        )->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

        foreach ([
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID,
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID.
                '/versions/'.self::PUBLISHED_VERSION_ID,
        ] as $uri) {
            Auth::forgetGuards();
            $this->asAdmin($token)->deleteJson($uri)->assertStatus(405);
        }

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/'.self::PUBLISHED_GACHA_ID.
            '/versions/'.self::PUBLISHED_VERSION_ID.'/probability',
            []
        )->assertNotFound();
    }

    public function test_database_guards_reject_physical_delete_and_revision_bypass(): void
    {
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::PUBLISHED_GACHA_ID)
            ->firstOrFail();
        $version = DB::table('catalog_gacha_versions')
            ->where('public_id', self::PUBLISHED_VERSION_ID)
            ->firstOrFail();
        foreach ([
            fn () => DB::table('catalog_gachas')->where('id', $gacha->id)->delete(),
            fn () => DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                'category_id' => $gacha->category_id,
                'revision' => $gacha->revision,
            ]),
            fn () => DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                'state' => 'disabled',
                'revision' => $gacha->revision + 1,
            ]),
            fn () => DB::table('catalog_gacha_versions')->where('id', $version->id)->delete(),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                DB::rollBack();
                self::fail('The Gacha database guard must reject this mutation.');
            } catch (QueryException $exception) {
                DB::rollBack();
                self::assertSame('P0001', $exception->errorInfo[0]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function gachaInput(string $code): array
    {
        return [
            'code' => $code,
            'slug' => $code,
            'category_id' => self::CATEGORY_ID,
            'tag_ids' => [self::TAG_ID],
        ];
    }

    /** @return array<string, mixed> */
    private function versionInput(string $title): array
    {
        return [
            'title' => $title,
            'description' => 'Plain text description',
            'notices' => 'Draft only',
            'price_points' => 100,
            'total_count' => 1000,
            'presentation_asset_id' => self::ASSET_ID,
            'publish_start_at' => '2026-08-01T00:00:00Z',
            'publish_end_at' => '2027-08-01T00:00:00Z',
            'prizes' => [$this->prizeInput(10)],
        ];
    }

    /** @return array<string, mixed> */
    private function prizeInput(int $sortOrder): array
    {
        return [
            'prize_id' => self::PRIZE_ID,
            'initial_inventory' => 10,
            'sort_order' => $sortOrder,
        ];
    }

    private function mutatingRequest(
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
        return $this
            ->withCredentials()
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
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid gacha draft management test password'),
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
