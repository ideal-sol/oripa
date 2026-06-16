<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_receive_token(): void
    {
        $admin = AdminUser::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('secret-password'),
            'role' => AdminRole::Admin,
            'is_active' => true,
        ]);

        $response = $this->postJson('/admin/api/login', [
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'device_name' => 'feature-test',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('admin.id', $admin->id)
            ->assertJsonPath('admin.email', 'admin@example.test')
            ->assertJsonPath('admin.role', AdminRole::Admin->value)
            ->assertJsonStructure(['access_token']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.login',
        ]);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        AdminUser::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        $this->postJson('/admin/api/login', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rejects_inactive_admin(): void
    {
        AdminUser::factory()->create([
            'email' => 'inactive@example.test',
            'password' => Hash::make('secret-password'),
            'is_active' => false,
        ]);

        $this->postJson('/admin/api/login', [
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_admin_me_returns_authenticated_admin(): void
    {
        $admin = AdminUser::factory()->create([
            'role' => AdminRole::Owner,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        $this->getJson('/admin/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonPath('data.role', AdminRole::Owner->value)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_user_token_cannot_access_admin_me(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/admin/api/me')->assertForbidden();
    }

    public function test_admin_can_logout_current_token(): void
    {
        $admin = AdminUser::factory()->create(['is_active' => true]);
        $token = $admin->createToken('feature-test', ['admin']);

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/admin/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.logout',
        ]);
    }
}
