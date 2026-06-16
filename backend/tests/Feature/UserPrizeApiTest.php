<?php

namespace Tests\Feature;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Domain\Shipping\Enums\UserPrizeStatus;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\User;
use App\Models\UserPrize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPrizeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_only_own_prizes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$gacha, $rank, $prize] = $this->createPrizeFixture();

        $ownPrize = $this->createUserPrize($user, $gacha, $prize, UserPrizeStatus::Stored, now()->subMinute());
        $otherPrize = $this->createUserPrize($other, $gacha, $prize, UserPrizeStatus::Stored, now());

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/prizes');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownPrize->id)
            ->assertJsonPath('data.0.status', 'stored')
            ->assertJsonPath('data.0.gacha.title', $gacha->title)
            ->assertJsonPath('data.0.prize.name', $prize->name)
            ->assertJsonPath('data.0.prize.rank.display_name', $rank->display_name);

        $this->assertNotSame($otherPrize->id, $response->json('data.0.id'));
    }

    public function test_authenticated_user_can_filter_prizes_by_status(): void
    {
        $user = User::factory()->create();
        [$gacha, , $prize] = $this->createPrizeFixture();

        $stored = $this->createUserPrize($user, $gacha, $prize, UserPrizeStatus::Stored, now());
        $this->createUserPrize($user, $gacha, $prize, UserPrizeStatus::Converted, now()->subMinute(), convertedPoint: 100);

        Sanctum::actingAs($user);

        $this->getJson('/api/me/prizes?status=stored')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stored->id)
            ->assertJsonPath('data.0.status', 'stored');
    }

    public function test_guest_cannot_get_prizes(): void
    {
        $this->getJson('/api/me/prizes')->assertUnauthorized();
    }

    public function test_status_filter_must_be_valid(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/me/prizes?status=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    /**
     * @return array{0: Gacha, 1: GachaRank, 2: GachaPrize}
     */
    private function createPrizeFixture(): array
    {
        $gacha = Gacha::factory()->create([
            'title' => 'Prize Box Gacha',
            'slug' => 'prize-box-gacha',
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'display_name' => 'S Rank',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'Prize Box Item',
        ]);

        return [$gacha, $rank, $prize];
    }

    private function createUserPrize(
        User $user,
        Gacha $gacha,
        GachaPrize $prize,
        UserPrizeStatus $status,
        mixed $acquiredAt,
        ?int $convertedPoint = null,
    ): UserPrize {
        $version = app(ProbabilityVersionPublisher::class)->publish($gacha, [
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
        $stage = $version->stages()->firstOrFail();

        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'test-'.$user->id.'-'.$status->value.'-'.microtime(true),
            'status' => DrawRequestStatus::Completed,
            'consumed_point_total' => $gacha->price,
        ]);

        $drawResult = DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => $drawRequest->id,
            'result_type' => DrawResultType::Prize,
            'rank_id' => $prize->rank_id,
            'prize_id' => $prize->id,
            'consumed_point' => $gacha->price,
            'granted_point' => 0,
            'random_value' => 1,
            'probability_version_id' => $version->id,
            'probability_version_stage_id' => $stage->id,
            'created_at' => $acquiredAt,
        ]);

        return UserPrize::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $drawResult->id,
            'status' => $status,
            'acquired_at' => $acquiredAt,
            'storage_expire_at' => now()->addDays(30),
            'converted_point' => $convertedPoint,
        ]);
    }
}
