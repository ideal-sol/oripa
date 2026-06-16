<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users_with_filters(): void
    {
        $this->actingAdmin();
        $active = User::factory()->create([
            'name' => 'Active Buyer',
            'email' => 'active-buyer@example.test',
            'status' => 'active',
        ]);
        User::factory()->create([
            'name' => 'Suspended Buyer',
            'email' => 'suspended-buyer@example.test',
            'status' => 'suspended',
        ]);
        Wallet::query()->create([
            'user_id' => $active->id,
            'paid_balance' => 1000,
            'free_balance' => 100,
        ]);

        $this->getJson('/admin/api/users?status=active&q=buyer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.email', 'active-buyer@example.test')
            ->assertJsonPath('data.0.wallet.paid_balance', 1000);
    }

    public function test_admin_can_show_user_with_profile_and_wallet(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        UserProfile::query()->create([
            'user_id' => $user->id,
            'last_name' => '山田',
            'first_name' => '太郎',
            'postal_code' => '100-0001',
            'prefecture' => '東京都',
            'city' => '千代田区',
            'address_line1' => '千代田1-1',
            'phone_number' => '09012345678',
        ]);
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 500,
            'free_balance' => 50,
        ]);

        $this->getJson("/admin/api/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.profile.last_name', '山田')
            ->assertJsonPath('data.wallet.total_balance', 550);
    }

    public function test_user_token_cannot_access_admin_users(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/users')->assertForbidden();
    }

    public function test_admin_can_update_user_status_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $user = User::factory()->create(['status' => 'active']);

        $this->putJson("/admin/api/users/{$user->id}", [
            'status' => 'suspended',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'suspended',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'user_id' => $user->id,
            'action' => 'admin.user.updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
        ]);
    }

    public function test_user_status_must_be_valid(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['status' => 'active']);

        $this->putJson("/admin/api/users/{$user->id}", [
            'status' => 'invalid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
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
