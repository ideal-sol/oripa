<?php

namespace Tests\Feature;

use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Models\PointLot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointBalanceSnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_command_creates_snapshot_for_specified_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-31 00:10:00', 'Asia/Tokyo'));
        $user = User::factory()->create();
        $this->createLot($user, PointType::Paid, 1200, null);
        $this->createLot($user, PointType::Free, 800, CarbonImmutable::now('Asia/Tokyo')->addDay());

        $this->artisan('points:snapshot-balances --date=2026-03-31')
            ->expectsOutput('Snapshot 2026-03-31 saved. paid=1200 free=800 base_date=true')
            ->assertSuccessful();

        $this->assertDatabaseHas('point_balance_snapshots', [
            'snapshot_date' => '2026-03-31',
            'paid_unused_balance' => 1200,
            'free_unused_balance' => 800,
            'is_base_date' => true,
        ]);
    }

    public function test_command_defaults_to_previous_day_in_asia_tokyo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 00:05:00', 'Asia/Tokyo'));

        $this->artisan('points:snapshot-balances')
            ->expectsOutput('Snapshot 2026-03-31 saved. paid=0 free=0 base_date=true')
            ->assertSuccessful();

        $this->assertDatabaseHas('point_balance_snapshots', [
            'snapshot_date' => '2026-03-31',
            'paid_unused_balance' => 0,
            'free_unused_balance' => 0,
            'is_base_date' => true,
        ]);
    }

    public function test_command_marks_september_thirtieth_as_base_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 00:10:00', 'Asia/Tokyo'));

        $this->artisan('points:snapshot-balances')
            ->expectsOutput('Snapshot 2026-09-30 saved. paid=0 free=0 base_date=true')
            ->assertSuccessful();

        $this->assertDatabaseHas('point_balance_snapshots', [
            'snapshot_date' => '2026-09-30',
            'is_base_date' => true,
        ]);
    }

    public function test_command_rejects_invalid_date(): void
    {
        $this->artisan('points:snapshot-balances --date=2026-02-31')
            ->expectsOutput('Date must be in YYYY-MM-DD format.')
            ->assertFailed();
    }

    public function test_scheduler_lists_snapshot_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('points:snapshot-balances')
            ->assertSuccessful();
    }

    private function createLot(User $user, PointType $pointType, int $amount, ?CarbonImmutable $expireAt): PointLot
    {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'source_type' => $pointType === PointType::Paid ? PointLotSourceType::Purchase : PointLotSourceType::Campaign,
            'source_id' => null,
            'granted_at' => CarbonImmutable::now('Asia/Tokyo')->subDay(),
            'expire_at' => $expireAt,
        ]);
    }
}
