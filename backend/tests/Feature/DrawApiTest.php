<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DrawApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('existingDrawCountProvider')]
    public function test_existing_draw_response_contract_is_unchanged(int $drawCount): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $this->createWalletWithPaidLot($user, 100 * $drawCount);
        $this->publishPointBackStage($gacha, $prize);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => $drawCount,
            'idempotency_key' => "api-characterization-{$drawCount}",
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'gacha_id',
                    'draw_count',
                    'idempotency_key',
                    'status',
                    'consumed_point_total',
                    'results',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJsonPath('data.draw_count', $drawCount)
            ->assertJsonCount($drawCount, 'data.results');
    }

    public function test_authenticated_user_can_draw_gacha(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $this->createWalletWithPaidLot($user, 200);
        $this->publishPointBackStage($gacha, $prize);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 2,
            'idempotency_key' => 'api-draw-key',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.gacha_id', $gacha->id)
            ->assertJsonPath('data.draw_count', 2)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonCount(2, 'data.results')
            ->assertJsonPath('data.results.0.draw_sequence_number', 1)
            ->assertJsonPath('data.results.0.result_type', DrawResultType::PointBack->value)
            ->assertJsonPath('data.results.0.granted_point', 10);

        $this->assertSame(2, $gacha->refresh()->sold_count);
        $this->assertSame(0, $user->wallet->refresh()->paid_balance);
        $this->assertSame(20, $user->wallet->free_balance);
    }

    public function test_bulk_draw_requires_header_and_returns_compact_aggregate_response(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $prize->forceFill(['max_win_count' => 200])->save();
        $gacha->forceFill(['price' => 1, 'total_count' => 200])->save();
        $this->createWalletWithPaidLot($user, 100);
        $this->publishPrizeStage($gacha, $prize);

        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $response = $this
            ->withHeader('Idempotency-Key', 'bulk-api-key')
            ->postJson("/api/gachas/{$gacha->id}/draw", ['draw_count' => 100]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.requested_count', 100)
            ->assertJsonPath('data.executed_count', 100)
            ->assertJsonPath('data.consumed_point', 100)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.idempotent_replay', false)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.gacha_id')
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.results')
            ->assertJsonCount(1, 'data.prize_counts')
            ->assertJsonCount(1, 'data.rank_counts')
            ->assertJsonPath('data.prize_counts.0.win_count', 100)
            ->assertJsonPath('data.rank_counts.0.win_count', 100);

        $this
            ->withHeader('Idempotency-Key', 'bulk-api-key')
            ->postJson("/api/gachas/{$gacha->id}/draw", ['draw_count' => 100])
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.bulk_request_id', $response->json('data.bulk_request_id'));

        $this->assertDatabaseCount('draw_requests', 1);
        $this->assertDatabaseCount('draw_results', 100);
    }

    public function test_bulk_draw_rejects_mismatched_header_and_body_keys(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $this->createWalletWithPaidLot($user, 100);
        $this->publishPointBackStage($gacha, $prize);
        Sanctum::actingAs($user);

        $this
            ->withHeader('Idempotency-Key', 'header-key')
            ->postJson("/api/gachas/{$gacha->id}/draw", [
                'draw_count' => 100,
                'idempotency_key' => 'body-key',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_draw_count_outside_legacy_or_bulk_values_is_rejected(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $this->createWalletWithPaidLot($user, 100);
        $this->publishPointBackStage($gacha, $prize);
        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 11,
            'idempotency_key' => 'invalid-count',
        ])->assertUnprocessable()->assertJsonValidationErrors('draw_count');
    }

    public function test_unauthenticated_user_cannot_draw(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $this->createWalletWithPaidLot($user, 100);
        $this->publishPointBackStage($gacha, $prize);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 1,
            'idempotency_key' => 'guest-key',
        ])->assertUnauthorized();
    }

    public function test_it_validates_draw_payload(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $this->createWalletWithPaidLot($user, 100);
        $this->publishPointBackStage($gacha, $prize);

        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 0,
            'idempotency_key' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['draw_count', 'idempotency_key']);
    }

    public function test_it_returns_validation_error_when_points_are_insufficient(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $this->createWalletWithPaidLot($user, 99);
        $this->publishPointBackStage($gacha, $prize);

        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 1,
            'idempotency_key' => 'insufficient-api',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['points']);
    }

    public function test_it_rejects_draw_when_daily_draw_limit_would_be_exceeded(): void
    {
        [$user, $gacha, $prize] = $this->createDrawableFixture();
        $gacha->forceFill(['daily_draw_limit' => 2])->save();
        $this->createWalletWithPaidLot($user, 300);
        $this->publishPointBackStage($gacha, $prize);

        Sanctum::actingAs($user);

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 2,
            'idempotency_key' => 'limited-first',
        ])->assertCreated();

        $this->postJson("/api/gachas/{$gacha->id}/draw", [
            'draw_count' => 1,
            'idempotency_key' => 'limited-second',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['draw']);

        $this->assertSame(2, $gacha->refresh()->sold_count);
    }

    /**
     * @return array{0: User, 1: Gacha, 2: GachaPrize}
     */
    private function createDrawableFixture(): array
    {
        $user = User::factory()->create();
        $gacha = Gacha::factory()->create([
            'price' => 100,
            'total_count' => 10000,
            'sold_count' => 0,
            'status' => GachaStatus::Active,
            'minimum_guarantee_value' => 10,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create();

        return [$user, $gacha, $prize];
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

    private function publishPointBackStage(Gacha $gacha, GachaPrize $prize): void
    {
        app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 0],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 1_000_000],
                ],
            ],
        ], AdminUser::factory()->create());
    }

    private function publishPrizeStage(Gacha $gacha, GachaPrize $prize): void
    {
        app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 1_000_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 0],
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
