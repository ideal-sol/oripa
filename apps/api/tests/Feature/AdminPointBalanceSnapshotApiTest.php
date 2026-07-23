<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\AdminUser;
use App\Models\PointBalanceSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPointBalanceSnapshotApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_latest_snapshot(): void
    {
        $this->actingAdmin();
        $this->createSnapshot('2026-03-31', 1000, 2000, true);
        $latest = $this->createSnapshot('2026-04-01', 1500, 2500, false);

        $this->getJson('/admin/api/point-balance-snapshots/latest')
            ->assertOk()
            ->assertJsonPath('data.id', $latest->id)
            ->assertJsonPath('data.snapshot_date', '2026-04-01')
            ->assertJsonPath('data.paid_balance', 1500)
            ->assertJsonPath('data.free_balance', 2500)
            ->assertJsonPath('data.total_balance', 4000)
            ->assertJsonPath('data.is_base_date', false)
            ->assertJsonPath('data.updated_at', null);
    }

    public function test_latest_snapshot_returns_null_when_no_snapshot_exists(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/api/point-balance-snapshots/latest')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_admin_can_filter_snapshot_list_by_date_range(): void
    {
        $this->actingAdmin();
        $this->createSnapshot('2026-03-30', 100, 200, false);
        $march = $this->createSnapshot('2026-03-31', 300, 400, true);
        $april = $this->createSnapshot('2026-04-01', 500, 600, false);
        $this->createSnapshot('2026-04-02', 700, 800, false);

        $this->getJson('/admin/api/point-balance-snapshots?date_from=2026-03-31&date_to=2026-04-01&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $april->id)
            ->assertJsonPath('data.0.snapshot_date', '2026-04-01')
            ->assertJsonPath('data.0.total_balance', 1100);

        $this->getJson('/admin/api/point-balance-snapshots?date_from=2026-03-31&date_to=2026-04-01&per_page=20&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0');

        $this->getJson('/admin/api/point-balance-snapshots?date_from=2026-03-31&date_to=2026-04-01&per_page=20')
            ->assertOk()
            ->assertJsonPath('data.1.id', $march->id)
            ->assertJsonPath('data.1.snapshot_date', '2026-03-31');
    }

    public function test_admin_can_get_base_date_snapshots_for_year_with_missing_dates(): void
    {
        $this->actingAdmin();
        $snapshot = $this->createSnapshot('2026-03-31', 900, 100, true);
        $this->createSnapshot('2025-09-30', 9999, 9999, true);

        $this->getJson('/admin/api/point-balance-snapshots/base-dates?year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-03-31')
            ->assertJsonPath('data.0.exists', true)
            ->assertJsonPath('data.0.snapshot.id', $snapshot->id)
            ->assertJsonPath('data.0.snapshot.total_balance', 1000)
            ->assertJsonPath('data.1.date', '2026-09-30')
            ->assertJsonPath('data.1.exists', false)
            ->assertJsonPath('data.1.snapshot', null);
    }

    public function test_snapshot_api_requires_admin_authentication(): void
    {
        $this->getJson('/admin/api/point-balance-snapshots/latest')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/point-balance-snapshots/latest')->assertForbidden();
    }

    public function test_snapshot_api_validates_dates_year_and_per_page(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/api/point-balance-snapshots?date_from=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');

        $this->getJson('/admin/api/point-balance-snapshots?date_from=2026-04-02&date_to=2026-04-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');

        $this->getJson('/admin/api/point-balance-snapshots?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->getJson('/admin/api/point-balance-snapshots/base-dates?year=1999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
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

    private function createSnapshot(string $date, int $paid, int $free, bool $isBaseDate): PointBalanceSnapshot
    {
        return PointBalanceSnapshot::query()->create([
            'snapshot_date' => $date,
            'paid_unused_balance' => $paid,
            'free_unused_balance' => $free,
            'is_base_date' => $isBaseDate,
            'created_at' => CarbonImmutable::parse($date.' 00:10:00', 'Asia/Tokyo'),
        ]);
    }
}
