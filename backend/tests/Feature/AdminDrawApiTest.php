<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
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

class AdminDrawApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_draw_requests_with_filters(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['email' => 'draw-user@example.test']);
        $fixture = $this->createDrawFixture($user, DrawRequestStatus::Completed);
        $this->createDrawFixture(User::factory()->create(), DrawRequestStatus::Failed);

        $this->getJson('/admin/api/draw-requests?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fixture['draw_request']->id)
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.user.email', 'draw-user@example.test')
            ->assertJsonPath('data.0.gacha.title', 'Admin Draw Gacha')
            ->assertJsonPath('data.0.results_count', 1);
    }

    public function test_admin_can_show_draw_request_with_results(): void
    {
        $this->actingAdmin();
        $fixture = $this->createDrawFixture(User::factory()->create(), DrawRequestStatus::Completed);
        $drawRequest = $fixture['draw_request'];

        $this->getJson("/admin/api/draw-requests/{$drawRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $drawRequest->id)
            ->assertJsonPath('data.results.0.draw_sequence_number', 1)
            ->assertJsonPath('data.results.0.result_type', 'prize')
            ->assertJsonPath('data.results.0.prize.name', 'Admin Draw Prize')
            ->assertJsonPath('data.results.0.probability_stage.stage_key', 'stage_1')
            ->assertJsonPath('data.results.0.user_prize.status', 'stored');
    }

    public function test_admin_can_list_draw_results_with_filters(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['email' => 'draw-result@example.test']);
        $fixture = $this->createDrawFixture($user, DrawRequestStatus::Completed);
        $this->createPointBackFixture(User::factory()->create());

        $this->getJson('/admin/api/draw-results?result_type=prize')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fixture['draw_result']->id)
            ->assertJsonPath('data.0.user.email', 'draw-result@example.test')
            ->assertJsonPath('data.0.prize.name', 'Admin Draw Prize')
            ->assertJsonPath('data.0.user_prize.id', $fixture['user_prize']->id);
    }

    public function test_user_token_cannot_access_admin_draws(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/draw-requests')->assertForbidden();
        $this->getJson('/admin/api/draw-results')->assertForbidden();
    }

    private function actingAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        return $admin;
    }

    /**
     * @return array{draw_request: DrawRequest, draw_result: DrawResult, user_prize: UserPrize}
     */
    private function createDrawFixture(User $user, DrawRequestStatus $status): array
    {
        [$gacha, $prize] = $this->createPrizeFixture();
        $version = $gacha->currentProbabilityVersion()->with('stages')->firstOrFail();
        $stage = $version->stages->first();

        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'admin-draw-'.$user->id.'-'.fake()->uuid(),
            'status' => $status,
            'consumed_point_total' => $gacha->price,
        ]);

        $drawResult = DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => 1,
            'rank_id' => $prize->rank_id,
            'prize_id' => $prize->id,
            'result_type' => DrawResultType::Prize,
            'consumed_point' => $gacha->price,
            'granted_point' => 0,
            'random_value' => 1,
            'probability_version_id' => $version->id,
            'probability_version_stage_id' => $stage->id,
        ]);

        $userPrize = UserPrize::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $drawResult->id,
            'status' => UserPrizeStatus::Stored,
            'acquired_at' => now(),
            'storage_expire_at' => now()->addDays(30),
        ]);

        return [
            'draw_request' => $drawRequest,
            'draw_result' => $drawResult,
            'user_prize' => $userPrize,
        ];
    }

    private function createPointBackFixture(User $user): DrawResult
    {
        [$gacha, $prize] = $this->createPrizeFixture('Admin PointBack Gacha', 'admin-pointback-gacha', 'Admin PointBack Prize');
        $version = $gacha->currentProbabilityVersion()->with('stages')->firstOrFail();
        $stage = $version->stages->first();
        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'admin-point-back-'.$user->id.'-'.fake()->uuid(),
            'status' => DrawRequestStatus::Completed,
            'consumed_point_total' => $gacha->price,
        ]);

        return DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => 1,
            'rank_id' => null,
            'prize_id' => null,
            'result_type' => DrawResultType::PointBack,
            'consumed_point' => $gacha->price,
            'granted_point' => 100,
            'random_value' => 1,
            'probability_version_id' => $version->id,
            'probability_version_stage_id' => $stage->id,
        ]);
    }

    /**
     * @return array{0: Gacha, 1: GachaPrize}
     */
    private function createPrizeFixture(
        string $gachaTitle = 'Admin Draw Gacha',
        string $gachaSlugPrefix = 'admin-draw-gacha',
        string $prizeName = 'Admin Draw Prize',
    ): array {
        $gacha = Gacha::factory()->create([
            'title' => $gachaTitle,
            'slug' => $gachaSlugPrefix.'-'.fake()->uuid(),
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'display_name' => 'S Rank',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => $prizeName,
        ]);

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

        return [$gacha->refresh(), $prize];
    }
}
