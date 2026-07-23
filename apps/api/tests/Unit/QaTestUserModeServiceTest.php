<?php

namespace Tests\Unit;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Services\QaTestUserModeService;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\QaTestUserMode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaTestUserModeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_creates_and_updates_qa_test_user_mode_with_audit_logs(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14 10:00:00', 'Asia/Tokyo'));
        $service = app(QaTestUserModeService::class);
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();

        $mode = $service->upsert($user, $owner, [
            'reason' => 'Initial QA test',
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->toIso8601String(),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHour()->toIso8601String(),
        ]);

        $this->assertTrue($mode->is_enabled);
        $this->assertTrue($service->isActive($mode));
        $this->assertSame('Initial QA test', $mode->reason);
        $this->assertSame($owner->id, $mode->enabled_by_admin_user_id);
        $this->assertNull($mode->disabled_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_test_user.enabled',
            'admin_user_id' => $owner->id,
            'user_id' => $user->id,
            'auditable_type' => QaTestUserMode::class,
            'auditable_id' => $mode->id,
        ]);

        $updated = $service->upsert($user, $owner, [
            'reason' => 'Updated QA test',
            'starts_at' => null,
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHours(2)->toIso8601String(),
        ]);

        $this->assertSame($mode->id, $updated->id);
        $this->assertSame('Updated QA test', $updated->reason);
        $this->assertNull($updated->starts_at);
        $this->assertSame(1, QaTestUserMode::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_test_user.updated',
            'admin_user_id' => $owner->id,
            'user_id' => $user->id,
            'auditable_type' => QaTestUserMode::class,
            'auditable_id' => $mode->id,
        ]);
    }

    public function test_it_disables_without_deleting_and_records_audit_log(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14 10:00:00', 'Asia/Tokyo'));
        $service = app(QaTestUserModeService::class);
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();
        $mode = $service->upsert($user, $owner, [
            'reason' => 'Disable QA test',
            'starts_at' => null,
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHour()->toIso8601String(),
        ]);

        $disabled = $service->disable($user, $owner);

        $this->assertSame($mode->id, $disabled?->id);
        $this->assertFalse($disabled->is_enabled);
        $this->assertFalse($service->isActive($disabled));
        $this->assertSame($owner->id, $disabled->disabled_by_admin_user_id);
        $this->assertNotNull($disabled->disabled_at);
        $this->assertSame(1, QaTestUserMode::query()->whereKey($mode->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.qa_test_user.disabled',
            'admin_user_id' => $owner->id,
            'user_id' => $user->id,
            'auditable_type' => QaTestUserMode::class,
            'auditable_id' => $mode->id,
        ]);
    }

    public function test_it_treats_expired_and_future_modes_as_inactive(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-14 10:00:00', 'Asia/Tokyo'));
        $service = app(QaTestUserModeService::class);
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();

        $expired = QaTestUserMode::query()->create([
            'user_id' => $user->id,
            'is_enabled' => true,
            'reason' => 'Expired',
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->subHours(3),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->subHour(),
            'enabled_by_admin_user_id' => $owner->id,
        ]);

        $this->assertFalse($service->isActive($expired));

        $future = $service->upsert($user, $owner, [
            'reason' => 'Future',
            'starts_at' => CarbonImmutable::now('Asia/Tokyo')->addHour()->toIso8601String(),
            'ends_at' => CarbonImmutable::now('Asia/Tokyo')->addHours(2)->toIso8601String(),
        ]);

        $this->assertFalse($service->isActive($future));
    }

    public function test_disabling_missing_mode_is_safe(): void
    {
        $service = app(QaTestUserModeService::class);
        $owner = AdminUser::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();

        $this->assertNull($service->disable($user, $owner));
        $this->assertSame(0, AuditLog::query()->where('action', 'admin.qa_test_user.disabled')->count());
    }
}
