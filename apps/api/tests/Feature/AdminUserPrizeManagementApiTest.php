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

class AdminUserPrizeManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_user_prizes_with_filters(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['email' => 'prize-owner@example.test']);
        $stored = $this->createUserPrize($user, UserPrizeStatus::Stored);
        $this->createUserPrize(User::factory()->create(), UserPrizeStatus::Converted, convertedPoint: 200);

        $this->getJson('/admin/api/user-prizes?status=stored')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stored->id)
            ->assertJsonPath('data.0.status', 'stored')
            ->assertJsonPath('data.0.user.email', 'prize-owner@example.test')
            ->assertJsonPath('data.0.gacha.title', 'Admin User Prize Gacha')
            ->assertJsonPath('data.0.prize.name', 'Admin User Prize Item')
            ->assertJsonPath('data.0.prize.rank.display_name', 'S Rank');
    }

    public function test_admin_can_show_user_prize_with_draw_result(): void
    {
        $this->actingAdmin();
        $userPrize = $this->createUserPrize(User::factory()->create(), UserPrizeStatus::Stored);

        $this->getJson("/admin/api/user-prizes/{$userPrize->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $userPrize->id)
            ->assertJsonPath('data.draw_result.result_type', 'prize')
            ->assertJsonPath('data.draw_result.prize.name', 'Admin User Prize Item')
            ->assertJsonPath('data.draw_result.probability_version.version_number', 1)
            ->assertJsonPath('data.draw_result.probability_stage.stage_key', 'stage_1');
    }

    public function test_user_token_cannot_access_admin_user_prizes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/user-prizes')->assertForbidden();
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

    private function createUserPrize(User $user, UserPrizeStatus $status, ?int $convertedPoint = null): UserPrize
    {
        [$gacha, $prize] = $this->createPrizeFixture();
        $version = $gacha->currentProbabilityVersion()->with('stages')->firstOrFail();
        $stage = $version->stages->first();

        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => 1,
            'idempotency_key' => 'admin-user-prize-'.$user->id.'-'.fake()->uuid(),
            'status' => DrawRequestStatus::Completed,
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

        return UserPrize::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'gacha_prize_id' => $prize->id,
            'draw_result_id' => $drawResult->id,
            'status' => $status,
            'acquired_at' => now(),
            'storage_expire_at' => now()->addDays(30),
            'converted_point' => $convertedPoint,
        ]);
    }

    /**
     * @return array{0: Gacha, 1: GachaPrize}
     */
    private function createPrizeFixture(): array
    {
        $gacha = Gacha::factory()->create([
            'title' => 'Admin User Prize Gacha',
            'slug' => 'admin-user-prize-gacha-'.fake()->uuid(),
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'display_name' => 'S Rank',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'Admin User Prize Item',
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
