<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\AdminUser;
use App\Models\Payment;
use App\Models\User;
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
        $payment = $this->createPayment(User::factory()->create(), PaymentStatus::Succeeded, 'mock_payment_refund');

        $this->postJson("/admin/api/payments/{$payment->id}/refund", [
            'reason' => 'Customer support refund.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.metadata.admin_status_change.reason', 'Customer support refund.')
            ->assertJsonPath('data.metadata.admin_status_change.point_reversal', 'pending_manual_or_followup_process');

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

        $this->postJson("/admin/api/payments/{$payment->id}/chargeback", [
            'reason' => 'Card issuer dispute.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'chargeback')
            ->assertJsonPath('data.user.status', 'suspended');

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
}
