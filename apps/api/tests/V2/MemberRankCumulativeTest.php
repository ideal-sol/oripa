<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankAdminService;
use App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker;
use App\Domain\MemberRank\Services\V2MemberRankPromotionWorker;
use App\Domain\MemberRank\Services\V2MemberRankPublicReadService;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Domain\MemberRank\Services\V2MemberRankSettingsService;
use App\Domain\MemberRank\Services\V2MemberRankTransferService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankCumulativeTest extends TestCase
{
    use MemberRankAccountingFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureRankFixture();
        DB::beginTransaction();
        $this->enableRankFixture();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_purchase_across_months_and_old_worker_never_demote(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 80000);
        $this->rankClock('2026-11-30T15:00:00Z');
        self::assertSame(0, app(V2MemberRankMonthlyWorker::class)->run('retired-worker'));
        self::assertSame(80000, app(V2MemberRankResolver::class)->project($user->id)['rank_points']);
        $this->settleRankPayment($user, 20000);
        $result = app(V2MemberRankPublicReadService::class)->current($user->id)['data'];
        self::assertSame(4, $result['rank']['level']);
        self::assertSame(100000, $result['rank_points']);
        self::assertNull($result['period']);
        self::assertSame('cumulative', $result['point_basis']);
        self::assertCount(2, $result['breakdown']['recent']);
        $this->rankClock('2026-12-31T15:00:00Z');
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame(0, DB::table('member_rank_changes')->where('reason', 'demotion')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_transfer_edit_and_replay_keep_one_allowance_without_coins(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 20000);
        $context = $this->rankAdmin();
        $service = app(V2MemberRankAdminService::class);
        $input = ['rank' => 'gold', 'kind' => 'transfer', 'reason' => 'Synthetic transfer', 'skip_count' => 0, 'grant_bonus' => false];
        $preview = $service->preview($context, $user->public_id, 'rank', $input);
        $before = (array) DB::table('wallets')->where('user_id', $user->id)->first();
        $service->write($context, $user->public_id, 'rank', [...$input,
            'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7());
        self::assertSame($before, (array) DB::table('wallets')->where('user_id', $user->id)->first());
        self::assertDatabaseHas('member_rank_transfer_allowances', ['user_id' => $user->id, 'points' => 80000, 'equivalent_level' => 4]);
        $transfer = app(V2MemberRankTransferService::class);
        $key = (string) Str::uuid7();
        $edit = ['status' => 'none', 'equivalent_level' => null, 'points' => 0, 'expected_revision' => 1, 'reason' => 'Synthetic revert'];
        self::assertFalse($transfer->write($context, $user->public_id, $edit, $key)['idempotent_replay']);
        self::assertTrue($transfer->write($context, $user->public_id, $edit, $key)['idempotent_replay']);
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame(1, DB::table('member_rank_transfer_allowances')->where('user_id', $user->id)->count());
        self::assertSame(2, DB::table('member_rank_transfer_histories')->where('user_id', $user->id)->count());
        self::assertSame(1, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_threshold_change_waits_for_monthly_promotion_and_bonus_is_once(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 80000);
        $settings = app(V2MemberRankSettingsService::class);
        $ranks = $settings->effectiveAt(CarbonImmutable::now())['ranks'];
        $ranks[3]['reach_line'] = 70000;
        $ranks[3]['maintain_line'] = 60000;
        $settings->update($this->rankAdmin(), (string) Str::uuid7(), ['expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 0, 'ranks' => $ranks]);
        $this->rankClock('2026-10-31T15:00:00Z');
        self::assertSame(3, app(V2MemberRankResolver::class)->project($user->id)['level']);
        $worker = app(V2MemberRankPromotionWorker::class);
        self::assertSame(1, $worker->run('monthly-test'));
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame(0, $worker->run('monthly-test'));
        self::assertSame(1, DB::table('member_rank_achievements')->where('user_id', $user->id)->where('level', 4)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_line_increase_adds_only_new_shortage_and_line_decrease_cancels_only_compensation(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 120000);
        $settings = app(V2MemberRankSettingsService::class);
        $ranks = $settings->effectiveAt(CarbonImmutable::now())['ranks'];
        $ranks[3]['reach_line'] = 150000;
        $settings->update($this->rankAdmin(), (string) Str::uuid7(), [
            'expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 0, 'ranks' => $ranks,
        ]);
        $this->rankClock('2026-10-31T15:00:00Z');
        $wallet = (array) DB::table('wallets')->where('user_id', $user->id)->first();
        $worker = app(V2MemberRankPromotionWorker::class);
        self::assertSame(1, $worker->run('compensation'));
        self::assertSame(150000, app(V2MemberRankResolver::class)->project($user->id)['rank_points']);
        self::assertSame($wallet, (array) DB::table('wallets')->where('user_id', $user->id)->first());
        $this->settleRankPayment($user, 10000);
        self::assertSame(0, $worker->run('compensation'));
        self::assertSame(30000, (int) DB::table('member_rank_compensation_entries')->where('user_id', $user->id)->sum('delta'));
        $ranks[3]['reach_line'] = 70000;
        $settings->update($this->rankAdmin(), (string) Str::uuid7(), [
            'expected_revision' => 2, 'enabled' => true, 'default_skip_count' => 0, 'ranks' => $ranks,
        ]);
        $this->rankClock('2026-11-30T15:00:00Z');
        self::assertSame(1, $worker->run('compensation'));
        self::assertSame([30000, -30000], DB::table('member_rank_compensation_entries')->where('user_id', $user->id)->orderBy('id')->pluck('delta')->all());
        $history = app(\App\Domain\MemberRank\Services\V2MemberRankAdminReadService::class)->compensationHistory($user->id, []);
        self::assertSame([-30000, 30000], array_column($history['items'], 'delta'));
        self::assertSame([0, 30000], array_column($history['items'], 'remaining'));
        self::assertSame(130000, app(V2MemberRankResolver::class)->project($user->id)['rank_points']);
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_signed_months_are_summed_before_floor_and_manual_positive_uses_current_origin_month(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 80000);
        $this->rankClock('2026-11-30T15:00:00Z');
        $this->adjust($user, 'points', ['delta' => -60000, 'reason' => 'Synthetic cumulative reduction']);
        self::assertDatabaseHas('member_rank_month_totals', ['user_id' => $user->id, 'month' => '2026-12-01', 'points' => -60000]);
        self::assertSame(20000, app(V2MemberRankResolver::class)->project($user->id)['rank_points']);
        self::assertSame(3, app(V2MemberRankResolver::class)->project($user->id)['level']);
        $this->adjust($user, 'points', ['delta' => 80000, 'reason' => 'Synthetic cumulative increase']);
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertDatabaseHas('member_rank_changes', ['user_id' => $user->id, 'month' => '2026-12-01', 'to_level' => 4, 'reason' => 'promotion']);
        self::assertSame(0, DB::table('member_rank_bonus_recoveries')->where('user_id', $user->id)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_manual_downgrade_allows_repromotion_without_duplicate_bonus_and_first_upper_rank_grants(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 100000);
        $this->adjust($user, 'rank', ['rank' => 'bronze', 'kind' => 'support', 'reason' => 'Synthetic downgrade', 'skip_count' => 0, 'grant_bonus' => false]);
        self::assertSame(2, app(V2MemberRankResolver::class)->project($user->id)['level']);
        $this->settleRankPayment($user, 1000);
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame(3, DB::table('member_rank_achievements')->where('user_id', $user->id)->count());
        $this->settleRankPayment($user, 199000);
        self::assertSame(5, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame(1, DB::table('member_rank_achievements')->where('user_id', $user->id)->where('level', 5)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_public_recent_is_three_purchases_and_total_includes_all_history(): void
    {
        $user = $this->rankUser();
        foreach ([1000, 2000, 3000, 4000] as $amount) $this->settleRankPayment($user, $amount);
        $this->adjust($user, 'points', ['delta' => 50, 'reason' => 'Synthetic adjustment']);
        $data = app(V2MemberRankPublicReadService::class)->current($user->id)['data'];
        self::assertCount(3, $data['breakdown']['recent']);
        self::assertSame([4000, 3000, 2000], array_column($data['breakdown']['recent'], 'points'));
        self::assertSame(10050, $data['breakdown']['total']);
        self::assertSame(10050, $data['rank_points']);
        self::assertTrue($data['promotion_bonus']['received']);
    }

    public function test_purchase_rows_42000_with_compensation_10000_total_52000_and_upper_promotion(): void
    {
        $user = $this->rankUser();
        foreach ([14000, 14000, 14000] as $amount) $this->settleRankPayment($user, $amount);
        $this->adjust($user, 'rank', ['rank' => 'gold', 'kind' => 'support', 'reason' => 'Synthetic support', 'skip_count' => 0, 'grant_bonus' => false]);
        $settings = app(V2MemberRankSettingsService::class);
        $ranks = $settings->effectiveAt(CarbonImmutable::now())['ranks'];
        $ranks[3]['reach_line'] = 110000;
        $settings->update($this->rankAdmin(), (string) Str::uuid7(), ['expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 0, 'ranks' => $ranks]);
        $this->rankClock('2026-10-31T15:00:00Z');
        app(V2MemberRankPromotionWorker::class)->run('example');
        $data = app(V2MemberRankPublicReadService::class)->current($user->id)['data'];
        self::assertSame(42000, array_sum(array_column($data['breakdown']['recent'], 'points')));
        self::assertSame(52000, $data['breakdown']['total']);
        self::assertSame(['purchase', 'purchase', 'purchase'], array_column($data['breakdown']['recent'], 'kind'));
        $this->settleRankPayment($user, 248000);
        self::assertSame(5, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame(300000, app(V2MemberRankResolver::class)->project($user->id)['rank_points']);
        self::assertSame(10000, (int) DB::table('member_rank_compensation_entries')->where('user_id', $user->id)->sum('delta'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_refund_completion_rank_choice_is_explicit_atomic_and_replayed_once(): void
    {
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 100000);
        $service = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
        $context = $this->rankAdmin();
        $prepared = $service->write($context, $payment->public_id, 'refund', ['reason' => 'Synthetic refund'], (string) Str::uuid7());
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        $choice = $service->detail($context, $payment->public_id)['data']['rank_change'];
        self::assertSame(0, $choice['rank_points_after']);
        $input = ['reason' => 'Synthetic refund completion', 'provider_refund_confirmed' => true,
            'rank_after' => 'silver', 'rank_preview_token' => $choice['preview_token']];
        $key = (string) Str::uuid7();
        $first = $service->write($context, $payment->public_id, 'complete', $input, $key, $prepared['data']['id']);
        self::assertSame('succeeded', $first['data']['status']);
        self::assertSame(3, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertTrue($service->write($context, $payment->public_id, 'complete', $input, $key, $prepared['data']['id'])['idempotent_replay']);
        self::assertSame(1, DB::table('member_rank_manual_changes')->where('user_id', $user->id)->where('kind', 'payment_incident')->count());
        self::assertSame(0, DB::table('member_rank_bonus_recoveries')->where('user_id', $user->id)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_legacy_transfer_dry_run_then_apply_once_and_beginner_transfer_are_coin_neutral(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 20000);
        DB::table('member_rank_states')->where('user_id', $user->id)->update(['level' => 4, 'transferred_at' => now()]);
        $wallet = (array) DB::table('wallets')->where('user_id', $user->id)->first();
        $this->artisan('v2:member-rank:backfill-transfers')->assertSuccessful();
        self::assertSame(0, DB::table('member_rank_transfer_histories')->count());
        $this->artisan('v2:member-rank:backfill-transfers', ['--apply' => true])->assertSuccessful();
        $this->artisan('v2:member-rank:backfill-transfers', ['--apply' => true])->assertSuccessful();
        self::assertDatabaseHas('member_rank_transfer_allowances', ['user_id' => $user->id, 'points' => 80000, 'revision' => 1]);
        self::assertSame(1, DB::table('member_rank_transfer_histories')->where('user_id', $user->id)->count());
        self::assertSame($wallet, (array) DB::table('wallets')->where('user_id', $user->id)->first());
        $this->adjust($user, 'rank', ['rank' => 'beginner', 'kind' => 'transfer', 'reason' => 'Synthetic beginner transfer', 'skip_count' => 0, 'grant_bonus' => false]);
        self::assertDatabaseHas('member_rank_transfer_allowances', ['user_id' => $user->id, 'equivalent_level' => 1, 'points' => 0, 'revision' => 2]);
        $this->settleRankPayment($user, 280000);
        self::assertSame(0, DB::table('member_rank_achievements')->where('user_id', $user->id)->whereIn('level', [3, 4])->count());
        self::assertSame(1, DB::table('member_rank_achievements')->where('user_id', $user->id)->where('level', 5)->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_refund_and_support_shortages_are_not_refilled_when_line_is_unchanged(): void
    {
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 100000);
        $service = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
        $context = $this->rankAdmin();
        $prepared = $service->write($context, $payment->public_id, 'refund', ['reason' => 'Synthetic refund'], (string) Str::uuid7());
        $service->write($context, $payment->public_id, 'complete', ['reason' => 'Synthetic unchanged', 'provider_refund_confirmed' => true], (string) Str::uuid7(), $prepared['data']['id']);
        $support = $this->rankUser();
        $this->adjust($support, 'rank', ['rank' => 'gold', 'kind' => 'support', 'reason' => 'Synthetic support', 'skip_count' => 0, 'grant_bonus' => false]);
        $this->rankClock('2026-11-30T15:00:00Z');
        app(V2MemberRankPromotionWorker::class)->run('shortages');
        foreach ([$user, $support] as $member) {
            self::assertSame(0, app(V2MemberRankResolver::class)->project($member->id)['rank_points']);
            self::assertSame(4, app(V2MemberRankResolver::class)->project($member->id)['level']);
        }
        self::assertSame(0, DB::table('member_rank_compensation_entries')->count());
    }

    public function test_stale_refund_choice_rejects_all_writes_and_same_key_can_retry_with_fresh_preview(): void
    {
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 100000);
        $service = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
        $context = $this->rankAdmin();
        $prepared = $service->write($context, $payment->public_id, 'refund', ['reason' => 'Synthetic refund'], (string) Str::uuid7());
        $choice = $service->detail($context, $payment->public_id)['data']['rank_change'];
        $this->adjust($user, 'points', ['delta' => 1, 'reason' => 'Concurrent change']);
        $key = (string) Str::uuid7();
        $input = ['reason' => 'Synthetic completion', 'provider_refund_confirmed' => true, 'rank_after' => 'bronze', 'rank_preview_token' => $choice['preview_token']];
        try {
            $service->write($context, $payment->public_id, 'complete', $input, $key, $prepared['data']['id']);
            self::fail('Stale preview must fail.');
        } catch (\App\Domain\Payment\V2\Exceptions\V2AdminPaymentReadException $exception) {
            self::assertSame(409, $exception->status);
        }
        self::assertDatabaseHas('payment_adjustments', ['public_id' => $prepared['data']['id'], 'status' => 'points_reserved']);
        self::assertSame(4, app(V2MemberRankResolver::class)->project($user->id)['level']);
        $input['rank_preview_token'] = $service->detail($context, $payment->public_id)['data']['rank_change']['preview_token'];
        $service->write($context, $payment->public_id, 'complete', $input, $key, $prepared['data']['id']);
        self::assertSame(2, app(V2MemberRankResolver::class)->project($user->id)['level']);
    }

    public function test_chargeback_explicit_rank_and_rank_audit_failure_roll_back_payment_and_points(): void
    {
        $user = $this->rankUser();
        $payment = $this->settleRankPayment($user, 100000);
        $service = app(\App\Domain\Payment\V2\Services\V2AdminPaymentAdjustmentService::class);
        $context = $this->rankAdmin();
        $choice = $service->detail($context, $payment->public_id)['data']['rank_change'];
        $input = ['reason' => 'Synthetic chargeback', 'rank_after' => 'vip', 'rank_preview_token' => $choice['preview_token']];
        $wallet = (array) DB::table('wallets')->where('user_id', $user->id)->first();
        DB::unprepared("CREATE FUNCTION pg_temp.reject_rank_manual() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic rank write failure'; END; $$");
        DB::unprepared('CREATE TRIGGER synthetic_rank_write_failure BEFORE INSERT ON member_rank_manual_changes FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_rank_manual()');
        $key = (string) Str::uuid7();
        try {
            $service->write($context, $payment->public_id, 'chargeback', $input, $key);
            self::fail('Injected rank write failure must roll back.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('synthetic rank write failure', $exception->getMessage());
        }
        self::assertSame($wallet, (array) DB::table('wallets')->where('user_id', $user->id)->first());
        self::assertSame(0, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
        DB::unprepared('DROP TRIGGER synthetic_rank_write_failure ON member_rank_manual_changes');
        $service->write($context, $payment->public_id, 'chargeback', $input, $key);
        self::assertSame(7, app(V2MemberRankResolver::class)->project($user->id)['level']);
        self::assertSame(0, DB::table('member_rank_achievements')->where('user_id', $user->id)->where('level', '>', 4)->count());
        self::assertTrue($service->write($context, $payment->public_id, 'chargeback', $input, $key)['idempotent_replay']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_late_month_processing_uses_current_grant_date_and_replays_missed_months(): void
    {
        $user = $this->rankUser();
        $this->settleRankPayment($user, 80000);
        $worker = app(V2MemberRankPromotionWorker::class);
        $worker->run('initial');
        $settings = app(V2MemberRankSettingsService::class);
        $ranks = $settings->effectiveAt(CarbonImmutable::now())['ranks'];
        $ranks[3]['reach_line'] = 70000;
        // Retired maintain line remains 100000; it cannot block a valid cumulative line.
        $settings->update($this->rankAdmin(), (string) Str::uuid7(), ['expected_revision' => 1, 'enabled' => true, 'default_skip_count' => 0, 'ranks' => $ranks]);
        $this->rankClock('2026-12-31T15:00:00Z');
        foreach (range(1, 4) as $attempt) $worker->run('catchup');
        self::assertSame(0, $worker->progress()['remaining']);
        self::assertSame(['2026-10-01', '2026-11-01', '2026-12-01', '2027-01-01'], DB::table('member_rank_cumulative_events')->where('user_id', $user->id)->orderBy('month')->pluck('month')->all());
        $achievement = DB::table('member_rank_achievements')->where('user_id', $user->id)->where('level', 4)->sole();
        self::assertSame('2026-11-01', $achievement->month);
        self::assertTrue(CarbonImmutable::parse($achievement->occurred_at)->equalTo(CarbonImmutable::now()));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function adjust(object $user, string $operation, array $input): void
    {
        $service = app(V2MemberRankAdminService::class);
        $context = $this->rankAdmin();
        $preview = $service->preview($context, $user->public_id, $operation, $input);
        $service->write($context, $user->public_id, $operation, [...$input,
            'expected_revision' => $preview['expected_revision'], 'preview_token' => $preview['preview_token']], (string) Str::uuid7());
    }
}
