<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\GachaStatus;
use App\Domain\Gacha\Enums\MinimumGuaranteeType;
use App\Domain\Gacha\Enums\ProbabilityMode;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\Gacha;
use App\Models\GachaCategory;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\GachaTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGachaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_all_gacha_statuses(): void
    {
        $admin = $this->actingAdmin();
        $draft = Gacha::factory()->create(['status' => GachaStatus::Draft]);
        $active = Gacha::factory()->create(['status' => GachaStatus::Active]);

        $response = $this->getJson('/admin/api/gachas');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $draft->id])
            ->assertJsonFragment(['id' => $active->id])
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'title',
                    'slug',
                    'category',
                    'price',
                    'total_count',
                    'sold_count',
                    'remaining_count',
                    'probability_mode',
                    'minimum_guarantee',
                    'status',
                    'ranks_count',
                    'prizes_count',
                ]],
                'links',
                'meta',
            ]);

        $this->assertSame(AdminRole::Admin, $admin->role);
    }

    public function test_user_token_cannot_access_admin_gacha_api(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/gachas')->assertForbidden();
    }

    public function test_admin_can_create_gacha_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $category = GachaCategory::factory()->create();
        $tag = GachaTag::factory()->create();

        $payload = $this->payload($category->id, [
            'title' => 'Admin Created Gacha',
            'slug' => 'admin-created-gacha',
            'tag_ids' => [$tag->id],
        ]);

        $response = $this->postJson('/admin/api/gachas', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.title', 'Admin Created Gacha')
            ->assertJsonPath('data.slug', 'admin-created-gacha')
            ->assertJsonPath('data.status', GachaStatus::Draft->value)
            ->assertJsonPath('data.daily_draw_limit', null)
            ->assertJsonPath('data.tags.0.id', $tag->id)
            ->assertJsonPath('data.tag_ids.0', $tag->id)
            ->assertJsonPath('data.minimum_guarantee.type', MinimumGuaranteeType::Point->value);

        $gacha = Gacha::query()->where('slug', 'admin-created-gacha')->firstOrFail();

        $this->assertDatabaseHas('gacha_tag_assignments', [
            'gacha_id' => $gacha->id,
            'gacha_tag_id' => $tag->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha.created',
            'auditable_type' => Gacha::class,
            'auditable_id' => $gacha->id,
        ]);
    }

    public function test_admin_can_create_limited_gacha_with_daily_draw_limit(): void
    {
        $this->actingAdmin();
        $category = GachaCategory::factory()->create();

        $this->postJson('/admin/api/gachas', $this->payload($category->id, [
            'slug' => 'daily-limited-gacha',
            'daily_draw_limit' => 3,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.daily_draw_limit', 3);

        $this->assertDatabaseHas('gachas', [
            'slug' => 'daily-limited-gacha',
            'daily_draw_limit' => 3,
        ]);
    }

    public function test_admin_can_list_gacha_categories(): void
    {
        $this->actingAdmin();
        $category = GachaCategory::factory()->create([
            'name' => 'Apple',
            'slug' => 'apple',
            'sort_order' => 1,
        ]);

        $this->getJson('/admin/api/gacha-categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $category->id)
            ->assertJsonPath('data.0.name', 'Apple');
    }

    public function test_create_rejects_active_status_before_probability_publish(): void
    {
        $this->actingAdmin();
        $category = GachaCategory::factory()->create();

        $this->postJson('/admin/api/gachas', $this->payload($category->id, [
            'status' => GachaStatus::Active->value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_admin_can_show_gacha_with_ranks_and_prizes(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create();
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'Admin Prize',
        ]);

        $this->getJson("/admin/api/gachas/{$gacha->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $gacha->id)
            ->assertJsonPath('data.ranks.0.id', $rank->id)
            ->assertJsonPath('data.ranks.0.prizes.0.id', $prize->id)
            ->assertJsonPath('data.ranks.0.prizes.0.name', 'Admin Prize');
    }

    public function test_admin_can_update_gacha_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $oldTag = GachaTag::factory()->create();
        $newTag = GachaTag::factory()->create();
        $gacha = Gacha::factory()->create([
            'title' => 'Old Title',
            'sold_count' => 5,
            'total_count' => 10,
        ]);
        $gacha->tags()->sync([$oldTag->id]);

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'title' => 'Updated Title',
            'total_count' => 20,
            'status' => GachaStatus::Paused->value,
            'tag_ids' => [$newTag->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title')
            ->assertJsonPath('data.total_count', 20)
            ->assertJsonPath('data.status', GachaStatus::Paused->value)
            ->assertJsonPath('data.tag_ids.0', $newTag->id);

        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha.updated',
            'auditable_type' => Gacha::class,
            'auditable_id' => $gacha->id,
        ]);
        $this->assertDatabaseMissing('gacha_tag_assignments', [
            'gacha_id' => $gacha->id,
            'gacha_tag_id' => $oldTag->id,
        ]);
        $this->assertDatabaseHas('gacha_tag_assignments', [
            'gacha_id' => $gacha->id,
            'gacha_tag_id' => $newTag->id,
        ]);
    }

    public function test_update_rejects_total_count_below_sold_count(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'sold_count' => 5,
            'total_count' => 10,
        ]);

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'total_count' => 4,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total_count']);
    }

    public function test_update_rejects_activation_without_published_probability_version(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'status' => GachaStatus::Draft,
            'current_probability_version_id' => null,
        ]);

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'status' => GachaStatus::Active->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(GachaStatus::Draft, $gacha->refresh()->status);
    }

    public function test_update_rejects_activation_when_readiness_checks_fail(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'status' => GachaStatus::Draft,
            'total_count' => 100,
            'sold_count' => 0,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'is_active' => true,
            'is_visible' => true,
            'cost_price' => 100,
            'max_win_count' => 1,
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [[
            'stage_key' => 'stage_1',
            'name' => 'Default',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
            'probabilities' => [
                ['prize_id' => $prize->id, 'probability_ppm' => 100_000],
                ['is_minimum_guarantee' => true, 'probability_ppm' => 900_000],
            ],
        ]]);

        $prize->forceFill(['is_active' => false])->save();

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'status' => GachaStatus::Active->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(GachaStatus::Draft, $gacha->refresh()->status);
    }

    public function test_admin_can_activate_ready_gacha(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'status' => GachaStatus::Draft,
            'total_count' => 100,
            'sold_count' => 0,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'is_active' => true,
            'is_visible' => true,
            'cost_price' => 100,
            'max_win_count' => 1,
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [[
            'stage_key' => 'stage_1',
            'name' => 'Default',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
            'probabilities' => [
                ['prize_id' => $prize->id, 'probability_ppm' => 100_000],
                ['is_minimum_guarantee' => true, 'probability_ppm' => 900_000],
            ],
        ]]);

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'status' => GachaStatus::Active->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', GachaStatus::Active->value);

        $this->assertSame(GachaStatus::Active, $gacha->refresh()->status);
    }

    public function test_admin_can_activate_gacha_when_only_warning_checks_fail(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'status' => GachaStatus::Draft,
            'price' => 100,
            'total_count' => 10,
            'sold_count' => 0,
            'minimum_guarantee_cost' => 0,
            'target_margin' => null,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'result_image_url' => null,
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'is_active' => true,
            'is_visible' => true,
            'cost_price' => 900,
            'max_win_count' => 1,
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [[
            'stage_key' => 'stage_1',
            'name' => 'Default',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
            'probabilities' => [
                ['prize_id' => $prize->id, 'probability_ppm' => 999_999],
                ['is_minimum_guarantee' => true, 'probability_ppm' => 1],
            ],
        ]]);

        $this->getJson("/admin/api/gachas/{$gacha->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonFragment(['key' => 'expected_profit', 'passed' => false, 'severity' => 'warning']);

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'status' => GachaStatus::Active->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', GachaStatus::Active->value);

        $this->assertSame(GachaStatus::Active, $gacha->refresh()->status);
    }

    public function test_update_rejects_locked_fields_while_gacha_is_active(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'status' => GachaStatus::Active,
            'price' => 500,
            'total_count' => 100,
        ]);

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'price' => 600,
            'total_count' => 200,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price', 'total_count']);

        $gacha->refresh();
        $this->assertSame(500, $gacha->price);
        $this->assertSame(100, $gacha->total_count);
    }

    public function test_admin_can_update_active_gacha_editable_fields_without_readiness_validation(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'status' => GachaStatus::Active,
            'title' => 'Active Before',
            'description' => 'Before description',
        ]);

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'title' => 'Active After',
            'description' => 'After description',
            'status' => GachaStatus::Active->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Active After')
            ->assertJsonPath('data.status', GachaStatus::Active->value);

        $this->assertDatabaseHas('gachas', [
            'id' => $gacha->id,
            'title' => 'Active After',
            'description' => 'After description',
            'status' => GachaStatus::Active->value,
        ]);
    }

    public function test_admin_can_update_active_gacha_when_locked_fields_are_submitted_unchanged(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'status' => GachaStatus::Active,
            'title' => 'Active Before',
            'price' => 500,
            'total_count' => 100,
            'probability_mode' => ProbabilityMode::Single->value,
            'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
            'minimum_guarantee_value' => 100,
            'minimum_guarantee_cost' => 0,
            'target_margin' => '30.00',
        ]);

        $this->putJson("/admin/api/gachas/{$gacha->id}", [
            'title' => 'Active After',
            'price' => 500,
            'total_count' => 100,
            'probability_mode' => ProbabilityMode::Single->value,
            'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
            'minimum_guarantee_value' => 100,
            'minimum_guarantee_cost' => 0,
            'target_margin' => 30,
            'status' => GachaStatus::Active->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Active After');

        $this->assertDatabaseHas('gachas', [
            'id' => $gacha->id,
            'title' => 'Active After',
            'price' => 500,
            'total_count' => 100,
        ]);
    }

    public function test_readiness_reports_missing_publish_requirements(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'total_count' => 100,
            'sold_count' => 0,
            'current_probability_version_id' => null,
        ]);

        $this->getJson("/admin/api/gachas/{$gacha->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.gacha_id', $gacha->id)
            ->assertJsonPath('data.ready', false)
            ->assertJsonFragment([
                'key' => 'ranks',
                'passed' => false,
            ])
            ->assertJsonFragment([
                'key' => 'probability_version',
                'passed' => false,
            ]);
    }

    public function test_readiness_reports_missing_images(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'main_image_url' => null,
            'total_count' => 100,
            'sold_count' => 0,
            'current_probability_version_id' => null,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'image_url' => null,
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'image_url' => '',
            'is_active' => true,
            'is_visible' => true,
            'cost_price' => 100,
            'max_win_count' => 1,
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [[
            'stage_key' => 'stage_1',
            'name' => 'Default',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
            'probabilities' => [
                ['prize_id' => $prize->id, 'probability_ppm' => 100_000],
                ['is_minimum_guarantee' => true, 'probability_ppm' => 900_000],
            ],
        ]]);

        $this->getJson("/admin/api/gachas/{$gacha->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonFragment(['key' => 'gacha_image', 'passed' => false])
            ->assertJsonFragment(['key' => 'rank_images', 'passed' => false])
            ->assertJsonFragment(['key' => 'prize_images', 'passed' => false]);
    }

    public function test_readiness_reports_negative_profit(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'price' => 100,
            'total_count' => 10,
            'sold_count' => 0,
            'minimum_guarantee_cost' => 1000,
            'target_margin' => 30,
            'current_probability_version_id' => null,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'is_active' => true,
            'is_visible' => true,
            'cost_price' => 10000,
            'max_win_count' => 10,
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [[
            'stage_key' => 'stage_1',
            'name' => 'Default',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
            'probabilities' => [
                ['prize_id' => $prize->id, 'probability_ppm' => 500_000],
                ['is_minimum_guarantee' => true, 'probability_ppm' => 500_000],
            ],
        ]]);

        $this->getJson("/admin/api/gachas/{$gacha->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonFragment(['key' => 'max_profit', 'passed' => false])
            ->assertJsonFragment(['key' => 'expected_profit', 'passed' => false])
            ->assertJsonFragment(['key' => 'target_margin', 'passed' => false]);
    }

    public function test_readiness_reports_ready_gacha(): void
    {
        $this->actingAdmin();
        $gacha = Gacha::factory()->create([
            'total_count' => 100,
            'sold_count' => 0,
            'current_probability_version_id' => null,
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'is_active' => true,
            'is_visible' => true,
            'cost_price' => 100,
            'max_win_count' => 1,
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [[
            'stage_key' => 'stage_1',
            'name' => 'Default',
            'min_draw_number' => 1,
            'max_draw_number' => null,
            'sort_order' => 1,
            'probabilities' => [
                ['prize_id' => $prize->id, 'probability_ppm' => 100_000],
                ['is_minimum_guarantee' => true, 'probability_ppm' => 900_000],
            ],
        ]]);

        $this->getJson("/admin/api/gachas/{$gacha->id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.gacha_id', $gacha->id)
            ->assertJsonPath('data.ready', true);
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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(int $categoryId, array $overrides = []): array
    {
        return [
            ...[
                'title' => 'Admin Gacha',
                'slug' => 'admin-gacha',
                'category_id' => $categoryId,
                'price' => 500,
                'total_count' => 10000,
                'probability_mode' => ProbabilityMode::Single->value,
                'minimum_guarantee_type' => MinimumGuaranteeType::Point->value,
                'minimum_guarantee_value' => 10,
                'minimum_guarantee_cost' => 10,
                'status' => GachaStatus::Draft->value,
                'start_at' => now()->toIso8601String(),
                'end_at' => now()->addMonth()->toIso8601String(),
                'description' => 'Admin gacha description.',
                'caution' => 'Admin gacha caution.',
                'main_image_url' => 'https://example.test/admin-gacha.png',
                'target_margin' => 30.5,
            ],
            ...$overrides,
        ];
    }
}
