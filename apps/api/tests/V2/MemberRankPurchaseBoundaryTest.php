<?php

namespace Tests\V2;

use App\Domain\MemberRank\Services\V2MemberRankPurchaseBonusService;
use App\Domain\MemberRank\Services\V2MemberRankResolver;
use App\Domain\Payment\V2\Services\V2PaymentService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemberRankAccountingFixture;
use Tests\TestCase;

final class MemberRankPurchaseBoundaryTest extends TestCase
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

    public static function maintenance(): array
    {
        return [[4999, 1, 0], [5000, 2, 25]];
    }

    #[DataProvider('maintenance')]
    public function test_maintenance_threshold_changes_next_purchase_not_original_snapshot(int $previous, int $level, int $bonus): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        $original = $this->settleRankPayment($user, $previous);
        $snapshot = DB::table('member_rank_purchase_bonuses')->where('payment_id', $original->id)->sole();
        $this->rankClock('2026-10-31T15:00:00Z');
        $payment = $this->settleRankPayment($user, 5000);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'rank_level' => $level, 'bonus_amount' => $bonus]);
        self::assertEquals($snapshot, DB::table('member_rank_purchase_bonuses')->where('payment_id', $original->id)->sole());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_six_month_idle_and_closed_frozen_rank_use_canonical_resolver(): void
    {
        $this->enableRankFixture();
        $idle = $this->rankUser();
        $closed = $this->rankUser();
        $this->seedRankState($idle, 7);
        $this->seedRankState($closed, 7);
        [$pending, $event] = $this->rankPayment($closed, 5000, 0, null, 'konbini');
        app(V2MemberRankResolver::class)->freeze($closed->id, CarbonImmutable::now());
        DB::table('users')->where('id', $closed->id)->update(['state' => 'closed']);
        $this->rankClock('2027-03-31T15:00:00Z');
        $payment = $this->settleRankPayment($idle, 5000);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'rank_level' => 1, 'bonus_amount' => 0, 'month' => '2027-04-01']);
        self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $pending->id, 'rank_level' => 7, 'bonus_amount' => 200, 'month' => '2027-04-01']);
        self::assertSame(0, DB::table('member_rank_point_entries')->where('user_id', $closed->id)->count());
        self::assertDatabaseHas('member_rank_states', ['user_id' => $closed->id, 'level' => 7, 'baseline_month' => '2026-10-01']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_off_payment_replay_never_backfills_purchase_bonus_after_activation(): void
    {
        $user = $this->rankUser();
        [$payment, $event] = $this->rankPayment($user, 5000);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        $this->enableRankFixture();
        $this->seedRankState($user, 2);
        app(V2PaymentService::class)->confirmSucceeded($event->id);
        self::assertSame(0, DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->count());
        self::assertSame(0, DB::table('point_operations')->where('source_type', 'member_rank_purchase')->count());
    }

    public static function unsettledStates(): array
    {
        return [['requires_action'], ['processing'], ['failed'], ['canceled'], ['expired']];
    }

    #[DataProvider('unsettledStates')]
    public function test_unsettled_provider_status_never_creates_bonus(string $status): void
    {
        $this->enableRankFixture();
        $user = $this->rankUser();
        $this->seedRankState($user, 2);
        [$payment, $event] = $this->rankPayment($user, 5000);
        app(V2PaymentService::class)->applyVerifiedStatus($event->id, $status);
        self::assertSame(0, DB::table('member_rank_purchase_bonuses')->where('payment_id', $payment->id)->count());
        self::assertSame(0, DB::table('point_operations')->where('user_id', $user->id)->count());
        self::assertSame(0, DB::table('member_rank_point_entries')->where('user_id', $user->id)->count());
    }

    public function test_m4_service_latency_is_measured_without_a_performance_acceptance_claim(): void
    {
        $samples = [];
        foreach (range(1, 8) as $iteration) {
            DB::beginTransaction();
            $user = $this->rankUser();
            $this->seedRankState($user, 2);
            $payment = $this->settleRankPayment($user, 5000);
            $this->enableRankFixture();
            DB::table('users')->where('id', $user->id)->lockForUpdate()->firstOrFail();
            $start = hrtime(true);
            app(V2MemberRankPurchaseBonusService::class)->grantForPayment($payment->id);
            $samples[] = (hrtime(true) - $start) / 1000000;
            self::assertDatabaseHas('member_rank_purchase_bonuses', ['payment_id' => $payment->id, 'bonus_amount' => 25]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::rollBack();
        }
        sort($samples);
        $result = ['samples' => 8, 'median_ms' => ($samples[3] + $samples[4]) / 2, 'max_ms' => max($samples),
            'budget_ms' => 50, 'scope' => 'M4 grant service only; excludes original payment, rank accounting and commit; shared synthetic host, not capacity proof'];
        fwrite(STDOUT, "\nMEMBER_RANK_M4_LATENCY ".json_encode($result, JSON_THROW_ON_ERROR)."\n");
    }
}
