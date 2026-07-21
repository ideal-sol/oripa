<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\Gacha;
use App\Models\Payment;
use App\Models\PointLedger;
use App\Models\PointPurchasePlan;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSalesCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_csv_endpoints_require_admin_authentication(): void
    {
        $this->get('/admin/api/sales/monthly.csv?year=2026&month=6')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->get('/admin/api/sales/monthly.csv?year=2026&month=6')->assertForbidden();
    }

    public function test_admin_can_export_sales_csv_files_with_bom_and_expected_headers(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['name' => 'CSV User', 'email' => 'csv@example.test']);
        $plan = PointPurchasePlan::query()->create([
            'name' => 'CSV Plan',
            'amount' => 6000,
            'paid_point_amount' => 6000,
            'free_point_amount' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $payment = $this->createPayment(
            $user,
            PaymentStatus::Chargeback,
            6000,
            '2026-06-11 13:43:00',
            chargebackAt: '2026-06-22 10:00:00',
            metadata: ['payment_method' => 'credit_card', 'point_purchase_plan_id' => $plan->id],
        );
        $this->createPayment($user, PaymentStatus::Refunded, 2000, '2026-06-20 12:00:00', refundedAt: '2026-06-22 11:00:00');
        $this->createPayment($user, PaymentStatus::Succeeded, 9999, '2026-07-01 00:00:00');

        $wallet = Wallet::query()->create(['user_id' => $user->id, 'paid_balance' => 0, 'free_balance' => 0]);
        $gacha = Gacha::factory()->create(['title' => 'CSV Gacha']);
        $drawRequest = $this->createDrawRequest($user, $gacha, '2026-06-05 12:00:00', 2);
        $outsideDrawRequest = $this->createDrawRequest($user, $gacha, '2026-07-01 12:00:00', 1);
        $this->createLedger($user, $wallet, PointType::Paid, -800, $drawRequest->id, '2026-06-05 12:00:01');
        $this->createLedger($user, $wallet, PointType::Free, -200, $drawRequest->id, '2026-06-05 12:00:02');
        $this->createLedger($user, $wallet, PointType::Paid, -999, $outsideDrawRequest->id, '2026-07-01 12:00:01');

        $monthly = $this->get('/admin/api/sales/monthly.csv?year=2026&month=6')->assertOk();
        $this->assertCsvResponse($monthly->getContent(), '対象日,総売上,返金金額,チャージバック金額,純売上');
        $monthly->assertHeader('content-disposition', 'attachment; filename="sales_monthly_2026-06.csv"');
        $this->assertStringContainsString('2026-06-11,6000,0,0,6000', $monthly->getContent());
        $this->assertStringContainsString('2026-06-22,0,2000,6000,-8000', $monthly->getContent());
        $this->assertStringNotContainsString('2026-07-01', $monthly->getContent());

        $dailyPayments = $this->get('/admin/api/sales/daily-payments.csv?date=2026-06-11')->assertOk();
        $this->assertCsvResponse($dailyPayments->getContent(), '決済日時,決済ID,ユーザー,メールアドレス,購入プラン');
        $dailyPayments->assertHeader('content-disposition', 'attachment; filename="sales_daily_payments_2026-06-11.csv"');
        $this->assertStringContainsString((string) $payment->id, $dailyPayments->getContent());
        $this->assertStringContainsString('CSV User', $dailyPayments->getContent());
        $this->assertStringContainsString('CSV Plan', $dailyPayments->getContent());

        $dailyAdjustments = $this->get('/admin/api/sales/daily-adjustments.csv?date=2026-06-22')->assertOk();
        $this->assertCsvResponse($dailyAdjustments->getContent(), '発生日,種別,決済ID,元決済日');
        $dailyAdjustments->assertHeader('content-disposition', 'attachment; filename="sales_daily_adjustments_2026-06-22.csv"');
        $this->assertStringContainsString('チャージバック', $dailyAdjustments->getContent());
        $this->assertStringContainsString('返金', $dailyAdjustments->getContent());

        $originalPaymentDateAdjustments = $this->get('/admin/api/sales/daily-adjustments.csv?date=2026-06-11')->assertOk();
        $this->assertStringNotContainsString((string) $payment->id, $originalPaymentDateAdjustments->getContent());

        $monthlyPoints = $this->get('/admin/api/sales/monthly-point-consumption.csv?year=2026&month=6')->assertOk();
        $this->assertCsvResponse($monthlyPoints->getContent(), '対象日,有償ポイント消費合計,無償ポイント消費合計');
        $monthlyPoints->assertHeader('content-disposition', 'attachment; filename="sales_monthly_point_consumption_2026-06.csv"');
        $this->assertStringContainsString('CSV Gacha', $monthlyPoints->getContent());
        $this->assertStringContainsString('2026-06-05,800,200', $monthlyPoints->getContent());

        $dailyPoints = $this->get('/admin/api/sales/daily-point-consumption.csv?date=2026-06-05')->assertOk();
        $this->assertCsvResponse($dailyPoints->getContent(), '日時,"draw_request ID",ユーザー,メールアドレス,ガチャID,ガチャ名');
        $dailyPoints->assertHeader('content-disposition', 'attachment; filename="sales_daily_point_consumption_2026-06-05.csv"');
        $this->assertStringContainsString((string) $drawRequest->id, $dailyPoints->getContent());
        $this->assertStringContainsString('CSV Gacha', $dailyPoints->getContent());
        $this->assertStringContainsString(',800,200,', $dailyPoints->getContent());
    }

    public function test_sales_csv_validates_request_parameters(): void
    {
        $this->actingAdmin();

        $this->get('/admin/api/sales/daily-payments.csv?date=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
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

    private function assertCsvResponse(string $content, string $expectedHeaderPrefix): void
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString($expectedHeaderPrefix, $content);
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
            'provider_payment_id' => 'csv-payment-'.fake()->uuid(),
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
            'idempotency_key' => 'sales-csv-'.fake()->uuid(),
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

    private function createLedger(User $user, Wallet $wallet, PointType $pointType, int $amount, int $drawRequestId, string $createdAt): PointLedger
    {
        $ledger = PointLedger::query()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'point_lot_id' => null,
            'point_type' => $pointType,
            'ledger_type' => PointLedgerType::Spend,
            'amount' => $amount,
            'balance_after' => 0,
            'related_type' => 'draw_request',
            'related_id' => $drawRequestId,
            'description' => 'csv test',
        ]);

        $timestamp = CarbonImmutable::parse($createdAt, 'Asia/Tokyo');
        $ledger->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save();

        return $ledger;
    }
}
