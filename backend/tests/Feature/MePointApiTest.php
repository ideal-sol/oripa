<?php

namespace Tests\Feature;

use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Services\PointLotService;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MePointApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_me(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'user@example.test',
            'status' => 'active',
        ]);
        Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => 100,
            'free_balance' => 50,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'user@example.test')
            ->assertJsonPath('data.wallet.paid_balance', 100)
            ->assertJsonPath('data.wallet.free_balance', 50)
            ->assertJsonPath('data.wallet.total_balance', 150);
    }

    public function test_guest_cannot_get_me(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_authenticated_user_can_get_point_balance_and_lots(): void
    {
        $user = User::factory()->create();

        app(PointLotService::class)->grantPaid($user, 1000, description: 'Purchase points.');
        app(PointLotService::class)->grantFree(
            user: $user,
            amount: 100,
            expireAt: now()->addDays(30),
            sourceType: PointLotSourceType::Campaign,
            description: 'Campaign points.',
        );

        Sanctum::actingAs($user);

        $this->getJson('/api/me/points')
            ->assertOk()
            ->assertJsonPath('wallet.paid_balance', 1000)
            ->assertJsonPath('wallet.free_balance', 100)
            ->assertJsonPath('wallet.total_balance', 1100)
            ->assertJsonCount(2, 'lots')
            ->assertJsonPath('lots.0.point_type', 'free')
            ->assertJsonPath('lots.0.remaining_amount', 100)
            ->assertJsonPath('lots.1.point_type', 'paid')
            ->assertJsonPath('lots.1.expire_at', null);
    }

    public function test_authenticated_user_can_get_only_own_point_ledgers(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        app(PointLotService::class)->grantPaid($user, 1000, description: 'User purchase.');
        app(PointLotService::class)->grantFree(
            user: $user,
            amount: 100,
            expireAt: now()->addDays(30),
            sourceType: PointLotSourceType::Campaign,
            description: 'User campaign.',
        );
        app(PointLotService::class)->grantPaid($other, 999, description: 'Other purchase.');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me/point-ledgers');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.description', 'User campaign.')
            ->assertJsonPath('data.1.description', 'User purchase.');

        $this->assertStringNotContainsString('Other purchase.', $response->getContent());
    }
}
