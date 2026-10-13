<?php

namespace Tests\V2;

use App\Domain\Audit\V2\Services\V2AuditChainVerifier;
use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Catalog\Services\V2CatalogMasterMutationService;
use App\Domain\ContentContact\Services\V2ContentContactAdminService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Identity\Services\V2SessionPolicy;
use App\Models\V2\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DraftPrizeRemovalTest extends TestCase
{
    private V2CatalogMasterMutationService $service;
    private V2AdminAuthorizationContext $context;
    private array $gacha;
    private array $rank;
    private array $prize;
    private string $token;
    private string $versionId;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('V2_F4_CONCURRENCY_TEST') === '1') {
            self::assertTrue(app()->environment('testing'));
            self::assertMatchesRegularExpression('/^oripa_v2_mig[0-9]{3}_f4_/', DB::connection()->getDatabaseName());
            self::assertSame(0, DB::table('catalog_gachas')->count(), 'Use a fresh isolated F4 database.');
        }
        DB::beginTransaction();
        Storage::fake('local');
        config(['filesystems.default' => 'local', 'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1', 'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'v2_identity.origins.admin' => 'https://admin.example.test', 'v2_identity.origins.user' => 'https://example.test']);
        app(V2CatalogFixtureImporter::class)->import(json_decode(file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'), true, 512, JSON_THROW_ON_ERROR));
        $admin = Admin::query()->create(['email_display' => 'removal@example.test', 'email_normalized' => 'removal@example.test',
            'email_verified_at' => now(), 'password_hash' => app(V2PasswordPolicy::class)->hash('valid removal password'),
            'role' => V2AdminRole::Owner, 'state' => V2AdminState::Active]);
        $sessions = app(V2SessionPolicy::class);
        $this->token = $sessions->issueOpaqueSessionId();
        $hash = $sessions->hashSessionId($this->token);
        DB::table('admin_sessions')->insert(['session_id_hash' => $hash, 'admin_id' => $admin->id, 'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false, 'created_at' => now()->subHour(), 'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15), 'absolute_expires_at' => now()->addHours(7)]);
        $this->context = new V2AdminAuthorizationContext((int) $admin->id, $admin->public_id, $admin->role, $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)->correlation($hash), (string) Str::uuid7());
        $this->service = app(V2CatalogMasterMutationService::class);
        $this->gacha = $this->service->createGachaCore($this->context, (string) Str::uuid7(), [
            'title' => 'Removal Draft', 'category_id' => '0198a001-0000-7000-8000-000000000001',
            'tag_ids' => [], 'price_points' => 100, 'total_count' => 1000, 'daily_draw_limit' => 0,
            'audience_code' => 'all_users', 'presentation_asset_id' => '0198a001-0000-7000-8000-000000000005',
            'publish_start_at' => '2026-08-20T00:00:00Z', 'publish_end_at' => '2027-08-20T00:00:00Z', 'description' => null, 'notices' => null,
        ])['data'];
        $this->versionId = $this->gacha['current_version']['id'];
        $this->rank = $this->service->createRankMaster($this->context, (string) Str::uuid7(), [
            'rank_name' => 'F4賞', 'lineup_image' => $this->image(), 'result_image' => $this->image(),
        ])['data'];
        $this->service->setGachaRankVideo($this->context, $this->gacha['id'], $this->rank['id'], (string) Str::uuid7(), [
            'video_asset_id' => '0198a001-0000-7000-8000-000000000006',
        ]);
        $this->prize = $this->createPrize('CARD-A');
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    public function test_http_removal_archives_master_deletes_only_target_and_replays(): void
    {
        $other = $this->createPrize('CARD-B');
        $target = DB::table('catalog_prizes')->where('public_id', $this->prize['id'])->firstOrFail();
        $relation = DB::table('catalog_gacha_version_prizes')->where('prize_id', $target->id)->firstOrFail();
        $revision = $this->revision();
        $key = (string) Str::uuid7();
        $response = $this->httpRemove(['expected_version_revision' => $revision], $key)->assertOk()
            ->assertJsonPath('data.id', $this->prize['id'])->assertJsonPath('data.gacha_version_revision', $revision + 1)
            ->assertJsonPath('idempotent_replay', false)->json('data');
        self::assertDatabaseMissing('catalog_gacha_version_prizes', ['id' => $relation->id]);
        self::assertDatabaseMissing('prize_inventories', ['gacha_version_prize_id' => $relation->id]);
        $archived = DB::table('catalog_prizes')->where('id', $target->id)->firstOrFail();
        self::assertNotNull($archived->archived_at);
        self::assertSame('CARD-A', $archived->external_id);
        self::assertSame((int) $target->revision + 1, (int) $archived->revision);
        self::assertDatabaseHas('catalog_prizes', ['public_id' => $other['id'], 'archived_at' => null]);
        $after = $this->snapshot();
        $replay = $this->httpRemove(['expected_version_revision' => $revision], $key)->assertOk()
            ->assertJsonPath('idempotent_replay', true)->json('data');
        self::assertEquals($response, $replay);
        self::assertSame($after, $this->snapshot());
        $this->httpRemove(['expected_version_revision' => $revision + 1], $key)
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        self::assertSame(1, DB::table('audit_logs')->where('action_code', 'catalog.master.prize_removed')->count());
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }

    public function test_missing_inventory_is_safe(): void
    {
        $relation = $this->relation();
        DB::table('prize_inventories')->where('gacha_version_prize_id', $relation->id)->delete();
        $this->remove();
        self::assertDatabaseMissing('catalog_gacha_version_prizes', ['id' => $relation->id]);
    }

    public function test_inventory_adjustment_history_rejects_without_business_mutation(): void
    {
        $this->adjustPrize();
        $before = $this->snapshot();
        foreach (range(1, 2) as $attempt) {
            $this->httpRemove(['expected_version_revision' => $this->revision()], 'history-refusal')
                ->assertConflict()->assertJsonPath('code', 'CATALOG_PRIZE_REMOVAL_INVENTORY_HISTORY');
            self::assertSame($before, $this->snapshot());
        }
        self::assertSame(1, DB::table('prize_inventory_adjustments')->count());
        self::assertSame(0, DB::table('audit_logs')->where('action_code', 'catalog.master.prize_removed')->count());
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }

    #[DataProvider('invalidRequests')]
    public function test_validation_and_occ_preserve_business_state(array $input, string $key, int $status): void
    {
        $before = $this->snapshot();
        $this->httpRemove($input, $key)->assertStatus($status);
        self::assertSame($before, $this->snapshot());
    }

    public static function invalidRequests(): array
    {
        return [[[], 'missing-revision', 422], [['expected_version_revision' => 0], 'zero', 422],
            [['expected_version_revision' => '2'], 'string', 422], [['expected_version_revision' => 2, 'other' => true], 'extra', 422],
            [['expected_version_revision' => 1], 'stale', 409], [['expected_version_revision' => 2], '', 422]];
    }

    public function test_operator_cannot_remove(): void
    {
        DB::table('admins')->where('id', $this->context->adminId)->update(['role' => 'operator']);
        $before = $this->snapshot();
        $this->httpRemove(['expected_version_revision' => $this->revision()])->assertForbidden();
        self::assertSame($before, $this->snapshot());
    }

    #[DataProvider('ineligibleStates')]
    public function test_ineligible_states_are_conflicts(string $table, array $changes): void
    {
        $identifier = $table === 'catalog_gachas' ? $this->gacha['id'] : $this->versionId;
        DB::table($table)->where('public_id', $identifier)->update([...$changes, 'revision' => DB::raw('revision + 1')]);
        $before = $this->snapshot();
        $this->httpRemove(['expected_version_revision' => $this->revision()])->assertConflict();
        self::assertSame($before, $this->snapshot());
    }

    public static function ineligibleStates(): array
    {
        return [['catalog_gachas', ['archived_at' => '2026-10-01T00:00:00Z', 'state' => 'disabled']],
            ['catalog_gachas', ['first_published_at' => '2026-10-01T00:00:00Z']],
            ['catalog_gacha_versions', ['archived_at' => '2026-10-01T00:00:00Z']]];
    }

    public function test_unrelated_identifiers_are_rejected(): void
    {
        $foreignPrize = DB::table('catalog_prizes')->where('gacha_id', '<>',
            DB::table('catalog_gachas')->where('public_id', $this->gacha['id'])->value('id'))->value('public_id');
        self::assertNotNull($foreignPrize);
        $before = $this->snapshot();
        $this->assertConflictOrNotFound(fn () => $this->service->removeGachaDraftPrize($this->context,
            '0198a001-0000-7000-8000-000000000011', $this->versionId, $this->prize['id'], (string) Str::uuid7(), ['expected_version_revision' => $this->revision()]));
        $this->assertConflictOrNotFound(fn () => $this->service->removeGachaDraftPrize($this->context,
            $this->gacha['id'], $this->versionId, (string) Str::uuid7(), (string) Str::uuid7(), ['expected_version_revision' => $this->revision()]));
        $this->assertConflictOrNotFound(fn () => $this->service->removeGachaDraftPrize($this->context,
            $this->gacha['id'], $this->versionId, $foreignPrize, (string) Str::uuid7(), ['expected_version_revision' => $this->revision()]));
        $this->assertConflictOrNotFound(fn () => $this->service->removeGachaDraftPrize($this->context,
            $this->gacha['id'], (string) Str::uuid7(), $this->prize['id'], (string) Str::uuid7(), ['expected_version_revision' => $this->revision()]));
        self::assertSame($before, $this->snapshot());
    }

    public function test_shared_prize_in_another_draft_is_rejected(): void
    {
        $this->service->cloneGachaDraft($this->context, $this->gacha['id'], $this->versionId, (string) Str::uuid7(), []);
        $before = $this->snapshot();
        $this->httpRemove(['expected_version_revision' => $this->revision()])->assertConflict()
            ->assertJsonPath('code', 'CATALOG_PRIZE_REMOVAL_REFERENCED');
        self::assertSame($before, $this->snapshot());
    }

    public function test_f3_missing_prize_error_clears_only_after_removal_then_apply_and_publish_succeed(): void
    {
        $this->createPrize('CARD-B');
        $removed = $this->createPrize('CARD-C');
        $content = app(V2ContentContactAdminService::class);
        $category = $content->createBannerCategory($this->context, ['name' => 'F4 Library'], (string) Str::uuid7());
        $asset = $content->uploadBannerAsset($this->context, $this->image(), (string) Str::uuid7());
        foreach (['CARD-A', 'CARD-B'] as $externalId) {
            $content->createManagedBanner($this->context, ['title' => $externalId, 'external_id' => $externalId,
                'category_id' => $category['id'], 'asset_id' => $asset['id']], (string) Str::uuid7());
        }
        $csv = ['file_name' => 'f4.csv', 'content_base64' => base64_encode("管理ID,ランク,枚数,交換ポイント,原価\nCARD-A,F4賞,2,3,4\nCARD-B,F4賞,2,3,4"),
            'expected_version_revision' => $this->revision()];
        try {
            $this->service->previewPrizeImport($this->context, $this->gacha['id'], $this->versionId, $csv);
            self::fail('Missing prize accepted');
        } catch (V2CatalogException $error) {
            self::assertSame(422, $error->status);
            self::assertContains('PRIZE_MISSING_FROM_CSV', array_column($error->details['errors'], 'code'));
        }
        $this->remove($removed['id']);
        $csv['expected_version_revision'] = $this->revision();
        $plan = $this->service->previewPrizeImport($this->context, $this->gacha['id'], $this->versionId, $csv);
        self::assertSame(['create' => 0, 'update' => 0, 'unchanged' => 2], $plan['summary']);
        $this->service->applyPrizeImport($this->context, $this->gacha['id'], $this->versionId, (string) Str::uuid7(), [...$csv, 'plan_checksum' => $plan['plan_checksum']]);
        $preflight = $this->service->preflightGachaPublish($this->context, $this->gacha['id'], $this->versionId,
            (string) Str::uuid7(), ['expected_revision' => $this->revision()]);
        self::assertTrue($preflight['data']['publishable']);
        $this->service->publishGachaVersionImmediately($this->context, $this->gacha['id'], $this->versionId,
            (string) Str::uuid7(), ['expected_revision' => $this->revision(),
                'expected_gacha_revision' => (int) DB::table('catalog_gachas')->where('public_id', $this->gacha['id'])->value('revision')]);
        $this->httpRemove(['expected_version_revision' => $this->revision()])->assertConflict();
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }

    public function test_scheduled_draft_probability_reference_is_preserved(): void
    {
        $this->service->scheduleGachaVersionPublish($this->context, $this->gacha['id'], $this->versionId, (string) Str::uuid7(), [
            'scheduled_for' => now()->addDay()->toIso8601String(), 'expected_revision' => $this->revision(),
            'expected_gacha_revision' => (int) DB::table('catalog_gachas')->where('public_id', $this->gacha['id'])->value('revision'),
        ]);
        self::assertTrue(DB::table('catalog_probability_entries')->where('gacha_version_prize_id', $this->relation()->id)->exists());
        $before = $this->snapshot();
        $this->httpRemove(['expected_version_revision' => $this->revision()])->assertConflict()
            ->assertJsonPath('code', 'CATALOG_PRIZE_REMOVAL_REFERENCED');
        self::assertSame($before, $this->snapshot());
    }

    public function test_unreferenced_prize_removal_synchronizes_pending_schedule_and_outbox(): void
    {
        $gachaId = DB::table('catalog_gachas')->where('public_id', $this->gacha['id'])->value('id');
        $this->service->scheduleGachaVersionPublish($this->context, $this->gacha['id'], $this->versionId, (string) Str::uuid7(), [
            'scheduled_for' => now()->addDay()->toIso8601String(), 'expected_revision' => $this->revision(),
            'expected_gacha_revision' => (int) DB::table('catalog_gachas')->where('id', $gachaId)->value('revision'),
        ]);
        $additional = $this->service->createGachaRankPrize($this->context, $this->gacha['id'], $this->versionId,
            $this->rank['id'], (string) Str::uuid7(), [...$this->prizeInput('CARD-ADDITIONAL'), 'is_active' => false])['data'];
        $result = $this->remove($additional['id']);
        self::assertDatabaseHas('catalog_gacha_publish_schedules', ['gacha_id' => $gachaId, 'status' => 'scheduled',
            'expected_version_revision' => $result['data']['gacha_version_revision']]);
        self::assertDatabaseHas('outbox_messages', ['aggregate_public_id' => $additional['id'], 'event_type' => 'catalog.master.prize_removed']);
    }

    #[DataProvider('loginTypes')]
    public function test_login_gacha_is_rejected(string $type): void
    {
        $gacha = $this->service->saveComposition($this->context, (string) Str::uuid7(), [
            'gacha_type' => $type, 'title' => 'Login removal fixture', 'description' => null, 'notices' => null,
            'presentation_asset_id' => '0198a001-0000-7000-8000-000000000005', 'price_points' => 0,
            'minimum_exchange_points' => $type === 'login_daily' ? 10 : null,
            'publish_start_at' => '2026-07-01T00:00:00Z', 'publish_end_at' => null,
            'category_id' => null, 'tag_ids' => [], 'total_count' => null, 'daily_draw_limit' => $type === 'login_daily' ? 1 : 0,
            'audience_code' => 'all_users', 'first_time_eligible_days' => 7, 'allowed_draw_counts' => [1],
            'ranks' => [['rank_id' => $this->rank['id'], 'rank_revision_number' => null, 'video_asset_id' => '0198a001-0000-7000-8000-000000000006']],
            'prizes' => [['name' => 'Login prize', 'presentation_asset_id' => '0198a001-0000-7000-8000-000000000007',
                'rank_id' => $this->rank['id'], 'exchange_points' => 10, 'cost_price' => 0, 'initial_inventory' => 3,
                'shipping_only' => false, 'percentage' => '100']],
        ])['data'];
        $version = DB::table('catalog_gacha_versions')->where('public_id', $gacha['current_version']['id'])->firstOrFail();
        $prizeId = DB::table('catalog_gacha_version_prizes as relation')->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('relation.gacha_version_id', $version->id)->value('prize.public_id');
        $before = $this->snapshot();
        try {
            $this->service->removeGachaDraftPrize($this->context, $gacha['id'], $version->public_id, $prizeId,
                (string) Str::uuid7(), ['expected_version_revision' => (int) $version->revision]);
            self::fail('Login Gacha accepted');
        } catch (V2CatalogException $error) {
            self::assertSame(409, $error->status);
            self::assertSame('CATALOG_MUTATION_INVALID', $error->errorCode);
        }
        self::assertSame($before, $this->snapshot());
    }

    public static function loginTypes(): array
    {
        return [['login_daily'], ['signup_once']];
    }

    public function test_audit_failure_rolls_back_removal(): void
    {
        DB::unprepared("CREATE FUNCTION f4_audit_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action_code = 'catalog.master.prize_removed' THEN RAISE EXCEPTION 'F4 audit failure'; END IF; RETURN NEW; END $$; CREATE TRIGGER f4_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION f4_audit_failure()");
        $before = $this->snapshot();
        try {
            $this->remove();
            self::fail('Audit failure did not roll back');
        } catch (\Illuminate\Database\QueryException $error) {
            self::assertStringContainsString('F4 audit failure', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function test_concurrent_adjustment_and_removal_have_one_safe_winner(): void
    {
        if (getenv('V2_F4_CONCURRENCY_TEST') !== '1') {
            self::markTestSkipped('Requires an explicit isolated F4 concurrency run.');
        }
        self::assertTrue(function_exists('pcntl_fork'));
        $revision = $this->revision();
        $adjustment = [...$this->prizeInput('CARD-A'), 'expected_revision' => 1,
            'total_inventory' => 1, 'available_inventory' => 1, 'expected_inventory_revision' => 0, 'inventory_reason' => 'F4 race'];
        DB::commit();
        DB::disconnect();
        $start = microtime(true) + 0.5;
        $workers = [];
        foreach (['adjust', 'remove'] as $operation) {
            $path = tempnam(sys_get_temp_dir(), 'f4-race-');
            $process = pcntl_fork();
            self::assertNotSame(-1, $process);
            if ($process === 0) {
                while (microtime(true) < $start) usleep(1000);
                DB::reconnect();
                DB::statement("SET statement_timeout = '15s'");
                try {
                    if ($operation === 'adjust') {
                        $this->service->updateGachaRankPrize($this->context, $this->gacha['id'], $this->versionId,
                            $this->rank['id'], $this->prize['id'], (string) Str::uuid7(), $adjustment);
                    } else {
                        $this->service->removeGachaDraftPrize($this->context, $this->gacha['id'], $this->versionId,
                            $this->prize['id'], (string) Str::uuid7(), ['expected_version_revision' => $revision]);
                    }
                    $outcome = 'success';
                } catch (V2CatalogException $error) {
                    $outcome = $error->errorCode;
                } catch (\Throwable $error) {
                    $outcome = get_class($error);
                }
                file_put_contents($path, $outcome);
                DB::disconnect();
                exit(0);
            }
            $workers[$operation] = [$process, $path];
        }
        $outcomes = [];
        foreach ($workers as $operation => [$process, $path]) {
            pcntl_waitpid($process, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $outcomes[$operation] = file_get_contents($path);
            unlink($path);
        }
        DB::reconnect();
        self::assertContains($outcomes, [
            ['adjust' => 'success', 'remove' => 'CATALOG_REVISION_CONFLICT'],
            ['adjust' => 'CATALOG_REVISION_CONFLICT', 'remove' => 'success'],
        ]);
        self::assertSame($revision + 1, $this->revision());
        $prize = DB::table('catalog_prizes')->where('public_id', $this->prize['id'])->firstOrFail();
        if ($outcomes['adjust'] === 'success') {
            self::assertNull($prize->archived_at);
            self::assertSame(1, DB::table('prize_inventory_adjustments')->count());
            self::assertDatabaseHas('prize_inventories', ['gacha_version_prize_id' => $this->relation()->id, 'total_quantity' => 1]);
            $this->httpRemove(['expected_version_revision' => $this->revision()])->assertConflict()
                ->assertJsonPath('code', 'CATALOG_PRIZE_REMOVAL_INVENTORY_HISTORY');
        } else {
            self::assertNotNull($prize->archived_at);
            self::assertSame(0, DB::table('prize_inventory_adjustments')->count());
            self::assertDatabaseMissing('catalog_gacha_version_prizes', ['prize_id' => $prize->id]);
        }
        self::assertTrue(app(V2AuditChainVerifier::class)->verify());
    }

    private function createPrize(string $externalId): array
    {
        return $this->service->createGachaRankPrize($this->context, $this->gacha['id'], $this->versionId,
            $this->rank['id'], (string) Str::uuid7(), $this->prizeInput($externalId))['data'];
    }

    private function prizeInput(string $externalId): array
    {
        return ['external_id' => $externalId, 'name' => $externalId, 'presentation_asset_id' => '0198a001-0000-7000-8000-000000000007',
            'total_inventory' => 2, 'exchange_points' => 3, 'cost_price' => 4, 'is_active' => true, 'expected_version_revision' => $this->revision()];
    }

    private function adjustPrize(): void
    {
        $this->service->updateGachaRankPrize($this->context, $this->gacha['id'], $this->versionId, $this->rank['id'],
            $this->prize['id'], (string) Str::uuid7(), [...$this->prizeInput('CARD-A'), 'expected_revision' => 1,
                'total_inventory' => 1, 'available_inventory' => 1, 'expected_inventory_revision' => 0, 'inventory_reason' => 'Synthetic F4 adjustment']);
    }

    private function remove(?string $prizeId = null): array
    {
        return $this->service->removeGachaDraftPrize($this->context, $this->gacha['id'], $this->versionId,
            $prizeId ?? $this->prize['id'], (string) Str::uuid7(), ['expected_version_revision' => $this->revision()]);
    }

    private function httpRemove(array $input, ?string $key = null)
    {
        Auth::forgetGuards();
        $csrf = str_repeat('a', 64);
        return $this->withCredentials()->withUnencryptedCookie('__Host-oripa_admin_session', $this->token)
            ->withServerVariables(['HTTPS' => 'on'])->withUnencryptedCookie('__Host-oripa_admin_xsrf', $csrf)
            ->withHeaders(['Origin' => 'https://admin.example.test', 'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf, 'Idempotency-Key' => $key ?? (string) Str::uuid7()])
            ->deleteJson('/admin/api/v2/catalog/gachas/'.$this->gacha['id'].'/versions/'.$this->versionId.'/prizes/'.$this->prize['id'], $input);
    }

    private function revision(): int
    {
        return (int) DB::table('catalog_gacha_versions')->where('public_id', $this->versionId)->value('revision');
    }

    private function relation(): object
    {
        return DB::table('catalog_gacha_version_prizes')->where('prize_id', DB::table('catalog_prizes')->where('public_id', $this->prize['id'])->value('id'))->firstOrFail();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['catalog_gachas', 'catalog_gacha_versions', 'catalog_prizes', 'catalog_gacha_version_prizes', 'prize_inventories',
            'prize_inventory_adjustments', 'catalog_probability_versions', 'catalog_probability_entries', 'catalog_minimum_guarantees',
            'draw_results', 'user_prizes', 'wallets', 'catalog_gacha_publish_schedules'] as $table) {
            $snapshot[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()));
        }
        return $snapshot;
    }

    private function assertConflictOrNotFound(callable $operation): void
    {
        try {
            $operation();
            self::fail('Unrelated identifier accepted');
        } catch (V2CatalogException $error) {
            self::assertContains($error->status, [404, 409]);
        }
    }

    private function image(): array
    {
        return ['file_name' => 'f4.png', 'mime_type' => 'image/png', 'content_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='];
    }
}
