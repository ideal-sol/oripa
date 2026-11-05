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
    use PaidyFixtures, MemberRankAccountingFixture, \Tests\V2\Fixtures\UnivapayFixtures;
    protected function setUp(): void { parent::setUp(); $this->configurePaidy(); }
    protected function tearDown(): void { $this->quiescePaidyFixtureJobs(); Carbon::setTestNow(); CarbonImmutable::setTestNow(); parent::tearDown(); }

    public function test_new_buyer_jst_registration_optional_config_and_method_visibility(): void
    {
        DB::beginTransaction();
        try {
            $this->rankClock('2026-11-05T15:01:00Z');
            $user = $this->paidyUser();
            $user->forceFill(['created_at' => '2026-11-04T15:01:00Z'])->save();
            $data = app(V2PaidyBuyerData::class)->forUser($user);
            self::assertSame('2026-11-05', $data['account_registration_date']);
            self::assertSame(1, $data['age']);
            self::assertSame(['credit_card_used' => false], $data['previous_payment_methods']);
            self::assertSame(['コイン'], $data['order_item_categories']);
            foreach (['days_since_first_transaction', 'last_order_at', 'delivery_locn_type', 'billing_address', 'shipping_address'] as $key) self::assertArrayNotHasKey($key, $data);
            config(['v2_paidy.delivery_locn_type' => 'synthetic', 'v2_paidy.order_item_categories' => ' コイン, , synthetic ', 'v2_payment.methods_enabled' => 'credit_card,konbini,virtual_account,paidy']);
            $data = app(V2PaidyBuyerData::class)->forUser($user);
            self::assertSame('synthetic', $data['delivery_locn_type']);
            self::assertSame(['コイン', 'synthetic'], $data['order_item_categories']);
            self::assertSame(['credit_card_used' => false, 'convenience_store_prepayment_used' => false, 'bank_transfer_used' => false], $data['previous_payment_methods']);
            config(['v2_paidy.delivery_locn_type' => ' ', 'v2_paidy.order_item_categories' => ' , ']);
            $data = app(V2PaidyBuyerData::class)->forUser($user);
            self::assertArrayNotHasKey('delivery_locn_type', $data);
            self::assertArrayNotHasKey('order_item_categories', $data);
        } finally { DB::rollBack(); }
    }

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
            self::assertSame(100, $data['days_since_first_transaction']);
            self::assertSame(['credit_card_used' => true], $data['previous_payment_methods']);
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

    public function test_previous_methods_use_eligible_successes_across_providers_and_exclude_adjustments(): void
    {
        DB::beginTransaction();
        try {
            $this->rankClock('2026-11-05T15:01:00Z');
            $this->configureUnivapay('live');
            config(['v2_fincode.base_url' => 'https://api.fincode.jp', 'v2_univapay.mode' => 'live', 'v2_payment.methods_enabled' => 'credit_card,konbini,virtual_account,paidy']);
            $user = $this->paidyUser(); $service = app(V2PaymentService::class);
            foreach ([['univapay', 'credit_card'], ['fincode', 'konbini'], ['fincode', 'virtual_account']] as [$provider, $method]) {
                $payment = $service->createPayment($user->id, $this->paidyPlan()->id, $provider, null, (string) Str::uuid(), $method);
                $event = $service->recordVerifiedProviderEvent($provider, 'v23:'.Str::uuid(), 'payment.succeeded', '{}', [], $payment->id, providerOccurredAt: CarbonImmutable::parse('2026-11-04T15:01:00Z'));
                $service->confirmSucceeded($event->id);
            }
            $data = app(V2PaidyBuyerData::class)->forUser($user);
            self::assertSame(1, $data['days_since_first_transaction']);
            self::assertSame(['credit_card_used' => true, 'convenience_store_prepayment_used' => true, 'bank_transfer_used' => true], $data['previous_payment_methods']);
            config(['v2_payment.methods_enabled' => 'credit_card,netridecash,paidy']);
            self::assertSame(['credit_card_used' => true], app(V2PaidyBuyerData::class)->forUser($user)['previous_payment_methods']);
            $excludedUser = $this->paidyUser();
            $excluded = $this->settleRankPayment($excludedUser, 1000);
            // Deferred run FK, rolled back with this isolated test transaction.
            DB::select('SELECT pg_advisory_xact_lock(740022, 1)');
            DB::table('member_rank_prelaunch_excluded_origins')->insert(['payment_id' => $excluded->id, 'run_id' => 1]);
            $data = app(V2PaidyBuyerData::class)->forUser($excludedUser);
            self::assertSame(['credit_card_used' => false], $data['previous_payment_methods']);
            self::assertArrayNotHasKey('days_since_first_transaction', $data);
            foreach (['refund', 'chargeback'] as $type) {
                $other = $this->paidyUser();
                $payment = $this->settleRankPayment($other, 1000);
                $admin = $this->rankAdmin();
                $adjustments = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
                $result = $adjustments->write($admin, $payment->public_id, $type, ['reason' => 'Synthetic v23 exclusion'], (string) Str::uuid());
                if ($type === 'refund') $adjustments->write($admin, $payment->public_id, 'complete', ['reason' => 'Synthetic v23 exclusion', 'provider_refund_confirmed' => true], (string) Str::uuid(), $result['data']['id']);
                $data = app(V2PaidyBuyerData::class)->forUser($other);
                self::assertSame(['credit_card_used' => false], $data['previous_payment_methods']);
                self::assertArrayNotHasKey('days_since_first_transaction', $data);
            }
        } finally { DB::rollBack(); }
    }
}
