<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\Catalog\Services\V2ScheduledGachaPublishWorker;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class GachaLifecyclePresentationTest extends TestCase
{
    private const CATEGORY_ID = '0198a001-0000-7000-8000-000000000001';
    private const TAG_ID = '0198a001-0000-7000-8000-000000000002';
    private const GACHA_ASSET_ID = '0198a001-0000-7000-8000-000000000005';
    private const PRIZE_ASSET_ID = '0198a001-0000-7000-8000-000000000007';

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

    public function test_core_update_commits_initial_publish_with_state_only_and_other_changes(): void
    {
        $token = $this->createAdminSession();
        foreach ([false, true] as $changesPresentation) {
            $slug = $changesPresentation
                ? 'lifecycle-core-publish-with-presentation'
                : 'lifecycle-core-publish-state-only';
            $prepared = $this->prepareGacha(
                $token,
                $slug,
                $this->databaseNow()->subMinute()
            );
            $gachaBefore = DB::table('catalog_gachas')
                ->where('public_id', $prepared['gacha_id'])->firstOrFail();
            $input = $prepared['input'];
            if ($changesPresentation) {
                $input['title'] = '状態と同時に更新したタイトル';
            }

            $response = $this->updateGacha(
                $token,
                $prepared['gacha_id'],
                $input,
                'published',
                $slug.'-update'
            )->assertOk()
                ->assertJsonPath('data.publication_status', 'published');
            if ($changesPresentation) {
                $response->assertJsonPath(
                    'data.current_version.title',
                    '状態と同時に更新したタイトル'
                );
            }
            $this->assertActivationConstraintIsValid();

            $gachaAfter = DB::table('catalog_gachas')
                ->where('public_id', $prepared['gacha_id'])->firstOrFail();
            self::assertSame(
                (int) $gachaBefore->revision + 1,
                (int) $gachaAfter->revision
            );
            self::assertNotNull($gachaAfter->published_version_id);
            self::assertNotNull($gachaAfter->active_draw_state_id);
        }
    }

    public function test_core_update_without_management_transition_remains_valid(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-core-presentation-only',
            $this->databaseNow()->subMinute()
        );
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        $version = DB::table('catalog_gacha_versions')
            ->where('public_id', $prepared['version_id'])->firstOrFail();

        $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.$prepared['gacha_id'],
            [
                ...$prepared['input'],
                'title' => '状態変更なしの更新タイトル',
                'expected_revision' => (int) $gacha->revision,
                'expected_version_revision' => (int) $version->revision,
            ],
            'lifecycle-core-presentation-only-update'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'draft')
            ->assertJsonPath(
                'data.current_version.title',
                '状態変更なしの更新タイトル'
            );
        $this->assertActivationConstraintIsValid();

        self::assertSame(
            (int) $gacha->revision + 1,
            (int) DB::table('catalog_gachas')
                ->where('public_id', $prepared['gacha_id'])
                ->value('revision')
        );
    }

    public function test_draft_can_unpublish_restore_and_publish(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-draft-unpublished-restore',
            $this->databaseNow()->subMinute()
        );
        $gachaId = (int) DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->value('id');
        $versionCount = DB::table('catalog_gacha_versions')
            ->where('gacha_id', $gachaId)->count();
        $historyCounts = collect([
            'gacha_draw_states',
            'draw_requests',
            'draw_results',
            'user_prizes',
            'user_prize_status_histories',
        ])->mapWithKeys(static fn (string $table): array => [
            $table => DB::table($table)->count(),
        ])->all();

        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'unpublished',
            'lifecycle-draft-unpublish'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'unpublished')
            ->assertJsonPath('data.current_version.id', $prepared['version_id']);
        $unpublished = DB::table('catalog_gachas')
            ->where('id', $gachaId)->firstOrFail();
        self::assertNull($unpublished->first_published_at);
        self::assertNull($unpublished->published_version_id);
        self::assertNull($unpublished->active_draw_state_id);

        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'published',
            'lifecycle-draft-unpublished-direct-publish'
        )->assertUnprocessable()
            ->assertJsonPath(
                'code',
                'CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID'
            );

        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'draft',
            'lifecycle-draft-unpublished-restore'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'draft')
            ->assertJsonPath('data.current_version.id', $prepared['version_id']);
        self::assertSame(
            'draft',
            DB::table('catalog_gachas')->where('id', $gachaId)
                ->value('management_status')
        );
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'published',
            'lifecycle-restored-initial-publish'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'published');
        $this->assertActivationConstraintIsValid();

        self::assertSame(
            $versionCount,
            DB::table('catalog_gacha_versions')->where('gacha_id', $gachaId)->count()
        );
        self::assertSame(
            1,
            DB::table('gacha_draw_states')->where('gacha_id', $gachaId)->count()
        );
        foreach (array_diff(array_keys($historyCounts), ['gacha_draw_states']) as $table) {
            self::assertSame($historyCounts[$table], DB::table($table)->count());
        }
    }

    public function test_published_can_restore_through_draft_without_rewriting_history(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-published-draft-restore',
            $this->databaseNow()->subMinute()
        );
        $this->publish($token, $prepared, 'lifecycle-restore-publish');
        $this->assertActivationConstraintIsValid();
        $published = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        $firstPublishedAt = (string) $published->first_published_at;
        $oldVersionId = (int) $published->published_version_id;
        $oldDrawStateId = (int) $published->active_draw_state_id;
        $oldInventory = DB::table('prize_inventories')
            ->where('gacha_draw_state_id', $oldDrawStateId)
            ->orderBy('id')
            ->get()->map(fn (object $row): array => (array) $row)->all();
        $historyCounts = collect([
            'draw_requests',
            'draw_results',
            'user_prizes',
            'user_prize_status_histories',
        ])->mapWithKeys(static fn (string $table): array => [
            $table => DB::table($table)->count(),
        ])->all();

        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'unpublished',
            'lifecycle-restore-unpublish'
        )->assertOk()->assertJsonPath('data.publication_status', 'unpublished');
        $this->assertActivationConstraintIsValid();
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'published',
            'lifecycle-restore-direct-publish'
        )->assertUnprocessable()
            ->assertJsonPath(
                'code',
                'CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID'
            );
        $restored = $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'draft',
            'lifecycle-restore-draft'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'draft')
            ->json('data');
        self::assertSame(2, $restored['version_count']);
        self::assertNotSame(
            $prepared['version_id'],
            $restored['current_version']['id']
        );
        self::assertSame('draft', $restored['current_version']['status']);
        $immutableInput = $prepared['input'];
        $immutableInput['price_points']++;
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $immutableInput,
            'draft',
            'lifecycle-restore-immutable'
        )->assertConflict()
            ->assertJsonPath(
                'code',
                'CATALOG_GACHA_POST_PUBLISH_FIELD_IMMUTABLE'
            );
        $migration = require database_path(
            'migrations-v2/'.
            '2026_09_15_000061_allow_v2_gacha_unpublished_draft_restore.php'
        );
        try {
            $migration->down();
            self::fail('MIG-072 rollback must fail after a Gacha restoration.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Cannot roll back MIG-072',
                $exception->getMessage()
            );
        }

        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'published',
            'lifecycle-restore-republish'
        )->assertOk()->assertJsonPath('data.publication_status', 'published');
        $this->assertActivationConstraintIsValid();

        $republished = DB::table('catalog_gachas')
            ->where('id', $published->id)->firstOrFail();
        self::assertSame($firstPublishedAt, (string) $republished->first_published_at);
        self::assertNotSame($oldVersionId, (int) $republished->published_version_id);
        self::assertNotSame($oldDrawStateId, (int) $republished->active_draw_state_id);
        self::assertFalse((bool) $republished->sales_paused);
        self::assertDatabaseHas('gacha_draw_states', [
            'id' => $oldDrawStateId,
            'status' => 'closed',
            'close_reason' => 'superseded',
        ]);
        self::assertSame(
            $oldInventory,
            DB::table('prize_inventories')
                ->where('gacha_draw_state_id', $oldDrawStateId)
                ->orderBy('id')
                ->get()->map(fn (object $row): array => (array) $row)->all()
        );
        foreach ($historyCounts as $table => $count) {
            self::assertSame($count, DB::table($table)->count());
        }
    }

    public function test_core_update_commits_terminal_unpublish_from_published_and_paused(): void
    {
        $token = $this->createAdminSession();
        foreach (['published', 'sales_paused'] as $sourceStatus) {
            $slug = 'lifecycle-unpublish-'.$sourceStatus;
            $prepared = $this->prepareGacha(
                $token,
                $slug,
                $this->databaseNow()->subMinute()
            );
            $this->publish($token, $prepared, $slug.'-publish');
            $this->assertActivationConstraintIsValid();
            if ($sourceStatus === 'sales_paused') {
                $this->updateGacha(
                    $token,
                    $prepared['gacha_id'],
                    $prepared['input'],
                    'sales_paused',
                    $slug.'-pause'
                )->assertOk();
            }

            $gachaBefore = DB::table('catalog_gachas')
                ->where('public_id', $prepared['gacha_id'])->firstOrFail();
            $drawStateBefore = DB::table('gacha_draw_states')
                ->where('id', $gachaBefore->active_draw_state_id)->firstOrFail();
            $inventoryBefore = DB::table('prize_inventories')
                ->where('gacha_draw_state_id', $drawStateBefore->id)
                ->orderBy('id')
                ->pluck('available_quantity', 'id')
                ->all();
            $historyCountsBefore = collect([
                'draw_requests',
                'draw_results',
                'user_prizes',
                'user_prize_status_histories',
            ])->mapWithKeys(
                static fn (string $table): array => [
                    $table => DB::table($table)->count(),
                ]
            )->all();
            $input = $prepared['input'];
            if ($sourceStatus === 'sales_paused') {
                $input['title'] = '販売停止から非公開と同時に更新したタイトル';
            }

            $response = $this->updateGacha(
                $token,
                $prepared['gacha_id'],
                $input,
                'unpublished',
                $slug.'-unpublish'
            );
            self::assertSame(200, $response->status(), $response->getContent());
            $response->assertJsonPath('data.publication_status', 'unpublished')
                ->assertJsonPath('data.published_version', null);
            if ($sourceStatus === 'sales_paused') {
                $response->assertJsonPath(
                    'data.current_version.title',
                    '販売停止から非公開と同時に更新したタイトル'
                );
            }
            $this->assertActivationConstraintIsValid();

            $gachaAfter = DB::table('catalog_gachas')
                ->where('id', $gachaBefore->id)->firstOrFail();
            self::assertSame(
                (int) $gachaBefore->revision
                    + 2,
                (int) $gachaAfter->revision
            );
            self::assertSame('unpublished', $gachaAfter->management_status);
            self::assertSame(
                $sourceStatus === 'sales_paused',
                (bool) $gachaAfter->sales_paused
            );
            self::assertNull($gachaAfter->published_version_id);
            self::assertNull($gachaAfter->active_draw_state_id);
            self::assertSame(
                (int) $drawStateBefore->sold_count,
                (int) DB::table('gacha_draw_states')
                    ->where('id', $drawStateBefore->id)
                    ->value('sold_count')
            );
            self::assertSame(
                'closed',
                DB::table('gacha_draw_states')
                    ->where('id', $drawStateBefore->id)
                    ->value('status')
            );
            self::assertSame(
                $inventoryBefore,
                DB::table('prize_inventories')
                    ->where('gacha_draw_state_id', $drawStateBefore->id)
                    ->orderBy('id')
                    ->pluck('available_quantity', 'id')
                    ->all()
            );
            self::assertSame(
                $historyCountsBefore,
                collect(array_keys($historyCountsBefore))->mapWithKeys(
                    static fn (string $table): array => [
                        $table => DB::table($table)->count(),
                    ]
                )->all()
            );

            $this->updateGacha(
                $token,
                $prepared['gacha_id'],
                $input,
                'published',
                $slug.'-terminal-republish'
            )->assertUnprocessable()
                ->assertJsonPath(
                    'code',
                    'CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID'
                );
            $this->assertActivationConstraintIsValid();
        }
    }

    public function test_terminal_unpublish_accepts_legacy_snapshot_and_sold_out_draw_state(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-legacy-terminal-unpublish',
            $this->databaseNow()->subMinute()
        );
        $this->publish($token, $prepared, 'lifecycle-legacy-terminal-publish');
        $this->assertActivationConstraintIsValid();
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        $version = DB::table('catalog_gacha_versions')
            ->where('id', $gacha->published_version_id)->firstOrFail();
        $drawState = DB::table('gacha_draw_states')
            ->where('id', $gacha->active_draw_state_id)->firstOrFail();
        $soldCount = (int) DB::table('prize_inventories')
            ->where('gacha_draw_state_id', $drawState->id)
            ->sum('total_quantity');

        DB::statement('SET LOCAL session_replication_role = replica');
        try {
            DB::table('catalog_probability_versions')
                ->where('id', $version->published_probability_version_id)
                ->update(['snapshot_sha256' => str_repeat('0', 64)]);
            DB::table('gacha_draw_states')->where('id', $drawState->id)->update([
                'status' => 'sold_out',
                'sold_count' => $soldCount,
                'sold_out_at' => DB::raw('CURRENT_TIMESTAMP'),
            ]);
            DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                'sold_count' => $soldCount,
            ]);
            DB::table('prize_inventories')
                ->where('gacha_draw_state_id', $drawState->id)->update([
                    'available_quantity' => 0,
                    'awarded_count' => DB::raw(
                        'total_quantity - withdrawn_quantity'
                    ),
                ]);
        } finally {
            DB::statement('SET LOCAL session_replication_role = origin');
        }
        $inventoryBefore = DB::table('prize_inventories')
            ->where('gacha_draw_state_id', $drawState->id)
            ->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        $response = $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'unpublished',
            'lifecycle-legacy-terminal-unpublish'
        );
        self::assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('data.publication_status', 'unpublished');

        self::assertSame(
            'sold_out',
            DB::table('gacha_draw_states')->where('id', $drawState->id)->value('status')
        );
        self::assertSame(
            $soldCount,
            (int) DB::table('gacha_draw_states')
                ->where('id', $drawState->id)->value('sold_count')
        );
        self::assertSame(
            $inventoryBefore,
            DB::table('prize_inventories')
                ->where('gacha_draw_state_id', $drawState->id)
                ->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()
        );
        $this->assertActivationConstraintIsValid();
    }

    public function test_terminal_unpublish_returns_stable_problem_for_structural_mismatch(): void
    {
        $method = new \ReflectionMethod(
            V2CatalogMasterMutationService::class,
            'gachaUnpublishException'
        );
        $method->setAccessible(true);
        $exception = $method->invoke(app(V2CatalogMasterMutationService::class));

        self::assertSame('CATALOG_GACHA_UNPUBLISH_INVALID', $exception->errorCode);
        self::assertSame(422, $exception->status);
    }

    public function test_initial_publish_uses_one_draw_state_and_current_overlay(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-immediate',
            $this->databaseNow()->subMinute()
        );
        $published = $this->publish($token, $prepared, 'lifecycle-immediate-publish');
        $this->assertActivationConstraintIsValid();
        $drawStateId = (int) DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])
            ->value('active_draw_state_id');
        $versionSnapshot = DB::table('catalog_gacha_versions')
            ->where('public_id', $prepared['version_id'])->firstOrFail();
        $prizeSnapshot = DB::table('catalog_gacha_version_prizes')
            ->where('gacha_version_id', $versionSnapshot->id)
            ->where('prize_id', DB::table('catalog_prizes')
                ->where('public_id', $prepared['prize_id'])->value('id'))
            ->firstOrFail();
        $probability = DB::table('catalog_probability_versions')
            ->where('gacha_version_id', $versionSnapshot->id)
            ->firstOrFail();
        self::assertSame('published', $probability->status);
        self::assertSame(
            (int) $probability->id,
            (int) $versionSnapshot->published_probability_version_id
        );
        self::assertSame(
            (int) $probability->id,
            (int) DB::table('gacha_draw_states')
                ->where('id', $drawStateId)
                ->value('probability_version_id')
        );
        self::assertDatabaseHas('catalog_probability_stages', [
            'probability_version_id' => $probability->id,
            'code' => '__canonical_inventory_v1',
        ]);
        $stageId = DB::table('catalog_probability_stages')
            ->where('probability_version_id', $probability->id)
            ->value('id');
        self::assertSame(
            1000000,
            (int) DB::table('catalog_probability_entries')
                ->where('probability_stage_id', $stageId)
                ->sum('probability_ppm')
        );
        self::assertDatabaseHas('catalog_minimum_guarantees', [
            'probability_stage_id' => $stageId,
            'result_type' => 'point_back',
            'point_amount' => 0,
            'probability_ppm' => 0,
        ]);

        $input = $prepared['input'];
        $input['title'] = '現在表示タイトル';
        $input['description'] = '現在表示説明';
        $input['publish_end_at'] = $this->databaseNow()->addYear()->toIso8601String();
        $paused = $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $input,
            'sales_paused',
            'lifecycle-pause'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'sales_paused')
            ->json('data');

        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertOk()
            ->assertJsonPath('data.title', '現在表示タイトル')
            ->assertJsonPath('data.sale_state', 'paused')
            ->assertJsonPath('data.remaining_count', 10);
        self::assertSame($drawStateId, (int) DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])
            ->value('active_draw_state_id'));
        self::assertSame(1, DB::table('gacha_draw_states')
            ->where('gacha_id', DB::table('catalog_gachas')
                ->where('public_id', $prepared['gacha_id'])->value('id'))
            ->count());
        self::assertSame($versionSnapshot->title, DB::table('catalog_gacha_versions')
            ->where('id', $versionSnapshot->id)->value('title'));
        self::assertSame($prizeSnapshot->display_name, DB::table('catalog_gacha_version_prizes')
            ->where('id', $prizeSnapshot->id)->value('display_name'));

        $this->updatePublishedPrize($token, $prepared, '現在表示景品');
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertOk()
            ->assertJsonPath('data.ranks.0.prizes.0.name', '現在表示景品');
        self::assertSame($prizeSnapshot->display_name, DB::table('catalog_gacha_version_prizes')
            ->where('id', $prizeSnapshot->id)->value('display_name'));

        $immutableInput = $input;
        $immutableInput['price_points'] = 101;
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $immutableInput,
            'sales_paused',
            'lifecycle-post-publish-economic-change'
        )->assertConflict()
            ->assertJsonPath(
                'code',
                'CATALOG_GACHA_POST_PUBLISH_FIELD_IMMUTABLE'
            );
        $gachaAfterId = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->value('id');
        self::assertSame(1, DB::table('gacha_draw_states')
            ->where('gacha_id', $gachaAfterId)
            ->count());
        self::assertSame(100, (int) DB::table('catalog_gacha_versions')
            ->where('id', $versionSnapshot->id)->value('price_points'));

        $resumed = $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $input,
            'published',
            'lifecycle-resume'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'published')
            ->json('data');
        self::assertSame($drawStateId, (int) DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])
            ->value('active_draw_state_id'));
        self::assertSame($published['draw_state']['sold_count'], 0);

        $unpublished = $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $input,
            'unpublished',
            'lifecycle-unpublish'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'unpublished')
            ->assertJsonPath('data.current_version.title', '現在表示タイトル')
            ->assertJsonPath('data.published_version', null)
            ->json('data');
        self::assertDatabaseHas('gacha_draw_states', [
            'id' => $drawStateId,
            'status' => 'closed',
            'close_reason' => 'superseded',
        ]);
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertNotFound();
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $input,
            'published',
            'lifecycle-terminal-reopen'
        )->assertUnprocessable();
        $unpublishedInput = $input;
        $unpublishedInput['title'] = '非公開後の現在表示タイトル';
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $unpublishedInput,
            'unpublished',
            'lifecycle-terminal-presentation'
        )->assertOk()
            ->assertJsonPath(
                'data.current_version.title',
                '非公開後の現在表示タイトル'
            );
        self::assertNotNull($paused['first_published_at']);
        self::assertNotNull($resumed['first_published_at']);
        self::assertNotNull($unpublished['first_published_at']);
    }

    public function test_initial_publish_rolls_back_activation_on_outbox_failure(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-rollback',
            $this->databaseNow()->subMinute()
        );
        $gachaBefore = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        $stateCountBefore = DB::table('gacha_draw_states')->count();
        $inventoryCountBefore = DB::table('prize_inventories')->count();
        $idempotencyCountBefore = DB::table('idempotency_records')->count();
        $probabilityCountBefore = DB::table('catalog_probability_versions')->count();
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_test_reject_initial_gacha_publish_outbox()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event_type = 'catalog.master.immediately_published' THEN
                    RAISE EXCEPTION 'synthetic initial publish outbox failure';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER v2_test_reject_initial_gacha_publish_outbox '.
            'BEFORE INSERT ON outbox_messages FOR EACH ROW '.
            'EXECUTE FUNCTION v2_test_reject_initial_gacha_publish_outbox()'
        );

        try {
            $this->withoutExceptionHandling();
            $this->mutate(
                $token,
                'POST',
                $this->versionRoot($prepared).'/publish',
                [
                    'expected_revision' => $prepared['version_revision'],
                    'expected_gacha_revision' => $gachaBefore->revision,
                ],
                'lifecycle-initial-publish-outbox-failure'
            );
            self::fail('Initial Publish must roll back on Outbox failure.');
        } catch (QueryException $exception) {
            self::assertStringContainsString(
                'synthetic initial publish outbox failure',
                $exception->getMessage()
            );
        } finally {
            $this->withExceptionHandling();
            DB::statement(
                'DROP TRIGGER IF EXISTS '.
                'v2_test_reject_initial_gacha_publish_outbox '.
                'ON outbox_messages'
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS '.
                'v2_test_reject_initial_gacha_publish_outbox()'
            );
        }

        $gachaAfter = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        self::assertNull($gachaAfter->published_version_id);
        self::assertNull($gachaAfter->active_draw_state_id);
        self::assertNull($gachaAfter->first_published_at);
        self::assertNull($gachaAfter->current_title);
        self::assertSame($gachaBefore->revision, $gachaAfter->revision);
        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $prepared['version_id'],
            'status' => 'draft',
            'published_at' => null,
            'revision' => $prepared['version_revision'],
        ]);
        self::assertSame($stateCountBefore, DB::table('gacha_draw_states')->count());
        self::assertSame(
            $inventoryCountBefore,
            DB::table('prize_inventories')->count()
        );
        self::assertSame(
            $idempotencyCountBefore,
            DB::table('idempotency_records')->count()
        );
        self::assertSame(
            $probabilityCountBefore,
            DB::table('catalog_probability_versions')->count()
        );
    }

    public function test_initial_publish_returns_stable_prize_and_internal_problem_codes(): void
    {
        $token = $this->createAdminSession();
        $empty = $this->prepareGacha(
            $token,
            'lifecycle-empty-inventory',
            $this->databaseNow()->subMinute()
        );
        $emptyVersionId = DB::table('catalog_gacha_versions')
            ->where('public_id', $empty['version_id'])->value('id');
        DB::table('catalog_gacha_version_prizes')
            ->where('gacha_version_id', $emptyVersionId)
            ->update(['initial_inventory' => 0]);
        $emptyGachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', $empty['gacha_id'])->value('revision');
        $this->mutate(
            $token,
            'POST',
            $this->versionRoot($empty).'/publish',
            [
                'expected_revision' => $empty['version_revision'],
                'expected_gacha_revision' => $emptyGachaRevision,
            ],
            'lifecycle-empty-inventory-publish'
        )->assertUnprocessable()
            ->assertJsonPath(
                'code',
                'CATALOG_GACHA_PUBLISH_PRIZE_INSUFFICIENT'
            );

        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-internal-failure',
            $this->databaseNow()->subMinute()
        );
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_test_reject_canonical_probability_insert()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'synthetic canonical Probability failure';
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER v2_test_reject_canonical_probability_insert '.
            'BEFORE INSERT ON catalog_probability_versions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_test_reject_canonical_probability_insert()'
        );
        try {
            $gachaRevision = (int) DB::table('catalog_gachas')
                ->where('public_id', $prepared['gacha_id'])->value('revision');
            $this->mutate(
                $token,
                'POST',
                $this->versionRoot($prepared).'/publish',
                [
                    'expected_revision' => $prepared['version_revision'],
                    'expected_gacha_revision' => $gachaRevision,
                ],
                'lifecycle-internal-failure-publish'
            )->assertStatus(500)
                ->assertJsonPath(
                    'code',
                    'CATALOG_GACHA_PUBLISH_INTERNAL_FAILURE'
                );
        } finally {
            DB::statement(
                'DROP TRIGGER IF EXISTS '.
                'v2_test_reject_canonical_probability_insert '.
                'ON catalog_probability_versions'
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS '.
                'v2_test_reject_canonical_probability_insert()'
            );
        }
    }

    public function test_initial_schedule_can_be_changed_and_cancelled_before_start(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-cancel',
            $this->databaseNow()->addHour()
        );
        $scheduled = $this->schedule($token, $prepared, 'lifecycle-schedule');
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertNotFound();
        $reserved = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        self::assertNull($reserved->first_published_at);
        self::assertNull($reserved->published_version_id);
        self::assertNull($reserved->active_draw_state_id);
        self::assertSame('scheduled', $reserved->management_status);
        self::assertDatabaseHas('catalog_gacha_versions', [
            'public_id' => $prepared['version_id'],
            'status' => 'draft',
            'published_at' => null,
            'published_probability_version_id' => null,
        ]);
        self::assertDatabaseHas('catalog_probability_versions', [
            'public_id' => $scheduled['selected_probability']['id'],
            'status' => 'draft',
        ]);
        $migration = require database_path(
            'migrations-v2/'.
            '2026_09_14_000059_internalize_v2_canonical_probability_publish.php'
        );
        try {
            $migration->down();
            self::fail('MIG-068 rollback must fail while a selection is pending.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Cannot roll back MIG-068',
                $exception->getMessage()
            );
        }

        $changedStart = $this->databaseNow()->addHours(2);
        $input = $prepared['input'];
        $input['publish_start_at'] = $changedStart->toIso8601String();
        $input['title'] = '予約中の全面編集';
        $input['price_points'] = 250;
        $input['total_count'] = 20;
        $input['daily_draw_limit'] = 3;
        $input['audience_code'] = 'line_users';
        $input['allowed_draw_counts'] = [1, 10];
        $changed = $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $input,
            'scheduled',
            'lifecycle-reschedule'
        )->assertOk()->json('data');
        self::assertNull($changed['first_published_at']);
        self::assertSame('予約中の全面編集', $changed['current_version']['title']);
        self::assertSame(250, $changed['current_version']['price_points']);
        self::assertSame(20, $changed['current_version']['total_count']);
        $revisedSchedule = DB::table('catalog_gacha_publish_schedules')
            ->where('public_id', $scheduled['id'])->firstOrFail();
        self::assertSame('scheduled', $revisedSchedule->status);
        self::assertSame(
            $scheduled['selected_probability']['id'],
            DB::table('catalog_probability_versions')
                ->where('id', $revisedSchedule->probability_version_id)
                ->value('public_id')
        );
        self::assertSame(
            $changedStart->utc()->toIso8601ZuluString(),
            CarbonImmutable::parse((string) $revisedSchedule->scheduled_for)
                ->utc()->toIso8601ZuluString()
        );

        $cancelled = $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $input,
            'draft',
            'lifecycle-cancel'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'draft')
            ->json('data');
        self::assertNull($cancelled['first_published_at']);
        self::assertSame(0, DB::table('gacha_draw_states')
            ->where('gacha_id', $reserved->id)->count());
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'gacha_id' => DB::table('catalog_gachas')
                ->where('public_id', $prepared['gacha_id'])->value('id'),
            'status' => 'cancelled',
        ]);
        self::assertSame('scheduled', $scheduled['status']);
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertNotFound();
    }

    public function test_due_worker_commits_first_publication_and_cannot_cancel(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-clock',
            $this->databaseNow()->addSeconds(2)
        );
        $scheduled = $this->schedule(
            $token,
            $prepared,
            'lifecycle-clock-schedule'
        );
        $probabilityId = (int) DB::table('catalog_probability_versions')
            ->where('public_id', $scheduled['selected_probability']['id'])
            ->value('id');
        self::assertDatabaseHas('catalog_probability_versions', [
            'id' => $probabilityId,
            'status' => 'draft',
        ]);
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertNotFound();
        self::assertSame(
            0,
            app(V2ScheduledGachaPublishWorker::class)
                ->run('lifecycle-worker')
        );
        self::assertSame('scheduled', DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])
            ->value('management_status'));
        sleep(3);
        self::assertSame(
            1,
            app(V2ScheduledGachaPublishWorker::class)
                ->run('lifecycle-worker')
        );
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertOk()->assertJsonPath('data.sale_state', 'on_sale');
        $scheduledGacha = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        self::assertSame('published', $scheduledGacha->management_status);
        self::assertNotNull($scheduledGacha->first_published_at);
        self::assertNull($scheduledGacha->scheduled_start_at);
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'gacha_id' => $scheduledGacha->id,
            'status' => 'completed',
        ]);
        $publishedVersion = DB::table('catalog_gacha_versions')
            ->where('public_id', $prepared['version_id'])->firstOrFail();
        self::assertSame(
            $probabilityId,
            (int) $publishedVersion->published_probability_version_id
        );
        self::assertDatabaseHas('catalog_probability_versions', [
            'id' => $probabilityId,
            'status' => 'published',
        ]);
        self::assertSame(
            1,
            DB::table('catalog_probability_versions')
                ->where('gacha_version_id', $publishedVersion->id)
                ->count()
        );
        $drawStateId = (int) $scheduledGacha->active_draw_state_id;
        $currentStart = (string) $scheduledGacha->current_publish_start_at;

        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'draft',
            'lifecycle-late-cancel'
        )->assertUnprocessable()
            ->assertJsonPath(
                'code',
                'CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID'
            );

        $currentInput = $prepared['input'];
        $currentInput['title'] = '予約開始後の表示タイトル';
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $currentInput,
            'published',
            'lifecycle-started-presentation'
        )->assertOk()
            ->assertJsonPath(
                'data.current_version.title',
                '予約開始後の表示タイトル'
            );
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $currentInput,
            'sales_paused',
            'lifecycle-started-pause'
        )->assertOk()
            ->assertJsonPath('data.publication_status', 'sales_paused');
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertOk()
            ->assertJsonPath('data.sale_state', 'paused')
            ->assertJsonPath('data.title', '予約開始後の表示タイトル');
        $paused = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        self::assertSame($drawStateId, (int) $paused->active_draw_state_id);
        self::assertNull($paused->scheduled_start_at);
        self::assertSame(
            CarbonImmutable::parse($currentStart)->utc()->toIso8601ZuluString(),
            CarbonImmutable::parse((string) $paused->current_publish_start_at)
                ->utc()->toIso8601ZuluString()
        );
    }

    public function test_due_worker_retries_internal_publish_without_duplicate_metadata(): void
    {
        config(['v2_catalog.scheduled_publish.retry_base_seconds' => 1]);
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-worker-retry',
            $this->databaseNow()->addSeconds(2)
        );
        $scheduled = $this->schedule(
            $token,
            $prepared,
            'lifecycle-worker-retry-schedule'
        );
        $probabilityId = (int) DB::table('catalog_probability_versions')
            ->where('public_id', $scheduled['selected_probability']['id'])
            ->value('id');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_test_reject_canonical_probability_publish()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.status = 'published' THEN
                    RAISE EXCEPTION 'synthetic canonical Probability publish failure';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(
            'CREATE TRIGGER v2_test_reject_canonical_probability_publish '.
            'BEFORE UPDATE ON catalog_probability_versions FOR EACH ROW '.
            'EXECUTE FUNCTION v2_test_reject_canonical_probability_publish()'
        );
        sleep(3);
        try {
            self::assertSame(
                1,
                app(V2ScheduledGachaPublishWorker::class)
                    ->run('lifecycle-retry-worker')
            );
        } finally {
            DB::statement(
                'DROP TRIGGER IF EXISTS '.
                'v2_test_reject_canonical_probability_publish '.
                'ON catalog_probability_versions'
            );
            DB::statement(
                'DROP FUNCTION IF EXISTS '.
                'v2_test_reject_canonical_probability_publish()'
            );
        }
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'public_id' => $scheduled['id'],
            'status' => 'scheduled',
            'attempts' => 1,
            'failure_code' => null,
        ]);
        self::assertDatabaseHas('catalog_probability_versions', [
            'id' => $probabilityId,
            'status' => 'draft',
        ]);
        sleep(2);
        self::assertSame(
            1,
            app(V2ScheduledGachaPublishWorker::class)
                ->run('lifecycle-retry-worker')
        );
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'public_id' => $scheduled['id'],
            'status' => 'completed',
            'attempts' => 2,
        ]);
        self::assertDatabaseHas('catalog_probability_versions', [
            'id' => $probabilityId,
            'status' => 'published',
        ]);
        $versionId = DB::table('catalog_gacha_versions')
            ->where('public_id', $prepared['version_id'])->value('id');
        self::assertSame(
            1,
            DB::table('catalog_probability_versions')
                ->where('gacha_version_id', $versionId)
                ->count()
        );
    }

    /** @return array<string, mixed> */
    private function prepareGacha(
        string $token,
        string $slug,
        CarbonImmutable $startsAt
    ): array {
        $input = [
            'title' => 'Lifecycle '.$slug,
            'category_id' => self::CATEGORY_ID,
            'tag_ids' => [self::TAG_ID],
            'price_points' => 100,
            'total_count' => 10,
            'daily_draw_limit' => 0,
            'audience_code' => 'all_users',
            'first_time_eligible_days' => 7,
            'allowed_draw_counts' => [1, 5, 10],
            'presentation_asset_id' => self::GACHA_ASSET_ID,
            'publish_start_at' => $startsAt->toIso8601String(),
            'publish_end_at' => $this->databaseNow()->addYear()->toIso8601String(),
            'description' => 'Lifecycle description',
            'notices' => 'Lifecycle notices',
        ];
        $core = $this->mutate(
            $token,
            'POST',
            '/admin/api/v2/catalog/gachas/core',
            $input,
            $slug.'-core'
        )->assertCreated()->json('data');
        $root = '/admin/api/v2/catalog/gachas/'.$core['id'].'/versions/'.
            $core['current_version']['id'];
        $rank = $this->mutate($token, 'POST', $root.'/ranks', [
            'code' => 's',
            'name' => 'Sランク',
            'description' => null,
            'image_asset_id' => null,
            'video_asset_id' => null,
            'expected_version_revision' => 1,
        ], $slug.'-rank')->assertCreated()->json('data');
        $prize = $this->mutate($token, 'POST', $root.'/prizes', [
            'rank_id' => $rank['id'],
            'presentation_asset_id' => self::PRIZE_ASSET_ID,
            'name' => 'Lifecycle Prize',
            'total_inventory' => 10,
            'exchange_points' => 100,
            'cost_price' => 50,
            'is_active' => true,
            'expected_version_revision' => 2,
        ], $slug.'-prize')->assertCreated()->json('data');
        $versionRevision = (int) DB::table('catalog_gacha_versions')
            ->where('public_id', $core['current_version']['id'])->value('revision');

        return [
            'gacha_id' => $core['id'],
            'public_code' => $core['public_code'],
            'slug' => $core['slug'],
            'version_id' => $core['current_version']['id'],
            'version_revision' => $versionRevision,
            'prize_id' => $prize['id'],
            'rank_id' => $rank['id'],
            'input' => $input,
        ];
    }

    /** @param array<string, mixed> $prepared */
    private function publish(string $token, array $prepared, string $key): array
    {
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->value('revision');
        $preflight = $this->mutate(
            $token,
            'POST',
            $this->versionRoot($prepared).'/publish-preflight',
            ['expected_revision' => $prepared['version_revision']],
            $key.'-preflight'
        )->assertOk()->json('data');
        self::assertTrue(
            $preflight['publishable'],
            json_encode($preflight['blocking_reasons'], JSON_THROW_ON_ERROR)
        );
        self::assertNull($preflight['selected_probability']);

        return $this->mutate(
            $token,
            'POST',
            $this->versionRoot($prepared).'/publish',
            [
                'expected_revision' => $prepared['version_revision'],
                'expected_gacha_revision' => $gachaRevision,
            ],
            $key
        )->assertOk()->json('data');
    }

    /** @param array<string, mixed> $prepared */
    private function schedule(string $token, array $prepared, string $key): array
    {
        $gachaRevision = (int) DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->value('revision');
        $preflight = $this->mutate(
            $token,
            'POST',
            $this->versionRoot($prepared).'/publish-schedule/preflight',
            [
                'scheduled_for' => $prepared['input']['publish_start_at'],
                'expected_revision' => $prepared['version_revision'],
                'expected_gacha_revision' => $gachaRevision,
            ],
            $key.'-preflight'
        )->assertOk()->json('data');
        self::assertTrue(
            $preflight['publishable'],
            json_encode($preflight['blocking_reasons'], JSON_THROW_ON_ERROR)
        );
        self::assertNull($preflight['selected_probability']);

        return $this->mutate(
            $token,
            'POST',
            $this->versionRoot($prepared).'/publish-schedule',
            [
                'scheduled_for' => $prepared['input']['publish_start_at'],
                'expected_revision' => $prepared['version_revision'],
                'expected_gacha_revision' => $gachaRevision,
            ],
            $key
        )->assertCreated()->json('data');
    }

    /** @param array<string, mixed> $prepared */
    private function versionRoot(array $prepared): string
    {
        return '/admin/api/v2/catalog/gachas/'.$prepared['gacha_id'].
            '/versions/'.$prepared['version_id'];
    }

    private function updateGacha(
        string $token,
        string $gachaId,
        array $input,
        string $status,
        string $key
    ) {
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', $gachaId)->firstOrFail();
        $version = DB::table('catalog_gacha_versions')
            ->when(
                $gacha->published_version_id !== null,
                fn ($query) => $query->where(
                    'id',
                    $gacha->published_version_id
                ),
                fn ($query) => $query
                    ->where('gacha_id', $gacha->id)
                    ->where(
                        'status',
                        in_array($gacha->management_status, [
                            'draft', 'scheduled',
                        ], true)
                            || (
                                $gacha->first_published_at === null
                                && $gacha->management_status === 'unpublished'
                            )
                                ? 'draft'
                                : 'published'
                    )
                    ->orderByDesc('id')
            )->firstOrFail();

        return $this->mutate(
            $token,
            'PUT',
            '/admin/api/v2/catalog/gachas/'.$gachaId,
            [
                ...$input,
                'management_status' => $status,
                'expected_revision' => (int) $gacha->revision,
                'expected_version_revision' => (int) $version->revision,
            ],
            $key
        );
    }

    /** @param array<string, mixed> $prepared */
    private function updatePublishedPrize(
        string $token,
        array $prepared,
        string $name
    ): void {
        $gacha = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        $version = DB::table('catalog_gacha_versions')
            ->where('id', $gacha->published_version_id)->firstOrFail();
        $prize = DB::table('catalog_prizes')
            ->where('public_id', $prepared['prize_id'])->firstOrFail();
        $inventory = DB::table('prize_inventories')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'prize_inventories.gacha_version_prize_id'
            )
            ->where('relation.prize_id', $prize->id)
            ->firstOrFail(['prize_inventories.available_quantity', 'prize_inventories.lock_version']);
        $this->mutate(
            $token,
            'PUT',
            $this->versionRoot($prepared).'/prizes/'.$prepared['prize_id'],
            [
                'rank_id' => $prepared['rank_id'],
                'presentation_asset_id' => self::PRIZE_ASSET_ID,
                'name' => $name,
                'total_inventory' => 10,
                'available_inventory' => (int) $inventory->available_quantity,
                'exchange_points' => 100,
                'cost_price' => 50,
                'is_active' => true,
                'expected_revision' => (int) $prize->revision,
                'expected_version_revision' => (int) $version->revision,
                'expected_inventory_revision' => (int) $inventory->lock_version,
                'inventory_reason' => 'Presentation-only edit; inventory unchanged',
            ],
            'lifecycle-prize-presentation'
        )->assertOk();
    }

    private function databaseNow(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            DB::selectOne('SELECT STATEMENT_TIMESTAMP() AS value')->value
        );
    }

    private function assertActivationConstraintIsValid(): void
    {
        DB::statement(
            'SET CONSTRAINTS catalog_gachas_validate_activation IMMEDIATE'
        );
        DB::statement(
            'SET CONSTRAINTS catalog_gachas_validate_activation DEFERRED'
        );
    }

    private function mutate(
        string $token,
        string $method,
        string $uri,
        array $payload,
        string $key
    ) {
        $csrf = str_repeat('a', 64);
        $request = $this->withCredentials()
            ->withUnencryptedCookie('__Host-oripa_admin_session', $token)
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://admin.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => $key,
            ]);

        Auth::forgetGuards();

        return $method === 'PUT'
            ? $request->putJson($uri, $payload)
            : $request->postJson($uri, $payload);
    }

    private function createAdminSession(): string
    {
        $email = 'lifecycle-'.Str::uuid7().'@example.test';
        $adminId = (int) DB::table('admins')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'email_display' => $email,
            'email_normalized' => $email,
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)
                ->hash('valid lifecycle test password'),
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
            'idle_expires_at' => now()->addHours(6),
            'absolute_expires_at' => $created->copy()->addHours(12),
        ]);

        return $token;
    }
}
