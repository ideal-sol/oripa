<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminGachaPublishPreflightTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PUBLISHED_GACHA_VERSION_ID = '0198a001-0000-7000-8000-000000000012';
    private const PUBLISHED_PROBABILITY_ID = '0198a001-0000-7000-8000-000000000013';
    private const PRIZE_S_ID = '0198a001-0000-7000-8000-000000000009';
    private const PRIZE_A_ID = '0198a001-0000-7000-8000-000000000010';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        app(V2CatalogFixtureImporter::class)->import($this->fixture());
        config([
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('q', 32)),
            'v2_identity.origins.admin' => 'https://admin.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_selection_and_publish_preflight_are_canonical_without_activation(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $root = $this->versionRoot($draft['id']);

        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson($root.'/published-probability-candidates?limit=20')
            ->assertOk()
            ->assertJsonPath('items.0.id', $probability['id'])
            ->assertJsonPath('items.0.validation_status', 'valid')
            ->assertJsonPath('items.0.stage_count', 1)
            ->assertJsonMissingPath('items.0.internal_id');

        Auth::forgetGuards();
        $this->asAdmin($token)->getJson($root.'/probability-selection')
            ->assertOk()
            ->assertJsonPath('data.selected_probability', null)
            ->assertJsonPath('data.gacha_version_revision', $draft['revision']);

        $selectionKey = 'gacha-probability-selection-canonical';
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            $selectionKey
        )->assertOk()
            ->assertJsonPath(
                'data.published_probability_version.id',
                $probability['id']
            )
            ->assertJsonPath('data.revision', $draft['revision'] + 1)
            ->json('data');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            $selectionKey
        )->assertOk()
            ->assertJsonPath('data.id', $draft['id'])
            ->assertJsonPath('idempotent_replay', true);

        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)
            ->firstOrFail();
        $publicPointerBefore = DB::table('catalog_gachas')
            ->where('id', $gacha->id)
            ->value('published_version_id');
        self::assertNotNull($publicPointerBefore);
        $drawPointersBefore = DB::table('gacha_draw_states')
            ->where('gacha_id', $gacha->id)
            ->orderBy('id')
            ->pluck('probability_version_id', 'id')
            ->all();

        Auth::forgetGuards();
        $preflight = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish-preflight',
            ['expected_revision' => $selected['revision']],
            'gacha-publish-preflight-canonical'
        )->assertOk()
            ->assertJsonPath('data.publishable', true)
            ->assertJsonPath(
                'data.selected_probability.id',
                $probability['id']
            )
            ->assertJsonPath(
                'data.validation_codes.0',
                'GACHA_PUBLISH_PREFLIGHT_READY'
            )
            ->assertJsonCount(0, 'data.blocking_reasons')
            ->json('data');

        self::assertTrue(Str::isUuid($preflight['request_id']));
        self::assertSame(
            $publicPointerBefore,
            DB::table('catalog_gachas')
                ->where('id', $gacha->id)
                ->value('published_version_id')
        );
        self::assertSame(
            $drawPointersBefore,
            DB::table('gacha_draw_states')
                ->where('gacha_id', $gacha->id)
                ->orderBy('id')
                ->pluck('probability_version_id', 'id')
                ->all()
        );
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.probability_selected',
            'target_public_id' => $draft['id'],
        ]);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.publish_preflight_completed',
            'target_public_id' => $draft['id'],
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => $draft['id'],
            'event_type' => 'catalog.master.probability_selected',
        ]);
        self::assertDatabaseMissing('outbox_messages', [
            'aggregate_public_id' => $draft['id'],
            'event_type' => 'catalog.master.publish_preflight_completed',
        ]);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish',
            ['expected_revision' => $selected['revision']],
            'gacha-publish-endpoint-must-not-exist'
        )->assertNotFound();
    }

    public function test_preflight_returns_typed_blockers_without_changing_catalog_or_draw(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $draft = $this->cloneGachaDraft($token);
        $root = $this->versionRoot($draft['id']);
        $gachaBefore = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)
            ->firstOrFail();
        $versionBefore = DB::table('catalog_gacha_versions')
            ->where('public_id', $draft['id'])
            ->firstOrFail();
        $drawBefore = DB::table('gacha_draw_states')
            ->where('gacha_id', $gachaBefore->id)
            ->orderBy('id')
            ->pluck('probability_version_id', 'id')
            ->all();

        Auth::forgetGuards();
        $response = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish-preflight',
            ['expected_revision' => $draft['revision']],
            'gacha-publish-preflight-blocked'
        )->assertOk()
            ->assertJsonPath('data.publishable', false)
            ->json('data');

        self::assertContains(
            'GACHA_PROBABILITY_NOT_SELECTED',
            $response['validation_codes']
        );
        self::assertSame(
            (array) $gachaBefore,
            (array) DB::table('catalog_gachas')->where('id', $gachaBefore->id)
                ->firstOrFail()
        );
        self::assertSame(
            (array) $versionBefore,
            (array) DB::table('catalog_gacha_versions')
                ->where('id', $versionBefore->id)->firstOrFail()
        );
        self::assertSame(
            $drawBefore,
            DB::table('gacha_draw_states')
                ->where('gacha_id', $gachaBefore->id)
                ->orderBy('id')
                ->pluck('probability_version_id', 'id')
                ->all()
        );
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.publish_preflight_failed',
            'reason_code' => 'publish_preflight_blocked',
        ]);
    }

    public function test_selection_enforces_permission_fresh_mfa_occ_and_parent(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($owner);
        $root = $this->versionRoot($draft['id']);

        $operator = $this->createAdminSession(V2AdminRole::Operator);
        Auth::forgetGuards();
        $this->asAdmin($operator)
            ->getJson($root.'/published-probability-candidates')
            ->assertOk();
        Auth::forgetGuards();
        $this->mutatingRequest(
            $operator,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-selection-operator'
        )->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => self::PUBLISHED_PROBABILITY_ID,
            ],
            'gacha-selection-cross-version'
        )->assertUnprocessable()
            ->assertJsonPath('code', 'CATALOG_PROBABILITY_SELECTION_INVALID');

        $stale = $this->createAdminSession(V2AdminRole::Admin);
        DB::table('admin_sessions')
            ->where(
                'session_id_hash',
                app(V2SessionPolicy::class)->hashSessionId($stale)
            )
            ->update(['mfa_verified_at' => now()->subMinutes(5)]);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $stale,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-selection-stale-mfa'
        )->assertForbidden()
            ->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-selection-occ'
        )->assertOk();
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-selection-stale-revision'
        )->assertConflict()->assertJsonPath('code', 'CATALOG_REVISION_CONFLICT');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => self::PUBLISHED_PROBABILITY_ID,
            ],
            'gacha-selection-occ'
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_database_guard_rejects_cross_version_clear_and_revision_bypass(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $version = DB::table('catalog_gacha_versions')
            ->where('public_id', $draft['id'])
            ->firstOrFail();
        $crossVersionProbabilityId = DB::table('catalog_probability_versions')
            ->where('public_id', self::PUBLISHED_PROBABILITY_ID)
            ->value('id');

        foreach ([
            fn () => DB::table('catalog_gacha_versions')
                ->where('id', $version->id)->update([
                    'published_probability_version_id' => $crossVersionProbabilityId,
                    'revision' => (int) $version->revision + 1,
                ]),
            fn () => DB::table('catalog_gacha_versions')
                ->where('id', $version->id)->update([
                    'published_probability_version_id' => DB::table(
                        'catalog_probability_versions'
                    )->where('public_id', $probability['id'])->value('id'),
                    'revision' => (int) $version->revision,
                ]),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                DB::rollBack();
                self::fail('The Gacha Probability Selection guard must reject this update.');
            } catch (QueryException $exception) {
                DB::rollBack();
                self::assertSame('P0001', $exception->errorInfo[0]);
            }
        }

        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $this->versionRoot($draft['id']).'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-selection-guard-valid'
        )->assertOk()->json('data');
        DB::beginTransaction();
        try {
            DB::table('catalog_gacha_versions')
                ->where('public_id', $draft['id'])->update([
                    'published_probability_version_id' => null,
                    'revision' => $selected['revision'] + 1,
                ]);
            DB::rollBack();
            self::fail('A selected Published Probability cannot be cleared.');
        } catch (QueryException $exception) {
            DB::rollBack();
            self::assertSame('P0001', $exception->errorInfo[0]);
        }
    }

    public function test_selection_outbox_failure_rolls_back_pointer_and_idempotency(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_test_reject_gacha_probability_selection_outbox()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.topic = 'catalog.change'
                   AND NEW.event_type = 'catalog.master.probability_selected' THEN
                    RAISE EXCEPTION 'synthetic Gacha selection outbox failure';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER v2_test_reject_gacha_probability_selection_outbox '.
            'BEFORE INSERT ON outbox_messages FOR EACH ROW '.
            'EXECUTE FUNCTION v2_test_reject_gacha_probability_selection_outbox()'
        );
        $token = $this->createAdminSession(V2AdminRole::Admin);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $idempotencyCountBefore = DB::table('idempotency_records')->count();

        try {
            $this->withoutExceptionHandling();
            Auth::forgetGuards();
            $this->mutatingRequest(
                $token,
                'PUT',
                $this->versionRoot($draft['id']).'/probability-selection',
                [
                    'expected_revision' => $draft['revision'],
                    'probability_version_id' => $probability['id'],
                ],
                'gacha-selection-outbox-failure'
            );
            self::fail('Selection must roll back when Outbox persistence fails.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'synthetic Gacha selection outbox failure',
                $exception->getMessage()
            );
        } finally {
            $this->withExceptionHandling();
            DB::statement(
                'DROP TRIGGER IF EXISTS '.
                'v2_test_reject_gacha_probability_selection_outbox '.
                'ON outbox_messages'
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS '.
                'v2_test_reject_gacha_probability_selection_outbox()'
            );
        }

        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $draft['id'],
            'published_probability_version_id' => null,
            'revision' => $draft['revision'],
        ]);
        self::assertSame(
            $idempotencyCountBefore,
            DB::table('idempotency_records')->count()
        );
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function createDraftWithPublishedProbability(string $token): array
    {
        $draft = $this->cloneGachaDraft($token);
        $root = $this->versionRoot($draft['id']).'/probability-versions';
        Auth::forgetGuards();
        $probability = $this->mutatingRequest(
            $token,
            'POST',
            $root,
            [],
            'gacha-preflight-probability-create-'.$draft['id']
        )->assertCreated()->json('data');
        Auth::forgetGuards();
        $saved = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/'.$probability['id'].'/entries',
            [
                'expected_revision' => $probability['revision'],
                'stages' => [[
                    'code' => 'stage-1',
                    'name' => 'Stage 1',
                    'min_draw_number' => 1,
                    'max_draw_number' => null,
                    'entries' => [[
                        'result_type' => 'prize',
                        'prize_id' => self::PRIZE_S_ID,
                        'point_amount' => null,
                        'probability_ppm' => 600000,
                    ]],
                    'minimum_guarantee' => [
                        'result_type' => 'prize',
                        'prize_id' => self::PRIZE_A_ID,
                        'point_amount' => null,
                        'probability_ppm' => 400000,
                    ],
                ]],
            ],
            'gacha-preflight-probability-save-'.$draft['id']
        )->assertOk()->json('data');
        Auth::forgetGuards();
        $published = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/'.$probability['id'].'/publish',
            ['expected_revision' => $saved['revision']],
            'gacha-preflight-probability-publish-'.$draft['id']
        )->assertOk()->json('data');

        return [$draft, $published];
    }

    /** @return array<string, mixed> */
    private function cloneGachaDraft(string $token): array
    {
        Auth::forgetGuards();

        return $this->mutatingRequest(
            $token,
            'POST',
            $this->versionRoot(self::PUBLISHED_GACHA_VERSION_ID).'/clone',
            [],
            'gacha-preflight-clone-'.Str::uuid7()
        )->assertCreated()->json('data');
    }

    private function versionRoot(string $versionId): string
    {
        return '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.
            '/versions/'.$versionId;
    }

    private function mutatingRequest(
        string $token,
        string $method,
        string $uri,
        array $payload,
        string $key
    ) {
        $csrf = str_repeat('c', 64);
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
                ->hash('valid gacha preflight test password'),
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

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
    }
}
