<?php

namespace Tests\V2;

use App\Domain\Draw\Services\V2DrawEligibilityService;
use App\Domain\Identity\Services\V2PreRegistrationService;
use App\Domain\Identity\Services\V2RegistrationQualification;
use App\Domain\MemberRank\Services\V2MemberRankAccountingService;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Domain\Payment\V2\Services\V2PaymentService;
use App\Domain\Payment\V2\Services\V2LimitedBonusCampaignService;
use App\Domain\Payment\V2\Services\V2PointPurchaseEligibilityService;
use App\Domain\Point\Services\V2CurrentUserPointReadService;
use App\Domain\Point\Services\V2PointService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankAccountingTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function thresholds(): array
    {
        return [[9999, 1, []], [10000, 2, [500]], [29999, 2, [500]],
            [30000, 3, [500, 2000]], [99999, 3, [500, 2000]], [100000, 4, [500, 2000, 5000]]];
    }

    #[DataProvider('thresholds')]
    public function test_thresholds_jump_bonus_lots_label_and_replay(int $amount, int $level, array $bonuses): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        [$payment, $event] = $this->rankPayment($user, $amount, 137);
        $service = app(V2PaymentService::class);
        $grant = $service->confirmSucceeded($event->id);
        self::assertSame($grant->id, $service->confirmSucceeded($event->id)->id);
        $duplicate = $service->recordVerifiedProviderEvent('fincode', 'duplicate-'.Str::uuid7(), 'payment.succeeded', '{}', [], $payment->id, null, now());
        $service->confirmSucceeded($duplicate->id);
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => $level, 'last_judged_month' => null]);
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-10-01', 'points' => $amount]);
        self::assertSame(1, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
        self::assertSame($bonuses, DB::table('member_rank_achievements')->where('user_id', $user->id)->orderBy('level')->pluck('bonus_amount')->all());
        self::assertSame($level === 1 ? 0 : 1, DB::table('member_rank_changes')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => $amount, 'free_balance' => 137 + array_sum($bonuses)]);
        $operations = DB::table('point_operations')->where('user_id', $user->id)->where('source_type', 'member_rank_promotion')->get();
        self::assertCount(count($bonuses), $operations);
        foreach ($operations as $operation) {
            self::assertSame('free_grant', $operation->operation_type);
            $lot = DB::table('point_lots')->where('grant_operation_id', $operation->id)->sole();
            self::assertSame('free', $lot->point_type);
            self::assertTrue(CarbonImmutable::parse($lot->expire_at)->equalTo(CarbonImmutable::parse($lot->granted_at)->addDays(180)));
            self::assertDatabaseHas('point_ledger_entries', ['point_operation_id' => $operation->id, 'amount_delta' => $lot->granted_amount]);
        }
        $history = app(V2CurrentUserPointReadService::class)->history($user, null, 100);
        self::assertSame(count($bonuses), collect($history['items'])->filter(fn (array $row): bool => $row['reason']['label'] === '昇格ボーナス')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_same_month_accumulates_and_next_month_starts_independently(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->settleRankPayment($user, 5000);
        $this->settleRankPayment($user, 5000);
        $this->rankClock('2026-10-31T15:00:00Z');
        $payment = $this->settleRankPayment($user, 30000, 700, '2026-10-31T14:59:59Z');
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-10-01', 'points' => 10000]);
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-11-01', 'points' => 30000]);
        self::assertDatabaseHas('member_rank_point_entries', ['payment_id' => $payment->id, 'month' => '2026-11-01']);
        self::assertSame([500, 2000], DB::table('member_rank_achievements')->where('user_id', $user->id)->orderBy('level')->pluck('bonus_amount')->all());
        self::assertTrue(CarbonImmutable::parse(DB::table('payments')->where('id', $payment->id)->value('points_granted_at'))->equalTo(CarbonImmutable::now()));
    }

    public function test_manual_high_rank_without_consumed_qualifications_earns_without_false_promotion(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        foreach ([[10000, 500], [20000, 2500], [70000, 7500]] as [$amount, $bonusTotal]) {
            $this->settleRankPayment($user, $amount);
            self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => $bonusTotal]);
        }
        self::assertSame(0, DB::table('member_rank_changes')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('member_rank_states', ['user_id' => $user->id, 'level' => 4]);
    }

    public function test_consumed_manual_qualifications_and_repromotion_do_not_pay_again(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 4);
        foreach ([2 => 500, 3 => 2000, 4 => 5000] as $level => $amount) {
            $publicId = (string) Str::uuid7();
            $achievement = DB::table('member_rank_achievements')->insertGetId([
                'public_id' => $publicId, 'user_id' => $user->id, 'level' => $level, 'generation' => 1,
                'month' => '2026-10-01', 'rank_points' => 0, 'setting_revision' => 1,
                'basis' => 'manual_rank', 'origin_public_id' => (string) Str::uuid7(), 'bonus_amount' => $amount, 'occurred_at' => now(),
            ]);
            app(V2PointService::class)->grantMemberRankPromotion($user->id, $achievement, $publicId, $amount, CarbonImmutable::now());
            DB::table('member_rank_entitlements')->insert(['user_id' => $user->id, 'level' => $level, 'achievement_id' => $achievement]);
        }
        $this->settleRankPayment($user, 100000);
        self::assertSame(3, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['level' => 2]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $this->settleRankPayment($user, 100000);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => 7500]);
    }

    public function test_effective_month_settings_zero_bonus_consumption_and_new_generation_keys(): void
    {
        $this->enableRankFixture();
        $context = $this->rankAdmin();
        $settings = app(V2MemberRankSettingsService::class);
        $values = $settings->read($context)['current']['ranks'];
        $values[1]['promotion_bonus'] = 0;
        $values[1]['reach_line'] = 12000;
        $settings->update($context, (string) Str::uuid7(), ['expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 1, 'ranks' => $values]);
        $october = $this->rankUser();
        $this->settleRankPayment($october, 10000);
        self::assertDatabaseHas('member_rank_achievements', ['user_id' => $october->id, 'setting_revision' => 1, 'bonus_amount' => 500]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $november = $this->rankUser();
        $this->settleRankPayment($november, 10000);
        self::assertSame(0, DB::table('member_rank_achievements')->where('user_id', $november->id)->count());
        $this->settleRankPayment($november, 2000);
        self::assertDatabaseHas('member_rank_achievements', ['user_id' => $november->id, 'setting_revision' => 2, 'bonus_amount' => 0]);
        self::assertSame(0, DB::table('point_operations')->where('user_id', $november->id)->where('source_type', 'member_rank_promotion')->count());
        $values[1]['promotion_bonus'] = 700;
        $settings->update($this->rankAdmin(), (string) Str::uuid7(), ['expected_revision' => 2, 'enabled' => true, 'default_skip_count' => 1, 'ranks' => $values]);
        $this->rankClock('2026-11-30T15:00:00Z');
        $this->settleRankPayment($november, 12000);
        self::assertSame(1, DB::table('member_rank_achievements')->where('user_id', $november->id)->count());
        $this->assertRankConstraint(fn () => DB::table('member_rank_entitlements')->where('user_id', $november->id)->update(['achievement_id' => null]));
        $this->settleRankPayment($november, 1);
        self::assertDatabaseHas('member_rank_achievements', ['user_id' => $november->id, 'generation' => 1, 'bonus_amount' => 0]);
        self::assertSame(1, DB::table('member_rank_achievements')->where('user_id', $november->id)->count());
    }

    public static function preRegistrationCases(): array
    {
        return [[false, false, '2026-10-31T15:00:00Z'], [true, false, '2026-10-31T15:00:00Z'],
            [true, true, '2026-10-31T15:00:00Z'], [true, false, '2026-10-31T14:59:59Z']];
    }

    #[DataProvider('preRegistrationCases')]
    public function test_real_pre_registration_500_and_created_at_reset_never_rewrite_paid_history(bool $enabled, bool $paidBefore, string $activationAt): void
    {
        if ($enabled) {
            $this->enableRankFixture();
        }
        DB::table('pre_registration_settings')->where('id', 1)->update(['is_enabled' => true, 'cutoff_at' => $activationAt]);
        $user = $this->rankUser(true);
        $originalRegistration = $user->pre_registered_at;
        if ($paidBefore) {
            $this->settleRankPayment($user, 10000, 111);
        }
        $before = $this->rankSnapshot($user->id);
        $payments = DB::table('payments')->where('user_id', $user->id)->orderBy('id')->get()->toJson();
        $operations = DB::table('point_operations')->where('user_id', $user->id)->orderBy('id')->get();
        $this->rankClock($activationAt);
        $context = $this->rankAdmin();
        $key = (string) Str::uuid7();
        $preRegistration = app(V2PreRegistrationService::class);
        $result = $preRegistration->activate($context, $key, ['expected_revision' => 1]);
        self::assertSame(1, $result['data']['summary']['activated']);
        self::assertTrue($preRegistration->activate($context, $key, ['expected_revision' => 1])['idempotent_replay']);
        self::assertSame($before, $this->rankSnapshot($user->id));
        self::assertSame($payments, DB::table('payments')->where('user_id', $user->id)->orderBy('id')->get()->toJson());
        self::assertSame($operations->toJson(), DB::table('point_operations')->whereIn('id', $operations->pluck('id'))->orderBy('id')->get()->toJson());
        $user->refresh();
        self::assertEquals($originalRegistration, $user->pre_registered_at);
        self::assertTrue($user->created_at->equalTo(CarbonImmutable::now()));
        self::assertTrue(app(V2RegistrationQualification::class)->origin($user, 'first_registration_qualified_at')->equalTo(CarbonImmutable::now()));
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => $paidBefore ? 1111 : 500]);
        $freeGrant = DB::table('point_operations')->where('user_id', $user->id)->where('source_type', 'pre_registration')->sole();
        self::assertSame('free_grant', $freeGrant->operation_type);
        self::assertDatabaseHas('point_lots', ['grant_operation_id' => $freeGrant->id, 'point_type' => 'free', 'granted_amount' => 500]);
        $eligibility = app(V2PointPurchaseEligibilityService::class);
        $firstPlan = (object) ['audience_code' => 'first_purchase_users', 'code' => 'synthetic-new-first-plan'];
        self::assertTrue($eligibility->eligible($user, $firstPlan, CarbonImmutable::now()));
        self::assertFalse($eligibility->eligible($user, $firstPlan, CarbonImmutable::now()->addDay()));
        self::assertTrue(app(V2DrawEligibilityService::class)->evaluateLogin($user,
            (object) ['id' => 999999, 'gacha_type' => 'signup_once'],
            (object) ['publish_start_at' => $activationAt], CarbonImmutable::now())['eligible']);
        $this->rankClock('2026-10-31T15:00:00Z');
        $after = $this->settleRankPayment($user, 30000, 333, '2026-10-31T14:59:59Z');
        if ($enabled) {
            self::assertDatabaseHas('member_rank_point_entries', ['payment_id' => $after->id, 'month' => '2026-11-01', 'applied_delta' => 30000]);
            self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-11-01', 'points' => 30000]);
            if ($paidBefore) {
                self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-10-01', 'points' => 10000]);
            }
        } else {
            self::assertSame($before, $this->rankSnapshot($user->id));
        }
    }

    public function test_activation_month_accounting_converges_with_live_settlement_and_excludes_old_month(): void
    {
        $user = $this->rankUser();
        $this->rankClock('2026-09-30T14:59:59Z');
        $old = $this->settleRankPayment($user, 10000);
        $this->rankClock('2026-10-01T00:00:00Z');
        $current = $this->settleRankPayment($user, 30000);
        app(V2PointService::class)->grantFree($user->id, 500, (string) Str::uuid7());
        $this->rankClock('2026-10-15T00:00:00Z');
        $this->enableRankFixture();
        $accounting = app(V2MemberRankAccountingService::class);
        foreach ([$old, $current, $current] as $payment) {
            $accounting->accountGrantedPayment($payment->id);
        }
        $live = $this->settleRankPayment($user, 70000);
        $accounting->accountGrantedPayment($live->id);
        self::assertSame(2, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-10-01', 'points' => 100000]);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'free_balance' => 8000]);
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'member_rank_purchase')->count());
    }

    public function test_mid_bonus_database_failure_rolls_back_entire_payment_and_retry_succeeds(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        [$payment, $event] = $this->rankPayment($user, 100000);
        DB::unprepared("CREATE FUNCTION rank_test_fail() RETURNS trigger AS $$ BEGIN IF NEW.source_type = 'member_rank_promotion' AND EXISTS (SELECT 1 FROM member_rank_achievements WHERE id = NEW.source_id AND level = 3) THEN RAISE EXCEPTION 'synthetic bonus failure'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql; CREATE TRIGGER rank_test_fail BEFORE INSERT ON point_operations FOR EACH ROW EXECUTE FUNCTION rank_test_fail();");
        try {
            app(V2PaymentService::class)->confirmSucceeded($event->id);
            self::fail('Injected failure must abort settlement.');
        } catch (QueryException) {
            self::assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'created', 'points_granted_at' => null]);
            self::assertSame(0, DB::table('payment_point_grants')->where('payment_id', $payment->id)->count());
            self::assertSame(0, DB::table('point_operations')->where('user_id', $user->id)->count());
            foreach ($this->rankTables() as $table) {
                self::assertSame(0, DB::table($table)->where('user_id', $user->id)->count());
            }
            self::assertSame(0, DB::table('point_lots')->where('user_id', $user->id)->count());
        }
        DB::unprepared('DROP TRIGGER rank_test_fail ON point_operations; DROP FUNCTION rank_test_fail();');
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 100000, 'free_balance' => 7500]);
    }

    public function test_nonpayment_grants_and_consumption_do_not_create_rank_rows(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $points = app(V2PointService::class);
        $points->grantFree($user->id, 500, (string) Str::uuid7());
        $points->grantLineFriendReward($user->id, 1, (string) Str::uuid7(), 500, now());
        $points->grantReferralReward($user->id, 1, (string) Str::uuid7(), 'referred', 500, now());
        $points->grantPrizeExchange($user->id, 1, (string) Str::uuid7(), 500, now());
        $admin = $this->rankAdmin();
        foreach (['paid', 'free'] as $type) {
            $points->applyAdminAdjustmentWithinTransaction($user->id, $admin->adminId, $type, 'grant', 10000,
                'point.admin_adjustment:'.hash('sha256', (string) Str::uuid7()), now());
        }
        $points->grantDrawPointBackBatch($user->id, 1, (string) Str::uuid7(), [[
            'draw_result_id' => 1, 'draw_result_public_id' => (string) Str::uuid7(), 'amount' => 500,
        ]], now());
        $points->consume($user->id, 100, (string) Str::uuid7());
        foreach ($this->rankTables() as $table) {
            self::assertSame(0, DB::table($table)->where('user_id', $user->id)->count());
        }
    }

    public function test_disabled_payment_methods_preserve_wallet_mail_outbox_and_write_no_rank(): void
    {
        foreach (['credit_card', 'paypay', 'konbini'] as $method) {
            $user = $this->rankUser();
            [$payment, $event] = $this->rankPayment($user, 10000, 123, method: $method);
            $service = app(V2PaymentService::class);
            $first = $service->confirmSucceeded($event->id);
            self::assertSame($first->id, $service->confirmSucceeded($event->id)->id);
            self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 10000, 'free_balance' => 123]);
            self::assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'succeeded']);
            self::assertSame(2, DB::table('point_lots')->where('user_id', $user->id)->count());
            self::assertSame(1, DB::table('mail_deliveries')->where('event_key', 'coin.purchase.completed:'.$payment->public_id)->count());
            self::assertSame(1, DB::table('outbox_messages')->where('deduplication_key', 'payment.succeeded:'.$payment->public_id)->count());
            foreach ($this->rankTables() as $table) {
                self::assertSame(0, DB::table($table)->where('user_id', $user->id)->count());
            }
        }
    }

    private function rankTables(): array
    {
        return ['member_rank_states', 'member_rank_month_totals', 'member_rank_point_entries',
            'member_rank_changes', 'member_rank_achievements', 'member_rank_entitlements'];
    }

    public function test_database_rejects_mutated_history_duplicate_payment_negative_and_mismatched_totals(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->settleRankPayment($user, 10000);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        foreach (['member_rank_point_entries', 'member_rank_changes', 'member_rank_achievements'] as $table) {
            $this->assertRankConstraint(fn () => DB::table($table)->where('user_id', $user->id)->delete());
            $this->assertRankConstraint(fn () => DB::table($table)->where('user_id', $user->id)->update(['user_id' => $user->id]));
        }
        foreach ([-1, 9999, 10001] as $total) {
            $this->assertRankConstraint(fn () => DB::table('member_rank_month_totals')->where('user_id', $user->id)->update(['points' => $total]));
        }
        $entry = (array) DB::table('member_rank_point_entries')->where('user_id', $user->id)->sole();
        unset($entry['id']);
        $this->assertRankConstraint(fn () => DB::table('member_rank_point_entries')->insert($entry));
        $entry['applied_delta'] = 9000;
        $entry['requested_delta'] = 9000;
        $this->assertRankConstraint(fn () => DB::table('member_rank_point_entries')->insert($entry));
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'points' => 10000]);
    }

    public function test_fully_recovered_entitlement_preserves_old_achievement_and_uses_new_key(): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->settleRankPayment($user, 10000);
        $first = DB::table('member_rank_achievements')->where('user_id', $user->id)->sole();
        $admin = $this->rankAdmin();
        $service = app(\App\Domain\MemberRank\Services\V2MemberRankAdminService::class);
        $input = ['delta' => -10000, 'reason' => 'Actual full recovery before regrant'];
        $preview = $service->preview($admin, $user->public_id, 'points', $input);
        $service->write($admin, $user->public_id, 'points', [...$input, 'expected_revision' => $preview['expected_revision'],
            'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        $this->settleRankPayment($user, 10000);
        $keys = DB::table('point_operations')->where('user_id', $user->id)->where('source_type', 'member_rank_promotion')->pluck('business_key');
        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
        self::assertSame((array) $first, (array) DB::table('member_rank_achievements')->where('id', $first->id)->sole());
        self::assertDatabaseHas('member_rank_achievements', ['user_id' => $user->id, 'level' => 2, 'generation' => 2]);
        self::assertSame(3, DB::table('member_rank_changes')->where('user_id', $user->id)->count());
        self::assertDatabaseHas('member_rank_bonus_recoveries', ['achievement_id' => $first->id, 'recovered_amount' => 500, 'shortfall_amount' => 0]);
    }

    public function test_settlement_latency_with_three_promotion_bonuses_is_measured(): void
    {
        $durations = [];
        foreach ([false, true] as $enabled) {
            if ($enabled) {
                $this->enableRankFixture();
            }
            $samples = [];
            for ($sample = 0; $sample < 8; $sample++) {
                $user = $this->rankUser();
                [, $event] = $this->rankPayment($user, 100000);
                $start = hrtime(true);
                app(V2PaymentService::class)->confirmSucceeded($event->id);
                $samples[] = (hrtime(true) - $start) / 1000000;
                self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 100000, 'free_balance' => $enabled ? 7500 : 0]);
            }
            sort($samples);
            $durations[$enabled ? 'enabled' : 'disabled'] = ['median_ms' => ($samples[3] + $samples[4]) / 2, 'max_ms' => max($samples)];
        }
        $durations['additional_median_ms'] = $durations['enabled']['median_ms'] - $durations['disabled']['median_ms'];
        fwrite(STDOUT, "\nMEMBER_RANK_LATENCY ".json_encode($durations, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_limited_bonus_keeps_provider_time_while_rank_month_uses_grant_time(): void
    {
        foreach ([false, true] as $enabled) {
            if ($enabled) {
                $this->enableRankFixture();
            }
            $this->rankClock('2026-10-31T14:59:58Z');
            $user = $this->rankUser();
            [$unused] = $this->rankPayment($user, 10000, 37);
            app(V2LimitedBonusCampaignService::class)->create($unused->point_purchase_plan_id, true,
                CarbonImmutable::parse('2026-10-01T00:00:00Z'), CarbonImmutable::parse('2026-10-31T15:00:00Z'), 81);
            [$payment, $event] = $this->rankPayment($user, 10000, 37, '2026-10-31T14:59:59Z', planId: $unused->point_purchase_plan_id);
            $this->rankClock('2026-10-31T15:00:00Z');
            app(V2PaymentService::class)->confirmSucceeded($event->id);
            self::assertDatabaseHas('payments', ['id' => $payment->id, 'limited_bonus_point_amount' => 81]);
            self::assertDatabaseHas('wallets', ['user_id' => $user->id, 'paid_balance' => 10000, 'free_balance' => $enabled ? 618 : 118]);
            if ($enabled) {
                self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-11-01', 'points' => 10000]);
            } else {
                self::assertSame(0, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
            }
        }
    }

    private function assertRankConstraint(callable $operation): void
    {
        try {
            DB::transaction($operation);
            self::fail('Database must reject this accounting mutation.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    private function rankSnapshot(int $userId): array
    {
        return array_map(fn (string $table): string => DB::table($table)->where('user_id', $userId)->get()->toJson(), $this->rankTables());
    }
}
