<?php

namespace Tests\V2;

use App\Domain\Catalog\Services\V2CatalogFixtureImporter;
use App\Domain\Draw\Exceptions\V2DrawException;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use App\Domain\Draw\Services\V2DrawService;
use App\Domain\Identity\Enums\V2UserState;
use App\Domain\Identity\Services\V2PasswordPolicy;
use App\Domain\Point\Services\V2PointService;
use App\Models\V2\DrawResult;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class DrawVerticalSliceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        CarbonImmutable::setTestNow('2026-07-29T00:00:00Z');
        config([
            'cache.default' => 'array',
            'v2_audit.active_hmac_key_version' => 'v1',
            'v2_audit.hmac_keys.v1' => 'base64:'.base64_encode(str_repeat('d', 32)),
            'v2_audit.business_timezone' => 'Asia/Tokyo',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::rollBack();
        parent::tearDown();
    }

    public function test_draw_schema_is_separated_strict_and_append_only(): void
    {
        foreach ([
            'gacha_draw_states',
            'prize_inventories',
            'draw_requests',
            'draw_results',
            'user_prizes',
            'payment_adjustment_prize_actions',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing Draw table: {$table}");
            self::assertFalse(Schema::hasColumn($table, 'tenant_id'));
        }
        self::assertFalse(Schema::hasColumn('draw_results', 'individual_ppm'));
        self::assertFalse(Schema::hasColumn('user_prizes', 'shipping_status'));
        self::assertTrue(Schema::hasColumn('draw_requests', 'public_id'));

        [$user] = $this->fixture([1]);
        $response = $this->draw($user, 1, 'immutable-draw-key-0001');
        $result = DrawResult::query()->where('draw_request_id', $this->requestId($response))->firstOrFail();
        try {
            $result->forceFill(['point_back_amount' => 999])->save();
            self::fail('Draw Result update must fail.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        try {
            DB::table('draw_results')->where('id', $result->id)->delete();
            self::fail('Draw Result delete must fail.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_all_allowed_counts_persist_ordered_results_and_compact_bulk_response(): void
    {
        [$user] = $this->fixture(
            [5_000, 50_000, 150_000, 999_999],
            dailyDrawLimit: 1_116,
            allowedDrawCounts: [1, 5, 10, 100, 1000]
        );
        $expected = 0;
        foreach ([1, 5, 10, 100, 1000] as $count) {
            $response = $this->draw($user, $count, "allowed-count-{$count}-key");
            $expected += $count;
            self::assertSame($count, $response['requested_count']);
            self::assertSame($count, $response['executed_count']);
            self::assertSame(100 * $count, $response['point_cost_total']);
            self::assertSame($count < 100, array_key_exists('results', $response));
            self::assertLessThanOrEqual(20, count($response['high_rank_results']));
            self::assertSame(
                $count,
                array_sum(array_column($response['rank_counts'], 'count'))
                    + $this->pointBackCount($response)
            );
        }

        self::assertSame($expected, DB::table('draw_results')->where('user_id', $user->id)->count());
        self::assertSame(
            range(1, $expected),
            DB::table('draw_results')
                ->where('user_id', $user->id)
                ->orderBy('draw_sequence_number')
                ->pluck('draw_sequence_number')
                ->map(static fn ($value): int => (int) $value)
                ->all()
        );
        self::assertSame(
            DB::table('draw_results')->where('user_id', $user->id)
                ->where('result_type', 'prize')->count(),
            DB::table('user_prizes')->where('user_id', $user->id)->count()
        );
        $this->expectDrawFailure(
            fn () => $this->draw($user, 1, 'allowed-count-over-limit-key'),
            'DAILY_DRAW_LIMIT_EXCEEDED'
        );
    }

    public function test_successful_prize_draw_moves_operational_inventory_atomically(): void
    {
        [$user] = $this->fixture([1], totalCount: 100);
        $inventoryBefore = DB::table('prize_inventories')
            ->orderBy('id')
            ->firstOrFail();
        $stateBefore = DB::table('gacha_draw_states')->firstOrFail();

        $response = $this->draw($user, 1, 'operational-inventory-draw-key');

        $inventoryAfter = DB::table('prize_inventories')
            ->where('id', $inventoryBefore->id)
            ->firstOrFail();
        $stateAfter = DB::table('gacha_draw_states')->firstOrFail();
        self::assertSame(1, $response['executed_count']);
        self::assertSame(
            (int) $inventoryBefore->awarded_count + 1,
            (int) $inventoryAfter->awarded_count
        );
        self::assertSame(
            (int) $inventoryBefore->available_quantity - 1,
            (int) $inventoryAfter->available_quantity
        );
        self::assertSame(
            (int) $inventoryAfter->total_quantity,
            (int) $inventoryAfter->awarded_count
                + (int) $inventoryAfter->available_quantity
                + (int) $inventoryAfter->withdrawn_quantity
        );
        self::assertSame(
            (int) $stateBefore->sold_count + 1,
            (int) $stateAfter->sold_count
        );
    }

    public function test_locked_remaining_inventory_is_the_dynamic_integer_weight(): void
    {
        [$user] = $this->fixture(
            [1],
            totalCount: 5,
            allowedDrawCounts: [1, 5]
        );
        $inventories = DB::table('prize_inventories')
            ->orderBy('id')
            ->get(['id', 'gacha_version_prize_id']);
        DB::table('prize_inventories')->where('id', $inventories[0]->id)->update([
            'total_quantity' => 2,
            'awarded_count' => 0,
            'available_quantity' => 2,
            'withdrawn_quantity' => 0,
        ]);
        DB::table('prize_inventories')->where('id', $inventories[1]->id)->update([
            'total_quantity' => 3,
            'awarded_count' => 0,
            'available_quantity' => 3,
            'withdrawn_quantity' => 0,
        ]);
        $tickets = [2, 2, 1, 2, 1];
        $bounds = [];
        $index = 0;
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static function (int $minimum, int $maximum) use (
                    &$bounds,
                    &$index,
                    $tickets
                ): int {
                    $bounds[] = [$minimum, $maximum];

                    return $tickets[$index++];
                }
            )
        );

        $response = $this->draw($user, 5, 'dynamic-inventory-weight-key');
        $selected = DB::table('draw_results')
            ->orderBy('request_sequence')
            ->pluck('gacha_version_prize_id')
            ->map(static fn ($value): int => (int) $value)
            ->all();

        self::assertSame([[1, 5], [1, 4], [1, 3], [1, 2], [1, 1]], $bounds);
        self::assertSame([
            (int) $inventories[0]->gacha_version_prize_id,
            (int) $inventories[1]->gacha_version_prize_id,
            (int) $inventories[0]->gacha_version_prize_id,
            (int) $inventories[1]->gacha_version_prize_id,
            (int) $inventories[1]->gacha_version_prize_id,
        ], $selected);
        self::assertSame(5, $response['executed_count']);
        self::assertSame(0, $response['point_back_total']);
        self::assertSame(5, DB::table('draw_results')->where('result_type', 'prize')->count());
        self::assertSame(0, DB::table('draw_results')->where('result_type', 'point_back')->count());
        self::assertSame(0, (int) DB::table('prize_inventories')->sum('available_quantity'));
        self::assertSame(5, (int) DB::table('prize_inventories')->sum('awarded_count'));
    }

    public function test_first_time_audience_uses_registration_age_not_draw_history(): void
    {
        [$user] = $this->fixture([5_000], audienceCode: 'first_time_users');
        self::assertSame(
            1,
            $this->draw($user, 1, 'audience-first-time-user-key')['executed_count']
        );
        self::assertSame(
            1,
            $this->draw($user, 1, 'audience-draw-history-ignored-key')['executed_count']
        );
        DB::table('users')->where('id', $user->id)->update([
            'created_at' => now()->subDays(7)->subSecond(),
        ]);
        $this->expectDrawFailure(
            fn () => $this->draw($user, 1, 'audience-registration-expired-key'),
            'GACHA_AUDIENCE_NOT_ELIGIBLE'
        );
    }

    public function test_first_time_audience_includes_exact_seven_day_boundary(): void
    {
        [$user] = $this->fixture([5_000], audienceCode: 'first_time_users');
        DB::table('users')->where('id', $user->id)->update([
            'created_at' => now()->subDays(7),
        ]);
        self::assertSame(
            1,
            $this->draw($user, 1, 'audience-seven-day-boundary-key')['executed_count']
        );
    }

    public function test_line_audience_requires_linked_identity_and_confirmed_friendship(): void
    {
        [$lineUser] = $this->fixture([5_000], audienceCode: 'line_users');
        $this->expectDrawFailure(
            fn () => $this->draw($lineUser, 1, 'audience-line-unlinked-key'),
            'GACHA_AUDIENCE_NOT_ELIGIBLE'
        );
        $subjectHash = hash('sha256', 'line-user-'.$lineUser->public_id);
        DB::table('external_identity_accounts')->insert([
            'public_id' => (string) Str::uuid7(),
            'user_id' => $lineUser->id,
            'provider' => 'line',
            'issuer' => 'https://access.line.me',
            'subject_hash' => $subjectHash,
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->expectDrawFailure(
            fn () => $this->draw($lineUser, 1, 'audience-line-not-friend-key'),
            'GACHA_AUDIENCE_NOT_ELIGIBLE'
        );
        DB::table('line_friendships')->insert([
            'public_id' => (string) Str::uuid7(),
            'subject_hash' => $subjectHash,
            'user_id' => $lineUser->id,
            'status' => 'friend',
            'followed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::assertSame(
            1,
            $this->draw($lineUser, 1, 'audience-line-confirmed-key')['executed_count']
        );
    }

    public function test_daily_limit_uses_jst_day_and_rejection_is_atomic(): void
    {
        CarbonImmutable::setTestNow('2026-07-29T14:59:59Z');
        [$user] = $this->fixture([5_000], dailyDrawLimit: 1);
        $this->draw($user, 1, 'daily-before-jst-midnight-key');
        $completedAt = CarbonImmutable::parse(
            DB::table('draw_requests')->where('user_id', $user->id)->value('completed_at')
        );
        self::assertSame(
            '2026-07-29T14:59:59+00:00',
            $completedAt->utc()->toIso8601String()
        );
        $dayStart = CarbonImmutable::parse('2026-07-29T00:00:00', 'Asia/Tokyo')->utc();
        $dayEnd = $dayStart->addDay();
        self::assertSame(1, (int) DB::table('draw_requests as request')
            ->join('gacha_draw_states as state', 'state.id', '=', 'request.gacha_draw_state_id')
            ->where('request.user_id', $user->id)
            ->where('request.status', 'completed')
            ->where('request.is_qa_draw', false)
            ->whereRaw(
                'request.completed_at >= ?::timestamptz',
                [$dayStart->toIso8601String()]
            )
            ->whereRaw(
                'request.completed_at < ?::timestamptz',
                [$dayEnd->toIso8601String()]
            )
            ->sum('request.executed_count'));

        $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
        $soldCount = (int) DB::table('gacha_draw_states')->value('sold_count');
        $inventoryWon = (int) DB::table('prize_inventories')->sum('awarded_count');
        $historyCount = DB::table('draw_results')->count();
        $this->expectDrawFailure(
            fn () => $this->draw($user, 1, 'daily-same-jst-day-key'),
            'DAILY_DRAW_LIMIT_EXCEEDED'
        );
        self::assertSame($wallet->paid_balance, DB::table('wallets')->where('user_id', $user->id)
            ->value('paid_balance'));
        self::assertSame($wallet->free_balance, DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance'));
        self::assertSame($soldCount, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame($inventoryWon, (int) DB::table('prize_inventories')->sum('awarded_count'));
        self::assertSame($historyCount, DB::table('draw_results')->count());

        CarbonImmutable::setTestNow('2026-07-29T15:00:00Z');
        self::assertSame(
            1,
            $this->draw($user, 1, 'daily-after-jst-midnight-key')['executed_count']
        );
    }

    public function test_probability_stage_guarantee_and_point_back_do_not_select_results(): void
    {
        [$user] = $this->fixture([999_999, 250_000, 5_000]);
        DB::table('gacha_draw_states')->update(['sold_count' => 499]);
        $response = $this->draw($user, 5, 'stage-boundary-key-0001');
        $requestId = $this->requestId($response);
        $rows = DB::table('draw_results as dr')
            ->join('catalog_probability_stages as ps', 'ps.id', '=', 'dr.probability_stage_id')
            ->where('dr.draw_request_id', $requestId)
            ->orderBy('dr.request_sequence')
            ->get(['ps.code', 'dr.result_type', 'dr.point_back_amount']);

        self::assertSame('stage-1', $rows[0]->code);
        self::assertSame('stage-1', $rows[1]->code);
        self::assertSame('prize', $rows[0]->result_type);
        self::assertSame('prize', $rows[1]->result_type);
        self::assertSame(0, (int) $rows[1]->point_back_amount);
        self::assertSame('prize', $rows[2]->result_type);
        self::assertSame(0, $response['point_back_total']);
    }

    public function test_point_consumption_prize_results_and_reconciliation_are_consistent(): void
    {
        [$user] = $this->fixture([150_000], allowedDrawCounts: [1, 5, 10, 100]);
        $response = $this->draw($user, 100, 'point-back-key-0001');

        self::assertSame(10_000, $response['point_cost_total']);
        self::assertSame(0, $response['point_back_total']);
        self::assertSame(990_000, $response['wallet_after']['total_points']);
        self::assertSame(0, DB::table('draw_results')
            ->where('draw_request_id', $this->requestId($response))
            ->where('result_type', 'point_back')->count());
        self::assertSame(100, DB::table('draw_results')
            ->where('draw_request_id', $this->requestId($response))
            ->where('result_type', 'prize')->count());
        self::assertSame(100, DB::table('user_prizes')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('point_operations')
            ->where('source_type', 'draw')
            ->where('operation_type', 'free_grant')->count());
    }

    public function test_idempotent_replay_returns_canonical_result_and_conflict_is_rejected(): void
    {
        [$user] = $this->fixture([5_000], dailyDrawLimit: 10);
        $first = $this->draw($user, 10, 'replay-draw-key-0001');
        $second = $this->draw($user, 10, 'replay-draw-key-0001');

        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(10, DB::table('draw_results')->count());
        self::assertSame(1, DB::table('draw_requests')->count());
        try {
            $this->draw($user, 5, 'replay-draw-key-0001');
            self::fail('Different request must conflict.');
        } catch (V2DrawException $exception) {
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
            self::assertSame(409, $exception->status);
        }
        self::assertDatabaseHas('audit_logs', ['action_code' => 'draw.idempotent_replay']);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'draw.failed']);
        $this->expectDrawFailure(
            fn () => $this->draw($user, 1, 'replay-does-not-double-count-key'),
            'DAILY_DRAW_LIMIT_EXCEEDED'
        );
    }

    public function test_remaining_count_is_executed_atomically_and_replayed_canonically(): void
    {
        [$user] = $this->fixture(
            [50_000],
            totalCount: 1_000,
            allowedDrawCounts: [1, 100, 1000]
        );
        DB::table('prize_inventories')
            ->orderBy('id')
            ->limit(1)
            ->update([
                'available_quantity' => 0,
                'withdrawn_quantity' => 100,
            ]);
        DB::table('gacha_draw_states')->update(['sold_count' => 100]);

        $first = $this->draw($user, 1000, 'partial-remaining-draw-key');
        $replay = $this->draw($user, 1000, 'partial-remaining-draw-key');

        self::assertSame(1000, $first['requested_count']);
        self::assertSame(900, $first['executed_count']);
        self::assertSame(90_000, $first['point_cost_total']);
        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame(1000, $replay['requested_count']);
        self::assertSame(900, $replay['executed_count']);
        self::assertSame($first['id'], $replay['id']);
        self::assertSame(900, DB::table('draw_results')->count());
        self::assertSame(900, DB::table('draw_results')->where('result_type', 'prize')->count());
        self::assertSame(0, DB::table('draw_results')->where('result_type', 'point_back')->count());
        self::assertSame(900, DB::table('user_prizes')->count());
        self::assertSame(0, $first['point_back_total']);
        self::assertSame(910_000, $first['wallet_after']['total_points']);
        self::assertSame(0, (int) DB::table('prize_inventories')->sum('available_quantity'));
        self::assertSame(900, (int) DB::table('prize_inventories')->sum('awarded_count'));
        self::assertSame(
            900,
            DB::table('draw_requests')->where('status', 'completed')->sum('executed_count')
        );
        self::assertSame(1, DB::table('draw_requests')->count());
        self::assertSame(1_000, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame('sold_out', DB::table('gacha_draw_states')->value('status'));
        self::assertEquals($first['prize_counts'], $replay['prize_counts']);
    }

    public function test_legacy_point_back_success_replays_saved_canonical_response_without_mutation(): void
    {
        [$user] = $this->fixture([50_000], totalCount: 10);
        $key = 'legacy-point-back-replay-key';
        $first = $this->draw($user, 1, $key);
        $request = DB::table('draw_requests')->where('public_id', $first['id'])->firstOrFail();
        $legacy = $first;
        $legacy['point_back_total'] = 100;
        $legacy['results'][0]['result_type'] = 'point_back';
        $legacy['results'][0]['rank'] = null;
        $legacy['results'][0]['prize'] = null;
        $legacy['results'][0]['point_back'] = ['amount' => 100, 'point_type' => 'free'];
        $encoded = json_encode($legacy, JSON_THROW_ON_ERROR);
        DB::table('draw_requests')->where('id', $request->id)->update([
            'point_back_total' => 100,
            'response_data' => $encoded,
        ]);
        DB::table('idempotency_records')
            ->where('resource_public_id', $request->public_id)
            ->update(['response_data' => $encoded]);
        $before = [
            'wallet' => DB::table('wallets')->where('user_id', $user->id)->first(),
            'inventory' => DB::table('prize_inventories')->orderBy('id')->get()->toArray(),
            'sold_count' => DB::table('gacha_draw_states')->value('sold_count'),
            'draw_results' => DB::table('draw_results')->count(),
            'user_prizes' => DB::table('user_prizes')->count(),
        ];

        $replay = $this->draw($user, 1, $key);

        $legacy['idempotent_replay'] = true;
        self::assertEquals($legacy, $replay);
        self::assertEquals(
            $before['wallet'],
            DB::table('wallets')->where('user_id', $user->id)->first()
        );
        self::assertEquals(
            $before['inventory'],
            DB::table('prize_inventories')->orderBy('id')->get()->toArray()
        );
        self::assertSame($before['sold_count'], DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame($before['draw_results'], DB::table('draw_results')->count());
        self::assertSame($before['user_prizes'], DB::table('user_prizes')->count());
    }

    public function test_daily_limit_does_not_trigger_partial_execution(): void
    {
        [$user] = $this->fixture(
            [150_000],
            totalCount: 1_000,
            dailyDrawLimit: 900,
            allowedDrawCounts: [1, 100, 1000]
        );
        DB::table('gacha_draw_states')->update(['sold_count' => 100]);

        $this->expectDrawFailure(
            fn () => $this->draw($user, 1000, 'daily-limit-no-partial-key'),
            'DAILY_DRAW_LIMIT_EXCEEDED'
        );
        self::assertSame(0, DB::table('draw_requests')->count());
        self::assertSame(100, (int) DB::table('gacha_draw_states')->value('sold_count'));
    }

    public function test_point_sold_out_and_publication_fail_before_history(): void
    {
        [$user] = $this->fixture([5_000], freePoints: 99);
        $this->expectDrawFailure(
            fn () => $this->draw($user, 1, 'insufficient-point-key-0001'),
            'INSUFFICIENT_POINTS'
        );
        self::assertSame(0, DB::table('draw_results')->count());

        app(V2PointService::class)->grantFree(
            $user->id,
            100_000,
            now()->addYear(),
            'top-up-for-negative-cases'
        );
        DB::table('gacha_draw_states')->update([
            'sold_count' => 5_000,
            'status' => 'sold_out',
            'sold_out_at' => now(),
        ]);
        DB::table('prize_inventories')->update([
            'withdrawn_quantity' => DB::raw(
                'withdrawn_quantity + available_quantity'
            ),
            'available_quantity' => 0,
        ]);
        $this->expectDrawFailure(
            fn () => $this->draw($user, 5, 'insufficient-count-key-0001'),
            'GACHA_NOT_DRAWABLE'
        );
        DB::table('gacha_draw_states')->update([
            'sold_count' => 0,
            'status' => 'paused',
            'sold_out_at' => null,
        ]);
        $this->expectDrawFailure(
            fn () => $this->draw($user, 1, 'paused-draw-key-0001'),
            'GACHA_NOT_DRAWABLE'
        );
        self::assertSame(0, DB::table('draw_results')->count());
        self::assertSame(0, DB::table('user_prizes')->count());
    }

    public function test_chunk_failure_rolls_back_point_inventory_history_audit_and_outbox(): void
    {
        [$user] = $this->fixture(
            [999_999],
            allowedDrawCounts: [1, 5, 10, 100, 1000]
        );
        $walletBefore = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION v2_fail_draw_result_insert()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'synthetic Draw Result persistence failure';
            END;
            $$;
            CREATE TRIGGER draw_results_fail_insert
            BEFORE INSERT ON draw_results
            FOR EACH ROW EXECUTE FUNCTION v2_fail_draw_result_insert();
            SQL);
        $auditBefore = DB::table('audit_logs')->count();
        $outboxBefore = DB::table('outbox_messages')->count();

        $this->expectDrawFailure(
            fn () => $this->draw($user, 1000, 'rollback-chunk-key-0001'),
            'DRAW_INTERNAL_ERROR'
        );
        self::assertSame(0, DB::table('draw_requests')->count());
        self::assertSame(0, DB::table('draw_results')->count());
        self::assertSame(0, DB::table('user_prizes')->count());
        self::assertSame($walletBefore, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));
        self::assertSame($outboxBefore, DB::table('outbox_messages')->count());
        self::assertSame($auditBefore + 1, DB::table('audit_logs')->count());
        self::assertDatabaseHas('audit_logs', ['action_code' => 'draw.failed']);
    }

    public function test_response_audit_and_outbox_do_not_expose_internal_or_sensitive_fields(): void
    {
        [$user] = $this->fixture([5_000, 150_000]);
        $response = $this->draw($user, 10, 'public-boundary-key-0001');
        $serialized = json_encode($response, JSON_THROW_ON_ERROR);
        foreach ([
            'internal_id',
            'individual_ppm',
            'cost_price',
            'password',
            'session_id',
            'random_value',
            'storage_identifier',
        ] as $prohibited) {
            self::assertStringNotContainsString($prohibited, $serialized);
        }
        foreach ([
            'draw.started',
            'draw.point_consumed',
            'draw.inventory_changed',
            'draw.user_prizes_created',
            'draw.completed',
        ] as $event) {
            self::assertDatabaseHas('audit_logs', ['action_code' => $event]);
        }
        self::assertDatabaseHas('outbox_messages', [
            'event_type' => 'draw.completed',
            'aggregate_public_id' => $response['id'],
        ]);
    }

    public function test_http_contract_requires_user_realm_csrf_origin_json_and_idempotency(): void
    {
        [$user, $gachaId] = $this->fixture([5_000], audienceCode: 'first_time_users');
        config([
            'v2_identity.origins.user' => 'https://storefront.example.test',
        ]);
        Auth::guard('v2_user')->setUser($user);
        $csrf = str_repeat('a', 64);
        $response = $this
            ->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => 'http-draw-key-00000001',
                'X-Request-Id' => (string) Str::uuid7(),
            ])
            ->postJson("/api/v2/gachas/{$gachaId}/draws", ['draw_count' => 5])
            ->assertOk()
            ->assertJsonPath('requested_count', 5)
            ->assertJsonPath('status', 'completed');
        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control')
        );
        $drawRequestId = $response->json('id');

        DB::table('users')->where('id', $user->id)->update([
            'created_at' => now()->subDays(8),
        ]);

        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => 'http-audience-rejection-key',
            ])
            ->postJson("/api/v2/gachas/{$gachaId}/draws", ['draw_count' => 1])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'GACHA_AUDIENCE_NOT_ELIGIBLE');

        $drawRequestCount = DB::table('draw_requests')->count();
        $drawResultCount = DB::table('draw_results')->count();
        $this->getJson("/api/v2/draw-requests/{$drawRequestId}")
            ->assertOk()
            ->assertJsonPath('id', $drawRequestId);
        self::assertSame($drawRequestCount, DB::table('draw_requests')->count());
        self::assertSame($drawResultCount, DB::table('draw_results')->count());

        Auth::forgetGuards();
        $this->postJson(
            "/api/v2/gachas/{$gachaId}/draws",
            ['draw_count' => 1],
            ['Idempotency-Key' => 'unauthenticated-key-0001']
        )->assertUnauthorized();
    }

    public function test_http_contract_returns_typed_daily_limit_problem(): void
    {
        [$user, $gachaId] = $this->fixture([5_000], dailyDrawLimit: 5);
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        Auth::guard('v2_user')->setUser($user);
        $csrf = str_repeat('b', 64);
        $headers = [
            'Origin' => 'https://storefront.example.test',
            'Sec-Fetch-Site' => 'same-origin',
            'X-XSRF-TOKEN' => $csrf,
        ];
        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders($headers + ['Idempotency-Key' => 'http-daily-initial-key'])
            ->postJson("/api/v2/gachas/{$gachaId}/draws", ['draw_count' => 5])
            ->assertOk();

        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders($headers + ['Idempotency-Key' => 'http-daily-rejected-key'])
            ->postJson("/api/v2/gachas/{$gachaId}/draws", ['draw_count' => 1])
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'DAILY_DRAW_LIMIT_EXCEEDED');
    }

    public function test_http_contract_rejects_count_disabled_for_gacha(): void
    {
        [$user, $gachaId] = $this->fixture(
            [5_000],
            allowedDrawCounts: [1, 10, 100]
        );
        config(['v2_identity.origins.user' => 'https://storefront.example.test']);
        Auth::guard('v2_user')->setUser($user);
        $csrf = str_repeat('c', 64);

        $this->withCredentials()
            ->withServerVariables(['HTTPS' => 'on'])
            ->withUnencryptedCookie('__Host-oripa_user_xsrf', $csrf)
            ->withHeaders([
                'Origin' => 'https://storefront.example.test',
                'Sec-Fetch-Site' => 'same-origin',
                'X-XSRF-TOKEN' => $csrf,
                'Idempotency-Key' => 'http-gacha-disabled-count-key',
            ])
            ->postJson("/api/v2/gachas/{$gachaId}/draws", ['draw_count' => 5])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'INVALID_DRAW_REQUEST');

        self::assertSame(0, DB::table('draw_requests')->count());
        self::assertSame(0, DB::table('draw_results')->count());
    }

    public function test_single_bulk_performance_meets_merge_thresholds(): void
    {
        [$user] = $this->fixture(
            [150_000],
            freePoints: 2_000_000,
            totalCount: 10_000,
            allowedDrawCounts: [1, 5, 10, 100, 1000]
        );
        $active = false;
        $queryCount = 0;
        $queryTypes = [];
        DB::listen(static function ($query) use (&$active, &$queryCount, &$queryTypes): void {
            if ($active) {
                $queryCount++;
                $type = strtoupper((string) strtok(ltrim($query->sql), " \t\n"));
                $queryTypes[$type] = ($queryTypes[$type] ?? 0) + 1;
            }
        });
        $evidence = [];
        foreach ([100, 1000] as $count) {
            $durations = [];
            $queries = [];
            $sizes = [];
            $types = [];
            for ($run = 1; $run <= 5; $run++) {
                $queryCount = 0;
                $queryTypes = [];
                $started = hrtime(true);
                $active = true;
                $response = $this->draw(
                    $user,
                    $count,
                    "performance-{$count}-run-{$run}"
                );
                $active = false;
                $durations[] = (hrtime(true) - $started) / 1_000_000;
                $queries[] = $queryCount;
                $types[] = $queryTypes;
                $sizes[] = strlen(json_encode($response, JSON_THROW_ON_ERROR));
            }
            sort($durations);
            $evidence[(string) $count] = [
                'p50_ms' => round($durations[2], 3),
                'p95_ms' => round($durations[4], 3),
                'query_count_max' => max($queries),
                'query_types' => $types[array_search(max($queries), $queries, true)],
                'response_size_max' => max($sizes),
            ];
        }
        fwrite(STDOUT, "\nMIG-051_SINGLE_PERFORMANCE=".json_encode(
            $evidence,
            JSON_THROW_ON_ERROR
        )."\n");

        self::assertLessThanOrEqual(2_000, $evidence['1000']['p95_ms']);
        self::assertLessThanOrEqual(100, $evidence['1000']['query_count_max']);
        self::assertLessThanOrEqual(
            10,
            $evidence['1000']['query_count_max'] - $evidence['100']['query_count_max']
        );
        self::assertSame(
            $evidence['100']['query_types']['SELECT'],
            $evidence['1000']['query_types']['SELECT']
        );
        self::assertLessThan(100_000, $evidence['1000']['response_size_max']);
    }

    public function test_gacha_disabled_count_is_rejected_before_draw_mutation(): void
    {
        [$user] = $this->fixture(
            [5_000],
            allowedDrawCounts: [1, 10, 100]
        );
        $walletBefore = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');
        $soldBefore = (int) DB::table('gacha_draw_states')->value('sold_count');
        $inventoryBefore = (int) DB::table('prize_inventories')->sum('awarded_count');

        $this->expectDrawFailure(
            fn () => $this->draw($user, 5, 'gacha-disabled-count-key'),
            'INVALID_DRAW_REQUEST'
        );

        self::assertSame(0, DB::table('draw_requests')->count());
        self::assertSame(0, DB::table('draw_results')->count());
        self::assertSame(0, DB::table('user_prizes')->count());
        self::assertSame($walletBefore, (int) DB::table('wallets')
            ->where('user_id', $user->id)->value('free_balance'));
        self::assertSame($soldBefore, (int) DB::table('gacha_draw_states')->value('sold_count'));
        self::assertSame($inventoryBefore, (int) DB::table('prize_inventories')->sum('awarded_count'));

        $first = $this->draw($user, 10, 'gacha-enabled-count-key');
        $replay = $this->draw($user, 10, 'gacha-enabled-count-key');
        self::assertSame(10, $first['executed_count']);
        self::assertFalse($first['idempotent_replay']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame(1, DB::table('draw_requests')->count());
        self::assertSame(10, DB::table('draw_results')->count());
    }

    /**
     * @param list<int> $randomValues
     * @return array{User, string}
     */
    private function fixture(
        array $randomValues,
        int $freePoints = 1_000_000,
        int $totalCount = 5_000,
        string $audienceCode = 'all_users',
        int $dailyDrawLimit = 0,
        array $allowedDrawCounts = [1, 5, 10]
    ): array
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/Fixtures/catalog-alpha.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $fixture['gachas'][0]['sold_count'] = 0;
        $fixture['versions'][0]['total_count'] = $totalCount;
        $fixture['versions'][0]['audience_code'] = $audienceCode;
        $fixture['versions'][0]['daily_draw_limit'] = $dailyDrawLimit;
        $fixture['versions'][0]['allowed_draw_counts'] = $allowedDrawCounts;
        $inventoryQuantities = [intdiv($totalCount, 10), $totalCount - intdiv($totalCount, 10)];
        foreach ($fixture['gacha_prizes'] as $index => &$relation) {
            $relation['initial_inventory'] = $inventoryQuantities[$index] ?? 0;
        }
        unset($relation);
        app(V2CatalogFixtureImporter::class)->import($fixture);

        $user = User::query()->create([
            'email_display' => 'draw-'.Str::uuid().'@example.test',
            'email_normalized' => 'draw-'.Str::uuid().'@example.test',
            'email_verified_at' => now(),
            'password_hash' => app(V2PasswordPolicy::class)->hash('valid password'),
            'state' => V2UserState::Active,
        ]);
        if ($freePoints > 0) {
            app(V2PointService::class)->grantFree(
                $user->id,
                $freePoints,
                now()->addYear(),
                'draw-fixture-points-'.Str::uuid()
            );
        } else {
            app(V2PointService::class)->initializeWallet($user->id);
        }
        $index = 0;
        $this->app->instance(
            V2CryptographicRandomSource::class,
            new V2CryptographicRandomSource(
                static function (int $minimum, int $maximum) use (
                    &$index,
                    $randomValues
                ): int {
                    $value = $randomValues[$index % count($randomValues)];
                    $index++;

                    return max($minimum, min($maximum, $value));
                }
            )
        );

        return [$user, $fixture['gachas'][0]['public_id']];
    }

    /**
     * @return array<string, mixed>
     */
    private function draw(User $user, int $count, string $key): array
    {
        return app(V2DrawService::class)->create(
            $user,
            '0198a001-0000-7000-8000-000000000011',
            $count,
            $key,
            (string) Str::uuid7()
        );
    }

    private function requestId(array $response): int
    {
        return (int) DB::table('draw_requests')->where('public_id', $response['id'])->value('id');
    }

    private function pointBackCount(array $response): int
    {
        return (int) DB::table('draw_results')
            ->where('draw_request_id', $this->requestId($response))
            ->where('result_type', 'point_back')
            ->count();
    }

    private function expectDrawFailure(callable $callback, string $code): void
    {
        try {
            $callback();
            self::fail("Expected Draw failure: {$code}");
        } catch (V2DrawException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
