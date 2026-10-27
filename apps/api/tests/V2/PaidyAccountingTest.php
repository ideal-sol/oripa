<?php

namespace Tests\V2;

use App\Domain\Payment\V2\Services\V2PaidyBuyerData;
use App\Domain\Payment\V2\Services\V2PaidyPaymentService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;
use Tests\V2\Fixtures\PaidyFixtures;

final class PaidyAccountingTest extends TestCase
{
    use PaidyFixtures, MemberRankAccountingFixture;
    protected function setUp(): void { parent::setUp(); $this->configurePaidy(); }
    protected function tearDown(): void { $this->quiescePaidyFixtureJobs(); Carbon::setTestNow(); CarbonImmutable::setTestNow(); parent::tearDown(); }

    public function test_buyer_data_uses_non_paidy_commercial_purchases_and_jst_elapsed_days(): void
    {
        DB::beginTransaction();
        try {
            $this->rankClock('2026-10-27T04:00:00Z');
            $user = $this->paidyUser();
            $user->forceFill(['created_at' => now()->subDays(120)])->save();
            $service = app(V2PaymentService::class);
            foreach ([['live', 1000, 2], ['test', 9000, 1], ['live', 2000, 100]] as [$mode, $amount, $days]) {
                config(['v2_fincode.base_url' => $mode === 'live' ? 'https://api.fincode.jp' : 'https://api.test.fincode.jp']);
                $plan = $this->paidyPlan($amount);
                $payment = $service->createPayment($user->id, $plan->id, 'fincode', null, (string) Str::uuid(), 'credit_card');
                $event = $service->recordVerifiedProviderEvent('fincode', 'buyer:'.Str::uuid(), 'payment.succeeded', '{}', [], $payment->id, providerOccurredAt: now()->subDays($days));
                $service->confirmSucceeded($event->id);
            }
            // Paidy itself, including live Paidy, is never credit history.
            config(['v2_paidy.mode' => 'live']);
            $plan = $this->paidyPlan(5000);
            $paidy = $service->createPayment($user->id, $plan->id, 'paidy', null, (string) Str::uuid(), 'paidy');
            $event = $service->recordVerifiedProviderEvent('paidy', 'buyer:'.Str::uuid(), 'payment.succeeded', '{}', [], $paidy->id, providerOccurredAt: now());
            $service->confirmSucceeded($event->id);
            $data = app(V2PaidyBuyerData::class)->forUser($user);
            self::assertSame(120, $data['age']); self::assertSame(2, $data['order_count']); self::assertSame(3000, $data['ltv']);
            self::assertSame(1, $data['order_count_last3months']); self::assertSame(1000, $data['order_amount_last3months']);
            self::assertSame(1000, $data['last_order_amount']); self::assertSame(2, $data['last_order_at']);
            self::assertSame(17400, $data['number_of_points']);
        } finally { DB::rollBack(); }
    }

    public function test_live_paidy_reuses_rank_authority_once_but_test_paidy_does_not_count(): void
    {
        $this->configureRankFixture();
        DB::beginTransaction();
        try {
            $this->enableRankFixture();
            foreach (['test', 'live'] as $mode) {
                $this->configurePaidy($mode);
                [$user, $payment] = $this->paidyPayment();
                $service = app(V2PaymentService::class);
                $event = $service->recordVerifiedProviderEvent('paidy', 'rank:'.Str::uuid(), 'payment.succeeded', '{}', [], $payment->id, providerOccurredAt: now());
                $service->confirmSucceeded($event->id);
                $service->confirmSucceeded($event->id);
                self::assertSame('succeeded', DB::table('payments')->where('id', $payment->id)->value('status'));
                self::assertSame(1, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
                self::assertSame(1, DB::table('mail_deliveries')->where('source_public_id', $payment->public_id)->count());
                self::assertSame($mode === 'live' ? 1000 : 0, (int) DB::table('member_rank_month_totals')->where('user_id', $user->id)->sum('points'));
                self::assertSame($mode === 'live' ? 1 : 0, DB::table('member_rank_point_entries')->where('payment_id', $payment->id)->count());
                self::assertSame(0, app(V2PaidyBuyerData::class)->forUser($user)['order_count']);
            }
        } finally {
            DB::rollBack();
        }
    }
}
