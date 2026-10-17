<?php

namespace App\Domain\MemberRank\Services;

use Carbon\CarbonImmutable;

final class V2MemberRankMonthlyEvaluator
{
    public function evaluate(?array $state, array $totals, array $versions, CarbonImmutable $instant): array
    {
        $target = $instant->setTimezone('Asia/Tokyo')->startOfMonth();
        $result = [
            'level' => (int) ($state['level'] ?? 1),
            'skip_count' => (int) ($state['skip_count'] ?? 0),
            'baseline_month' => $state['baseline_month'] ?? $target->toDateString(),
            'baseline_level' => (int) ($state['baseline_level'] ?? 1),
            'last_judged_month' => $state['last_judged_month'] ?? null,
            'rank_points' => (int) ($totals[$target->toDateString()] ?? 0),
            'judgments' => [],
        ];
        if ($state === null) {
            return $result;
        }
        $cursor = CarbonImmutable::parse($result['baseline_month'], 'Asia/Tokyo')->startOfMonth();
        if ($cursor->greaterThan($target)) {
            throw new \LogicException('Member rank judgment cannot move behind its cursor.');
        }
        while ($cursor->lessThan($target)) {
            $version = null;
            foreach ($versions as $candidate) {
                if (CarbonImmutable::parse($candidate['effective_at'])->lessThanOrEqualTo($cursor)) {
                    $version = $candidate;
                    break;
                }
            }
            if ($version === null) {
                throw new \LogicException('Member rank historical settings are unavailable.');
            }
            $points = (int) ($totals[$cursor->toDateString()] ?? 0);
            $from = $result['level'];
            $skipped = $result['skip_count'] > 0;
            if ($skipped) {
                $result['skip_count']--;
            } elseif ($from > 1 && $points < (int) $version['maintain_lines'][$from]) {
                $result['level']--;
            }
            $month = $cursor->addMonth();
            $result['judgments'][] = [
                'month' => $cursor->toDateString(), 'judgment_month' => $month->toDateString(),
                'from_level' => $from, 'to_level' => $result['level'], 'rank_points' => $points,
                'setting_revision' => (int) $version['revision'], 'skipped' => $skipped,
            ];
            $result['baseline_month'] = $month->toDateString();
            $result['last_judged_month'] = $month->toDateString();
            $result['baseline_level'] = $result['level'];
            $cursor = $month;
        }

        return $result;
    }
}
