<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
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

    public function test_initial_publish_uses_one_draw_state_and_current_overlay(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-immediate',
            $this->databaseNow()->subMinute()
        );
        $published = $this->publish($token, $prepared, 'lifecycle-immediate-publish');
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
        $drawStateId = (int) DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])
            ->value('active_draw_state_id');
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertOk()->assertJsonPath('data.sale_state', 'coming_soon');

        $changedStart = $this->databaseNow()->addHours(2);
        $input = $prepared['input'];
        $input['publish_start_at'] = $changedStart->toIso8601String();
        $changed = $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $input,
            'scheduled',
            'lifecycle-reschedule'
        )->assertOk()->json('data');
        self::assertSame(
            $changedStart->utc()->toIso8601ZuluString(),
            CarbonImmutable::parse($changed['first_published_at'])
                ->utc()->toIso8601ZuluString()
        );
        self::assertSame($drawStateId, (int) DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])
            ->value('active_draw_state_id'));

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
        self::assertDatabaseHas('gacha_draw_states', [
            'id' => $drawStateId,
            'status' => 'closed',
            'close_reason' => 'schedule_cancelled',
        ]);
        self::assertDatabaseHas('catalog_gacha_publish_schedules', [
            'gacha_id' => DB::table('catalog_gachas')
                ->where('public_id', $prepared['gacha_id'])->value('id'),
            'status' => 'cancelled',
        ]);
        self::assertSame('completed', $scheduled['status']);
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertNotFound();
    }

    public function test_scheduled_publication_becomes_on_sale_without_worker_and_cannot_cancel(): void
    {
        $token = $this->createAdminSession();
        $prepared = $this->prepareGacha(
            $token,
            'lifecycle-clock',
            $this->databaseNow()->addSeconds(2)
        );
        $this->schedule($token, $prepared, 'lifecycle-clock-schedule');
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertOk()->assertJsonPath('data.sale_state', 'coming_soon');
        self::assertSame(
            0,
            app(V2ScheduledGachaPublishWorker::class)
                ->run('lifecycle-noop-worker')
        );
        self::assertSame('scheduled', DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])
            ->value('management_status'));
        sleep(3);
        $this->getJson('/api/v2/gachas/'.$prepared['public_code'])
            ->assertOk()->assertJsonPath('data.sale_state', 'on_sale');
        $scheduledGacha = DB::table('catalog_gachas')
            ->where('public_id', $prepared['gacha_id'])->firstOrFail();
        self::assertSame('scheduled', $scheduledGacha->management_status);
        self::assertTrue(
            CarbonImmutable::parse((string) $scheduledGacha->scheduled_start_at)
                ->lessThanOrEqualTo($this->databaseNow())
        );
        $drawStateId = (int) $scheduledGacha->active_draw_state_id;
        $currentStart = (string) $scheduledGacha->current_publish_start_at;

        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $prepared['input'],
            'draft',
            'lifecycle-late-cancel'
        )->assertConflict()
            ->assertJsonPath(
                'code',
                'CATALOG_GACHA_SCHEDULE_CONFLICT'
            );

        $currentInput = $prepared['input'];
        $currentInput['title'] = '予約開始後の表示タイトル';
        $this->updateGacha(
            $token,
            $prepared['gacha_id'],
            $currentInput,
            'scheduled',
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
        $probability = $this->mutate(
            $token,
            'POST',
            $root.'/probability-versions',
            [],
            $slug.'-probability'
        )->assertCreated()->json('data');
        $saved = $this->mutate(
            $token,
            'PUT',
            $root.'/probability-versions/'.$probability['id'].'/entries',
            [
                'expected_revision' => $probability['revision'],
                'stages' => [[
                    'code' => 'default',
                    'name' => '通常',
                    'min_draw_number' => 1,
                    'max_draw_number' => null,
                    'entries' => [[
                        'result_type' => 'prize',
                        'prize_id' => $prize['id'],
                        'point_amount' => null,
                        'probability_ppm' => 600_000,
                    ]],
                    'minimum_guarantee' => [
                        'result_type' => 'prize',
                        'prize_id' => $prize['id'],
                        'point_amount' => null,
                        'probability_ppm' => 400_000,
                    ],
                ]],
            ],
            $slug.'-probability-entries'
        )->assertOk()->json('data');
        $publishedProbability = $this->mutate(
            $token,
            'POST',
            $root.'/probability-versions/'.$probability['id'].'/publish',
            ['expected_revision' => $saved['revision']],
            $slug.'-probability-publish'
        )->assertOk()->json('data');
        $versionRevision = (int) DB::table('catalog_gacha_versions')
            ->where('public_id', $core['current_version']['id'])->value('revision');
        $selected = $this->mutate(
            $token,
            'PUT',
            $root.'/probability-selection',
            [
                'expected_revision' => $versionRevision,
                'probability_version_id' => $publishedProbability['id'],
            ],
            $slug.'-probability-select'
        )->assertOk()->json('data');

        return [
            'gacha_id' => $core['id'],
            'public_code' => $core['public_code'],
            'slug' => $core['slug'],
            'version_id' => $core['current_version']['id'],
            'version_revision' => $selected['revision'],
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
                    ->where('status', 'published')
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
