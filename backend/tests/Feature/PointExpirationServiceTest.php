<?php

namespace Tests\Feature;

use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Point\Services\PointExpirationService;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointExpirationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_expires_only_expired_free_point_lots(): void
    {
        $user = User::factory()->create();
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 500,
            'free_balance' => 300,
        ]);

        $expired = $this->createLot($user, PointType::Free, 200, now()->subDays(10), now()->subMinute());
        $active = $this->createLot($user, PointType::Free, 100, now()->subDay(), now()->addDay());
        $paid = $this->createLot($user, PointType::Paid, 500, now()->subDay(), null);

        $result = app(PointExpirationService::class)->expire();

        $this->assertSame([
            'expired_lot_count' => 1,
            'expired_point_amount' => 200,
        ], $result);
        $this->assertSame(0, $expired->refresh()->remaining_amount);
        $this->assertSame(100, $active->refresh()->remaining_amount);
        $this->assertSame(500, $paid->refresh()->remaining_amount);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 500,
            'free_balance' => 100,
        ]);
        $this->assertDatabaseHas('point_ledgers', [
            'user_id' => $user->id,
            'point_lot_id' => $expired->id,
            'point_type' => PointType::Free->value,
            'ledger_type' => PointLedgerType::Expire->value,
            'amount' => -200,
            'balance_after' => 100,
            'related_type' => 'point_lot',
            'related_id' => $expired->id,
        ]);
    }

    public function test_points_expire_command_runs_service(): void
    {
        $user = User::factory()->create();
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 0,
            'free_balance' => 50,
        ]);
        $this->createLot($user, PointType::Free, 50, now()->subDay(), now()->subMinute());

        $this->artisan('points:expire --limit=10')
            ->expectsOutput('Expired 1 point lots / 50 points.')
            ->assertSuccessful();

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'free_balance' => 0,
        ]);
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
