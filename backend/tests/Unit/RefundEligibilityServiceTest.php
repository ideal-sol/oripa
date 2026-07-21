<?php

namespace Tests\Unit;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\RefundEligibilityService;
use App\Domain\Point\Enums\PointLotSourceType;
use App\Domain\Point\Enums\PointType;
use App\Models\Payment;
use App\Models\PointLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_refund_when_all_payment_origin_points_are_unused(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $this->createLot($user, $payment, PointType::Paid, 1000, 1000);
        $this->createLot($user, $payment, PointType::Free, 200, 200);

        $result = app(RefundEligibilityService::class)->check($payment);

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['reason']);
        $this->assertSame(0, $result['used_amount']);
        $this->assertSame(1200, $result['refundable_amount']);
    }

    public function test_it_rejects_refund_when_any_payment_origin_point_was_used(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user);
        $this->createLot($user, $payment, PointType::Paid, 1000, 999);

        $result = app(RefundEligibilityService::class)->check($payment);

        $this->assertFalse($result['eligible']);
        $this->assertSame('Payment-origin points have already been used.', $result['reason']);
        $this->assertSame(1, $result['used_amount']);
        $this->assertSame(0, $result['refundable_amount']);
    }

    public function test_it_rejects_non_succeeded_payment(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment($user, PaymentStatus::Pending);
        $this->createLot($user, $payment, PointType::Paid, 1000, 1000);

        $result = app(RefundEligibilityService::class)->check($payment);

        $this->assertFalse($result['eligible']);
        $this->assertSame('Only succeeded payments can be refunded.', $result['reason']);
    }

    private function createPayment(User $user, PaymentStatus $status = PaymentStatus::Succeeded): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'payment-'.$user->id.'-'.strtolower($status->value),
            'status' => $status,
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 200,
            'currency' => 'JPY',
            'paid_at' => now(),
        ]);
    }

    private function createLot(User $user, Payment $payment, PointType $pointType, int $grantedAmount, int $remainingAmount): PointLot
    {
        return PointLot::query()->create([
            'user_id' => $user->id,
            'point_type' => $pointType,
            'granted_amount' => $grantedAmount,
            'remaining_amount' => $remainingAmount,
            'source_type' => PointLotSourceType::Purchase,
            'source_id' => $payment->id,
            'granted_at' => now()->subDay(),
            'expire_at' => $pointType === PointType::Free ? now()->addMonth() : null,
        ]);
    }
}
