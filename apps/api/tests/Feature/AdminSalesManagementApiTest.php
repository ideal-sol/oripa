<?php

namespace Tests\Feature;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Point\Enums\PointLedgerType;
use App\Domain\Point\Enums\PointType;
use App\Domain\Probability\Services\ProbabilityVersionPublisher;
use App\Models\AdminUser;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaPrize;
use App\Models\GachaRank;
use App\Models\Payment;
use App\Models\PointLedger;
use App\Models\PointPurchasePlan;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSalesManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_monthly_sales_summary(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();

        $this->createPayment($user, PaymentStatus::Succeeded, 1000, '2026-06-01 10:00:00', metadata: ['payment_method' => 'credit_card']);
        $this->createPayment($user, PaymentStatus::Refunded, 2000, '2026-06-01 11:00:00', refundedAt: '2026-06-02 10:00:00');
        $this->createPayment($user, PaymentStatus::Chargeback, 3000, '2026-06-03 11:00:00', chargebackAt: '2026-06-03 12:00:00');
        $this->createPayment($user, PaymentStatus::Pending, 9000, null);
        $this->createPayment($user, PaymentStatus::Failed, 9000, null);
        $this->createPayment($user, PaymentStatus::Canceled, 9000, null);

        $this->getJson('/admin/api/sales/monthly?year=2026&month=6')
            ->assertOk()
            ->assertJsonPath('data.total_amount', 6000)
            ->assertJsonPath('data.refund_amount', 2000)
            ->assertJsonPath('data.chargeback_amount', 3000)
            ->assertJsonPath('data.net_amount', 1000)
            ->assertJsonPath('data.days.0.date', '2026-06-01')
            ->assertJsonPath('data.days.0.total_amount', 3000)
            ->assertJsonPath('data.days.0.methods.0.payment_method', 'credit_card');
    }

    public function test_admin_can_get_daily_payments_with_plan_fallback(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['name' => 'Daily Buyer']);
        $plan = PointPurchasePlan::query()->create([
            'name' => 'Daily Plan',
            'amount' => 1000,
            'paid_point_amount' => 1000,
            'free_point_amount' => 100,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->createPayment($user, PaymentStatus::Succeeded, 1000, '2026-06-10 10:00:00', metadata: ['point_purchase_plan_id' => $plan->id]);
        $this->createPayment($user, PaymentStatus::Succeeded, 2000, '2026-06-10 11:00:00', metadata: ['point_purchase_plan_id' => 999999]);
        $this->createPayment($user, PaymentStatus::Succeeded, 3000, '2026-06-11 00:00:00');

        $this->getJson('/admin/api/sales/daily-payments?date=2026-06-10&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.amount', 2000)
            ->assertJsonPath('data.0.purchase_plan.name', '削除済みプラン')
            ->assertJsonPath('data.0.purchase_plan.deleted', true);

        $this->getJson('/admin/api/sales/daily-payments?date=2026-06-10&per_page=20')
            ->assertOk()
            ->assertJsonPath('data.1.purchase_plan.name', 'Daily Plan')
            ->assertJsonPath('data.1.user.name', 'Daily Buyer');
    }

    public function test_admin_can_get_daily_refund_and_chargeback_adjustments_by_event_date(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create(['name' => 'Adjustment User']);
        $plan = PointPurchasePlan::query()->create([
            'name' => 'Adjustment Plan',
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
            metadata: ['payment_method' => 'demo', 'point_purchase_plan_id' => $plan->id],
        );
        $this->createPayment($user, PaymentStatus::Refunded, 2000, '2026-06-20 12:00:00', refundedAt: '2026-06-22 11:00:00');
        $this->createPayment($user, PaymentStatus::Pending, 9999, null, refundedAt: '2026-06-22 12:00:00');
        $this->createPayment($user, PaymentStatus::Failed, 9999, null, chargebackAt: '2026-06-22 12:30:00');

        $this->getJson('/admin/api/sales/monthly?year=2026&month=6')
            ->assertOk()
            ->assertJsonPath('data.days.10.date', '2026-06-11')
            ->assertJsonPath('data.days.10.total_amount', 6000)
            ->assertJsonPath('data.days.21.date', '2026-06-22')
            ->assertJsonPath('data.days.21.chargeback_amount', 6000);

        $this->getJson('/admin/api/sales/daily-payments?date=2026-06-11')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.status', 'chargeback')
            ->assertJsonPath('data.0.chargeback_at', fn ($value): bool => is_string($value) && str_starts_with($value, '2026-06-22T'));

        $this->getJson('/admin/api/sales/daily-payments?date=2026-06-22')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/admin/api/sales/daily-adjustments?date=2026-06-22')
            ->assertOk()
            ->assertJsonPath('summary.total_amount', 0)
            ->assertJsonPath('summary.refund_amount', 2000)
            ->assertJsonPath('summary.chargeback_amount', 6000)
            ->assertJsonPath('summary.net_amount', -8000)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'refund')
            ->assertJsonPath('data.1.type', 'chargeback')
            ->assertJsonPath('data.1.payment_id', $payment->id)
            ->assertJsonPath('data.1.original_paid_at', fn ($value): bool => is_string($value) && str_starts_with($value, '2026-06-11T'))
            ->assertJsonPath('data.1.purchase_plan.name', 'Adjustment Plan')
            ->assertJsonPath('data.1.payment_method', 'demo')
            ->assertJsonPath('data.1.status', 'chargeback');

        $this->getJson('/admin/api/sales/daily-adjustments?date=2026-06-11')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_daily_adjustments_requires_admin_and_valid_date(): void
    {
        $this->getJson('/admin/api/sales/daily-adjustments?date=2026-06-22')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/sales/daily-adjustments?date=2026-06-22')->assertForbidden();

        $this->actingAdmin();

        $this->getJson('/admin/api/sales/daily-adjustments?date=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_admin_can_get_monthly_and_daily_point_consumption(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        $wallet = Wallet::query()->create(['user_id' => $user->id, 'paid_balance' => 0, 'free_balance' => 0]);
        $gacha = Gacha::factory()->create(['title' => 'API Sales Gacha']);
        $drawRequest = $this->createDrawRequest($user, $gacha, '2026-06-05 12:00:00', 2);

        $this->createLedger($user, $wallet, PointType::Paid, PointLedgerType::Spend, -800, 'draw_request', $drawRequest->id, '2026-06-05 12:00:01');
        $this->createLedger($user, $wallet, PointType::Free, PointLedgerType::Spend, -200, 'draw_request', $drawRequest->id, '2026-06-05 12:00:02');
        $this->createLedger($user, $wallet, PointType::Paid, PointLedgerType::Exchange, -999, 'draw_request', $drawRequest->id, '2026-06-05 12:00:03');

        $this->getJson('/admin/api/sales/monthly-point-consumption?year=2026&month=6')
            ->assertOk()
            ->assertJsonPath('data.paid_point_total', 800)
            ->assertJsonPath('data.free_point_total', 200)
            ->assertJsonPath('data.days.4.date', '2026-06-05')
            ->assertJsonPath('data.days.4.gachas.0.gacha_title', 'API Sales Gacha')
            ->assertJsonPath('data.days.4.gachas.0.draw_count', 2);

        $this->getJson('/admin/api/sales/daily-point-consumption?date=2026-06-05')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.draw_request_id', $drawRequest->id)
            ->assertJsonPath('data.0.paid_point', 800)
            ->assertJsonPath('data.0.free_point', 200)
            ->assertJsonPath('data.0.gacha.title', 'API Sales Gacha');
    }

    public function test_admin_can_get_draw_request_detail_with_results(): void
    {
        $this->actingAdmin();
        $user = User::factory()->create();
        [$gacha, $rank, $prize] = $this->createPrizeFixture();
        $version = $gacha->currentProbabilityVersion()->with('stages')->firstOrFail();
        $stage = $version->stages->first();
        $drawRequest = $this->createDrawRequest($user, $gacha, '2026-06-06 12:00:00', 1);

        DrawResult::query()->create([
            'draw_request_id' => $drawRequest->id,
            'user_id' => $user->id,
            'gacha_id' => $gacha->id,
            'draw_sequence_number' => 1,
            'rank_id' => $rank->id,
            'prize_id' => $prize->id,
            'result_type' => DrawResultType::Prize,
            'consumed_point' => $gacha->price,
            'granted_point' => 0,
            'random_value' => 123,
            'probability_version_id' => $version->id,
            'probability_version_stage_id' => $stage->id,
        ]);

        $this->getJson("/admin/api/sales/draw-requests/{$drawRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $drawRequest->id)
            ->assertJsonPath('data.results.0.draw_sequence_number', 1)
            ->assertJsonPath('data.results.0.result_type', 'prize')
            ->assertJsonPath('data.results.0.rank.display_name', 'Sales Rank')
            ->assertJsonPath('data.results.0.prize.name', 'Sales Prize')
            ->assertJsonPath('data.results.0.consumed_point', $gacha->price)
            ->assertJsonPath('data.results.0.granted_point', 0);
    }

    public function test_sales_api_requires_admin_authentication(): void
    {
        $this->getJson('/admin/api/sales/monthly?year=2026&month=6')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/admin/api/sales/monthly?year=2026&month=6')->assertForbidden();
    }

    public function test_sales_api_validates_dates_and_per_page_limit(): void
    {
        $this->actingAdmin();

        $this->getJson('/admin/api/sales/daily-payments?date=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->getJson('/admin/api/sales/daily-point-consumption?date=2026-06-01&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
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
            'idempotency_key' => 'sales-api-'.fake()->uuid(),
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

    /**
     * @return array{0: Gacha, 1: GachaRank, 2: GachaPrize}
     */
    private function createPrizeFixture(): array
    {
        $gacha = Gacha::factory()->create([
            'title' => 'Sales Detail Gacha',
            'slug' => 'sales-detail-gacha-'.fake()->uuid(),
        ]);
        $rank = GachaRank::factory()->create([
            'gacha_id' => $gacha->id,
            'rank_key' => 'S',
            'display_name' => 'Sales Rank',
        ]);
        $prize = GachaPrize::factory()->forGachaAndRank($gacha, $rank)->create([
            'name' => 'Sales Prize',
        ]);

        app(ProbabilityVersionPublisher::class)->publish($gacha, [
            [
                'stage_key' => 'stage_1',
                'name' => 'Stage 1',
                'min_draw_number' => 1,
                'max_draw_number' => null,
                'probabilities' => [
                    ['prize_id' => $prize->id, 'probability_ppm' => 1_000_000],
                    ['is_minimum_guarantee' => true, 'probability_ppm' => 0],
                ],
            ],
        ], AdminUser::factory()->create());

        return [$gacha->refresh(), $rank, $prize];
    }
}
