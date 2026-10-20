<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankAdminReadService;
use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\MemberRank\Services\V2MemberRankAccountingService;
use App\Domain\Payment\V2\Exceptions\V2AdminPaymentReadException;
use App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Point\Services\V2PointService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankPaymentAdjustmentTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        DB::beginTransaction();
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function lotShapes(): array
    {
        return [['refund', 100, 50, 2], ['cancel', 100, 50, 2], ['chargeback', 100, 50, 2],
            ['refund', 100, 0, 2], ['refund', 0, 50, 2], ['refund', 0, 0, 2],
            ['chargeback', 0, 0, 1], ['refund', 100, 50, 1]];
    }

    #[DataProvider('lotShapes')]
    public function test_actual_payment_free_and_separate_bonus_lots_are_adjusted_exactly_once(
        string $operation, int $free, int $limited, int $level
    ): void {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, $level);
        [$initial] = $this->rankPayment($user, 4000, $free);
        if ($limited > 0) {
            DB::table('point_purchase_plan_limited_bonus_campaigns')->insert([
                'public_id' => (string) Str::uuid7(), 'point_purchase_plan_id' => $initial->point_purchase_plan_id,
                'is_enabled' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
                'bonus_point_amount' => $limited, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        [$payment, $event] = $this->rankPayment($user, 4000, $free, null, 'credit_card', $initial->point_purchase_plan_id);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        $bonus = $level === 2 ? 20 : 0;
        $grant = DB::table('payment_point_grants')->where('payment_id', $payment->id)->sole();
        $lots = DB::table('point_lots')->where('grant_operation_id', $grant->point_operation_id)->get();
        self::assertCount(1 + ($free + $limited > 0 ? 1 : 0), $lots);
        self::assertSame($free + $limited, (int) $lots->where('point_type', 'free')->sum('granted_amount'));
        $snapshot = DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->sole();
        self::assertSame($bonus, (int) $snapshot->bonus_amount);
        self::assertSame($bonus > 0 ? 1 : 0, DB::table('point_lots')->where('grant_operation_id', $snapshot->point_operation_id)->count());
        $revision = (int) DB::table('member_rank_states')->where('user_id', $user->id)->value('revision');
        $service = app(V2AdminPaymentAdjustmentService::class);
        $context = $this->rankAdmin();
        $firstOperation = $operation === 'chargeback' ? 'chargeback' : 'refund';
        $result = $service->write($context, $payment->public_id, $firstOperation, ['reason' => 'M8取消'], (string) Str::uuid7());
        if ($operation !== 'chargeback') {
            $adjustmentId = DB::table('payment_adjustments')->where('public_id', $result['data']['id'])->value('id');
            self::assertSame(1 + ($free + $limited > 0 ? 1 : 0) + ($bonus > 0 ? 1 : 0),
                DB::table('point_lot_reservations')->where('payment_adjustment_id', $adjustmentId)->count());
            self::assertSame($free + $limited + $bonus, (int) DB::table('wallets')->where('user_id', $user->id)->value('free_reserved_balance'));
            $result = $service->write($context, $payment->public_id, $operation === 'cancel' ? 'cancel' : 'complete',
                ['reason' => 'M8確定', ...($operation === 'refund' ? ['provider_refund_confirmed' => true] : [])],
                (string) Str::uuid7(), $result['data']['id']);
        }
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'points' => $operation === 'cancel' ? 4000 : 0]);
        self::assertSame($revision + ($operation === 'cancel' ? 0 : 1), (int) DB::table('member_rank_states')->where('user_id', $user->id)->value('revision'));
        self::assertSame($operation === 'cancel' ? 0 : 1, DB::table('member_rank_payment_adjustments')->where('payment_id', $payment->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_reserved_balance' => 0, 'paid_reserved_balance' => 0,
            'paid_balance' => $operation === 'cancel' ? 4000 : 0, 'free_balance' => $operation === 'cancel' ? $free + $limited + $bonus : 0]);
        if ($operation !== 'cancel') {
            self::assertSame($free + $limited + $bonus, $result['data']['impact']['required_free_amount']);
            self::assertSame(['required_amount' => $bonus, 'reversed_amount' => $bonus, 'shortfall_amount' => 0], $result['data']['impact']['purchase_bonus']);
            self::assertSame(4000, $result['data']['impact']['rank_adjustment']['applied_points']);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_current_month_reversal_recovers_promotion_and_allows_only_future_reachievement(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 10000);
        $result = $this->chargeback($payment);
        self::assertSame(500, $result['data']['impact']['rank_adjustment']['recoveries'][0]['recovered_amount']);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 1]);
        self::assertDatabaseHas('member_rank_entitlements', ['user_id' => $user->id, 'level' => 2, 'achievement_id' => null]);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => 0, 'paid_balance' => 0]);
        $this->settleRankPayment($user, 10000);
        self::assertSame(2, DB::table('member_rank_achievements')->where('user_id', $user->id)->where('level', 2)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function expiryModes(): array
    {
        return [[false, 0], [true, 0], [false, 1], [true, 1]];
    }

    #[DataProvider('expiryModes')]
    public function test_expired_payment_and_bonus_lots_are_not_debited_at_exact_expiry(bool $provider, int $offset): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        $payment = $this->settleRankPayment($user, 4000, 150);
        $expiry = DB::table('point_lots')->where('user_id', $user->id)->min('expire_at');
        $service = app(V2AdminPaymentAdjustmentService::class);
        $this->rankClock(CarbonImmutable::parse($expiry)->subSecond()->toIso8601String());
        $preview = $service->preview($this->rankAdmin(), $payment->public_id, ['reason' => '期限前']);
        self::assertSame(170, $preview['data']['impact']['reversed_free_from_free']);
        $this->rankClock(CarbonImmutable::parse($expiry)->addSeconds($offset)->toIso8601String());
        if ($provider) {
            $payments = app(V2PaymentService::class);
            $event = $payments->recordVerifiedProviderEvent('fincode', 'm8-expiry:'.Str::uuid7(), 'payment.chargeback', '{}', [], $payment->id, null, now());
            $payments->processChargeback($event->id);
            $result = ['data' => $service->detail($this->rankAdmin(), $payment->public_id)['data']['adjustments'][0]];
        } else {
            $result = $this->chargeback($payment);
        }
        self::assertSame(4000, $result['data']['impact']['shortfall_paid_amount']);
        self::assertSame(170, $result['data']['impact']['shortfall_free_amount']);
        self::assertSame(0, $result['data']['impact']['reversed_free_from_free']);
        self::assertSame(20, $result['data']['impact']['purchase_bonus']['shortfall_amount']);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 4000, 'free_balance' => 170]);
        self::assertSame(4170, (int) DB::table('point_lots')->where('user_id', $user->id)->sum('remaining_amount'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_past_month_adjustment_changes_only_origin_total_and_has_separate_review(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 10000);
        $before = DB::table('member_rank_states')->where('user_id', $user->id)->sole();
        $this->rankClock('2026-10-31T15:00:00Z');
        $result = $this->chargeback($payment);
        self::assertSame('past_month', $result['data']['impact']['rank_adjustment']['effect']);
        self::assertSame([], $result['data']['impact']['rank_adjustment']['recoveries']);
        self::assertEquals($before, DB::table('member_rank_states')->where('user_id', $user->id)->sole());
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-10-01', 'points' => 0]);
        $read = app(V2MemberRankAdminReadService::class);
        $summary = $read->summary();
        self::assertSame([], $summary['reviews']);
        self::assertSame($payment->public_id, $summary['payment_adjustment_reviews'][0]['payment_id']);
        self::assertTrue($summary['payment_adjustment_reviews'][0]['past_month']);
        $history = $read->history($user->id, [], true);
        self::assertSame('payment_adjustment', $history['items'][0]['reason']);
        self::assertSame($payment->public_id, $history['items'][0]['payment_id']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_chargeback_excludes_expired_and_reserved_lots_while_consuming_valid_free_only(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        $payment = $this->settleRankPayment($user, 4000, 150);
        $expiredIds = DB::table('point_lots')->where('user_id', $user->id)->pluck('id')->all();
        $this->rankClock(CarbonImmutable::parse(DB::table('point_lots')->where('user_id', $user->id)->min('expire_at'))->toIso8601String());
        $reserved = $this->settleRankPayment($user, 1000, 100);
        app(V2AdminPaymentAdjustmentService::class)->write($this->rankAdmin(), $reserved->public_id, 'refund',
            ['reason' => '別決済予約'], (string) Str::uuid7());
        app(V2PointService::class)->grantFree($user->id, 200, 'm8-valid:'.Str::uuid7());
        $result = $this->chargeback($payment);
        self::assertSame(170, $result['data']['impact']['reversed_free_from_free']);
        self::assertSame(30, $result['data']['impact']['reversed_paid_shortage_from_free']);
        self::assertSame(3970, $result['data']['impact']['shortfall_paid_amount']);
        self::assertSame(0, $result['data']['impact']['shortfall_free_amount']);
        self::assertSame(4170, (int) DB::table('point_lots')->whereIn('id', $expiredIds)->sum('remaining_amount'));
        self::assertSame(0, DB::table('point_ledger_entries')->whereIn('point_lot_id', $expiredIds)->where('entry_type', 'reverse')->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 5000, 'free_balance' => 270,
            'paid_reserved_balance' => 1000, 'free_reserved_balance' => 100]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function remainingRankPoints(): array
    {
        return [[0], [500]];
    }

    #[DataProvider('remainingRankPoints')]
    public function test_requested_decrease_is_bounded_by_remaining_total_and_zero_does_not_increment_revision(int $remaining): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 4000);
        $admin = $this->rankAdmin();
        $service = app(V2MemberRankAdminService::class);
        $input = ['delta' => $remaining - 4000, 'reason' => '先行調整'];
        $preview = $service->preview($admin, $user->public_id, 'points', $input);
        $service->write($admin, $user->public_id, 'points', [...$input, 'expected_revision' => $preview['expected_revision'],
            'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        $revision = (int) DB::table('member_rank_states')->where('user_id', $user->id)->value('revision');
        $result = $this->chargeback($payment);
        self::assertSame(4000, $result['data']['impact']['rank_adjustment']['requested_points']);
        self::assertSame($remaining, $result['data']['impact']['rank_adjustment']['applied_points']);
        self::assertSame([], $result['data']['impact']['rank_adjustment']['recoveries']);
        self::assertSame($revision + ($remaining > 0 ? 1 : 0), (int) DB::table('member_rank_states')->where('user_id', $user->id)->value('revision'));
        self::assertDatabaseHas('member_rank_point_entries', ['origin_payment_id' => $payment->id, 'requested_delta' => -4000, 'applied_delta' => -$remaining]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function chargeback(object $payment): array
    {
        return app(V2AdminPaymentAdjustmentService::class)->write($this->rankAdmin(), $payment->public_id,
            'chargeback', ['reason' => 'M8検証'], (string) Str::uuid7());
    }

    public static function spentBonus(): array
    {
        return [[0, 1, 19, 1], [150, 160, 10, 20]];
    }

    #[DataProvider('spentBonus')]
    public function test_used_bonus_blocks_refund_without_reservations_and_cb_records_deterministic_shortfall(
        int $free, int $spent, int $reversed, int $bonusShortfall
    ): void {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        $payment = $this->settleRankPayment($user, 4000, $free);
        $context = $this->rankAdmin();
        app(V2PointService::class)->applyAdminAdjustmentWithinTransaction($user->id, $context->adminId, 'free', 'deduct',
            $spent, 'point.admin_adjustment:'.hash('sha256', (string) Str::uuid7()), now());
        try {
            app(V2AdminPaymentAdjustmentService::class)->write($context, $payment->public_id, 'refund', ['reason' => '使用済み'], (string) Str::uuid7());
            self::fail('A used related lot must reject the entire refund.');
        } catch (V2AdminPaymentReadException $exception) {
            self::assertSame('REFUND_POINTS_NOT_FULLY_UNUSED', $exception->errorCode);
        }
        self::assertSame(0, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_reserved_balance' => 0, 'paid_reserved_balance' => 0]);
        $result = $this->chargeback($payment);
        self::assertSame($reversed, $result['data']['impact']['reversed_free_from_free']);
        self::assertSame($spent, $result['data']['impact']['shortfall_free_amount']);
        self::assertSame($bonusShortfall, $result['data']['impact']['purchase_bonus']['shortfall_amount']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function manualBonuses(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('manualBonuses')]
    public function test_manual_rank_direct_bonus_is_preserved_but_self_reach_bonus_is_recovered(bool $grant): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $context = $this->rankAdmin();
        $rank = app(V2MemberRankAdminService::class);
        $input = ['rank' => 'bronze', 'kind' => 'support', 'reason' => '手動ランク', 'skip_count' => 1, 'grant_bonus' => $grant];
        $preview = $rank->preview($context, $user->public_id, 'rank', $input);
        $rank->write($context, $user->public_id, 'rank', [...$input, 'expected_revision' => $preview['expected_revision'],
            'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        $payment = $this->settleRankPayment($user, 10000);
        $result = $this->chargeback($payment);
        self::assertSame('bronze', $result['data']['impact']['rank_adjustment']['before_rank']);
        self::assertSame('bronze', $result['data']['impact']['rank_adjustment']['after_rank']);
        self::assertCount($grant ? 0 : 1, $result['data']['impact']['rank_adjustment']['recoveries']);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => $grant ? 500 : 0]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_initial_off_cancellation_is_excluded_from_first_activation_backfill(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 4000, 10);
        self::assertSame('none', $this->chargeback($payment)['data']['impact']['rank_adjustment']['effect']);
        $this->enableRankFixture();
        app(V2MemberRankAccountingService::class)->accountGrantedPayment($payment->id);
        self::assertSame(0, DB::table('member_rank_point_entries')->where('payment_id', $payment->id)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_missing_origin_before_activation_within_the_activation_month_fails_closed(): void
    {
        $this->rankClock('2026-10-01T00:00:00Z');
        $payment = $this->settleRankPayment($this->rankUser(), 4000, 10);
        $this->enableRankFixture();
        $this->rankClock('2026-10-20T00:00:00Z');
        try {
            $this->chargeback($payment);
            self::fail('Activation-month backfill must precede cancellation.');
        } catch (V2AdminPaymentReadException $exception) {
            self::assertSame('PAYMENT_RANK_ADJUSTMENT_NOT_READY', $exception->errorCode);
        }
        self::assertSame(0, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
        app(V2MemberRankAccountingService::class)->accountGrantedPayment($payment->id);
        self::assertSame(4000, $this->chargeback($payment)['data']['impact']['rank_adjustment']['applied_points']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_refund_rank_history_attributes_actual_completing_admin_not_preparer(): void
    {
        $this->enableRankFixture();
        $payment = $this->settleRankPayment($this->rankUser(), 4000);
        $service = app(V2AdminPaymentAdjustmentService::class);
        $prepared = $service->write($this->rankAdmin(), $payment->public_id, 'refund', ['reason' => '準備'], (string) Str::uuid7());
        $completer = $this->rankAdmin();
        $service->write($completer, $payment->public_id, 'complete', ['reason' => '確定', 'provider_refund_confirmed' => true],
            (string) Str::uuid7(), $prepared['data']['id']);
        self::assertDatabaseHas('member_rank_point_entries', ['origin_payment_id' => $payment->id, 'admin_id' => $completer->adminId]);
        self::assertDatabaseHas('audit_logs', ['action_code' => 'member_rank.payment.adjusted', 'actor_public_id' => $completer->adminPublicId]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_explicit_refund_cancellation_works_after_rank_freezes_and_expiry_arrives(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        $payment = $this->settleRankPayment($user, 4000, 150);
        $service = app(V2AdminPaymentAdjustmentService::class);
        $prepared = $service->write($this->rankAdmin(), $payment->public_id, 'refund', ['reason' => '準備'], (string) Str::uuid7());
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['frozen_at' => now()]);
        $this->rankClock(CarbonImmutable::parse(DB::table('point_lots')->where('user_id', $user->id)->min('expire_at'))->toIso8601String());
        $result = $service->write($this->rankAdmin(), $payment->public_id, 'cancel', ['reason' => '取りやめ'], (string) Str::uuid7(), $prepared['data']['id']);
        self::assertSame('canceled', $result['data']['status']);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_reserved_balance' => 0, 'paid_reserved_balance' => 0]);
        self::assertSame(0, DB::table('member_rank_payment_adjustments')->where('payment_id', $payment->id)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_refund_completion_consumes_existing_reservations_even_after_expiry(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        $payment = $this->settleRankPayment($user, 4000, 150);
        $service = app(V2AdminPaymentAdjustmentService::class);
        $prepared = $service->write($this->rankAdmin(), $payment->public_id, 'refund', ['reason' => '準備'], (string) Str::uuid7());
        $this->rankClock(CarbonImmutable::parse(DB::table('point_lots')->where('user_id', $user->id)->min('expire_at'))->toIso8601String());
        self::assertSame(0, app(V2PointService::class)->expire(CarbonImmutable::now()));
        $result = $service->write($this->rankAdmin(), $payment->public_id, 'complete',
            ['reason' => '予約後の確定', 'provider_refund_confirmed' => true], (string) Str::uuid7(), $prepared['data']['id']);
        self::assertSame(170, $result['data']['impact']['reversed_free_from_free']);
        self::assertSame('past_month', $result['data']['impact']['rank_adjustment']['effect']);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 0, 'free_balance' => 0,
            'paid_reserved_balance' => 0, 'free_reserved_balance' => 0]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_verified_event_replay_and_admin_second_key_cannot_repeat_rank_decrease(): void
    {
        $this->enableRankFixture();
        $payment = $this->settleRankPayment($this->rankUser(), 4000, 10);
        $service = app(V2PaymentService::class);
        $event = $service->recordVerifiedProviderEvent('fincode', 'm8-'.Str::uuid7(), 'payment.chargeback', '{}', [],
            $payment->id, null, now());
        $first = $service->processChargeback($event->id);
        self::assertSame($first->id, $service->processChargeback($event->id)->id);
        try {
            $this->chargeback($payment);
            self::fail('A second adjustment must conflict.');
        } catch (V2AdminPaymentReadException $exception) {
            self::assertSame('PAYMENT_ADJUSTMENT_CONFLICT', $exception->errorCode);
        }
        self::assertSame(1, DB::table('member_rank_point_entries')->where('origin_payment_id', $payment->id)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function rollbackStages(): array
    {
        return [['point_ledger_entries', 'INSERT'], ['member_rank_point_entries', 'INSERT'],
            ['member_rank_bonus_recoveries', 'INSERT'], ['payment_adjustments', 'UPDATE'], ['outbox_messages', 'INSERT']];
    }

    public static function recoveryBalances(): array
    {
        return [[0], [100], [500]];
    }

    #[DataProvider('recoveryBalances')]
    public function test_payment_cancellation_precedes_recovery_and_partial_recovery_retains_entitlement(
        int $balance
    ): void {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 10000, 150);
        if ($balance < 500) {
            app(V2PointService::class)->applyAdminAdjustmentWithinTransaction($user->id, $this->rankAdmin()->adminId,
                'free', 'deduct', 500 - $balance, 'point.admin_adjustment:'.hash('sha256', (string) Str::uuid7()), now());
        }
        $result = $this->chargeback($payment);
        self::assertSame(150, $result['data']['impact']['reversed_free_from_free']);
        self::assertSame(0, $result['data']['impact']['reversed_paid_shortage_from_free']);
        self::assertSame($balance, $result['data']['impact']['rank_adjustment']['recoveries'][0]['recovered_amount']);
        self::assertSame(500 - $balance, $result['data']['impact']['rank_adjustment']['recoveries'][0]['shortfall_amount']);
        self::assertSame($balance === 500,
            DB::table('member_rank_entitlements')->where('user_id', $user->id)->where('level', 2)->value('achievement_id') === null);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_rank_change_invalidates_admin_preview_revision(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 4000);
        $context = $this->rankAdmin();
        $rank = app(V2MemberRankAdminService::class);
        $input = ['delta' => 1, 'reason' => 'stale'];
        $preview = $rank->preview($context, $user->public_id, 'points', $input);
        $this->chargeback($payment);
        try {
            $rank->write($context, $user->public_id, 'points', [...$input, 'expected_revision' => $preview['expected_revision'],
                'preview_token' => $preview['preview_token']], (string) Str::uuid7());
            self::fail('Stale revision must fail.');
        } catch (\App\Domain\MemberRank\Exceptions\V2MemberRankException $exception) {
            self::assertSame(409, $exception->status);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_database_rejects_duplicate_success_and_mutation_of_cancellation_evidence(): void
    {
        $this->enableRankFixture();
        $payment = $this->settleRankPayment($this->rankUser(), 4000);
        $result = $this->chargeback($payment);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $adjustment = (array) DB::table('payment_adjustments')->where('public_id', $result['data']['id'])->sole();
        unset($adjustment['id']);
        $adjustment['public_id'] = (string) Str::uuid7();
        $this->rejectDatabase(fn () => DB::table('payment_adjustments')->insert($adjustment), 'payment_adjustments_one_success_per_payment');
        $this->rejectDatabase(fn () => DB::table('member_rank_payment_adjustments')->where('payment_id', $payment->id)
            ->update(['applied_points' => 0]), 'immutable');
        $this->rejectDatabase(fn () => DB::table('member_rank_point_entries')->where('origin_payment_id', $payment->id)
            ->delete(), 'immutable');
    }

    public function test_zero_promotion_bonus_releases_entitlement_without_a_point_operation(): void
    {
        $this->enableRankFixture();
        $settings = app(\App\Domain\MemberRank\Services\V2MemberRankSettingsService::class);
        $context = $this->rankAdmin();
        $ranks = $settings->read($context)['current']['ranks'];
        $ranks[1]['promotion_bonus'] = 0;
        $settings->update($context, (string) Str::uuid7(), ['expected_revision' => 1, 'enabled' => true,
            'default_skip_count' => 2, 'ranks' => $ranks]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 10000);
        $this->chargeback($payment);
        self::assertDatabaseHas('member_rank_bonus_recoveries', ['user_id' => $user->id,
            'requested_amount' => 0, 'recovered_amount' => 0, 'shortfall_amount' => 0, 'point_operation_id' => null]);
        self::assertDatabaseHas('member_rank_entitlements', ['user_id' => $user->id, 'level' => 2, 'achievement_id' => null]);
        $this->settleRankPayment($user, 10000);
        self::assertDatabaseHas('member_rank_achievements', ['user_id' => $user->id, 'generation' => 2, 'bonus_amount' => 0]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function unsupportedStates(): array
    {
        return [['frozen'], ['closed']];
    }

    #[DataProvider('unsupportedStates')]
    public function test_unsupported_rank_state_fails_closed_without_financial_side_effects(string $state): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 4000);
        if ($state === 'frozen') {
            DB::table('member_rank_states')->where('user_id', $user->id)->update(['frozen_at' => now()]);
        } else {
            DB::table('users')->where('id', $user->id)->update(['state' => 'closed']);
        }
        try {
            $this->chargeback($payment);
            self::fail('Unsupported state must not succeed.');
        } catch (V2AdminPaymentReadException $exception) {
            self::assertSame('PAYMENT_RANK_ADJUSTMENT_NOT_READY', $exception->errorCode);
        }
        self::assertSame(0, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 4000]);
    }

    public function test_deferred_database_guard_rejects_success_without_adjustment_accounting(): void
    {
        $payment = $this->settleRankPayment($this->rankUser(), 4000);
        $prepared = app(V2PaymentService::class)->reserveFullRefund($payment->id, (string) Str::uuid7());
        $this->rejectDatabase(function () use ($prepared): void {
            DB::table('payment_adjustments')->where('id', $prepared->id)->update(['status' => 'succeeded', 'succeeded_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'requires immutable rank and bonus accounting');
        self::assertDatabaseHas('payment_adjustments', ['id' => $prepared->id, 'status' => 'points_reserved']);
    }

    private function rejectDatabase(callable $operation, string $reason): void
    {
        try {
            DB::transaction($operation);
            self::fail('Invalid database write must be rejected.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }

    #[DataProvider('rollbackStages')]
    public function test_injected_failure_rolls_back_every_accounting_surface(string $table, string $operation): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 10000);
        $admin = $this->rankAdmin();
        $tables = ['wallets', 'point_lots', 'point_ledger_entries', 'point_operations', 'member_rank_point_entries',
            'member_rank_month_totals', 'member_rank_states', 'member_rank_entitlements', 'member_rank_bonus_recoveries',
            'payment_adjustments', 'member_rank_payment_adjustments', 'payment_adjustment_point_impacts', 'outbox_messages', 'audit_logs'];
        $before = [];
        foreach ($tables as $name) {
            $before[$name] = DB::table($name)->get()->toJson();
        }
        DB::unprepared("CREATE FUNCTION m8_injected_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'M8 rollback injection'; END; $$");
        DB::unprepared("CREATE TRIGGER m8_failure BEFORE {$operation} ON {$table} FOR EACH ROW EXECUTE FUNCTION m8_injected_failure()");
        try {
            app(V2AdminPaymentAdjustmentService::class)->write($admin, $payment->public_id, 'chargeback', ['reason' => 'rollback'], (string) Str::uuid7());
            self::fail('Injected exception must escape.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('M8 rollback injection', $exception->getMessage());
        }
        foreach ($tables as $name) {
            self::assertSame($before[$name], DB::table($name)->get()->toJson(), $name);
        }
    }
}
