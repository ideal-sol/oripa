<?php

namespace App\Domain\Gacha\Services;

use App\Models\Gacha;

class GachaProfitSimulationService
{
    /**
     * @return array<string, mixed>
     */
    public function simulate(Gacha $gacha): array
    {
        $gacha->loadMissing([
            'prizes',
            'currentProbabilityVersion.stages.probabilities.prize',
        ]);

        $price = (int) $gacha->price;
        $totalCount = (int) $gacha->total_count;
        $soldCount = (int) $gacha->sold_count;
        $remainingCount = max(0, $totalCount - $soldCount);

        $totalSales = $price * $totalCount;
        $soldSales = $price * $soldCount;
        $remainingSales = $price * $remainingCount;

        $prizeInventoryCost = 0;
        $prizeAwardedCost = 0;
        $prizeRemainingCost = 0;

        foreach ($gacha->prizes as $prize) {
            $costPrice = (int) $prize->cost_price;
            $maxWinCount = (int) $prize->max_win_count;
            $wonCount = (int) $prize->won_count;

            $prizeInventoryCost += $costPrice * $maxWinCount;
            $prizeAwardedCost += $costPrice * $wonCount;
            $prizeRemainingCost += $costPrice * max(0, $maxWinCount - $wonCount);
        }

        $minimumGuaranteeMaxCost = (int) $gacha->minimum_guarantee_cost * $totalCount;
        $maxCost = $prizeInventoryCost + $minimumGuaranteeMaxCost;
        $projectedProfit = $totalSales - $maxCost;
        $projectedMarginRate = $totalSales > 0 ? round(($projectedProfit / $totalSales) * 100, 2) : null;
        $targetMarginRate = $gacha->target_margin !== null ? (float) $gacha->target_margin : null;
        $targetProfit = $targetMarginRate !== null ? (int) round($totalSales * ($targetMarginRate / 100)) : null;
        $gapToTargetProfit = $targetProfit !== null ? $projectedProfit - $targetProfit : null;
        $expected = $this->expectedCost($gacha, $totalSales);

        return [
            'gacha_id' => $gacha->id,
            'sales' => [
                'price' => $price,
                'total_count' => $totalCount,
                'sold_count' => $soldCount,
                'remaining_count' => $remainingCount,
                'total_sales' => $totalSales,
                'sold_sales' => $soldSales,
                'remaining_sales' => $remainingSales,
            ],
            'costs' => [
                'prize_inventory_cost' => $prizeInventoryCost,
                'prize_awarded_cost' => $prizeAwardedCost,
                'prize_remaining_cost' => $prizeRemainingCost,
                'minimum_guarantee_max_cost' => $minimumGuaranteeMaxCost,
                'max_cost' => $maxCost,
            ],
            'profit' => [
                'projected_profit' => $projectedProfit,
                'projected_margin_rate' => $projectedMarginRate,
                'target_margin_rate' => $targetMarginRate,
                'target_profit' => $targetProfit,
                'gap_to_target_profit' => $gapToTargetProfit,
                'meets_target' => $gapToTargetProfit === null ? null : $gapToTargetProfit >= 0,
            ],
            'expected' => $expected,
            'warnings' => $this->warnings($gacha, $projectedProfit, $projectedMarginRate, $targetMarginRate),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expectedCost(Gacha $gacha, int $totalSales): array
    {
        $version = $gacha->currentProbabilityVersion;

        if (! $version) {
            return [
                'available' => false,
                'probability_version_id' => null,
                'expected_cost_per_draw' => null,
                'expected_total_cost' => null,
                'expected_profit' => null,
                'expected_margin_rate' => null,
                'stages' => [],
            ];
        }

        $expectedTotalCost = 0.0;
        $stageResults = [];

        foreach ($version->stages->sortBy('sort_order') as $stage) {
            $minDraw = max(1, (int) $stage->min_draw_number);
            $maxDraw = $stage->max_draw_number !== null
                ? min((int) $gacha->total_count, (int) $stage->max_draw_number)
                : (int) $gacha->total_count;
            $drawCount = max(0, $maxDraw - $minDraw + 1);
            $expectedCostPerDraw = 0.0;

            foreach ($stage->probabilities as $probability) {
                $probabilityRate = (int) $probability->probability_ppm / 1_000_000;
                $cost = $probability->is_minimum_guarantee
                    ? (int) $gacha->minimum_guarantee_cost
                    : (int) ($probability->prize?->cost_price ?? 0);

                $expectedCostPerDraw += $probabilityRate * $cost;
            }

            $stageExpectedCost = $expectedCostPerDraw * $drawCount;
            $expectedTotalCost += $stageExpectedCost;
            $stageResults[] = [
                'stage_key' => $stage->stage_key,
                'name' => $stage->name,
                'draw_count' => $drawCount,
                'expected_cost_per_draw' => round($expectedCostPerDraw, 2),
                'expected_total_cost' => (int) round($stageExpectedCost),
            ];
        }

        $expectedTotalCostInt = (int) round($expectedTotalCost);
        $expectedProfit = $totalSales - $expectedTotalCostInt;

        return [
            'available' => true,
            'probability_version_id' => $version->id,
            'expected_cost_per_draw' => (int) $gacha->total_count > 0
                ? round($expectedTotalCost / (int) $gacha->total_count, 2)
                : null,
            'expected_total_cost' => $expectedTotalCostInt,
            'expected_profit' => $expectedProfit,
            'expected_margin_rate' => $totalSales > 0 ? round(($expectedProfit / $totalSales) * 100, 2) : null,
            'stages' => $stageResults,
        ];
    }

    /**
     * @return list<string>
     */
    private function warnings(Gacha $gacha, int $projectedProfit, ?float $projectedMarginRate, ?float $targetMarginRate): array
    {
        $warnings = [];

        if ($gacha->prizes->isEmpty()) {
            $warnings[] = '景品が登録されていません。';
        }

        if ($projectedProfit < 0) {
            $warnings[] = '完売時の最大原価シナリオで赤字になります。';
        }

        if ($targetMarginRate !== null && $projectedMarginRate !== null && $projectedMarginRate < $targetMarginRate) {
            $warnings[] = '目標粗利率を下回っています。';
        }

        return $warnings;
    }
}
