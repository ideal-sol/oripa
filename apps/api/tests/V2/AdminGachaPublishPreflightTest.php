<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogReadService;
use App\Domain\Catalog\Services\V2ScheduledGachaPublishWorker;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\User;
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
            $root.'/schedule',
            ['expected_revision' => $selected['revision']],
            'gacha-schedule-endpoint-must-not-exist'
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

    public function test_immediate_publish_atomically_switches_public_and_draw_state(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        DB::table('catalog_gacha_versions')
            ->where('public_id', $draft['id'])
            ->update([
                'title' => 'Immediate Publish Version',
                'revision' => (int) $draft['revision'] + 1,
                'updated_at' => now(),
            ]);
        $draft['revision'] = (int) $draft['revision'] + 1;
        $root = $this->versionRoot($draft['id']);
        self::assertSame(
            'Fixture Catalog Gacha',
            app(V2CatalogReadService::class)
                ->getBySlug('fixture-catalog')['title']
        );
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-immediate-publish-selection'
        )->assertOk()->json('data');
        $gachaBefore = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        $oldState = DB::table('gacha_draw_states')
            ->where('id', $gachaBefore->active_draw_state_id)->firstOrFail();
        $user = User::query()->create([
            'email_display' => 'gacha-publish-draw@example.test',
            'email_normalized' => 'gacha-publish-draw@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid gacha publish draw password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            1_000,
            now()->addYear(),
            'gacha-immediate-publish-draw-points'
        );
        $oldDraw = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1,
            'gacha-immediate-old-version-draw',
            (string) Str::uuid7()
        );
        $oldDrawRequest = DB::table('draw_requests')
            ->where('public_id', $oldDraw['id'])->firstOrFail();
        self::assertSame(
            (int) $gachaBefore->published_version_id,
            (int) $oldDrawRequest->gacha_version_id
        );

        Auth::forgetGuards();
        $published = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish',
            [
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => $gachaBefore->revision,
            ],
            'gacha-immediate-publish-success'
        )->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.gacha_version_id', $draft['id'])
            ->assertJsonPath('data.selected_probability.id', $probability['id'])
            ->assertJsonPath(
                'data.previous_published_version.id',
                self::PUBLISHED_GACHA_VERSION_ID
            )
            ->assertJsonPath('data.draw_state.sold_count', 0)
            ->assertJsonPath('idempotent_replay', false)
            ->json('data');

        $gachaAfter = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        $newVersion = DB::table('catalog_gacha_versions')
            ->where('public_id', $draft['id'])->firstOrFail();
        $newState = DB::table('gacha_draw_states')
            ->where('id', $gachaAfter->active_draw_state_id)->firstOrFail();
        self::assertSame('published', $newVersion->status);
        self::assertNotNull($newVersion->published_at);
        self::assertSame((int) $newVersion->id, (int) $gachaAfter->published_version_id);
        self::assertSame((int) $newVersion->id, (int) $newState->gacha_version_id);
        self::assertSame(
            (int) $newVersion->published_probability_version_id,
            (int) $newState->probability_version_id
        );
        self::assertNotSame((int) $oldState->id, (int) $newState->id);
        self::assertSame(
            'Immediate Publish Version',
            app(V2CatalogReadService::class)
                ->getBySlug('fixture-catalog')['title']
        );
        self::assertDatabaseHas('gacha_draw_states', [
            'id' => $oldState->id,
            'gacha_version_id' => $oldState->gacha_version_id,
            'probability_version_id' => $oldState->probability_version_id,
        ]);
        self::assertSame(
            DB::table('catalog_gacha_version_prizes')
                ->where('gacha_version_id', $newVersion->id)->count(),
            DB::table('prize_inventories')
                ->where('gacha_draw_state_id', $newState->id)->count()
        );
        $newDraw = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1,
            'gacha-immediate-new-version-draw',
            (string) Str::uuid7()
        );
        $newDrawRequest = DB::table('draw_requests')
            ->where('public_id', $newDraw['id'])->firstOrFail();
        self::assertSame((int) $newVersion->id, (int) $newDrawRequest->gacha_version_id);
        self::assertSame(
            (int) $newVersion->published_probability_version_id,
            (int) $newDrawRequest->probability_version_id
        );
        self::assertSame(
            (int) $oldState->gacha_version_id,
            (int) DB::table('draw_requests')
                ->where('public_id', $oldDraw['id'])
                ->value('gacha_version_id')
        );
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.immediately_published',
            'target_public_id' => $draft['id'],
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => $draft['id'],
            'event_type' => 'catalog.master.immediately_published',
        ]);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish',
            [
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => $gachaBefore->revision,
            ],
            'gacha-immediate-publish-success'
        )->assertOk()
            ->assertJsonPath('data.published_at', $published['published_at'])
            ->assertJsonPath('idempotent_replay', true);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish',
            [
                'expected_revision' => (int) $selected['revision'] + 1,
                'expected_gacha_revision' => $gachaBefore->revision,
            ],
            'gacha-immediate-publish-success'
        )->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish',
            [
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => $gachaBefore->revision,
            ],
            'gacha-immediate-publish-stale-revision'
        )->assertConflict()
            ->assertJsonPath('code', 'CATALOG_REVISION_CONFLICT');
        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson(
                '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/publish-state'
            )->assertOk()
            ->assertJsonPath('data.current_published_version.id', $draft['id'])
            ->assertJsonPath('data.selected_probability.id', $probability['id'])
            ->assertJsonPath('data.draw_state.sold_count', 1);
    }

    public function test_immediate_publish_rolls_back_all_activation_on_outbox_failure(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $root = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-immediate-rollback-selection'
        )->assertOk()->json('data');
        $gachaBefore = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        $stateCountBefore = DB::table('gacha_draw_states')->count();
        $inventoryCountBefore = DB::table('prize_inventories')->count();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_test_reject_gacha_immediate_publish_outbox()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event_type = 'catalog.master.immediately_published' THEN
                    RAISE EXCEPTION 'synthetic immediate publish outbox failure';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER v2_test_reject_gacha_immediate_publish_outbox '.
            'BEFORE INSERT ON outbox_messages FOR EACH ROW '.
            'EXECUTE FUNCTION v2_test_reject_gacha_immediate_publish_outbox()'
        );

        try {
            $this->withoutExceptionHandling();
            Auth::forgetGuards();
            $this->mutatingRequest(
                $token,
                'POST',
                $root.'/publish',
                [
                    'expected_revision' => $selected['revision'],
                    'expected_gacha_revision' => $gachaBefore->revision,
                ],
                'gacha-immediate-publish-outbox-failure'
            );
            self::fail('Immediate Publish must roll back on Outbox failure.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'synthetic immediate publish outbox failure',
                $exception->getMessage()
            );
        } finally {
            $this->withExceptionHandling();
            DB::statement(
                'DROP TRIGGER IF EXISTS '.
                'v2_test_reject_gacha_immediate_publish_outbox '.
                'ON outbox_messages'
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS '.
                'v2_test_reject_gacha_immediate_publish_outbox()'
            );
        }

        $gachaAfter = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        self::assertSame(
            (int) $gachaBefore->published_version_id,
            (int) $gachaAfter->published_version_id
        );
        self::assertSame(
            (int) $gachaBefore->active_draw_state_id,
            (int) $gachaAfter->active_draw_state_id
        );
        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $draft['id'],
            'status' => 'draft',
            'published_at' => null,
            'revision' => $selected['revision'],
        ]);
        self::assertSame($stateCountBefore, DB::table('gacha_draw_states')->count());
        self::assertSame(
            $inventoryCountBefore,
            DB::table('prize_inventories')->count()
        );
    }

    public function test_activation_database_guards_reject_partial_or_destructive_sql(): void
    {
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        $state = DB::table('gacha_draw_states')
            ->where('id', $gacha->active_draw_state_id)->firstOrFail();
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $draft = $this->cloneGachaDraft($token);
        $draftId = DB::table('catalog_gacha_versions')
            ->where('public_id', $draft['id'])->value('id');

        DB::beginTransaction();
        try {
            DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                'published_version_id' => $draftId,
                'revision' => (int) $gacha->revision + 1,
            ]);
            DB::statement(
                'SET CONSTRAINTS catalog_gachas_validate_activation IMMEDIATE'
            );
            DB::rollBack();
            self::fail('A partial Public pointer update must be rejected.');
        } catch (QueryException $exception) {
            DB::rollBack();
            self::assertSame('P0001', $exception->errorInfo[0]);
        }

        foreach ([
            fn () => DB::table('gacha_draw_states')->insert([
                'gacha_id' => $gacha->id,
                'gacha_version_id' => $draftId,
                'probability_version_id' => $state->probability_version_id,
                'status' => 'selling',
                'total_count' => 1,
                'sold_count' => 0,
                'lock_version' => 0,
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            fn () => DB::table('gacha_draw_states')
                ->where('id', $state->id)->delete(),
        ] as $mutation) {
            DB::beginTransaction();
            try {
                $mutation();
                DB::rollBack();
                self::fail('The activation history guard must reject this SQL.');
            } catch (QueryException $exception) {
                DB::rollBack();
                self::assertSame('P0001', $exception->errorInfo[0]);
            }
        }
    }

    public function test_immediate_publish_requires_admin_permission_fresh_mfa_and_csrf(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($owner);
        $root = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $owner,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-immediate-security-selection'
        )->assertOk()->json('data');
        $payload = [
            'expected_revision' => $selected['revision'],
            'expected_gacha_revision' => DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->value('revision'),
        ];

        $operator = $this->createAdminSession(V2AdminRole::Operator);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $operator,
            'POST',
            $root.'/publish',
            $payload,
            'gacha-immediate-operator'
        )->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

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
            'POST',
            $root.'/publish',
            $payload,
            'gacha-immediate-stale'
        )->assertForbidden()
            ->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');

        Auth::forgetGuards();
        $this->asAdmin(str_repeat('x', 64))
            ->postJson($root.'/publish', $payload)
            ->assertUnauthorized();
        Auth::forgetGuards();
        $this->flushHeaders()
            ->asAdmin($owner)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'Idempotency-Key' => 'gacha-immediate-no-csrf',
            ])
            ->postJson($root.'/publish', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $draft['id'],
            'status' => 'draft',
        ]);
    }

    public function test_scheduled_publish_create_replay_cancel_and_draft_guard(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $root = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-schedule-selection'
        )->assertOk()->json('data');
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        $scheduledFor = CarbonImmutable::parse(
            DB::selectOne('SELECT CURRENT_TIMESTAMP AS value')->value
        )->addHour()->toIso8601String();
        $payload = [
            'scheduled_for' => $scheduledFor,
            'expected_revision' => $selected['revision'],
            'expected_gacha_revision' => $gacha->revision,
        ];

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish-schedule/preflight',
            $payload,
            'gacha-schedule-preflight'
        )->assertOk()
            ->assertJsonPath('data.publishable', true)
            ->assertJsonPath(
                'data.validation_codes.0',
                'GACHA_SCHEDULE_PREFLIGHT_READY'
            )
            ->assertJsonPath('data.server_timezone', 'UTC');

        Auth::forgetGuards();
        $scheduled = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish-schedule',
            $payload,
            'gacha-schedule-create'
        )->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.attempts', 0)
            ->assertJsonPath('idempotent_replay', false)
            ->json('data');
        self::assertTrue(Str::isUuid($scheduled['id']));
        DB::beginTransaction();
        try {
            DB::table('catalog_gacha_publish_schedules')
                ->where('public_id', $scheduled['id'])
                ->delete();
            DB::rollBack();
            self::fail('Publish Schedule history must be immutable.');
        } catch (QueryException $exception) {
            DB::rollBack();
            self::assertSame('P0001', $exception->errorInfo[0]);
        }
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish-schedule',
            $payload,
            'gacha-schedule-create'
        )->assertCreated()
            ->assertJsonPath('data.id', $scheduled['id'])
            ->assertJsonPath('idempotent_replay', true);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish-schedule',
            [...$payload, 'scheduled_for' => CarbonImmutable::parse(
                $scheduledFor
            )->addMinute()->toIso8601String()],
            'gacha-schedule-create'
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson($root.'/publish-schedule')
            ->assertOk()
            ->assertJsonPath('data.id', $scheduled['id']);

        DB::beginTransaction();
        try {
            DB::table('catalog_gacha_versions')
                ->where('public_id', $draft['id'])
                ->update([
                    'title' => 'Must remain immutable while scheduled',
                    'revision' => $scheduled['gacha_version_revision'] + 1,
                ]);
            DB::rollBack();
            self::fail('A scheduled Draft must be immutable.');
        } catch (QueryException $exception) {
            DB::rollBack();
            self::assertSame('P0001', $exception->errorInfo[0]);
        }

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish',
            [
                'expected_revision' => $scheduled['gacha_version_revision'],
                'expected_gacha_revision' => $scheduled['gacha_revision'],
            ],
            'gacha-immediate-conflicts-with-schedule'
        )->assertUnprocessable()
            ->assertJsonPath('code', 'CATALOG_GACHA_PUBLISH_INVALID');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish-schedule/'.$scheduled['id'].'/cancel',
            [
                'expected_schedule_revision' => $scheduled['revision'],
                'expected_gacha_revision' => $scheduled['gacha_revision'],
                'expected_version_revision' =>
                    $scheduled['gacha_version_revision'],
            ],
            'gacha-schedule-cancel'
        )->assertConflict()
            ->assertJsonPath('code', 'CATALOG_GACHA_SCHEDULE_CONFLICT');
        Auth::forgetGuards();
        $this->asAdmin($token)->getJson($root.'/publish-schedule')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath(
                'data.gacha_revision',
                $scheduled['gacha_revision']
            )
            ->assertJsonPath(
                'data.gacha_version_revision',
                $scheduled['gacha_version_revision']
            );
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.publish_scheduled',
            'target_public_id' => $scheduled['id'],
        ]);
        self::assertDatabaseHas('catalog_gachas', [
            'public_id' => self::GACHA_ID,
            'management_status' => 'scheduled',
        ]);
        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $draft['id'],
            'status' => 'published',
        ]);
        self::assertSame(
            0,
            app(V2ScheduledGachaPublishWorker::class)->run('compatibility-worker', 1)
        );
    }

    public function test_master_scheduled_state_changes_effective_sale_state_without_worker(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $root = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-worker-selection'
        )->assertOk()->json('data');
        $gachaBefore = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        $scheduledFor = CarbonImmutable::parse(
            DB::selectOne('SELECT CURRENT_TIMESTAMP AS value')->value
        )->addHour()->toIso8601String();
        Auth::forgetGuards();
        $detail = $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas/'.self::GACHA_ID)
            ->assertOk()->json('data');
        Auth::forgetGuards();
        $masterPayload = [
            'category_id' => $detail['category']['id'],
            'tag_ids' => array_column($detail['tags'], 'id'),
            'title' => $selected['title'],
            'price_points' => $selected['price_points'],
            'total_count' => $selected['total_count'],
            'daily_draw_limit' => $selected['daily_draw_limit'],
            'audience_code' => $selected['audience_code'],
            'first_time_eligible_days' => $selected['first_time_eligible_days'],
            'presentation_asset_id' => $selected['presentation_asset']['id'],
            'publish_start_at' => $scheduledFor,
            'publish_end_at' => $selected['publish_end_at'] === null
                ? null
                : CarbonImmutable::parse($selected['publish_end_at'])->toIso8601String(),
            'description' => $selected['description'],
            'notices' => $selected['notices'],
            'management_status' => 'scheduled',
            'expected_revision' => (int) $gachaBefore->revision,
            'expected_version_revision' => $selected['revision'],
        ];
        $scheduled = $this->mutatingRequest(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.self::GACHA_ID,
            $masterPayload,
            'gacha-master-scheduled-state'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'scheduled')
            ->json('data');

        self::assertSame(
            0,
            app(V2ScheduledGachaPublishWorker::class)->run('worker-a', 5)
        );
        self::assertSame(
            0,
            app(V2ScheduledGachaPublishWorker::class)->run('worker-b', 5)
        );

        $gachaAfter = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        $version = DB::table('catalog_gacha_versions')
            ->where('public_id', $draft['id'])->firstOrFail();
        $drawState = DB::table('gacha_draw_states')
            ->where('id', $gachaAfter->active_draw_state_id)->firstOrFail();
        self::assertSame('published', $version->status);
        self::assertSame((int) $version->id, (int) $gachaAfter->published_version_id);
        self::assertSame((int) $version->id, (int) $drawState->gacha_version_id);
        self::assertSame(
            (int) $version->published_probability_version_id,
            (int) $drawState->probability_version_id
        );
        self::assertSame('scheduled', $scheduled['publication_status']);
        self::assertDatabaseMissing('catalog_gacha_publish_schedules', [
            'gacha_id' => $gachaAfter->id,
            'status' => 'scheduled',
        ]);
        self::assertDatabaseHas('catalog_gacha_versions', [
            'id' => $gachaBefore->published_version_id,
            'status' => 'published',
        ]);
        Auth::forgetGuards();
        $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->assertJsonPath('data.publication_status', 'scheduled');
        self::assertSame(
            'coming_soon',
            app(V2CatalogReadService::class)
                ->getByPublicId(self::GACHA_ID)['sale_state']
        );
        CarbonImmutable::setTestNow(CarbonImmutable::parse($scheduledFor)->addSecond());
        try {
            self::assertSame(
                'on_sale',
                app(V2CatalogReadService::class)
                    ->getByPublicId(self::GACHA_ID)['sale_state']
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_schedule_requires_publish_permission_fresh_mfa_and_csrf(): void
    {
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($owner);
        $root = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $owner,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-schedule-security-selection'
        )->assertOk()->json('data');
        $payload = [
            'scheduled_for' => CarbonImmutable::parse(
                DB::selectOne('SELECT CURRENT_TIMESTAMP AS value')->value
            )->addHour()->toIso8601String(),
            'expected_revision' => $selected['revision'],
            'expected_gacha_revision' => DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->value('revision'),
        ];

        $operator = $this->createAdminSession(V2AdminRole::Operator);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $operator,
            'POST',
            $root.'/publish-schedule',
            $payload,
            'gacha-schedule-operator'
        )->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

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
            'POST',
            $root.'/publish-schedule',
            $payload,
            'gacha-schedule-stale'
        )->assertForbidden()
            ->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');

        Auth::forgetGuards();
        $this->asAdmin(str_repeat('x', 64))
            ->postJson($root.'/publish-schedule', $payload)
            ->assertUnauthorized();
        Auth::forgetGuards();
        $this->flushHeaders()
            ->asAdmin($owner)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'Idempotency-Key' => 'gacha-schedule-no-csrf',
            ])
            ->postJson($root.'/publish-schedule', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH');
        self::assertDatabaseMissing('catalog_gacha_publish_schedules', [
            'gacha_version_id' => DB::table('catalog_gacha_versions')
                ->where('public_id', $draft['id'])
                ->value('id'),
        ]);
    }

    public function test_scheduled_publish_worker_is_a_compatibility_no_op(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $root = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-worker-retry-selection'
        )->assertOk()->json('data');
        $gachaBefore = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        Auth::forgetGuards();
        $schedule = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish-schedule',
            [
                'scheduled_for' => CarbonImmutable::parse(
                    DB::selectOne('SELECT CURRENT_TIMESTAMP AS value')->value
                )->addHour()->toIso8601String(),
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => $gachaBefore->revision,
            ],
            'gacha-worker-retry-schedule'
        )->assertCreated()->json('data');
        self::assertSame(
            0,
            app(V2ScheduledGachaPublishWorker::class)->run('retry-worker', 1)
        );
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'public_id' => $schedule['id'],
            'status' => 'completed',
            'attempts' => 0,
        ]);
        self::assertDatabaseHas('catalog_gachas', [
            'public_id' => self::GACHA_ID,
            'management_status' => 'scheduled',
        ]);
        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $draft['id'],
            'status' => 'published',
        ]);
    }

    public function test_sales_pause_blocks_new_draw_but_preserves_replay_and_resumes(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Admin);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $versionRoot = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $versionRoot.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-sales-pause-valid-probability-selection'
        )->assertOk()->json('data');
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)
            ->value('revision');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $versionRoot.'/publish',
            [
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => $gachaRevision,
            ],
            'gacha-sales-pause-valid-version-publish'
        )->assertOk();

        $root = '/admin/api/v2/catalog/gachas/'.self::GACHA_ID;
        $gachaBefore = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)
            ->firstOrFail();
        $publishedVersionId = (int) $gachaBefore->published_version_id;
        $activeDrawStateId = (int) $gachaBefore->active_draw_state_id;
        $user = User::query()->create([
            'email_display' => 'sales-pause-draw@example.test',
            'email_normalized' => 'sales-pause-draw@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid sales pause draw password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            2_000,
            now()->addYear(),
            'gacha-sales-pause-draw-points'
        );
        $drawKey = 'gacha-sales-pause-completed-draw';
        $completed = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1,
            $drawKey,
            (string) Str::uuid7()
        );
        DB::table('gacha_draw_states')->where('id', $activeDrawStateId)->update([
            'sold_count' => DB::raw('total_count'),
        ]);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-pause/preflight',
            [
                'expected_gacha_revision' => $gachaBefore->revision,
                'reason_code' => 'operations_review',
            ],
            'gacha-sales-pause-preflight'
        )->assertOk()
            ->assertJsonPath('data.operation', 'pause')
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.sales_state.status', 'selling')
            ->assertJsonCount(0, 'data.blocking_reasons');

        Auth::forgetGuards();
        $paused = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-pause',
            [
                'expected_gacha_revision' => $gachaBefore->revision,
                'reason_code' => 'operations_review',
            ],
            'gacha-sales-pause'
        )->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.reason_code', 'operations_review')
            ->assertJsonPath('data.gacha_revision', $gachaBefore->revision + 1)
            ->assertJsonPath('idempotent_replay', false)
            ->json('data');

        self::assertSame(
            $publishedVersionId,
            (int) DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->value('published_version_id')
        );
        self::assertSame(
            $activeDrawStateId,
            (int) DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->value('active_draw_state_id')
        );
        self::assertSame(
            0,
            app(V2CatalogReadService::class)
                ->getBySlug('fixture-catalog')['remaining_count']
        );

        $walletBeforeRejectedDraw = DB::table('wallets')
            ->where('user_id', $user->id)
            ->firstOrFail();
        $inventoryBeforeRejectedDraw = DB::table('prize_inventories')
            ->where('gacha_draw_state_id', $activeDrawStateId)
            ->orderBy('id')
            ->pluck('won_count', 'id')
            ->all();
        try {
            app(V2DrawService::class)->create(
                $user,
                self::GACHA_ID,
                1,
                'gacha-sales-pause-new-draw',
                (string) Str::uuid7()
            );
            self::fail('A paused Gacha accepted a new Draw.');
        } catch (V2DrawException $exception) {
            self::assertSame('GACHA_SALES_PAUSED', $exception->errorCode);
        }
        self::assertEquals(
            $walletBeforeRejectedDraw,
            DB::table('wallets')->where('user_id', $user->id)->firstOrFail()
        );
        self::assertSame(
            $inventoryBeforeRejectedDraw,
            DB::table('prize_inventories')
                ->where('gacha_draw_state_id', $activeDrawStateId)
                ->orderBy('id')
                ->pluck('won_count', 'id')
                ->all()
        );
        self::assertSame(
            $completed['id'],
            app(V2DrawService::class)->create(
                $user,
                self::GACHA_ID,
                1,
                $drawKey,
                (string) Str::uuid7()
            )['id']
        );

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-pause',
            [
                'expected_gacha_revision' => $gachaBefore->revision,
                'reason_code' => 'operations_review',
            ],
            'gacha-sales-pause'
        )->assertOk()
            ->assertJsonPath('data.gacha_revision', $paused['gacha_revision'])
            ->assertJsonPath('idempotent_replay', true);

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-resume/preflight',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-sales-resume-preflight-sold-out'
        )->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.validation_codes.0', 'GACHA_SOLD_OUT')
            ->assertJsonPath('data.sales_state.status', 'paused');

        DB::table('gacha_draw_states')->where('id', $activeDrawStateId)->update([
            'sold_count' => 1,
        ]);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-resume/preflight',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-sales-resume-preflight-ready'
        )->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.sales_state.status', 'paused');

        Auth::forgetGuards();
        $resumed = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-resume',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-sales-resume'
        )->assertOk()
            ->assertJsonPath('data.status', 'selling')
            ->assertJsonPath(
                'data.gacha_revision',
                $paused['gacha_revision'] + 1
            )
            ->json('data');
        self::assertSame($publishedVersionId, (int) DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->value('published_version_id'));
        self::assertGreaterThan(
            0,
            app(V2CatalogReadService::class)
                ->getBySlug('fixture-catalog')['remaining_count']
        );
        self::assertNotNull(app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1,
            'gacha-sales-resume-new-draw',
            (string) Str::uuid7()
        )['id']);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.sales_paused',
            'target_public_id' => self::GACHA_ID,
        ]);
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.sales_resumed',
            'target_public_id' => self::GACHA_ID,
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => self::GACHA_ID,
            'event_type' => 'catalog.master.sales_paused',
        ]);
        self::assertSame('selling', $resumed['status']);
    }

    public function test_sales_pause_requires_publish_permission_fresh_mfa_and_occ(): void
    {
        $root = '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/sales-pause';
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        $payload = [
            'expected_gacha_revision' => (int) $gacha->revision,
            'reason_code' => 'inventory_review',
        ];
        $operator = $this->createAdminSession(V2AdminRole::Operator);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $operator,
            'POST',
            $root,
            $payload,
            'gacha-sales-pause-operator'
        )->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

        $stale = $this->createAdminSession(V2AdminRole::Owner);
        DB::table('admin_sessions')
            ->where(
                'session_id_hash',
                app(V2SessionPolicy::class)->hashSessionId($stale)
            )
            ->update(['mfa_verified_at' => now()->subMinutes(5)]);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $stale,
            'POST',
            $root,
            $payload,
            'gacha-sales-pause-stale-mfa'
        )->assertForbidden()
            ->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');

        $owner = $this->createAdminSession(V2AdminRole::Owner);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'POST',
            $root,
            $payload,
            'gacha-sales-pause-occ'
        )->assertOk();
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'POST',
            $root,
            [...$payload, 'reason_code' => 'incident_response'],
            'gacha-sales-pause-occ'
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'POST',
            $root,
            $payload,
            'gacha-sales-pause-stale-revision'
        )->assertConflict()->assertJsonPath('code', 'CATALOG_REVISION_CONFLICT');
    }

    public function test_sales_pause_database_guards_reject_bypass_and_history_delete(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $root = '/admin/api/v2/catalog/gachas/'.self::GACHA_ID;
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-pause',
            [
                'expected_gacha_revision' => (int) $gacha->revision,
                'reason_code' => 'incident_response',
            ],
            'gacha-sales-pause-db-guard'
        )->assertOk();

        foreach ([
            fn () => DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->update(['sales_paused' => false]),
            fn () => DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->delete(),
        ] as $bypass) {
            DB::beginTransaction();
            try {
                $bypass();
                DB::rollBack();
                self::fail('The Gacha Sales DB guard accepted a bypass.');
            } catch (QueryException $exception) {
                DB::rollBack();
                self::assertSame('P0001', $exception->errorInfo[0]);
            }
        }
    }

    public function test_immediate_publish_preserves_gacha_sales_pause(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $versionRoot = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $versionRoot.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-paused-immediate-selection'
        )->assertOk()->json('data');
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        Auth::forgetGuards();
        $paused = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/sales-pause',
            [
                'expected_gacha_revision' => (int) $gacha->revision,
                'reason_code' => 'operations_review',
            ],
            'gacha-paused-immediate-pause'
        )->assertOk()->json('data');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $versionRoot.'/publish',
            [
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => $paused['gacha_revision'],
            ],
            'gacha-paused-immediate-publish'
        )->assertOk()
            ->assertJsonPath('data.current_published_version.id', $draft['id']);

        $after = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        self::assertTrue((bool) $after->sales_paused);
        self::assertSame(
            (int) DB::table('catalog_gacha_versions')
                ->where('public_id', $draft['id'])->value('id'),
            (int) $after->published_version_id
        );
        self::assertSame(
            0,
            app(V2CatalogReadService::class)
                ->getBySlug('fixture-catalog')['remaining_count']
        );
    }

    public function test_scheduled_publish_preserves_pause_and_rebased_revision(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $versionRoot = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $versionRoot.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-paused-schedule-selection'
        )->assertOk()->json('data');
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        Auth::forgetGuards();
        $schedule = $this->mutatingRequest(
            $token,
            'POST',
            $versionRoot.'/publish-schedule',
            [
                'scheduled_for' => CarbonImmutable::parse(
                    DB::selectOne('SELECT CURRENT_TIMESTAMP AS value')->value
                )->addHour()->toIso8601String(),
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => (int) $gacha->revision,
            ],
            'gacha-paused-schedule-create'
        )->assertCreated()->json('data');
        Auth::forgetGuards();
        $paused = $this->mutatingRequest(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/'.self::GACHA_ID.'/sales-pause',
            [
                'expected_gacha_revision' => $schedule['gacha_revision'],
                'reason_code' => 'incident_response',
            ],
            'gacha-paused-schedule-pause'
        )->assertOk()
            ->assertJsonPath('data.publish_schedule.status', 'completed')
            ->assertJsonPath(
                'data.publish_schedule.revision',
                $schedule['revision']
            )
            ->json('data');
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'public_id' => $schedule['id'],
            'expected_gacha_revision' => $schedule['gacha_revision'],
            'revision' => $schedule['revision'],
            'status' => 'completed',
        ]);

        self::assertSame(
            0,
            app(V2ScheduledGachaPublishWorker::class)
                ->run('paused-schedule-worker', 1)
        );
        $after = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        self::assertTrue((bool) $after->sales_paused);
        self::assertSame(
            (int) DB::table('catalog_gacha_versions')
                ->where('public_id', $draft['id'])->value('id'),
            (int) $after->published_version_id
        );
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'public_id' => $schedule['id'],
            'status' => 'completed',
        ]);
    }

    public function test_paused_gacha_unpublishes_atomically_and_preserves_draw_replay(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $root = '/admin/api/v2/catalog/gachas/'.self::GACHA_ID;
        $gacha = $this->publishValidGachaVersion($token, 'atomic');
        $publishedVersion = DB::table('catalog_gacha_versions')
            ->where('id', $gacha->published_version_id)->firstOrFail();
        $probability = DB::table('catalog_probability_versions')
            ->where('id', $publishedVersion->published_probability_version_id)
            ->firstOrFail();
        $drawStateId = (int) $gacha->active_draw_state_id;
        $user = User::query()->create([
            'email_display' => 'unpublish-draw@example.test',
            'email_normalized' => 'unpublish-draw@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid unpublish draw password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            2_000,
            now()->addYear(),
            'gacha-unpublish-draw-points'
        );
        $drawKey = 'gacha-unpublish-completed-draw';
        $completed = app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            1,
            $drawKey,
            (string) Str::uuid7()
        );
        $walletBefore = DB::table('wallets')->where('user_id', $user->id)
            ->firstOrFail();
        $pointOperationsBefore = DB::table('point_operations')
            ->where('user_id', $user->id)->count();
        $pointLotsBefore = DB::table('point_lots')
            ->where('user_id', $user->id)->count();
        $pointLedgersBefore = DB::table('point_ledger_entries')
            ->where('user_id', $user->id)->count();
        $inventoryBefore = DB::table('prize_inventories')
            ->where('gacha_draw_state_id', $drawStateId)
            ->orderBy('id')->get()->map(fn (object $row): array => (array) $row)
            ->all();
        $drawResultsBefore = DB::table('draw_results')
            ->where('draw_request_id', DB::table('draw_requests')
                ->where('public_id', $completed['id'])->value('id'))
            ->count();

        Auth::forgetGuards();
        $paused = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-pause',
            [
                'expected_gacha_revision' => (int) $gacha->revision,
                'reason_code' => 'operations_review',
            ],
            'gacha-unpublish-pause'
        )->assertOk()->json('data');

        Auth::forgetGuards();
        $this->asAdmin($token)->getJson($root.'/unpublish-state')
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.sales_status', 'paused')
            ->assertJsonPath(
                'data.current_published_version.id',
                $publishedVersion->public_id
            );
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/unpublish/preflight',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-unpublish-preflight'
        )->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->assertJsonPath('data.validation_codes.0', 'GACHA_UNPUBLISH_READY')
            ->assertJsonCount(0, 'data.blocking_reasons');

        Auth::forgetGuards();
        $unpublished = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/unpublish',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-unpublish-mutation'
        )->assertOk()
            ->assertJsonPath('data.status', 'unpublished')
            ->assertJsonPath('data.sales_status', 'paused')
            ->assertJsonPath('data.current_published_version', null)
            ->assertJsonPath('data.selected_probability', null)
            ->assertJsonPath('data.draw_state', null)
            ->assertJsonPath(
                'data.gacha_revision',
                $paused['gacha_revision'] + 1
            )
            ->assertJsonPath('idempotent_replay', false)
            ->json('data');
        self::assertNotNull($unpublished['deactivated_at']);

        $after = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        self::assertNull($after->published_version_id);
        self::assertNull($after->active_draw_state_id);
        self::assertTrue((bool) $after->sales_paused);
        self::assertEquals(
            $publishedVersion,
            DB::table('catalog_gacha_versions')
                ->where('id', $publishedVersion->id)->firstOrFail()
        );
        self::assertEquals(
            $probability,
            DB::table('catalog_probability_versions')
                ->where('id', $probability->id)->firstOrFail()
        );
        self::assertDatabaseHas('gacha_draw_states', ['id' => $drawStateId]);
        self::assertSame(
            $inventoryBefore,
            DB::table('prize_inventories')
                ->where('gacha_draw_state_id', $drawStateId)
                ->orderBy('id')->get()->map(
                    fn (object $row): array => (array) $row
                )->all()
        );
        self::assertEquals(
            $walletBefore,
            DB::table('wallets')->where('user_id', $user->id)->firstOrFail()
        );
        self::assertSame(
            $pointOperationsBefore,
            DB::table('point_operations')->where('user_id', $user->id)->count()
        );
        self::assertSame(
            $pointLotsBefore,
            DB::table('point_lots')->where('user_id', $user->id)->count()
        );
        self::assertSame(
            $pointLedgersBefore,
            DB::table('point_ledger_entries')->where('user_id', $user->id)->count()
        );
        self::assertSame(
            $drawResultsBefore,
            DB::table('draw_results')
                ->where('draw_request_id', DB::table('draw_requests')
                    ->where('public_id', $completed['id'])->value('id'))
                ->count()
        );

        $this->getJson('/api/v2/gachas/by-slug/fixture-catalog')
            ->assertNotFound();
        try {
            app(V2DrawService::class)->create(
                $user,
                self::GACHA_ID,
                1,
                'gacha-unpublish-new-draw',
                (string) Str::uuid7()
            );
            self::fail('An unpublished Gacha accepted a new Draw.');
        } catch (V2DrawException $exception) {
            self::assertSame('GACHA_NOT_DRAWABLE', $exception->errorCode);
        }
        self::assertSame(
            $completed['id'],
            app(V2DrawService::class)->create(
                $user,
                self::GACHA_ID,
                1,
                $drawKey,
                (string) Str::uuid7()
            )['id']
        );

        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/unpublish',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-unpublish-mutation'
        )->assertOk()
            ->assertJsonPath('data.gacha_revision', $unpublished['gacha_revision'])
            ->assertJsonPath('idempotent_replay', true);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-resume',
            ['expected_gacha_revision' => $unpublished['gacha_revision']],
            'gacha-unpublish-resume-rejected'
        )->assertUnprocessable()
            ->assertJsonPath('code', 'CATALOG_GACHA_SALES_RESUME_INVALID');
        self::assertDatabaseHas('audit_logs', [
            'action_code' => 'catalog.master.unpublished',
            'target_public_id' => self::GACHA_ID,
        ]);
        self::assertDatabaseHas('outbox_messages', [
            'aggregate_public_id' => self::GACHA_ID,
            'event_type' => 'catalog.master.unpublished',
        ]);
    }

    public function test_unpublish_requires_pause_permission_fresh_mfa_occ_and_no_schedule(): void
    {
        $root = '/admin/api/v2/catalog/gachas/'.self::GACHA_ID;
        $owner = $this->createAdminSession(V2AdminRole::Owner);
        $gacha = $this->publishValidGachaVersion($owner, 'security');
        $operator = $this->createAdminSession(V2AdminRole::Operator);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $operator,
            'POST',
            $root.'/unpublish/preflight',
            ['expected_gacha_revision' => (int) $gacha->revision],
            'gacha-unpublish-operator'
        )->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');

        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'POST',
            $root.'/unpublish/preflight',
            ['expected_gacha_revision' => (int) $gacha->revision],
            'gacha-unpublish-not-paused'
        )->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath(
                'data.validation_codes.0',
                'GACHA_SALES_PAUSE_REQUIRED'
            );

        DB::table('admin_sessions')->update([
            'mfa_verified_at' => now()->subMinutes(5),
        ]);
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'POST',
            $root.'/unpublish',
            ['expected_gacha_revision' => (int) $gacha->revision],
            'gacha-unpublish-stale-mfa'
        )->assertForbidden()
            ->assertJsonPath('code', 'FRESH_AUTHENTICATION_REQUIRED');
        DB::table('admin_sessions')->update(['mfa_verified_at' => now()]);

        Auth::forgetGuards();
        $paused = $this->mutatingRequest(
            $owner,
            'POST',
            $root.'/sales-pause',
            [
                'expected_gacha_revision' => (int) $gacha->revision,
                'reason_code' => 'inventory_review',
            ],
            'gacha-unpublish-permission-pause'
        )->assertOk()->json('data');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'POST',
            $root.'/unpublish',
            ['expected_gacha_revision' => (int) $gacha->revision],
            'gacha-unpublish-stale-revision'
        )->assertConflict()->assertJsonPath('code', 'CATALOG_REVISION_CONFLICT');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'POST',
            $root.'/unpublish',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-unpublish-key-conflict'
        )->assertOk();
        Auth::forgetGuards();
        $this->mutatingRequest(
            $owner,
            'POST',
            $root.'/unpublish',
            ['expected_gacha_revision' => $paused['gacha_revision'] + 1],
            'gacha-unpublish-key-conflict'
        )->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_unpublish_database_guards_reject_partial_deactivation_and_resume(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $root = '/admin/api/v2/catalog/gachas/'.self::GACHA_ID;
        $gacha = $this->publishValidGachaVersion($token, 'db-guard');

        DB::beginTransaction();
        try {
            DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                'published_version_id' => null,
                'revision' => (int) $gacha->revision + 1,
            ]);
            DB::statement(
                'SET CONSTRAINTS catalog_gachas_validate_activation IMMEDIATE'
            );
            DB::rollBack();
            self::fail('A partial Public deactivation must be rejected.');
        } catch (QueryException $exception) {
            DB::rollBack();
            self::assertSame('P0001', $exception->errorInfo[0]);
        }

        DB::beginTransaction();
        try {
            DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                'published_version_id' => null,
                'active_draw_state_id' => null,
                'revision' => (int) $gacha->revision + 1,
            ]);
            DB::rollBack();
            self::fail('Unpaused direct deactivation must be rejected.');
        } catch (QueryException $exception) {
            DB::rollBack();
            self::assertSame('P0001', $exception->errorInfo[0]);
        }

        Auth::forgetGuards();
        $paused = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-pause',
            [
                'expected_gacha_revision' => (int) $gacha->revision,
                'reason_code' => 'incident_response',
            ],
            'gacha-unpublish-db-pause'
        )->assertOk()->json('data');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/unpublish',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-unpublish-db-mutation'
        )->assertOk();
        $unpublished = $this->asAdmin($token)
            ->getJson('/admin/api/v2/catalog/gachas/'.self::GACHA_ID)
            ->assertOk()
            ->json('data');

        DB::beginTransaction();
        try {
            DB::table('catalog_gachas')
                ->where('public_id', self::GACHA_ID)
                ->update([
                    'sales_paused' => false,
                    'sales_resumed_at' => DB::raw('CURRENT_TIMESTAMP'),
                    'revision' => $unpublished['revision'] + 1,
                ]);
            DB::rollBack();
            self::fail('An unpublished Gacha must not resume by direct SQL.');
        } catch (QueryException $exception) {
            DB::rollBack();
            self::assertSame('P0001', $exception->errorInfo[0]);
        }
    }

    public function test_unpublish_allows_completed_schedule_history_to_remain(): void
    {
        $token = $this->createAdminSession(V2AdminRole::Owner);
        $root = '/admin/api/v2/catalog/gachas/'.self::GACHA_ID;
        $this->publishValidGachaVersion($token, 'schedule-current');
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $versionRoot = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $versionRoot.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-unpublish-schedule-selection'
        )->assertOk()->json('data');
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
        Auth::forgetGuards();
        $schedule = $this->mutatingRequest(
            $token,
            'POST',
            $versionRoot.'/publish-schedule',
            [
                'scheduled_for' => CarbonImmutable::parse(
                    DB::selectOne('SELECT CURRENT_TIMESTAMP AS value')->value
                )->addHour()->toIso8601String(),
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => (int) $gacha->revision,
            ],
            'gacha-unpublish-schedule-create'
        )->assertCreated()->json('data');
        Auth::forgetGuards();
        $paused = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/sales-pause',
            [
                'expected_gacha_revision' => $schedule['gacha_revision'],
                'reason_code' => 'operations_review',
            ],
            'gacha-unpublish-schedule-pause'
        )->assertOk()->json('data');
        Auth::forgetGuards();
        $preflight = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/unpublish/preflight',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-unpublish-schedule-preflight'
        )->assertOk()
            ->assertJsonPath('data.allowed', true)
            ->json('data');
        self::assertNotContains(
            'GACHA_FUTURE_PUBLISH_SCHEDULE_EXISTS',
            $preflight['validation_codes']
        );
        Auth::forgetGuards();
        $unpublished = $this->mutatingRequest(
            $token,
            'POST',
            $root.'/unpublish',
            ['expected_gacha_revision' => $paused['gacha_revision']],
            'gacha-unpublish-completed-schedule'
        )->assertOk()->json('data');
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'public_id' => $schedule['id'],
            'status' => 'completed',
        ]);
        self::assertDatabaseHas('catalog_gachas', [
            'public_id' => self::GACHA_ID,
            'management_status' => 'unpublished',
            'published_version_id' => null,
            'active_draw_state_id' => null,
        ]);
    }

    private function publishValidGachaVersion(
        string $token,
        string $keySuffix
    ): object {
        [$draft, $probability] = $this->createDraftWithPublishedProbability($token);
        $root = $this->versionRoot($draft['id']);
        Auth::forgetGuards();
        $selected = $this->mutatingRequest(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $draft['revision'],
                'probability_version_id' => $probability['id'],
            ],
            'gacha-unpublish-selection-'.$keySuffix
        )->assertOk()->json('data');
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->value('revision');
        Auth::forgetGuards();
        $this->mutatingRequest(
            $token,
            'POST',
            $root.'/publish',
            [
                'expected_revision' => $selected['revision'],
                'expected_gacha_revision' => $gachaRevision,
            ],
            'gacha-unpublish-publish-'.$keySuffix
        )->assertOk();

        return DB::table('catalog_gachas')
            ->where('public_id', self::GACHA_ID)->firstOrFail();
    }

    private function forceScheduleDue(string $schedulePublicId): void
    {
        DB::statement(
            'ALTER TABLE catalog_gacha_publish_schedules '.
            'DISABLE TRIGGER catalog_gacha_publish_schedules_guard'
        );
        try {
            DB::table('catalog_gacha_publish_schedules')
                ->where('public_id', $schedulePublicId)
                ->update([
                    'scheduled_for' => DB::raw(
                        "CURRENT_TIMESTAMP - INTERVAL '1 minute'"
                    ),
                    'next_attempt_at' => DB::raw(
                        "CURRENT_TIMESTAMP - INTERVAL '1 minute'"
                    ),
                ]);
        } finally {
            DB::statement(
                'ALTER TABLE catalog_gacha_publish_schedules '.
                'ENABLE TRIGGER catalog_gacha_publish_schedules_guard'
            );
        }
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
