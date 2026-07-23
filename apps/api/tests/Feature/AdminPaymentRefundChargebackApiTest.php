<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Payment\Enums\PaymentReversalPointBucket;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\PaymentReversal;
use App\Models\PointLedger;
use App\Models\PointLot;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPaymentRefundChargebackApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_check_refund_eligibility(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 1000, freePointAmount: 100);
        $this->createLot($user, PointType::Paid, 1000, 1000, $payment->id);
        $this->createLot($user, PointType::Free, 100, 100, $payment->id);

        $this->getJson("/admin/api/payments/{$payment->id}/refund-eligibility")
            ->assertOk()
            ->assertJsonPath('data.payment_id', $payment->id)
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.used_amount', 0)
            ->assertJsonPath('data.refundable_amount', 1100);
    }

    public function test_admin_can_refund_unused_payment_origin_points(): void
    {
        $admin = $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 1000, freePointAmount: 200);
        $wallet = $this->createWallet($user, paid: 1000, free: 200);
        $paidLot = $this->createLot($user, PointType::Paid, 1000, 1000, $payment->id);
        $freeLot = $this->createLot($user, PointType::Free, 200, 200, $payment->id);

        $this->postJson("/admin/api/payments/{$payment->id}/refund", [
            'reason' => 'Customer support refund.',
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'refund')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment.status', 'refunded')
            ->assertJsonPath('data.admin_user_id', $admin->id)
            ->assertJsonPath('data.paid_reversed_amount', 1000)
            ->assertJsonPath('data.free_reversed_amount', 200);

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertNotNull($payment->refunded_at);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(0, $wallet->free_balance);
        $this->assertSame(0, $paidLot->refresh()->remaining_amount);
        $this->assertSame(0, $freeLot->refresh()->remaining_amount);
        $this->assertSame(2, PointLedger::query()->where('related_type', 'payment_reversal')->count());
    }

    public function test_refund_rejects_used_payment_origin_points_with_422(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $payment = $this->createPayment($user, paidPointAmount: 1000, freePointAmount: 0);
        $this->createWallet($user, paid: 999, free: 0);
        $this->createLot($user, PointType::Paid, 1000, 999, $payment->id);

        $this->postJson("/admin/api/payments/{$payment->id}/refund")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(0, PaymentReversal::query()->count());
    }

    public function test_admin_can_chargeback_payment_without_double_reversal(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['status' => 'active']);
        $payment = $this->createPayment($user, paidPointAmount: 500, freePointAmount: 200);
        $wallet = $this->createWallet($user, paid: 300, free: 450);
        $this->createLot($user, PointType::Paid, 300, 300, null);
        $this->createLot($user, PointType::Free, 450, 450, null);

        $first = $this->postJson("/admin/api/payments/{$payment->id}/chargeback", [
            'reason' => 'Card dispute.',
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'chargeback')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment.status', 'chargeback')
            ->assertJsonPath('data.shortfall_paid_amount', 0)
            ->json('data.id');

        $second = $this->postJson("/admin/api/payments/{$payment->id}/chargeback", [
            'reason' => 'Duplicate webhook.',
        ])
            ->assertOk()
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(PaymentStatus::Chargeback, $payment->refresh()->status);
        $this->assertSame('suspended', $user->refresh()->status);
        $this->assertSame(0, $wallet->refresh()->paid_balance);
        $this->assertSame(50, $wallet->free_balance);
        $this->assertSame(1, PaymentReversal::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(3, PointLedger::query()->where('related_type', 'payment_reversal')->count());
        $this->assertSame(200, \App\Models\PaymentReversalPointEntry::query()->where('bucket', PaymentReversalPointBucket::PaidPurchaseShortfallFromFree->value)->sum('amount'));
    }

    public function test_non_admin_user_cannot_access_refund_api(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $payment = $this->createPayment(User::factory()->create(), paidPointAmount: 100, freePointAmount: 0);

        $this->postJson("/admin/api/payments/{$payment->id}/refund")->assertForbidden();
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

    private function createPayment(User $user, int $paidPointAmount, int $freePointAmount): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'admin-payment-'.$user->id.'-'.$paidPointAmount.'-'.$freePointAmount.'-'.uniqid(),
            'status' => PaymentStatus::Succeeded,
            'amount' => 1000,
            'paid_point_amount' => $paidPointAmount,
            'free_point_amount' => $freePointAmount,
            'currency' => 'JPY',
            'paid_at' => now(),
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

    private function createLot(User $user, PointType $pointType, int $grantedAmount, int $remainingAmount, ?int $sourceId): PointLot
    {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $grantedAmount,
            'remaining_amount' => $remainingAmount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => $sourceId,
            'granted_at' => now()->subDay(),
            'expire_at' => $pointType === PointType::Free ? now()->addMonth() : null,
        ]);
    }
}
