<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Exceptions\BulkDrawConflictException;
use App\Domain\Gacha\Services\CryptographicRandomSource;
use App\Domain\Gacha\Exceptions\DrawException;
use App\Domain\Gacha\Services\DrawService;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Point\Exceptions\InsufficientPointsException;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaRank;
use App\Models\PointLot;
use App\Models\RankAsset;
use App\Models\User;
use App\Models\UserPrize;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DrawServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('existingDrawCountProvider')]
    public function test_existing_draw_counts_keep_sequential_results_and_single_point_consumption(int $drawCount): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 100,
            minimumGuaranteeValue: 0,
            totalCount: 100,
        );
        $this->createWalletWithPaidLot($user, 1_000);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $request = app(DrawService::class)->draw($user, $gacha, $drawCount, "characterization-{$drawCount}");

        $this->assertSame($drawCount, $request->results->count());
        $this->assertSame(range(1, $drawCount), $request->results->pluck('draw_sequence_number')->all());
        $this->assertSame($drawCount, $gacha->refresh()->sold_count);
        $this->assertSame(1_000 - (100 * $drawCount), $user->wallet->refresh()->paid_balance);
        $this->assertDatabaseCount('draw_requests', 1);
        $this->assertDatabaseCount('draw_results', $drawCount);
        $this->assertDatabaseCount('point_ledgers', 1);
    }

    public function test_it_draws_point_back_and_grants_minimum_guarantee_free_points(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100, minimumGuaranteeValue: 10);
        $this->createWalletWithPaidLot($user, 200);
        $this->publishSingleStage($gacha, $prize, prizePpm: 0, minimumGuaranteePpm: 1_000_000);

        $request = app(DrawService::class)->draw($user, $gacha, 2, 'draw-point-back');

        $this->assertSame(DrawRequestStatus::Completed, $request->status);
        $this->assertSame(2, $request->results->count());
        $this->assertSame(2, $gacha->refresh()->sold_count);
        $this->assertSame(0, $user->wallet->refresh()->paid_balance);
        $this->assertSame(20, $user->wallet->free_balance);
        $this->assertDatabaseCount('draw_results', 2);
        $this->assertDatabaseCount('user_prizes', 0);
        $this->assertDatabaseHas('draw_results', [
            'draw_sequence_number' => 1,
            'result_type' => DrawResultType::PointBack->value,
            'granted_point' => 10,
        ]);
        $this->assertDatabaseHas('draw_results', [
            'draw_sequence_number' => 2,
            'result_type' => DrawResultType::PointBack->value,
            'granted_point' => 10,
        ]);
        $this->assertSame(2, PointLot::query()->where('point_type', PointType::Free->value)->count());
    }

    public function test_bulk_draw_1000_persists_every_result_in_one_request(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 0,
            totalCount: 2_000,
            maxWinCount: 2_000,
        );
        $this->createWalletWithPaidLot($user, 1_000);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $request = app(DrawService::class)->draw($user, $gacha, 1_000, 'bulk-1000');

        $this->assertSame(DrawRequestStatus::Completed, $request->status);
        $this->assertTrue($request->isBulk());
        $this->assertNotEmpty($request->public_id);
        $this->assertSame(1_000, $request->results()->count());
        $this->assertSame(1_000, DrawResult::query()->distinct()->count('draw_sequence_number'));
        $this->assertSame(1_000, UserPrize::query()->count());
        $this->assertSame(1_000, UserPrize::query()->distinct()->count('draw_result_id'));
        $this->assertSame(1_000, $gacha->refresh()->sold_count);
        $this->assertSame(1_000, $prize->refresh()->won_count);
        $this->assertSame(0, $user->wallet->refresh()->paid_balance);
        $this->assertDatabaseCount('point_ledgers', 1);
    }

    public function test_bulk_draw_preserves_per_draw_random_order_and_persistence_semantics(): void
    {
        $randomValues = array_merge(...array_fill(0, 50, [100_000, 900_000]));
        $this->app->instance(
            CryptographicRandomSource::class,
            new class($randomValues) extends CryptographicRandomSource {
                public function __construct(private array $values)
                {
                }

                public function integer(int $minimum, int $maximum): int
                {
                    $value = array_shift($this->values);

                    if (! is_int($value) || $value < $minimum || $value > $maximum) {
                        throw new \RuntimeException('Deterministic random fixture was exhausted or invalid.');
                    }

                    return $value;
                }
            },
        );
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 2,
            totalCount: 100,
            maxWinCount: 100,
        );
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 500_000, minimumGuaranteePpm: 500_000);

        $request = app(DrawService::class)->draw($user, $gacha, 100, 'bulk-deterministic-semantics');
        $results = $request->results()->orderBy('draw_sequence_number')->get();

        $this->assertSame(range(1, 100), $results->pluck('draw_sequence_number')->all());
        $this->assertSame($randomValues, $results->pluck('random_value')->all());
        $this->assertSame(
            array_merge(...array_fill(0, 50, [DrawResultType::Prize, DrawResultType::PointBack])),
            $results->pluck('result_type')->all(),
        );
        $this->assertSame(50, UserPrize::query()->count());
        $this->assertSame(50, PointLot::query()
            ->where('source_type', PointLotSourceType::MinimumGuarantee)
            ->count());
        $this->assertSame(100, $gacha->refresh()->sold_count);
        $this->assertSame(50, $prize->refresh()->won_count);
        $this->assertSame(0, $user->wallet->refresh()->paid_balance);
        $this->assertSame(100, $user->wallet->free_balance);
    }

    public function test_bulk_draw_rebuilds_probability_range_after_prize_inventory_is_exhausted(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 1,
            totalCount: 100,
            maxWinCount: 10,
        );
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        app(DrawService::class)->draw($user, $gacha, 100, 'bulk-inventory-boundary');

        $this->assertSame(10, DrawResult::query()->where('result_type', DrawResultType::Prize->value)->count());
        $this->assertSame(90, DrawResult::query()->where('result_type', DrawResultType::PointBack->value)->count());
        $this->assertSame(10, $prize->refresh()->won_count);
        $this->assertSame(90, $user->wallet->refresh()->free_balance);
        $this->assertSame(90, PointLot::query()->where('point_type', PointType::Free->value)->count());
        $this->assertSame(90, PointLot::query()->where('point_type', PointType::Free->value)->sum('remaining_amount'));
        $this->assertSame(range(1, 90), \App\Models\PointLedger::query()
            ->where('point_type', PointType::Free->value)
            ->orderBy('id')
            ->pluck('balance_after')
            ->all());
    }

    public function test_bulk_draw_idempotent_replay_does_not_mutate_state_twice(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 0,
            totalCount: 500,
            maxWinCount: 500,
        );
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $first = app(DrawService::class)->draw($user, $gacha, 100, 'bulk-replay');
        $second = app(DrawService::class)->draw($user, $gacha, 100, 'bulk-replay');

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->idempotentReplay);
        $this->assertSame(100, $gacha->refresh()->sold_count);
        $this->assertSame(100, DrawResult::query()->count());
        $this->assertSame(100, UserPrize::query()->count());
        $this->assertDatabaseCount('point_ledgers', 1);
    }

    public function test_bulk_draw_rejects_reusing_key_for_a_different_request(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 0,
            totalCount: 2_000,
            maxWinCount: 2_000,
        );
        $this->createWalletWithPaidLot($user, 2_000);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);
        app(DrawService::class)->draw($user, $gacha, 100, 'bulk-conflict');

        $this->expectException(BulkDrawConflictException::class);

        app(DrawService::class)->draw($user, $gacha, 1_000, 'bulk-conflict');
    }

    public function test_bulk_draw_rejects_replay_after_the_idempotency_window_expires(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 0,
            totalCount: 500,
            maxWinCount: 500,
        );
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $request = app(DrawService::class)->draw($user, $gacha, 100, 'bulk-expired');
        $request->forceFill(['idempotency_expires_at' => now()->subSecond()])->save();

        try {
            app(DrawService::class)->draw($user, $gacha, 100, 'bulk-expired');
            $this->fail('The expired Idempotency-Key should not replay a prior bulk result.');
        } catch (BulkDrawConflictException) {
            $this->assertSame(100, $gacha->refresh()->sold_count);
            $this->assertSame(100, DrawResult::query()->count());
            $this->assertSame(100, UserPrize::query()->count());
            $this->assertDatabaseCount('draw_requests', 1);
            $this->assertDatabaseCount('point_ledgers', 1);
        }
    }

    public function test_bulk_draw_rejects_points_and_remaining_count_before_creating_results(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 10,
            minimumGuaranteeValue: 0,
            totalCount: 99,
            maxWinCount: 100,
        );
        $this->createWalletWithPaidLot($user, 999);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        try {
            app(DrawService::class)->draw($user, $gacha, 100, 'bulk-precheck');
            $this->fail('The bulk draw should have failed before execution.');
        } catch (DrawException) {
            $this->assertDatabaseCount('draw_requests', 0);
            $this->assertDatabaseCount('draw_results', 0);
            $this->assertDatabaseCount('user_prizes', 0);
            $this->assertSame(0, $gacha->refresh()->sold_count);
            $this->assertSame(999, $user->wallet->refresh()->paid_balance);
        }
    }

    public function test_bulk_draw_rejects_insufficient_points_before_creating_results(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 10,
            minimumGuaranteeValue: 0,
            totalCount: 500,
            maxWinCount: 500,
        );
        $this->createWalletWithPaidLot($user, 999);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        try {
            app(DrawService::class)->draw($user, $gacha, 100, 'bulk-insufficient-points');
            $this->fail('The bulk draw should have failed before execution.');
        } catch (InsufficientPointsException) {
            $this->assertDatabaseCount('draw_requests', 0);
            $this->assertDatabaseCount('draw_results', 0);
            $this->assertDatabaseCount('user_prizes', 0);
            $this->assertSame(0, $gacha->refresh()->sold_count);
            $this->assertSame(999, $user->wallet->refresh()->paid_balance);
        }
    }

    public function test_bulk_draw_rejects_a_committed_processing_record_for_the_same_key(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 0,
            totalCount: 500,
            maxWinCount: 500,
        );
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);
        DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 100,
            'idempotency_key' => 'bulk-processing',
            'request_hash' => hash('sha256', json_encode([
                'user_id' => $user->id,
                'gacha_id' => $gacha->id,
                'draw_count' => 100,
            ], JSON_THROW_ON_ERROR)),
            'idempotency_expires_at' => now()->addHour(),
            'status' => DrawRequestStatus::Processing,
            'consumed_point_total' => 100,
        ]);

        $this->expectException(BulkDrawConflictException::class);

        app(DrawService::class)->draw($user, $gacha, 100, 'bulk-processing');
    }

    public function test_bulk_draw_rolls_back_everything_when_a_result_insert_fails_midway(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 0,
            totalCount: 2_000,
            maxWinCount: 2_000,
        );
        $this->createWalletWithPaidLot($user, 1_000);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_bulk_draw_result() RETURNS trigger AS $$
            BEGIN
                IF NEW.draw_sequence_number = 251 THEN
                    RAISE EXCEPTION 'injected bulk draw failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER reject_bulk_draw_result_trigger
            BEFORE INSERT ON draw_results
            FOR EACH ROW EXECUTE FUNCTION reject_bulk_draw_result();
            SQL);

        try {
            app(DrawService::class)->draw($user, $gacha, 1_000, 'bulk-rollback');
            $this->fail('The injected draw failure should have aborted the transaction.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('draw_requests', 0);
            $this->assertDatabaseCount('draw_results', 0);
            $this->assertDatabaseCount('user_prizes', 0);
            $this->assertSame(0, $gacha->refresh()->sold_count);
            $this->assertSame(0, $prize->refresh()->won_count);
            $this->assertSame(1_000, $user->wallet->refresh()->paid_balance);
            $this->assertSame(1_000, PointLot::query()->sum('remaining_amount'));
        }
    }

    public function test_bulk_draw_applies_each_probability_stage_across_a_boundary(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 1,
            minimumGuaranteeValue: 0,
            totalCount: 10_200,
            soldCount: 9_950,
            maxWinCount: 200,
        );
        $this->createWalletWithPaidLot($user, 100);
        $version = $this->publishTwoStages($gacha, $prize);
        $stageIds = $version->stages()->orderBy('min_draw_number')->pluck('id')->all();

        app(DrawService::class)->draw($user, $gacha, 100, 'bulk-stage-boundary');

        $this->assertSame(49, DrawResult::query()->where('probability_version_stage_id', $stageIds[0])->count());
        $this->assertSame(51, DrawResult::query()->where('probability_version_stage_id', $stageIds[1])->count());
        $this->assertSame(range(9_951, 10_050), DrawResult::query()->orderBy('draw_sequence_number')->pluck('draw_sequence_number')->all());
    }

    public function test_bulk_draw_rejects_total_cost_outside_database_integer_range(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(
            price: 2_147_484,
            minimumGuaranteeValue: 0,
            totalCount: 1_000,
            maxWinCount: 1_000,
        );
        $this->createWalletWithPaidLot($user, 1);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $this->expectException(DrawException::class);

        app(DrawService::class)->draw($user, $gacha, 1_000, 'bulk-overflow');
    }

    public function test_it_draws_prize_updates_inventory_and_creates_user_prize(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100);
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $request = app(DrawService::class)->draw($user, $gacha, 1, 'draw-prize');

        $result = $request->results->first();

        $this->assertSame(DrawResultType::Prize, $result->result_type);
        $this->assertSame($prize->id, $result->prize_id);
        $this->assertSame(1, $prize->refresh()->won_count);
        $this->assertSame(1, $gacha->refresh()->sold_count);
        $this->assertSame(0, $user->wallet->refresh()->paid_balance);
        $this->assertDatabaseHas('user_prizes', [
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $result->id,
            'status' => 'stored',
        ]);
    }

    public function test_it_stores_random_rank_presentation_urls_for_prize_result(): void
    {
        [$user, $gacha, $prize, $rank] = $this->createDrawableFixture(price: 100);
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $imageAssets = [
            RankAsset::query()->create(['title' => 'S image 1', 'asset_type' => 'image', 'url' => 'https://example.test/s-1.png', 'is_active' => true]),
            RankAsset::query()->create(['title' => 'S image 2', 'asset_type' => 'image', 'url' => 'https://example.test/s-2.png', 'is_active' => true]),
        ];
        $videoAssets = [
            RankAsset::query()->create(['title' => 'S video 1', 'asset_type' => 'video', 'url' => 'https://example.test/s-1.mp4', 'is_active' => true]),
            RankAsset::query()->create(['title' => 'S video 2', 'asset_type' => 'video', 'url' => 'https://example.test/s-2.mp4', 'is_active' => true]),
        ];

        foreach ($imageAssets as $index => $asset) {
            $rank->rankImageAssets()->attach($asset->id, ['usage_type' => 'image', 'sort_order' => $index]);
        }

        foreach ($videoAssets as $index => $asset) {
            $rank->drawVideoAssets()->attach($asset->id, ['usage_type' => 'video', 'sort_order' => $index]);
        }

        $request = app(DrawService::class)->draw($user, $gacha, 1, 'draw-prize-presentation');
        $result = $request->results->first();

        $this->assertContains($result->selected_rank_image_url, ['https://example.test/s-1.png', 'https://example.test/s-2.png']);
        $this->assertContains($result->selected_draw_video_url, ['https://example.test/s-1.mp4', 'https://example.test/s-2.mp4']);
        $this->assertDatabaseHas('draw_results', [
            'id' => $result->id,
            'selected_rank_image_url' => $result->selected_rank_image_url,
            'selected_draw_video_url' => $result->selected_draw_video_url,
        ]);
    }

    public function test_it_returns_existing_completed_request_for_same_idempotency_key(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100, minimumGuaranteeValue: 10);
        $this->createWalletWithPaidLot($user, 100);
        $this->publishSingleStage($gacha, $prize, prizePpm: 0, minimumGuaranteePpm: 1_000_000);

        $first = app(DrawService::class)->draw($user, $gacha, 1, 'same-key');
        $second = app(DrawService::class)->draw($user, $gacha, 1, 'same-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $gacha->refresh()->sold_count);
        $this->assertSame(1, DrawResult::query()->count());
    }

    public function test_it_applies_stage_per_draw_when_multi_draw_crosses_boundary(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100, totalCount: 10000, soldCount: 9998);
        $this->createWalletWithPaidLot($user, 200);
        $version = $this->publishTwoStages($gacha, $prize);
        $stageIds = $version->stages()->orderBy('min_draw_number')->pluck('id')->all();

        app(DrawService::class)->draw($user, $gacha, 2, 'cross-boundary');

        $results = DrawResult::query()->orderBy('draw_sequence_number')->get();

        $this->assertSame([9999, 10000], $results->pluck('draw_sequence_number')->all());
        $this->assertSame([$stageIds[0], $stageIds[1]], $results->pluck('probability_version_stage_id')->all());
        $this->assertSame(10000, $gacha->refresh()->sold_count);
        $this->assertSame(GachaStatus::SoldOut, $gacha->status);
    }

    public function test_it_rolls_back_when_points_are_insufficient(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture(price: 100);
        $this->createWalletWithPaidLot($user, 99);
        $this->publishSingleStage($gacha, $prize, prizePpm: 1_000_000, minimumGuaranteePpm: 0);

        $this->expectException(InsufficientPointsException::class);

        try {
            app(DrawService::class)->draw($user, $gacha, 1, 'insufficient');
        } finally {
            $this->assertSame(0, $gacha->refresh()->sold_count);
            $this->assertSame(0, DrawResult::query()->count());
            $this->assertSame(0, $prize->refresh()->won_count);
        }
    }

    /**
     * @return array{0: User, 1: Gacha, 2: GachaPrize, 3: GachaRank}
     */
    private function createDrawableFixture(
        int $price = 100,
        int $minimumGuaranteeValue = 10,
        int $totalCount = 10000,
        int $soldCount = 0,
        int $maxWinCount = 10,
    ): array {
        $user = User::factory()->create();
        $gacha = Gacha::factory()->create([
            'price' => $price,
            'total_count' => $totalCount,
            'sold_count' => $soldCount,
            'status' => GachaStatus::Active,
            'minimum_guarantee_value' => $minimumGuaranteeValue,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'max_win_count' => $maxWinCount,
            'won_count' => 0,
            'is_active' => true,
        ]);

        return [$user, $gacha, $prize, $rank];
    }

    private function createWalletWithPaidLot(User $user, int $amount): void
    {
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => $amount,
            'free_balance' => 0,
        ]);

        PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => PointType::Paid,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => null,
            'granted_at' => now()->subDay(),
            'expire_at' => null,
        ]);
    }

    private function publishSingleStage(Gacha $gacha, GachaPrize $prize, int $prizePpm, int $minimumGuaranteePpm): GachaProbabilityVersion
    {
        return app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => $prizePpm],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => $minimumGuaranteePpm],
                ],
            ],
        ], AdminUser::factory()->create());
    }

    private function publishTwoStages(Gacha $gacha, GachaPrize $prize): GachaProbabilityVersion
    {
        return app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => 9999,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 0],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 1_000_000],
                ],
            ],
            [
                'stage_key' => 'stage_2',
                'name' => 'Stage 2',
                'min_draw_number' => 10000,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 0],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 1_000_000],
                ],
            ],
        ], AdminUser::factory()->create());
    }

    public static function existingDrawCountProvider(): array
    {
        return [
            'single' => [1],
            'five' => [5],
            'ten' => [10],
        ];
    }
}
