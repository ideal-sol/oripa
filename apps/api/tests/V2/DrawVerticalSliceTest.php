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
            dailyDrawLimit: 1_116
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
        $inventoryWon = (int) DB::table('prize_inventories')->sum('won_count');
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
        self::assertSame($inventoryWon, (int) DB::table('prize_inventories')->sum('won_count'));
        self::assertSame($historyCount, DB::table('draw_results')->count());

        CarbonImmutable::setTestNow('2026-07-29T15:00:00Z');
        self::assertSame(
            1,
            $this->draw($user, 1, 'daily-after-jst-midnight-key')['executed_count']
        );
    }

    public function test_stage_pointer_minimum_guarantee_and_point_back_follow_draw_order(): void
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
        self::assertSame('stage-2', $rows[1]->code);
        self::assertSame('prize', $rows[0]->result_type);
        self::assertSame('point_back', $rows[1]->result_type);
        self::assertSame(100, (int) $rows[1]->point_back_amount);
        self::assertSame('prize', $rows[2]->result_type);
    }

    public function test_point_consumption_point_back_and_reconciliation_are_consistent(): void
    {
        [$user] = $this->fixture([150_000]);
        $response = $this->draw($user, 100, 'point-back-key-0001');

        self::assertSame(10_000, $response['point_cost_total']);
        self::assertSame(10_000, $response['point_back_total']);
        self::assertSame(1_000_000, $response['wallet_after']['total_points']);
        self::assertSame(100, DB::table('draw_results')
            ->where('draw_request_id', $this->requestId($response))
            ->where('result_type', 'point_back')->count());
        self::assertSame(100, DB::table('point_operations')
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

    public function test_point_draw_count_inventory_and_publication_fail_before_history(): void
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
        DB::table('gacha_draw_states')->update(['sold_count' => 4_999]);
        $this->expectDrawFailure(
            fn () => $this->draw($user, 5, 'insufficient-count-key-0001'),
            'DRAW_COUNT_INSUFFICIENT'
        );
        DB::table('gacha_draw_states')->update(['sold_count' => 0, 'status' => 'paused']);
        $this->expectDrawFailure(
            fn () => $this->draw($user, 1, 'paused-draw-key-0001'),
            'GACHA_NOT_DRAWABLE'
        );
        self::assertSame(0, DB::table('draw_results')->count());
        self::assertSame(0, DB::table('user_prizes')->count());
    }

    public function test_chunk_failure_rolls_back_point_inventory_history_audit_and_outbox(): void
    {
        [$user] = $this->fixture([999_999]);
        $walletBefore = (int) DB::table('wallets')->where('user_id', $user->id)
            ->value('free_balance');
        $inventory = DB::table('prize_inventories as inventory')
            ->join(
                'catalog_gacha_version_prizes as relation',
                'relation.id',
                '=',
                'inventory.gacha_version_prize_id'
            )
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->where('prize.code', 'fixture-a-1')
            ->first(['inventory.id', 'inventory.initial_quantity']);
        DB::table('prize_inventories')->where('id', $inventory->id)->update([
            'won_count' => $inventory->initial_quantity,
        ]);
        $auditBefore = DB::table('audit_logs')->count();
        $outboxBefore = DB::table('outbox_messages')->count();

        $this->expectDrawFailure(
            fn () => $this->draw($user, 1000, 'rollback-chunk-key-0001'),
            'MINIMUM_GUARANTEE_UNAVAILABLE'
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

    public function test_single_bulk_performance_meets_merge_thresholds(): void
    {
        [$user] = $this->fixture([150_000], freePoints: 2_000_000, totalCount: 10_000);
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
        self::assertLessThan(100_000, $evidence['1000']['response_size_max']);
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
        int $dailyDrawLimit = 0
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
        foreach ($fixture['gacha_prizes'] as &$relation) {
            $relation['initial_inventory'] = $totalCount;
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
                static function () use (&$index, $randomValues): int {
                    $value = $randomValues[$index % count($randomValues)];
                    $index++;

                    return $value;
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
