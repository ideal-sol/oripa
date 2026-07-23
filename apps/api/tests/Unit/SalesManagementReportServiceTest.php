<?php

namespace Tests\Unit;

use App\Domain\Admin\Services\SalesManagementReportService;
use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Models\DrawRequest;
use App\Models\Gacha;
use App\Models\Payment;
use App\Models\PointLedger;
use App\Models\PointPurchasePlan;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesManagementReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_sales_aggregates_gross_refund_chargeback_net_and_methods(): void
    {
        $service = app(SalesManagementReportService::class);
        $user = User::factory()->create();

        $this->createPayment($user, PaymentStatus::Succeeded, 1000, '2026-06-01 10:00:00', metadata: ['payment_method' => 'credit_card']);
        $this->createPayment($user, PaymentStatus::Refunded, 2000, '2026-06-01 11:00:00', refundedAt: '2026-06-02 12:00:00');
        $this->createPayment($user, PaymentStatus::Chargeback, 3000, '2026-06-03 11:00:00', chargebackAt: '2026-06-03 12:00:00');
        $this->createPayment($user, PaymentStatus::Pending, 9000, null);
        $this->createPayment($user, PaymentStatus::Failed, 9000, null);
        $this->createPayment($user, PaymentStatus::Canceled, 9000, null);

        $report = $service->monthlySales(2026, 6);

        $this->assertSame(6000, $report['total_amount']);
        $this->assertSame(2000, $report['refund_amount']);
        $this->assertSame(3000, $report['chargeback_amount']);
        $this->assertSame(1000, $report['net_amount']);
        $this->assertCount(30, $report['days']);

        $juneFirst = collect($report['days'])->firstWhere('date', '2026-06-01');
        $this->assertSame(3000, $juneFirst['total_amount']);
        $this->assertSame([
            ['payment_method' => 'credit_card', 'amount' => 1000, 'count' => 1],
            ['payment_method' => 'mock', 'amount' => 2000, 'count' => 1],
        ], $juneFirst['methods']);

        $juneSecond = collect($report['days'])->firstWhere('date', '2026-06-02');
        $this->assertSame(2000, $juneSecond['refund_amount']);

        $juneThird = collect($report['days'])->firstWhere('date', '2026-06-03');
        $this->assertSame(3000, $juneThird['total_amount']);
        $this->assertSame(3000, $juneThird['chargeback_amount']);
        $this->assertSame(0, $juneThird['net_amount']);
    }

    public function test_daily_payments_uses_paid_at_and_resolves_purchase_plan_with_fallback(): void
    {
        $service = app(SalesManagementReportService::class);
        $user = User::factory()->create(['name' => 'Buyer']);
        $plan = PointPurchasePlan::query()->create([
            'name' => 'Gold Plan',
            'amount' => 3000,
            'paid_point_amount' => 3000,
            'free_point_amount' => 300,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->createPayment($user, PaymentStatus::Succeeded, 3000, '2026-06-10 10:00:00', metadata: ['point_purchase_plan_id' => $plan->id]);
        $this->createPayment($user, PaymentStatus::Succeeded, 5000, '2026-06-10 11:00:00', metadata: ['point_purchase_plan_id' => 999999]);
        $this->createPayment($user, PaymentStatus::Succeeded, 9000, '2026-06-11 00:00:00');

        $result = $service->dailyPayments('2026-06-10', 20);

        $this->assertCount(2, $result['data']);
        $this->assertSame('削除済みプラン', $result['data'][0]['purchase_plan']['name']);
        $this->assertTrue($result['data'][0]['purchase_plan']['deleted']);
        $this->assertSame('Gold Plan', $result['data'][1]['purchase_plan']['name']);
        $this->assertSame('Buyer', $result['data'][1]['user']['name']);
    }

    public function test_daily_adjustments_uses_refunded_at_and_chargeback_at_dates(): void
    {
        $service = app(SalesManagementReportService::class);
        $user = User::factory()->create(['name' => 'Adjusted Buyer']);
        $plan = PointPurchasePlan::query()->create([
            'name' => 'Adjustment Plan',
            'amount' => 6000,
            'paid_point_amount' => 6000,
            'free_point_amount' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $chargeback = $this->createPayment(
            $user,
            PaymentStatus::Chargeback,
            6000,
            '2026-06-11 13:43:00',
            chargebackAt: '2026-06-22 10:00:00',
            metadata: ['payment_method' => 'credit_card', 'point_purchase_plan_id' => $plan->id],
        );
        $this->createPayment($user, PaymentStatus::Refunded, 2000, '2026-06-20 12:00:00', refundedAt: '2026-06-22 11:00:00');
        $this->createPayment($user, PaymentStatus::Pending, 9999, null, refundedAt: '2026-06-22 12:00:00');
        $this->createPayment($user, PaymentStatus::Failed, 9999, null, chargebackAt: '2026-06-22 12:30:00');

        $monthly = $service->monthlySales(2026, 6);
        $juneEleventh = collect($monthly['days'])->firstWhere('date', '2026-06-11');
        $juneTwentySecond = collect($monthly['days'])->firstWhere('date', '2026-06-22');

        $this->assertSame(6000, $juneEleventh['total_amount']);
        $this->assertSame(6000, $juneTwentySecond['chargeback_amount']);

        $originalDayPayments = $service->dailyPayments('2026-06-11', 20);
        $this->assertCount(1, $originalDayPayments['data']);
        $this->assertSame($chargeback->id, $originalDayPayments['data'][0]['id']);
        $this->assertSame('chargeback', $originalDayPayments['data'][0]['status']);
        $this->assertNotNull($originalDayPayments['data'][0]['chargeback_at']);

        $chargebackDayPayments = $service->dailyPayments('2026-06-22', 20);
        $this->assertCount(0, $chargebackDayPayments['data']);

        $adjustments = $service->dailyAdjustments('2026-06-22');
        $this->assertSame(0, $adjustments['summary']['total_amount']);
        $this->assertSame(2000, $adjustments['summary']['refund_amount']);
        $this->assertSame(6000, $adjustments['summary']['chargeback_amount']);
        $this->assertSame(-8000, $adjustments['summary']['net_amount']);
        $this->assertCount(2, $adjustments['data']);
        $this->assertSame(['refund', 'chargeback'], collect($adjustments['data'])->pluck('type')->all());
        $this->assertSame('Adjustment Plan', collect($adjustments['data'])->firstWhere('type', 'chargeback')['purchase_plan']['name']);

        $originalDayAdjustments = $service->dailyAdjustments('2026-06-11');
        $this->assertCount(0, $originalDayAdjustments['data']);
    }

    public function test_monthly_point_consumption_aggregates_paid_and_free_by_draw_request_and_gacha(): void
    {
        $service = app(SalesManagementReportService::class);
        $user = User::factory()->create();
        $wallet = Wallet::query()->create(['user_id' => $user->id, 'paid_balance' => 0, 'free_balance' => 0]);
        $gacha = Gacha::factory()->create(['title' => 'Sales Gacha']);
        $drawRequest = $this->createDrawRequest($user, $gacha, '2026-06-05 12:00:00', 3);

        $this->createLedger($user, $wallet, PointType::Paid, PointLedgerType::Spend, -1000, 'draw_request', $drawRequest->id, '2026-06-05 12:00:01');
        $this->createLedger($user, $wallet, PointType::Free, PointLedgerType::Spend, -500, 'draw_request', $drawRequest->id, '2026-06-05 12:00:02');
        $this->createLedger($user, $wallet, PointType::Paid, PointLedgerType::Expire, -999, 'draw_request', $drawRequest->id, '2026-06-05 12:00:03');
        $this->createLedger($user, $wallet, PointType::Paid, PointLedgerType::Spend, -999, 'manual_adjustment', 1, '2026-06-05 12:00:04');

        $report = $service->monthlyPointConsumption(2026, 6);
        $day = collect($report['days'])->firstWhere('date', '2026-06-05');

        $this->assertSame(1000, $report['paid_point_total']);
        $this->assertSame(500, $report['free_point_total']);
        $this->assertSame(1000, $day['paid_point_total']);
        $this->assertSame(500, $day['free_point_total']);
        $this->assertSame('Sales Gacha', $day['gachas'][0]['gacha_title']);
        $this->assertSame(3, $day['gachas'][0]['draw_count']);
    }

    public function test_daily_point_consumption_returns_draw_request_rows_and_obeys_asia_tokyo_boundaries(): void
    {
        $service = app(SalesManagementReportService::class);
        $user = User::factory()->create();
        $wallet = Wallet::query()->create(['user_id' => $user->id, 'paid_balance' => 0, 'free_balance' => 0]);
        $gacha = Gacha::factory()->create(['title' => 'Boundary Gacha']);
        $included = $this->createDrawRequest($user, $gacha, '2026-06-01 00:00:00', 1);
        $excluded = $this->createDrawRequest($user, $gacha, '2026-06-02 00:00:00', 1);

        $this->createLedger($user, $wallet, PointType::Paid, PointLedgerType::Spend, -100, 'draw_request', $included->id, '2026-06-01 00:00:00');
        $this->createLedger($user, $wallet, PointType::Paid, PointLedgerType::Spend, -999, 'draw_request', $excluded->id, '2026-06-02 00:00:00');

        $result = $service->dailyPointConsumption('2026-06-01', 20);

        $this->assertCount(1, $result['data']);
        $this->assertSame($included->id, $result['data'][0]['draw_request_id']);
        $this->assertSame(100, $result['data'][0]['paid_point']);
        $this->assertSame(0, $result['data'][0]['free_point']);
        $this->assertSame('Boundary Gacha', $result['data'][0]['gacha']['title']);
    }

    private function createPayment(
        User $user,
        PaymentStatus $status,
        int $amount,
        ?string $paidAt,
        ?string $refundedAt = null,
        ?string $chargebackAt = null,
        array $metadata = [],
    ): Payment {
        return Payment::query()->create([
            'user_id' => $user->id,
            'provider' => 'mock',
            'provider_payment_id' => 'payment-'.fake()->uuid(),
            'status' => $status,
            'amount' => $amount,
            'paid_point_amount' => $amount,
            'free_point_amount' => 0,
            'currency' => 'JPY',
            'metadata' => $metadata,
            'paid_at' => $paidAt ? CarbonImmutable::parse($paidAt, 'Asia/Tokyo') : null,
            'refunded_at' => $refundedAt ? CarbonImmutable::parse($refundedAt, 'Asia/Tokyo') : null,
            'chargeback_at' => $chargebackAt ? CarbonImmutable::parse($chargebackAt, 'Asia/Tokyo') : null,
        ]);
    }

    private function createDrawRequest(User $user, Gacha $gacha, string $createdAt, int $drawCount): DrawRequest
    {
        $drawRequest = DrawRequest::query()->create([
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_count' => $drawCount,
            'idempotency_key' => 'sales-'.fake()->uuid(),
            'status' => DrawRequestStatus::Completed,
            'consumed_point_total' => $gacha->price * $drawCount,
        ]);

        $timestamp = CarbonImmutable::parse($createdAt, 'Asia/Tokyo');
        $drawRequest->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save();

        return $drawRequest;
    }

    private function createLedger(
        User $user,
        Wallet $wallet,
        PointType $pointType,
        PointLedgerType $ledgerType,
        int $amount,
        string $relatedType,
        int $relatedId,
        string $createdAt,
    ): PointLedger {
        $ledger = PointLedger::query()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => null,
            'point_type' => $pointType,
            'ledger_type' => $ledgerType,
            'amount' => $amount,
            'balance_after' => 0,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'description' => 'test',
        ]);

        $timestamp = CarbonImmutable::parse($createdAt, 'Asia/Tokyo');
        $ledger->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save();

        return $ledger;
    }
}
