<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_payments_with_filters(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['email' => 'buyer@example.test']);
        $payment = $this->createPayment($user, PaymentStatus::Succeeded, 'mock_payment_1');
        $this->createPayment(User::factory()->create(), PaymentStatus::Pending, 'mock_payment_2');

        $this->getJson('/admin/api/payments?status=succeeded')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.0.user.email', 'buyer@example.test');
    }

    public function test_admin_can_show_payment(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user, PaymentStatus::Pending, 'mock_payment_show');

        $this->getJson("/admin/api/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $payment->id)
            ->assertJsonPath('data.provider_payment_id', 'mock_payment_show')
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_user_token_cannot_access_admin_payments(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/payments')->assertForbidden();
    }

    public function test_admin_can_mark_succeeded_payment_as_refunded_and_audit_log_is_recorded(): void
    {
        $admin = $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user, PaymentStatus::Succeeded, 'mock_payment_refund');
        $wallet = $this->createWallet($user, paid: 1000, free: 100);
        $paidLot = $this->createPaymentLot($user, $payment, PointType::Paid, 1000);
        $freeLot = $this->createPaymentLot($user, $payment, PointType::Free, 100);

        $this->postJson("/admin/api/payments/{$payment->id}/refund", [
            'reason' => 'Customer support refund.',
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'refund')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.reason', 'Customer support refund.')
            ->assertJsonPath('data.payment.status', 'refunded')
            ->assertJsonPath('data.paid_reversed_amount', 1000)
            ->assertJsonPath('data.free_reversed_amount', 100)
            ->assertJsonPath('data.shortfall_paid_amount', 0)
            ->assertJsonPath('data.shortfall_free_amount', 0);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Refunded->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.payment.refunded',
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
        ]);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(0, $wallet->free_balance);
        $this->assertSame(0, $paidLot->refresh()->remaining_amount);
        $this->assertSame(0, $freeLot->refresh()->remaining_amount);
        $this->assertSame(2, PointLedger::query()->where('related_type', 'payment_reversal')->count());
        $this->assertDatabaseHas('payment_reversals', [
            'payment_id' => $payment->id,
            'admin_user_id' => $admin->id,
            'type' => 'refund',
            'status' => 'completed',
            'reason' => 'Customer support refund.',
        ]);
    }

    public function test_refund_rejects_pending_payment(): void
    {
        $this->actingAdmin();
        $payment = $this->createPayment(User::factory()->create(), PaymentStatus::Pending, 'mock_payment_pending_refund');

        $this->postJson("/admin/api/payments/{$payment->id}/refund")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Pending->value,
        ]);
    }

    public function test_admin_can_mark_chargeback_and_user_is_suspended(): void
    {
        $admin = $this->actingAdmin();
        $user = User::factory()->create(['status' => 'active']);
        $payment = $this->createPayment($user, PaymentStatus::Succeeded, 'mock_payment_chargeback');
        $wallet = $this->createWallet($user, paid: 1000, free: 100);
        $paidLot = $this->createPaymentLot($user, $payment, PointType::Paid, 1000);
        $freeLot = $this->createPaymentLot($user, $payment, PointType::Free, 100);

        $this->postJson("/admin/api/payments/{$payment->id}/chargeback", [
            'reason' => 'Card issuer dispute.',
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'chargeback')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.reason', 'Card issuer dispute.')
            ->assertJsonPath('data.payment.status', 'chargeback')
            ->assertJsonPath('data.user.status', 'suspended')
            ->assertJsonPath('data.paid_reversed_amount', 1000)
            ->assertJsonPath('data.free_reversed_amount', 100)
            ->assertJsonPath('data.shortfall_paid_amount', 0)
            ->assertJsonPath('data.shortfall_free_amount', 0);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Chargeback->value,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'suspended',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'admin.payment.chargeback',
            'auditable_type' => Payment::class,
            'auditable_id' => $payment->id,
        ]);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(0, $wallet->free_balance);
        $this->assertSame(0, $paidLot->refresh()->remaining_amount);
        $this->assertSame(0, $freeLot->refresh()->remaining_amount);
        $this->assertSame(2, PointLedger::query()->where('related_type', 'payment_reversal')->count());
        $this->assertDatabaseHas('payment_reversals', [
            'payment_id' => $payment->id,
            'admin_user_id' => $admin->id,
            'type' => 'chargeback',
            'status' => 'completed',
            'reason' => 'Card issuer dispute.',
        ]);
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

    private function createPayment(User $user, PaymentStatus $status, string $providerPaymentId): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => $providerPaymentId,
            'status' => $status,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'currency' => 'JPY',
            'paid_at' => $status === PaymentStatus::Succeeded ? now() : null,
        ]);
    }

    private function createWallet(User $user, int $paid, int $free): Wallet
    {
        return Wallet::query()->create([
            'user_id' => $user->id,
            'paid_balance' => $paid,
            'free_balance' => $free,
        ]);
    }

    private function createPaymentLot(User $user, Payment $payment, PointType $pointType, int $amount): PointLot
    {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $amount,
            'remaining_amount' => $amount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => $payment->id,
            'granted_at' => now()->subDay(),
            'expire_at' => $pointType === PointType::Free ? now()->addMonth() : null,
        ]);
    }
}
