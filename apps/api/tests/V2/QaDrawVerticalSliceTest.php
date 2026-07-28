<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Exceptions\V2AuthenticationException;
use App\Domain\Identity\Enums\V2AdminRole;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use App\Domain\QaDraw\Exceptions\V2QaDrawException;
use App\Domain\QaDraw\Services\V2QaDrawAdminService;
use App\Http\Controllers\V2\V2AdminQaDrawController;
use App\Models\V2\Admin;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QaDrawVerticalSliceTest extends TestCase
{
    private const GACHA_ID = '0198a001-0000-7000-8000-000000000011';
    private const PRIZE_S_ID = '0198a001-0000-7000-8000-000000000009';
    private const PRIZE_A_ID = '0198a001-0000-7000-8000-000000000010';
    private const IMAGE_ID = '0198a001-0000-7000-8000-000000000007';
    private const VIDEO_ID = '0198a001-0000-7000-8000-000000000006';

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-29T00:00:00Z');
        config([
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('q', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
            'v2_prize_shipping.address_hmac_key' => 'base64:'.
                base64_encode(str_repeat('p', 32)),
        ]);
    }

    protected function tearDown(): void
    {
        Auth::forgetGuards();
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_schema_is_strict_traceable_and_does_not_duplicate_qa_on_user_prizes(): void
    {
        foreach ([
            'qa_test_user_modes',
            'qa_draw_plans',
            'qa_draw_plan_items',
            'qa_draw_executions',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing QA table: {$table}");
            self::assertFalse(Schema::hasColumn($table, 'tenant_id'));
        }
        self::assertTrue(Schema::hasColumn('draw_requests', 'is_qa_draw'));
        self::assertTrue(Schema::hasColumn('draw_requests', 'qa_test_user_mode_id'));
        self::assertTrue(Schema::hasColumn('draw_results', 'qa_draw_plan_item_id'));
        self::assertFalse(Schema::hasColumn('user_prizes', 'is_qa_draw'));

        [$user, $owner] = $this->fixture();
        $this->enableMode($owner, $user);
        $plan = $this->qaPlan($owner, $user, [
            $this->item(self::PRIZE_A_ID, 1, 1),
        ]);
        $this->draw($user, 1, 'qa-schema-draw-key-0001');
        self::assertDatabaseHas('draw_requests', ['is_qa_draw' => true]);
        self::assertDatabaseHas('draw_results', ['is_qa_draw' => true]);
        self::assertDatabaseHas('qa_draw_executions', ['executed_count' => 1]);
        self::assertNotNull(DB::table('user_prizes as up')
            ->join('draw_results as result', 'result.id', '=', 'up.draw_result_id')
            ->where('result.is_qa_draw', true)
            ->value('up.public_id'));

        try {
            DB::table('qa_draw_executions')->update(['executed_count' => 5]);
            self::fail('QA Execution update must be rejected.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        try {
            DB::table('qa_draw_plans')->where('public_id', $plan['id'])->delete();
            self::fail('QA Plan deletion must be rejected.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_owner_only_mode_enforces_reason_window_and_logical_disable(): void
    {
        [$user, $owner] = $this->fixture();
        $service = app(V2QaDrawAdminService::class);
        $ownerContext = $this->adminContext($owner);
        $mode = $service->saveMode(
            $ownerContext,
            $user->public_id,
            'QA release verification',
            null,
            now()->addHours(24)->toIso8601String()
        );
        self::assertTrue($mode['is_active']);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'qa.mode.enabled']);

        $admin = $this->admin(V2AdminRole::Admin);
        foreach ([$admin, $this->admin(V2AdminRole::Operator)] as $denied) {
            try {
                $service->mode($this->adminContext($denied), $user->public_id);
                self::fail('Non-Owner must not read QA Mode.');
            } catch (V2AuthenticationException $exception) {
                self::assertSame(403, $exception->status);
            }
        }
        try {
            $service->saveMode(
                $ownerContext,
                $user->public_id,
                'Too long',
                null,
                now()->addHours(25)->toIso8601String()
            );
            self::fail('QA Mode longer than 24 hours must fail.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_CONFIGURATION_INVALID', $exception->errorCode);
        }

        $disabled = $service->disableMode($ownerContext, $user->public_id);
        self::assertFalse($disabled['is_enabled']);
        self::assertNotNull($disabled['disabled_at']);
        self::assertDatabaseCount('qa_test_user_modes', 1);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'qa.mode.disabled']);
    }

    public function test_admin_controller_distinguishes_unauthenticated_and_non_owner(): void
    {
        [$user] = $this->fixture();
        $controller = app(V2AdminQaDrawController::class);
        $request = Request::create('/admin/api/v2/users/'.$user->public_id.'/qa-mode');
        $request->headers->set('Accept', 'application/json');

        $unauthenticated = $controller->showMode($request, $user->public_id);
        self::assertSame(401, $unauthenticated->status());

        $request = $this->adminRequest($this->admin(V2AdminRole::Admin), $request->path());
        $forbidden = $controller->showMode($request, $user->public_id);
        self::assertSame(403, $forbidden->status());

        $request = $this->adminRequest($this->admin(V2AdminRole::Owner), $request->path());
        self::assertSame(200, $controller->showMode($request, $user->public_id)->status());
    }

    public function test_plan_validates_prize_assets_uniqueness_and_lifecycle(): void
    {
        [$user, $owner] = $this->fixture();
        $plan = $this->qaPlan($owner, $user, [
            $this->item(
                self::PRIZE_S_ID,
                2,
                1,
                self::IMAGE_ID,
                self::VIDEO_ID
            ),
            $this->item(self::PRIZE_A_ID, 3, 2),
        ]);
        self::assertSame('active', $plan['status']);
        self::assertCount(2, $plan['items']);

        try {
            $this->qaPlan($owner, $user, [$this->item(self::PRIZE_A_ID, 1, 1)]);
            self::fail('A second active User/Gacha Plan must conflict.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_ACTIVE_PLAN_CONFLICT', $exception->errorCode);
        }
        try {
            $this->qaPlan($owner, $user, [
                $this->item(self::PRIZE_A_ID, 1, 1, self::VIDEO_ID, null),
            ]);
            self::fail('Video Asset cannot be used as fixed image.');
        } catch (V2QaDrawException $exception) {
            self::assertSame('QA_CONFIGURATION_INVALID', $exception->errorCode);
        }

        $service = app(V2QaDrawAdminService::class);
        self::assertSame(
            'paused',
            $service->pausePlan($this->adminContext($owner), $plan['id'])['status']
        );
        self::assertSame(
            'active',
            $service->activatePlan($this->adminContext($owner), $plan['id'])['status']
        );
        self::assertSame(
            'disabled',
            $service->disablePlan($this->adminContext($owner), $plan['id'])['status']
        );
        try {
            $service->activatePlan($this->adminContext($owner), $plan['id']);
            self::fail('Disabled Plan must not reactivate.');
        } catch (V2QaDrawException $exception) {
            self::assertSame(422, $exception->status);
        }
    }

    public function test_inactive_mode_uses_normal_probability_without_qa_identification(): void
    {
        [$user, $owner] = $this->fixture(randomValues: [150_000]);
        app(V2QaDrawAdminService::class)->saveMode(
            $this->adminContext($owner),
            $user->public_id,
            'Future QA window',
            now()->addHour()->toIso8601String(),
            now()->addHours(2)->toIso8601String()
        );
        $response = $this->draw($user, 1, 'qa-inactive-normal-key-0001');

        self::assertDatabaseHas('draw_requests', [
            'public_id' => $response['id'],
            'is_qa_draw' => false,
        ]);
        self::assertDatabaseCount('qa_draw_executions', 0);
        self::assertSame(1, DB::table('draw_results')->where('is_qa_draw', false)->count());
    }

    public function test_active_qa_draw_uses_ordered_items_real_domain_updates_and_compact_response(): void
    {
        [$user, $owner] = $this->fixture(randomValues: [1, 2, 3, 4, 5]);
        $this->enableMode($owner, $user);
        $plan = $this->qaPlan($owner, $user, [
            $this->item(
                self::PRIZE_S_ID,
                2,
                1,
                self::IMAGE_ID,
                self::VIDEO_ID
            ),
            $this->item(self::PRIZE_A_ID, 3, 2),
        ]);
        $walletBefore = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');
        $response = $this->draw($user, 5, 'qa-forced-order-key-0001');
        $request = DB::table('draw_requests')->where('public_id', $response['id'])->first();
        $results = DB::table('draw_results as result')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'result.gacha_version_prize_id'
            )
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('result.draw_request_id', $request->id)
            ->orderBy('result.request_sequence')
            ->get(['prize.code', 'result.is_qa_draw', 'result.qa_draw_plan_item_id']);

        self::assertSame(
            ['fixture-s-1', 'fixture-s-1', 'fixture-a-1', 'fixture-a-1', 'fixture-a-1'],
            $results->pluck('code')->all()
        );
        self::assertTrue($results->every(fn (object $row): bool => $row->is_qa_draw));
        self::assertTrue($results->every(
            fn (object $row): bool => $row->qa_draw_plan_item_id !== null
        ));
        self::assertSame(0, DB::table('draw_results')->where('result_type', 'point_back')->count());
        self::assertSame(5, DB::table('user_prizes')->count());
        self::assertSame(5, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame(
            $walletBefore - 500,
            (int) DB::table('wallets')->where('user_id', $user->id)->value('free_balance')
        );
        self::assertDatabaseHas('qa_draw_plans', [
            'public_id' => $plan['id'],
            'status' => 'completed',
        ]);
        self::assertDatabaseHas('qa_draw_executions', [
            'draw_request_id' => $request->id,
            'executed_count' => 5,
        ]);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'qa.draw.completed']);
        self::assertDatabaseHas('outbox_messages', [
            'deduplication_key' => 'draw.completed:'.$response['id'],
        ]);
        $public = json_encode($response, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('qa_plan', $public);
        self::assertStringNotContainsString('qa_mode', $public);
        self::assertStringNotContainsString('QA release verification', $public);
    }

    public function test_qa_draw_supports_all_counts_and_replay_never_consumes_twice(): void
    {
        $randomCounter = (object) ['count' => 0];
        [$user, $owner] = $this->fixture(
            randomValues: [0],
            freePoints: 500_000,
            totalCount: 3_000,
            randomCounter: $randomCounter
        );
        $this->enableMode($owner, $user);
        $this->qaPlan($owner, $user, [
            $this->item(self::PRIZE_A_ID, 2_232, 1),
        ]);
        $expected = 0;
        foreach ([1, 5, 10, 100, 1000] as $count) {
            $key = "qa-allowed-count-{$count}-key";
            $first = $this->draw($user, $count, $key);
            $replay = $this->draw($user, $count, $key);
            $expected += $count;
            self::assertSame($count, $first['executed_count']);
            self::assertTrue($replay['idempotent_replay']);
            self::assertSame($first['id'], $replay['id']);
        }
        self::assertSame($expected, DB::table('draw_results')->count());
        self::assertSame($expected, DB::table('user_prizes')->count());
        self::assertSame($expected, (int) DB::table('qa_draw_plan_items')->value('consumed_count'));
        self::assertSame(5, DB::table('qa_draw_executions')->count());
        self::assertSame($expected, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame($expected, $randomCounter->count);

        try {
            $this->draw($user, 5, 'qa-allowed-count-10-key');
            self::fail('A QA idempotency key reused for another request must conflict.');
        } catch (V2DrawException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }

        $inFlightKey = 'qa-in-flight-key-0001';
        DB::table('idempotency_records')->insert([
            'public_id' => (string) Str::uuid7(),
            'scope' => 'draw.create',
            'actor_type' => 'user',
            'actor_public_id' => $user->public_id,
            'key_hash' => hash('sha256', $inFlightKey),
            'request_hash' => hash('sha256', json_encode([
                'draw_count' => 1,
                'gacha_id' => self::GACHA_ID,
            ], JSON_THROW_ON_ERROR)),
            'status' => 'processing',
            'created_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        try {
            $this->draw($user, 1, $inFlightKey);
            self::fail('An in-flight QA idempotency key must fail closed.');
        } catch (V2DrawException $exception) {
            self::assertSame('IDEMPOTENCY_REQUEST_IN_PROGRESS', $exception->errorCode);
            self::assertSame(409, $exception->status);
            self::assertTrue($exception->retryable);
        }
        self::assertSame($expected, (int) DB::table('qa_draw_plan_items')->value('consumed_count'));
        self::assertSame($expected, DB::table('draw_results')->count());
    }

    public function test_active_invalid_qa_never_falls_back_and_fails_before_domain_changes(): void
    {
        [$user, $owner] = $this->fixture(randomValues: [999_999]);
        $this->enableMode($owner, $user);
        $wallet = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');
        $pointRows = DB::table('point_ledger_entries')->count();
        $auditRows = DB::table('audit_logs')->count();

        try {
            $this->draw($user, 1, 'qa-missing-plan-key-0001');
            self::fail('Active QA Mode without a Plan must fail.');
        } catch (V2DrawException $exception) {
            self::assertSame('QA_CONFIGURATION_INVALID', $exception->errorCode);
            self::assertSame(422, $exception->status);
        }
        self::assertDatabaseCount('draw_requests', 0);
        self::assertDatabaseCount('draw_results', 0);
        self::assertDatabaseCount('user_prizes', 0);
        self::assertDatabaseCount('qa_draw_executions', 0);
        self::assertSame(0, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame($wallet, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));
        self::assertSame($pointRows, DB::table('point_ledger_entries')->count());
        self::assertGreaterThan($auditRows, DB::table('audit_logs')->count());
        self::assertDatabaseHas('audit_logs', ['action_code' => 'qa.draw.failed']);
    }

    public function test_expired_active_plan_fails_closed_and_is_completed_after_draw_rollback(): void
    {
        [$user, $owner] = $this->fixture();
        app(V2QaDrawAdminService::class)->saveMode(
            $this->adminContext($owner),
            $user->public_id,
            'Expiry transition verification',
            null,
            now()->addHours(4)->toIso8601String()
        );
        $plan = $this->qaPlan($owner, $user, [
            $this->item(self::PRIZE_A_ID, 1, 1),
        ]);
        DB::table('qa_draw_plans')
            ->where('public_id', $plan['id'])
            ->update(['ends_at' => now()->addHour()]);
        CarbonImmutable::setTestNow(now()->addHours(2));

        try {
            $this->draw($user, 1, 'qa-expired-plan-key-0001');
            self::fail('An expired active QA Plan must fail closed.');
        } catch (V2DrawException $exception) {
            self::assertSame('QA_CONFIGURATION_INVALID', $exception->errorCode);
        }

        self::assertDatabaseHas('qa_draw_plans', [
            'public_id' => $plan['id'],
            'status' => 'completed',
        ]);
        self::assertDatabaseCount('draw_requests', 0);
        self::assertDatabaseCount('qa_draw_executions', 0);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'qa.plan.completed']);
    }

    public function test_inventory_failure_rolls_back_plan_point_draw_and_execution(): void
    {
        [$user, $owner] = $this->fixture(totalCount: 1_000);
        $this->enableMode($owner, $user);
        $this->qaPlan($owner, $user, [
            $this->item(self::PRIZE_S_ID, 100, 1),
        ]);
        DB::table('prize_inventories as inventory')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'inventory.gacha_version_prize_id'
            )
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('prize.public_id', self::PRIZE_S_ID)
            ->update(['initial_quantity' => 10]);
        $wallet = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');

        try {
            $this->draw($user, 100, 'qa-inventory-failure-key');
            self::fail('QA Inventory shortage must fail.');
        } catch (V2DrawException $exception) {
            self::assertSame('QA_CONFIGURATION_INVALID', $exception->errorCode);
        }
        self::assertDatabaseCount('draw_requests', 0);
        self::assertDatabaseCount('qa_draw_executions', 0);
        self::assertSame(0, (int) DB::table('qa_draw_plan_items')->value('consumed_count'));
        self::assertSame(0, (int) DB::table('prize_inventories')->sum('won_count'));
        self::assertSame($wallet, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));
    }

    public function test_second_result_chunk_failure_rolls_back_the_entire_qa_draw(): void
    {
        [$user, $owner] = $this->fixture(
            randomValues: [1],
            freePoints: 200_000,
            totalCount: 2_000
        );
        $this->enableMode($owner, $user);
        $this->qaPlan($owner, $user, [
            $this->item(self::PRIZE_A_ID, 1_000, 1),
        ]);
        $wallet = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');
        $pointRows = DB::table('point_ledger_entries')->count();
        $outboxRows = DB::table('outbox_messages')->count();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fail_qa_draw_second_chunk()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.request_sequence = 251 THEN
                    RAISE EXCEPTION 'qa draw test chunk failure';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fail_qa_draw_second_chunk
            BEFORE INSERT ON draw_results
            FOR EACH ROW
            EXECUTE FUNCTION fail_qa_draw_second_chunk();
            SQL);

        try {
            $this->draw($user, 1_000, 'qa-second-chunk-failure-key');
            self::fail('The second QA result chunk must fail for this test.');
        } catch (V2DrawException $exception) {
            self::assertSame('DRAW_INTERNAL_ERROR', $exception->errorCode);
            self::assertSame(500, $exception->status);
        }

        self::assertDatabaseCount('draw_requests', 0);
        self::assertDatabaseCount('draw_results', 0);
        self::assertDatabaseCount('user_prizes', 0);
        self::assertDatabaseCount('qa_draw_executions', 0);
        self::assertSame(0, (int) DB::table('qa_draw_plan_items')->value('consumed_count'));
        self::assertSame(0, (int) DB::table('prize_inventories')->sum('won_count'));
        self::assertSame(0, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame($wallet, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));
        self::assertSame($pointRows, DB::table('point_ledger_entries')->count());
        self::assertSame($outboxRows, DB::table('outbox_messages')->count());
        self::assertDatabaseHas('audit_logs', ['action_code' => 'qa.draw.failed']);
    }

    public function test_qa_user_prize_remains_exchangeable_and_qa_execution_is_owner_readable(): void
    {
        [$user, $owner] = $this->fixture();
        $this->enableMode($owner, $user);
        $this->qaPlan($owner, $user, [
            $this->item(self::PRIZE_A_ID, 1, 1),
        ]);
        $response = $this->draw($user, 1, 'qa-exchange-shipping-key-0001');
        $userPrize = DB::table('user_prizes')->value('public_id');
        $exchange = app(V2PrizeShippingService::class)->exchange(
            $user,
            [$userPrize],
            'qa-prize-exchange-key-0001',
            $this->requestId()
        );
        self::assertSame(2_000, $exchange['exchange_point_total']);
        self::assertDatabaseHas('user_prizes', [
            'public_id' => $userPrize,
            'status' => 'converted',
        ]);

        $admin = app(V2QaDrawAdminService::class);
        $context = $this->adminContext($owner);
        $list = $admin->executions($context, ['draw_request_id' => $response['id']]);
        self::assertCount(1, $list['items']);
        $detail = $admin->execution(
            $context,
            $list['items'][0]['id']
        );
        self::assertSame($response['id'], $detail['draw_request_id']);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'qa.execution.read']);
    }

    /**
     * @param list<int> $randomValues
     * @return array{User, Admin}
     */
    private function fixture(
        array $randomValues = [5_000],
        int $freePoints = 1_000_000,
        int $totalCount = 5_000,
        ?object $randomCounter = null
    ): array {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = $totalCount;
        foreach ($fixture['gacha_prizes'] as &$relation) {
            $relation['initial_inventory'] = $totalCount;
        }
        unset($relation);
        app(V2CatalogFixtureImporter::class)->import($fixture);

        $user = User::query()->create([
            'email_display' => 'qa-user-'.Str::uuid().'@example.test',
            'email_normalized' => 'qa-user-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        app(V2PointService::class)->grantFree(
            $user->id,
            $freePoints,
            now()->addYear(),
            'qa-draw-fixture-points-'.Str::uuid()
        );
        $index = 0;
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(static function () use (
                &$index,
                $randomValues,
                $randomCounter
            ): int {
                $value = $randomValues[$index % count($randomValues)];
                $index++;
                if ($randomCounter !== null) {
                    $randomCounter->count++;
                }

                return $value;
            })
        );

        return [$user, $this->admin(V2AdminRole::Owner)];
    }

    private function admin(V2AdminRole $role): Admin
    {
        return Admin::query()->create([
            'email_display' => 'qa-admin-'.Str::uuid().'@example.test',
            'email_normalized' => 'qa-admin-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'role' => $role,
            'state' => V2AdminState::Active,
        ]);
    }

    private function enableMode(Admin $owner, User $user): array
    {
        return app(V2QaDrawAdminService::class)->saveMode(
            $this->adminContext($owner),
            $user->public_id,
            'QA release verification',
            null,
            now()->addHours(2)->toIso8601String()
        );
    }

    /** @param list<array<string, mixed>> $items */
    private function qaPlan(Admin $owner, User $user, array $items): array
    {
        return app(V2QaDrawAdminService::class)->createPlan(
            $this->adminContext($owner),
            $user->public_id,
            self::GACHA_ID,
            'QA deterministic plan',
            'QA release verification',
            null,
            now()->addHours(2)->toIso8601String(),
            $items
        );
    }

    private function adminContext(Admin $admin): V2AdminAuthorizationContext
    {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $this->insertAdminSession($admin, $hash);

        return new V2AdminAuthorizationContext(
            (int) $admin->id,
            $admin->public_id,
            $admin->role,
            $hash,
            app(\App\Domain\Audit\V2\Services\V2AuditHasher::class)
                ->correlation($hash),
            $this->requestId()
        );
    }

    private function insertAdminSession(Admin $admin, string $hash): void
    {
        DB::table('admin_sessions')->insert([
            'session_id_hash' => $hash,
            'admin_id' => $admin->id,
            'mfa_verified_at' => now(),
            'requires_mfa_enrollment' => false,
            'created_at' => now(),
            'last_activity_at' => now(),
            'idle_expires_at' => now()->addMinutes(15),
            'absolute_expires_at' => now()->addHours(8),
            'revoked_at' => null,
        ]);
    }

    private function adminRequest(Admin $admin, string $path): Request
    {
        $raw = bin2hex(random_bytes(32));
        $this->insertAdminSession($admin, hash('sha256', $raw));
        $request = Request::create('/'.$path);
        $request->cookies->set(
            '__Host-oripa_admin_session',
            $raw
        );
        $request->headers->set('Accept', 'application/json');

        return $request;
    }

    /** @return array<string, mixed> */
    private function item(
        string $prizeId,
        int $quantity,
        int $sortOrder,
        ?string $imageId = null,
        ?string $videoId = null
    ): array {
        return [
            'prize_id' => $prizeId,
            'quantity' => $quantity,
            'sort_order' => $sortOrder,
            'fixed_image_asset_id' => $imageId,
            'fixed_video_asset_id' => $videoId,
        ];
    }

    /** @return array<string, mixed> */
    private function draw(User $user, int $count, string $key): array
    {
        return app(V2DrawService::class)->create(
            $user,
            self::GACHA_ID,
            $count,
            $key,
            $this->requestId()
        );
    }

    private function requestId(): string
    {
        return (string) Str::uuid7();
    }
}
