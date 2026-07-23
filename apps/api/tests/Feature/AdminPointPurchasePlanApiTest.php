<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\PointPurchasePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPointPurchasePlanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_point_purchase_plan_with_sale_period(): void
    {
        PointPurchasePlan::query()->delete();
        $this->actingAdmin();

        $this->postJson('/admin/api/point-purchase-plans', [
            'name' => '期間限定',
            'amount' => 5000,
            'paid_point_amount' => 5000,
            'free_point_amount' => 500,
            'sort_order' => 10,
            'is_active' => true,
            'starts_at' => '2026-07-01 10:00:00',
            'ends_at' => '2026-07-31 23:59:59',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', '期間限定')
            ->assertJsonPath('data.starts_at', fn (?string $value): bool => str_starts_with((string) $value, '2026-07-01T10:00:00'))
            ->assertJsonPath('data.ends_at', fn (?string $value): bool => str_starts_with((string) $value, '2026-07-31T23:59:59'));

        $this->assertDatabaseHas('point_purchase_plans', [
            'name' => '期間限定',
            'starts_at' => '2026-07-01 10:00:00',
            'ends_at' => '2026-07-31 23:59:59',
        ]);
    }

    public function test_plan_end_must_be_after_start(): void
    {
        PointPurchasePlan::query()->delete();
        $this->actingAdmin();

        $this->postJson('/admin/api/point-purchase-plans', [
            'name' => 'Invalid',
            'amount' => 5000,
            'paid_point_amount' => 5000,
            'free_point_amount' => 500,
            'sort_order' => 10,
            'is_active' => true,
            'starts_at' => '2026-07-31 10:00:00',
            'ends_at' => '2026-07-01 10:00:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_admin_can_create_point_purchase_plan_with_only_end_at(): void
    {
        PointPurchasePlan::query()->delete();
        $this->actingAdmin();

        $this->postJson('/admin/api/point-purchase-plans', [
            'name' => '終了期限のみ',
            'amount' => 3000,
            'paid_point_amount' => 3000,
            'free_point_amount' => 300,
            'sort_order' => 20,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => '2026-08-31 23:59:59',
        ])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', null)
            ->assertJsonPath('data.ends_at', fn (?string $value): bool => str_starts_with((string) $value, '2026-08-31T23:59:59'));
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
