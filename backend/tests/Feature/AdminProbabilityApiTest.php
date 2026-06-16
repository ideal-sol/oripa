<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\ProbabilityMode;
use App\Domain\Probability\Enums\ProbabilityVersionStatus;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaProbabilityVersion;
use App\Models\GachaRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProbabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_probability_matrix(): void
    {
        $this->actingAdmin();
        [$gacha, $prizes] = $this->createGachaWithPrizes();
        app(ProbabilityVersionPublisher::class)->publish(
            $gacha,
            $this->singleStagePayload($prizes),
            AdminUser::factory()->create(),
            'Initial version.'
        );

        $this->getJson("/admin/api/gachas/{$gacha->id}/probability-matrix")
            ->assertOk()
            ->assertJsonPath('data.gacha.id', $gacha->id)
            ->assertJsonPath('data.current_probability_version.version_number', 1)
            ->assertJsonPath('data.minimum_guarantee.ppm.stage_1', 889000)
            ->assertJsonPath('data.ranks.0.prizes.0.id', $prizes[0]->id)
            ->assertJsonPath('data.ranks.0.prizes.0.ppm.stage_1', 1000);
    }

    public function test_admin_can_publish_probability_version_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        [$gacha, $prizes] = $this->createGachaWithPrizes();

        $response = $this->postJson("/admin/api/gachas/{$gacha->id}/probability-versions/publish", [
            'change_reason' => 'Initial publish from API.',
            'stages' => $this->singleStagePayload($prizes),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.gacha_id', $gacha->id)
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.status', ProbabilityVersionStatus::Published->value)
            ->assertJsonPath('data.change_reason', 'Initial publish from API.')
            ->assertJsonCount(1, 'data.stages')
            ->assertJsonCount(4, 'data.stages.0.probabilities');

        $version = GachaProbabilityVersion::query()->firstOrFail();

        $this->assertSame($version->id, $gacha->refresh()->current_probability_version_id);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.probability_version.published',
            'auditable_type' => GachaProbabilityVersion::class,
            'auditable_id' => $version->id,
        ]);
    }

    public function test_admin_can_preview_probability_version_without_persisting_it(): void
    {
        $this->actingAdmin();
        [$gacha, $prizes] = $this->createGachaWithPrizes();

        $this->postJson("/admin/api/gachas/{$gacha->id}/probability-versions/preview", [
            'stages' => $this->singleStagePayload($prizes),
        ])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.gacha_id', $gacha->id)
            ->assertJsonPath('data.total_ppm', 1_000_000)
            ->assertJsonPath('data.stages.0.total_ppm', 1_000_000)
            ->assertJsonPath('data.stages.0.minimum_guarantee_ppm', 889_000)
            ->assertJsonPath('data.stages.0.prize_count', 3);

        $this->assertDatabaseCount('gacha_probability_versions', 0);
        $this->assertNull($gacha->refresh()->current_probability_version_id);
    }

    public function test_publish_rejects_invalid_probability_total(): void
    {
        $this->actingAdmin();
        [$gacha, $prizes] = $this->createGachaWithPrizes();
        $stages = $this->singleStagePayload($prizes);
        $stages[0]['probabilities'][3]['probability_ppm'] = 888999;

        $this->postJson("/admin/api/gachas/{$gacha->id}/probability-versions/publish", [
            'stages' => $stages,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stages']);
    }

    public function test_preview_rejects_invalid_probability_total_without_persisting_it(): void
    {
        $this->actingAdmin();
        [$gacha, $prizes] = $this->createGachaWithPrizes();
        $stages = $this->singleStagePayload($prizes);
        $stages[0]['probabilities'][3]['probability_ppm'] = 888999;

        $this->postJson("/admin/api/gachas/{$gacha->id}/probability-versions/preview", [
            'stages' => $stages,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stages']);

        $this->assertDatabaseCount('gacha_probability_versions', 0);
    }

    public function test_user_token_cannot_access_probability_admin_api(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$gacha] = $this->createGachaWithPrizes();

        $this->getJson("/admin/api/gachas/{$gacha->id}/probability-matrix")
            ->assertForbidden();
    }

    /**
     * @return array{0: Gacha, 1: list<GachaPrize>}
     */
    private function createGachaWithPrizes(): array
    {
        $gacha = Gacha::factory()->create([
            'total_count' => 10000,
            'probability_mode' => ProbabilityMode::Single,
        ]);

        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'sort_order' => 1,
        ]);

        $prizes = [
            GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create(['name' => 'Prize 1', 'sort_order' => 1]),
            GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create(['name' => 'Prize 2', 'sort_order' => 2]),
            GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create(['name' => 'Prize 3', 'sort_order' => 3]),
        ];

        return [$gacha, $prizes];
    }

    /**
     * @param list<GachaPrize> $prizes
     * @return list<array<string, mixed>>
     */
    private function singleStagePayload(array $prizes): array
    {
        return [
            [
                'stage_key' => 'stage_1',
                'name' => 'Default',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'sort_order' => 1,
                'probabilities' => [
                    ['prize_id' => $prizes[0]->id, 'probability_ppm' => 1_000],
                    ['prize_id' => $prizes[1]->id, 'probability_ppm' => 10_000],
                    ['prize_id' => $prizes[2]->id, 'probability_ppm' => 100_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 889_000],
                ],
            ],
        ];
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
}
