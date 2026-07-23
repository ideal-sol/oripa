<?php

namespace Tests\Feature;

use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Point\Exceptions\InsufficientPointsException;
use App\Domain\Point\Services\PointConsumptionService;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointConsumptionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_consumes_free_lots_by_nearest_expiration_then_paid_lots_by_fifo(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 200,
            'free_balance' => 150,
        ]);

        $freeLater = $this->createLot($user, PointType::Free, 100, now()->subDays(2), now()->addDays(30));
        $paidNewer = $this->createLot($user, PointType::Paid, 100, now()->subDay(), null);
        $freeSooner = $this->createLot($user, PointType::Free, 50, now()->subDay(), now()->addDays(3));
        $paidOlder = $this->createLot($user, PointType::Paid, 100, now()->subDays(10), null);

        $consumptions = app(PointConsumptionService::class)->consume(
            user: $user,
            amount: 180,
            relatedType: 'test',
            relatedId: 1,
        );

        $this->assertSame([
            ['lot_id' => $freeSooner->id, 'point_type' => PointType::Free->value, 'amount' => 50],
            ['lot_id' => $freeLater->id, 'point_type' => PointType::Free->value, 'amount' => 100],
            ['lot_id' => $paidOlder->id, 'point_type' => PointType::Paid->value, 'amount' => 30],
        ], $consumptions);

        $this->assertSame(170, $wallet->refresh()->paid_balance);
        $this->assertSame(0, $wallet->free_balance);
        $this->assertSame(70, $paidOlder->refresh()->remaining_amount);
        $this->assertSame(100, $paidNewer->refresh()->remaining_amount);
        $this->assertSame(0, $freeSooner->refresh()->remaining_amount);
        $this->assertSame(0, $freeLater->refresh()->remaining_amount);
        $this->assertDatabaseCount('point_ledgers', 3);

        $this->assertSame([-50, -100, -30], PointLedger::query()->orderBy('id')->pluck('amount')->all());
    }

    public function test_it_rejects_insufficient_points(): void
    {
        $user = User::factory()->create();
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 10,
            'free_balance' => 0,
        ]);
        $this->createLot($user, PointType::Paid, 10, now()->subDay(), null);

        $this->expectException(InsufficientPointsException::class);

        app(PointConsumptionService::class)->consume($user, 11, 'test', 1);
    }

    private function createLot(User $user, PointType $pointType, int $amount, \DateTimeInterface $grantedAt, ?\DateTimeInterface $expireAt): PointLot
    {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'source_type' => $pointType === PointType::Paid ? PointLotSourceType::Purchase : PointLotSourceType::Campaign,
            'source_id' => null,
            'granted_at' => $grantedAt,
            'expire_at' => $expireAt,
        ]);
    }
}
