<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\QaTestUserMode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminQaTestUserModeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_owner_can_get_current_mode_and_missing_user_returns_null_data(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14 10:00:00', 'Asia/Tokyo'));
        $owner = $this->actingAdmin(AdminRole::Owner);
        $user = User::factory()->create();

        $this->getJson("/admin/api/users/{$user->id}/qa-test-mode")
            ->assertOk()
            ->assertJsonPath('data', null);

        $mode = $this->createMode($user, $owner, [
            'reason' => 'Current QA',
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHour(),
        ]);

        $this->getJson("/admin/api/users/{$user->id}/qa-test-mode")
            ->assertOk()
            ->assertJsonPath('data.id', $mode->id)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.reason', 'Current QA')
            ->assertJsonPath('data.enabled_by_admin_user_id', $owner->id)
            ->assertJsonPath('data.disabled_by_admin_user_id', null)
            ->assertJsonPath('data.disabled_at', null);
    }

    public function test_owner_can_enable_update_and_disable_mode(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14 10:00:00', 'Asia/Tokyo'));
        $owner = $this->actingAdmin(AdminRole::Owner);
        $user = User::factory()->create();

        $this->putJson("/admin/api/users/{$user->id}/qa-test-mode", [
            'reason' => 'Enable QA',
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->toIso8601String(),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHours(2)->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.reason', 'Enable QA');

        $mode = QaTestUserMode::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_test_user.enabled',
            'admin_user_id' => $owner->id,
            'user_id' => $user->id,
            'auditable_id' => $mode->id,
        ]);

        $this->putJson("/admin/api/users/{$user->id}/qa-test-mode", [
            'reason' => 'Updated QA',
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHours(3)->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $mode->id)
            ->assertJsonPath('data.reason', 'Updated QA')
            ->assertJsonPath('data.starts_at', null);

        $this->assertSame(1, QaTestUserMode::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_test_user.updated',
            'admin_user_id' => $owner->id,
            'user_id' => $user->id,
            'auditable_id' => $mode->id,
        ]);

        $this->deleteJson("/admin/api/users/{$user->id}/qa-test-mode")
            ->assertOk()
            ->assertJsonPath('data.id', $mode->id)
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.disabled_by_admin_user_id', $owner->id);

        $this->assertSame(1, QaTestUserMode::query()->whereKey($mode->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_test_user.disabled',
            'admin_user_id' => $owner->id,
            'user_id' => $user->id,
            'auditable_id' => $mode->id,
        ]);
    }

    public function test_expired_mode_is_returned_as_inactive(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14 10:00:00', 'Asia/Tokyo'));
        $owner = $this->actingAdmin(AdminRole::Owner);
        $user = User::factory()->create();
        $mode = $this->createMode($user, $owner, [
            'reason' => 'Expired QA',
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->subHours(2),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->subHour(),
        ]);

        $this->getJson("/admin/api/users/{$user->id}/qa-test-mode")
            ->assertOk()
            ->assertJsonPath('data.id', $mode->id)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_validation_errors_for_required_and_invalid_periods(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14 10:00:00', 'Asia/Tokyo'));
        $this->actingAdmin(AdminRole::Owner);
        $user = User::factory()->create();

        $this->putJson("/admin/api/users/{$user->id}/qa-test-mode", [
            'reason' => 'Missing ends_at',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->putJson("/admin/api/users/{$user->id}/qa-test-mode", [
            'reason' => 'Invalid range',
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->addHour()->toIso8601String(),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->toIso8601String(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');

        $this->putJson("/admin/api/users/{$user->id}/qa-test-mode", [
            'reason' => 'Too long',
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->toIso8601String(),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHours(25)->toIso8601String(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_admin_and_operator_are_forbidden_for_read_and_write(): void
    {
        $user = User::factory()->create();

        $this->actingAdmin(AdminRole::Admin);
        $this->getJson("/admin/api/users/{$user->id}/qa-test-mode")->assertForbidden();
        $this->putJson("/admin/api/users/{$user->id}/qa-test-mode", [
            'reason' => 'Forbidden',
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertForbidden();
        $this->deleteJson("/admin/api/users/{$user->id}/qa-test-mode")->assertForbidden();

        $this->actingAdmin(AdminRole::Operator);
        $this->getJson("/admin/api/users/{$user->id}/qa-test-mode")->assertForbidden();
        $this->putJson("/admin/api/users/{$user->id}/qa-test-mode", [
            'reason' => 'Forbidden',
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertForbidden();
        $this->deleteJson("/admin/api/users/{$user->id}/qa-test-mode")->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $user = User::factory()->create();

        $this->getJson("/admin/api/users/{$user->id}/qa-test-mode")->assertUnauthorized();
        $this->putJson("/admin/api/users/{$user->id}/qa-test-mode")->assertUnauthorized();
        $this->deleteJson("/admin/api/users/{$user->id}/qa-test-mode")->assertUnauthorized();
    }

    private function actingAdmin(AdminRole $role): AdminUser
    {
        $admin = AdminUser::factory()->create([
            'role' => $role,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin, ['admin']);

        return $admin;
    }

    private function createMode(User $user, AdminUser $owner, array $attributes = []): QaTestUserMode
    {
        return QaTestUserMode::query()->create(array_merge([
            'user_id' => $user->id,
            'is_enabled' => true,
            'reason' => 'QA test',
            'starts_at' => null,
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHour(),
            'enabled_by_admin_user_id' => $owner->id,
        ], $attributes));
    }
}
