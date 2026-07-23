<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_audit_logs_with_filters(): void
    {
        $admin = $this->actingAdmin();
        $user = User::factory()->create(['email' => 'audit-user@example.test']);
        $target = AuditLog::query()->create([
            'admin_user_id' => $admin->id,
            'user_id' => $user->id,
            'action' => 'admin.user.updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Agent',
            'metadata' => ['before' => ['status' => 'active'], 'after' => ['status' => 'suspended']],
        ]);
        AuditLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => 'admin.gacha.updated',
            'metadata' => [],
        ]);

        $this->getJson('/admin/api/audit-logs?action=admin.user.updated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.action', 'admin.user.updated')
            ->assertJsonPath('data.0.admin_user.id', $admin->id)
            ->assertJsonPath('data.0.user.email', 'audit-user@example.test')
            ->assertJsonPath('data.0.metadata.after.status', 'suspended');
    }

    public function test_user_token_cannot_access_admin_audit_logs(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/audit-logs')->assertForbidden();
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
