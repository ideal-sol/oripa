<?php

namespace Tests\Unit;

use App\Domain\MemberRank\Services\V2MemberRankCumulativePoints;
use App\Domain\MemberRank\Services\V2MemberRankMonthlyEvaluator;
use App\Domain\MemberRank\Services\V2MemberRankEventPromotionService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class MemberRankCumulativePolicyTest extends TestCase
{
    public function test_month_boundaries_keep_rank_and_points_without_judgments(): void
    {
        $state = ['level' => 4, 'baseline_month' => '2026-10-01', 'baseline_level' => 3, 'skip_count' => 2];
        foreach (['2026-11-30T14:59:59Z', '2026-11-30T15:00:00Z', '2026-12-31T15:00:00Z'] as $instant) {
            $result = (new V2MemberRankMonthlyEvaluator)->evaluate($state, [
                '2026-10-01' => 20000, '2026-11-01' => 60000, 'transfer' => 0, 'compensation' => 10000,
            ], [], CarbonImmutable::parse($instant));
            self::assertSame(4, $result['level']);
            self::assertSame(90000, $result['rank_points']);
            self::assertSame([], $result['judgments']);
            self::assertSame(0, $result['skip_count']);
        }
    }

    public function test_negative_month_and_allowances_are_combined_before_floor(): void
    {
        $policy = new V2MemberRankCumulativePoints;
        self::assertSame(52000, $policy->sum([42000, 10000]));
        self::assertSame(0, $policy->sum([20000, -30000]));
        self::assertSame(5000, $policy->sum([20000, -30000, 15000]));
    }

    public function test_read_does_not_promote_when_thresholds_change(): void
    {
        $result = (new V2MemberRankMonthlyEvaluator)->evaluate(['level' => 3], [80000], [
            ['effective_at' => '2026-11-30T15:00:00Z', 'reach_lines' => [4 => 70000]],
        ], CarbonImmutable::parse('2026-12-01T00:00:00+09:00'));
        self::assertSame(3, $result['level']);
        self::assertSame([], $result['judgments']);
    }

    public function test_overflow_fails_instead_of_rounding_points(): void
    {
        $this->expectException(\OverflowException::class);
        (new V2MemberRankCumulativePoints)->sum([PHP_INT_MAX, 1]);
    }

    public function test_compensation_only_covers_threshold_increases_and_cancels_worker_balance(): void
    {
        $policy = new V2MemberRankEventPromotionService;
        self::assertSame(30000, $policy->compensationDelta(120000, 0, 100000, 150000));
        self::assertSame(-30000, $policy->compensationDelta(150000, 30000, 150000, 70000));
        self::assertSame(0, $policy->compensationDelta(170000, 30000, 150000, 150000));
        self::assertSame(0, $policy->compensationDelta(80000, 30000, 150000, 150000));
        self::assertSame(50000, $policy->compensationDelta(80000, 0, 100000, 150000));
        self::assertSame(0, $policy->compensationDelta(80000, 30000, 150000, 100000));
        self::assertSame(-10000, $policy->compensationDelta(110000, 30000, 150000, 100000));
    }
}
