<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Point\Services\PointLotService;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPointAdjustmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_grant_paid_points_without_expiration(): void
    {
        $admin = $this->actingAdmin();
        $user = User::factory()->create();

        $this->postJson("/admin/api/users/{$user->id}/point-adjustments", [
            'adjustment_type' => 'grant',
            'point_type' => 'paid',
            'amount' => 1000,
            'reason' => 'Manual paid point compensation.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.adjustment_type', 'grant')
            ->assertJsonPath('data.point_type', 'paid')
            ->assertJsonPath('data.amount', 1000)
            ->assertJsonPath('data.admin_user_id', $admin->id);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 1000,
            'free_balance' => 0,
        ]);
        $this->assertDatabaseHas('point_lots', [
            'user_id' => $user->id,
            'point_type' => PointType::Paid->value,
            'granted_amount' => 1000,
            'remaining_amount' => 1000,
            'expire_at' => null,
        ]);
        $this->assertDatabaseHas('point_ledgers', [
            'user_id' => $user->id,
            'point_type' => PointType::Paid->value,
            'ledger_type' => PointLedgerType::Compensation->value,
            'amount' => 1000,
            'related_type' => 'point_adjustment',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'user_id' => $user->id,
            'action' => 'admin.point_adjustment.created',
        ]);
    }

    public function test_admin_can_grant_free_points_with_expiration(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $expireAt = now()->addDays(30)->toIso8601String();

        $this->postJson("/admin/api/users/{$user->id}/point-adjustments", [
            'adjustment_type' => 'grant',
            'point_type' => 'free',
            'amount' => 300,
            'expire_at' => $expireAt,
            'reason' => 'Campaign correction.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.point_type', 'free')
            ->assertJsonPath('data.amount', 300);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 0,
            'free_balance' => 300,
        ]);
        $this->assertDatabaseHas('point_ledgers', [
            'user_id' => $user->id,
            'point_type' => PointType::Free->value,
            'ledger_type' => PointLedgerType::Compensation->value,
            'amount' => 300,
            'related_type' => 'point_adjustment',
        ]);
    }

    public function test_admin_can_deduct_points_using_existing_consumption_order(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        app(PointLotService::class)->grantFree(
            user: $user,
            amount: 200,
            expireAt: now()->addDays(10),
            sourceType: \App\Domain\Point\Enums\PointLotSourceType::Campaign,
            description: 'Initial free points.',
        );
        app(PointLotService::class)->grantPaid($user, 500, description: 'Initial paid points.');

        $this->postJson("/admin/api/users/{$user->id}/point-adjustments", [
            'adjustment_type' => 'deduct',
            'amount' => 250,
            'reason' => 'Manual deduction.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.adjustment_type', 'deduct')
            ->assertJsonPath('data.point_type', null)
            ->assertJsonPath('data.amount', 250);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 450,
            'free_balance' => 0,
        ]);
        $this->assertDatabaseHas('point_ledgers', [
            'user_id' => $user->id,
            'point_type' => PointType::Free->value,
            'ledger_type' => PointLedgerType::Spend->value,
            'amount' => -200,
            'related_type' => 'point_adjustment',
        ]);
        $this->assertDatabaseHas('point_ledgers', [
            'user_id' => $user->id,
            'point_type' => PointType::Paid->value,
            'ledger_type' => PointLedgerType::Spend->value,
            'amount' => -50,
            'related_type' => 'point_adjustment',
        ]);
    }

    public function test_free_point_grant_requires_expiration(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();

        $this->postJson("/admin/api/users/{$user->id}/point-adjustments", [
            'adjustment_type' => 'grant',
            'point_type' => 'free',
            'amount' => 300,
            'reason' => 'Missing expiration.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expire_at');
    }

    public function test_admin_can_list_point_adjustments(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();

        $this->postJson("/admin/api/users/{$user->id}/point-adjustments", [
            'adjustment_type' => 'grant',
            'point_type' => 'paid',
            'amount' => 100,
            'reason' => 'List target.',
        ])->assertCreated();

        $this->getJson("/admin/api/point-adjustments?user_id={$user->id}&adjustment_type=grant")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $user->id)
            ->assertJsonPath('data.0.adjustment_type', 'grant');
    }

    public function test_user_token_cannot_adjust_points(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $user = User::factory()->create();

        $this->postJson("/admin/api/users/{$user->id}/point-adjustments", [
            'adjustment_type' => 'grant',
            'point_type' => 'paid',
            'amount' => 100,
            'reason' => 'Forbidden.',
        ])->assertForbidden();
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
