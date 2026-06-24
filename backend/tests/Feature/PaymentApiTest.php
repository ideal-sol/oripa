<?php

namespace Tests\Feature;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentPointGrantService;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Models\Payment;
use App\Models\PointPurchasePlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_pending_payment_without_point_grant(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/payments', [
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'terms_accepted' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 1000)
            ->assertJsonPath('data.paid_point_amount', 1000)
            ->assertJsonPath('data.free_point_amount', 100)
            ->assertJsonPath('data.currency', 'JPY')
            ->assertJsonPath('data.provider', 'mock');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'status' => PaymentStatus::Pending->value,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'currency' => 'JPY',
        ]);
        $this->assertDatabaseCount('point_lots', 0);
        $this->assertDatabaseCount('point_ledgers', 0);
        $this->assertDatabaseMissing('wallets', [
            'user_id' => $user->id,
        ]);
    }

    public function test_payment_payload_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/payments', [
            'amount' => 0,
            'paid_point_amount' => 0,
            'provider' => 'stripe',
            'terms_accepted' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'paid_point_amount', 'provider', 'terms_accepted']);
    }

    public function test_expired_point_purchase_plan_cannot_be_used_for_payment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $plan = PointPurchasePlan::query()->create([
            'name' => 'Expired',
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'sort_order' => 1,
            'is_active' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        $this->postJson('/api/payments', [
            'point_purchase_plan_id' => $plan->id,
            'terms_accepted' => true,
        ])
            ->assertNotFound();

        $this->assertDatabaseMissing('payments', [
            'user_id' => $user->id,
            'amount' => 1000,
        ]);
    }

    public function test_guest_cannot_create_payment(): void
    {
        $this->postJson('/api/payments', [
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'terms_accepted' => true,
        ])->assertUnauthorized();
    }

    public function test_payment_success_grants_paid_points_once(): void
    {
        config(['services.discord.admin_webhook_url' => 'https://discord.test/webhook']);
        Http::fake([
            'discord.test/*' => Http::response('', 204),
        ]);
        $user = User::factory()->create();
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'mock_test_payment',
            'status' => PaymentStatus::Pending,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'currency' => 'JPY',
        ]);

        app(PaymentPointGrantService::class)->markSucceeded($payment, 'evt_1');
        app(PaymentPointGrantService::class)->markSucceeded($payment->refresh(), 'evt_1');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Succeeded->value,
            'webhook_event_id' => 'evt_1',
        ]);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 1000,
            'free_balance' => 100,
        ]);
        $this->assertDatabaseHas('point_lots', [
            'user_id' => $user->id,
            'point_type' => PointType::Paid->value,
            'granted_amount' => 1000,
            'remaining_amount' => 1000,
            'expire_at' => null,
        ]);
        $this->assertDatabaseHas('point_lots', [
            'user_id' => $user->id,
            'point_type' => PointType::Free->value,
            'granted_amount' => 100,
            'remaining_amount' => 100,
        ]);
        $this->assertDatabaseHas('point_ledgers', [
            'user_id' => $user->id,
            'point_type' => PointType::Paid->value,
            'ledger_type' => PointLedgerType::Purchase->value,
            'amount' => 1000,
            'related_type' => 'purchase',
            'related_id' => $payment->id,
        ]);
        $this->assertDatabaseCount('point_lots', 2);
        $this->assertDatabaseCount('point_ledgers', 2);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://discord.test/webhook'
            && str_contains($request['content'], '【ポイント購入完了】')
            && str_contains($request['content'], '決済ID: '.$payment->id)
            && str_contains($request['content'], '有償ポイント: 1,000pt'));
    }

    public function test_user_can_confirm_own_mock_payment_in_local_environment(): void
    {
        $this->app['env'] = 'local';
        $user = User::factory()->create();
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'mock_local_checkout',
            'status' => PaymentStatus::Pending,
            'amount' => 3000,
            'paid_point_amount' => 3000,
            'free_point_amount' => 300,
            'currency' => 'JPY',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/payments/{$payment->id}/mock-succeed")
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.paid_point_amount', 3000)
            ->assertJsonPath('data.free_point_amount', 300);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $user->id,
            'paid_balance' => 3000,
            'free_balance' => 300,
        ]);
    }

    public function test_user_cannot_confirm_other_users_mock_payment(): void
    {
        $this->app['env'] = 'local';
        $user = User::factory()->create();
        $other = User::factory()->create();
        $payment = Payment::query()->create([
            'user_id' => $other->id,
            'provider' => 'mock',
            'provider_payment_id' => 'mock_other_checkout',
            'status' => PaymentStatus::Pending,
            'amount' => 3000,
            'paid_point_amount' => 3000,
            'free_point_amount' => 0,
            'currency' => 'JPY',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/payments/{$payment->id}/mock-succeed")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertDatabaseCount('point_lots', 0);
    }
}
