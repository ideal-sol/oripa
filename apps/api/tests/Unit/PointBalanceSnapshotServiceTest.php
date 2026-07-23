<?php

namespace Tests\Unit;

use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Point\Services\PointBalanceSnapshotService;
use App\Models\PointBalanceSnapshot;
use App\Models\PointLot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointBalanceSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_aggregates_paid_and_free_unused_balances(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 00:10:00', 'Asia/Tokyo'));
        $user = User::factory()->create();

        $this->createLot($user, PointType::Paid, 1000, null);
        $this->createLot($user, PointType::Paid, 0, null);
        $this->createLot($user, PointType::Free, 300, CarbonImmutable::now('Asia/Tokyo')->addDay());
        $this->createLot($user, PointType::Free, 0, CarbonImmutable::now('Asia/Tokyo')->addDay());
        $this->createLot($user, PointType::Free, 500, CarbonImmutable::now('Asia/Tokyo')->subMinute());

        $result = app(PointBalanceSnapshotService::class)->createForDate('2026-04-01');

        $this->assertSame('2026-04-01', $result['snapshot_date']);
        $this->assertSame(1000, $result['paid_unused_balance']);
        $this->assertSame(300, $result['free_unused_balance']);
        $this->assertFalse($result['is_base_date']);
        $this->assertDatabaseHas('point_balance_snapshots', [
            'snapshot_date' => '2026-04-01',
            'paid_unused_balance' => 1000,
            'free_unused_balance' => 300,
            'is_base_date' => false,
        ]);
    }

    public function test_it_handles_paid_only_and_free_only_balances(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-02 00:10:00', 'Asia/Tokyo'));
        $paidUser = User::factory()->create();
        $freeUser = User::factory()->create();

        $this->createLot($paidUser, PointType::Paid, 700, null);
        $this->createLot($freeUser, PointType::Free, 400, CarbonImmutable::now('Asia/Tokyo')->addDay());

        $balances = app(PointBalanceSnapshotService::class)->calculateBalances();

        $this->assertSame([
            'paid_unused_balance' => 700,
            'free_unused_balance' => 400,
        ], $balances);
    }

    public function test_it_defaults_to_previous_day_in_asia_tokyo(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-01 00:10:00', 'Asia/Tokyo'));

        $result = app(PointBalanceSnapshotService::class)->createForDate();

        $this->assertSame('2026-03-31', $result['snapshot_date']);
        $this->assertTrue($result['is_base_date']);
    }

    public function test_it_marks_base_dates(): void
    {
        $service = app(PointBalanceSnapshotService::class);

        $this->assertTrue($service->isBaseDate(CarbonImmutable::parse('2026-03-31', 'Asia/Tokyo')));
        $this->assertTrue($service->isBaseDate(CarbonImmutable::parse('2026-09-30', 'Asia/Tokyo')));
        $this->assertFalse($service->isBaseDate(CarbonImmutable::parse('2026-04-01', 'Asia/Tokyo')));
    }

    public function test_it_updates_existing_snapshot_for_same_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 00:10:00', 'Asia/Tokyo'));
        $user = User::factory()->create();
        $service = app(PointBalanceSnapshotService::class);

        $lot = $this->createLot($user, PointType::Paid, 100, null);
        $first = $service->createForDate('2026-09-30');

        $lot->forceFill([
            'granted_amount' => 250,
            'remaining_amount' => 250,
        ])->save();
        $second = $service->createForDate('2026-09-30');

        $this->assertSame($first['snapshot']->id, $second['snapshot']->id);
        $this->assertSame(1, PointBalanceSnapshot::query()->where('snapshot_date', '2026-09-30')->count());
        $this->assertSame(250, $second['paid_unused_balance']);
        $this->assertTrue($second['is_base_date']);
    }

    private function createLot(User $user, PointType $pointType, int $remainingAmount, ?CarbonImmutable $expireAt): PointLot
    {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => max($remainingAmount, 1),
            'remaining_amount' => $remainingAmount,
            'source_type' => $pointType === PointType::Paid ? PointLotSourceType::Purchase : PointLotSourceType::Campaign,
            'source_id' => null,
            'granted_at' => CarbonImmutable::now('Asia/Tokyo')->subDay(),
            'expire_at' => $expireAt,
        ]);
    }
}
