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

final class AdminProbabilityDraftManagementTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const GACHA_VERSION_ID = '0198a001-0000-7000-8000-000000000012';
    private const PUBLISHED_PROBABILITY_ID = '0198a001-0000-7000-8000-000000000013';
    private const PRIZE_S_ID = '0198a001-0000-7000-8000-000000000009';
    private const PRIZE_A_ID = '0198a001-0000-7000-8000-000000000010';

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
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_draft_create_replace_validate_replay_and_discard_are_canonical(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $root = $this->root();
        $createKey = 'probability-create-canonical-key';
        $draft = $this->mutatingRequest(
            $token,
            'POST',
            $root,
            [],
            $createKey
        )->assertCreated()
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.validation.is_valid', false)
            ->assertJsonPath('data.validation.errors.0', 'PROBABILITY_STAGE_REQUIRED')
            ->assertJsonCount(0, 'data.stages')
            ->json('data');

        Auth::forgetGuards();
        $this->mutatingRequest($token, 'POST', $root, [], $createKey)
            ->assertCreated()
            ->assertJsonPath('data.id', $draft['id'])
            ->assertJsonPath('idempotent_replay', true);

        Auth::forgetGuards();
        $incomplete = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/'.$draft['id'].'/entries',
            [
                'expected_revision' => 1,
                'stages' => [$this->stage(500000)],
            ],
            'probability-incomplete-save-key'
        )->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.validation.is_valid', false)
            ->assertJsonPath(
                'data.validation.stages.0.current_total_ppm',
                900000
            )
            ->json('data');

        Auth::forgetGuards();
        $valid = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/'.$draft['id'].'/entries',
            [
                'expected_revision' => $incomplete['revision'],
                'stages' => [$this->stage(600000)],
            ],
            'probability-valid-save-key'
        )->assertOk()
            ->assertJsonPath('data.validation.is_valid', true)
            ->assertJsonPath(
                'data.validation.stages.0.current_total_ppm',
                1000000
            )
            ->assertJsonPath('data.stages.0.entries.0.prize.id', self::PRIZE_S_ID)
            ->assertJsonMissingPath('data.stages.0.entries.0.internal_id')
            ->json('data');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/'.$draft['id'].'/validate',
            ['expected_revision' => $valid['revision']],
            'probability-server-validation-key'
        )->assertOk()
            ->assertJsonPath('data.validation.is_valid', true)
            ->assertJsonPath('data.revision', $valid['revision']);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/'.$draft['id'].'/archive',
            ['expected_revision' => $valid['revision']],
            'probability-discard-key'
        )->assertOk()
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.revision', $valid['revision'] + 1);

        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.validated',
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => $draft['id'],
            'event_type' => 'catalog.master.discarded',
        ]);
    }

    public function test_published_clone_preserves_source_and_draft_is_independent(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        $root = $this->root();
        $clone = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/'.self::PUBLISHED_PROBABILITY_ID.'/clone',
            []
        )->assertCreated()
            ->assertJsonPath(
                'data.cloned_from_version.id',
                self::PUBLISHED_PROBABILITY_ID
            )
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(2, 'data.stages')
            ->assertJsonPath('data.validation.is_valid', true)
            ->json('data');

        Auth::forgetGuards();
        $this->asAdmin($token)->getJson($root.'?status=draft&archive=active')
            ->assertOk()
            ->assertJsonFragment(['id' => $clone['id']])
            ->assertHeader('Cache-Control', 'no-store, private');

        Auth::forgetGuards();
        $this->asAdmin($token)->getJson($root.'/'.$clone['id'])
            ->assertOk()
            ->assertJsonPath('data.id', $clone['id'])
            ->assertJsonPath('data.stages.0.condition_type', 'sold_count');

        self::assertDatabaseHas('catalog_probability_versions', [
            'public_id' => self::PUBLISHED_PROBABILITY_ID,
            'status' => 'published',
            'revision' => 2,
            'archived_at' => null,
        ]);
    }

    public function test_structural_validation_occ_and_idempotency_fail_closed(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $root = $this->root();
        $draft = $this->mutatingRequest($token, 'POST', $root, [])->json('data');
        $uri = $root.'/'.$draft['id'].'/entries';
        $payload = [
            'expected_revision' => 1,
            'stages' => [$this->stage(600000)],
        ];

        foreach ([
            [
                'expected_revision' => 1,
                'stages' => [[
                    ...$this->stage(600000),
                    'entries' => [
                        $this->prizeTarget(self::PRIZE_S_ID, 300000),
                        $this->prizeTarget(self::PRIZE_S_ID, 300000),
                    ],
                ]],
            ],
            [
                'expected_revision' => 1,
                'stages' => [[
                    ...$this->stage(600000),
                    'entries' => [
                        $this->prizeTarget(
                            '0198a001-0000-7000-8000-000000000099',
                            600000
                        ),
                    ],
                ]],
            ],
            [
                'expected_revision' => 1,
                'stages' => [[...$this->stage(600000), 'min_draw_number' => 2]],
            ],
            [
                'expected_revision' => 1,
                'stages' => [[
                    ...$this->stage(600000),
                    'entries' => [[
                        ...$this->prizeTarget(self::PRIZE_S_ID, 600000),
                        'probability_ppm' => -1,
                    ]],
                ]],
            ],
        ] as $invalid) {
            Auth::forgetGuards();
            $this->mutatingRequest($token, 'PUT', $uri, $invalid)
                ->assertUnprocessable();
            self::assertDatabaseCount('catalog_probability_stages', 2);
        }

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            $uri,
            $payload,
            'probability-occ-key'
        )->assertOk()->assertJsonPath('data.revision', 2);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            $uri,
            $payload,
            'probability-occ-key'
        )->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('idempotent_replay', true);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            $uri,
            [...$payload, 'stages' => [$this->stage(500000)]],
            'probability-occ-key'
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        Auth::forgetGuards();
        $this->mutatingRequest($token, 'PUT', $uri, $payload)
            ->assertConflict()->assertJsonPath('code', 'CATALOG_REVISION_CONFLICT');
    }

    public function test_published_operator_and_forbidden_endpoints_are_protected(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $root = $this->root();
        $published = $this->asAdmin($owner)
            ->getJson($root.'/'.self::PUBLISHED_PROBABILITY_ID)
            ->assertOk()
            ->json('data');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'PUT',
            $root.'/'.self::PUBLISHED_PROBABILITY_ID.'/entries',
            [
                'expected_revision' => $published['revision'],
                'stages' => [$this->stage(600000)],
            ]
        )->assertConflict()
            ->assertJsonPath('code', 'CATALOG_PROBABILITY_VERSION_IMMUTABLE');

        $operator = $this->createAdminSession(V2AdminRole::Operator);
        Auth::forgetGuards();
        $this->asAdmin($operator)->getJson($root)->assertOk();
        Auth::forgetGuards();
        $this->mutatingRequest($operator, 'POST', $root, [])
            ->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

        Auth::forgetGuards();
        $this->asAdmin($owner)
            ->postJson($root.'/'.self::PUBLISHED_PROBABILITY_ID.'/publish', [])
            ->assertNotFound();
        Auth::forgetGuards();
        $this->asAdmin($owner)
            ->deleteJson($root.'/'.self::PUBLISHED_PROBABILITY_ID)
            ->assertStatus(405);
    }

    public function test_database_guards_reject_delete_revision_bypass_and_duplicate_prize(): void
    {
        $version = DB::table('catalog_probability_versions')
            ->where('public_id', self::PUBLISHED_PROBABILITY_ID)
            ->firstOrFail();
        $stage = DB::table('catalog_probability_stages')
            ->where('probability_version_id', $version->id)
            ->orderBy('id')
            ->firstOrFail();
        $entry = DB::table('catalog_probability_entries')
            ->where('probability_stage_id', $stage->id)
            ->whereNotNull('gacha_version_prize_id')
            ->firstOrFail();
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $this->mutatingRequest(
            $token,
            'POST',
            $this->root(),
            [],
            'probability-guard-draft-key'
        )->assertCreated();
        $draft = DB::table('catalog_probability_versions')
            ->where('gacha_version_id', $version->gacha_version_id)
            ->where('status', 'draft')
            ->firstOrFail();
        $draftStageId = DB::table('catalog_probability_stages')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'probability_version_id' => $draft->id,
            'code' => 'guard-stage',
            'display_name' => 'Guard Stage',
            'condition_type' => 'sold_count',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            fn () => DB::table('catalog_probability_versions')
                ->where('id', $version->id)->delete(),
            fn () => DB::table('catalog_probability_versions')
                ->where('id', $version->id)->update([
                    'snapshot_sha256' => str_repeat('a', 64),
                    'revision' => $version->revision,
                ]),
            fn () => DB::table('catalog_probability_entries')->insert([
                'probability_stage_id' => $stage->id,
                'result_type' => 'prize',
                'gacha_version_prize_id' => $entry->gacha_version_prize_id,
                'point_amount' => null,
                'probability_ppm' => 0,
                'sort_order' => 999,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            fn () => DB::table('catalog_probability_entries')
                ->where('id', $entry->id)
                ->update(['probability_stage_id' => $draftStageId]),
            fn () => DB::table('catalog_probability_stages')
                ->where('id', $stage->id)
                ->update(['probability_version_id' => $draft->id]),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                DB::rollBack();
                self::fail('The Probability database guard must reject this mutation.');
            } catch (QueryException $exception) {
                DB::rollBack();
                self::assertContains($exception->errorInfo[0], ['23505', 'P0001']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function stage(int $entryPpm): array
    {
        return [
            'code' => 'stage-1',
            'name' => 'Stage 1',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'entries' => [$this->prizeTarget(self::PRIZE_S_ID, $entryPpm)],
            'minimum_guarantee' =>
                $this->prizeTarget(self::PRIZE_A_ID, 400000),
        ];
    }

    /** @return array<string, mixed> */
    private function prizeTarget(string $prizeId, int $ppm): array
    {
        return [
            'result_type' => 'prize',
            'prize_id' => $prizeId,
            'point_amount' => null,
            'probability_ppm' => $ppm,
        ];
    }

    private function root(): string
    {
        return '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.
            '/versions/'.self::GACHA_VERSION_ID.'/probability-versions';
    }

    private function mutatingRequest(
        string $token,
        string $method,
        string $uri,
        array $payload,
        ?string $key = null
    ) {
        $csrf = str_repeat('b', 64);
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
                ->hash('valid probability editor test password'),
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
