<?php

namespace App\Domain\MemberRank\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class V2MemberRankPublicReadService
{
    public function __construct(
        private readonly V2MemberRankResolver $resolver,
        private readonly V2MemberRankSettingsService $settings
    ) {
    }

    public function current(int $userId): array
    {
        $instant = CarbonImmutable::now();
        $projection = $this->resolver->project($userId, $instant);
        if (! $projection['enabled']) {
            return ['data' => ['enabled' => false, ...array_fill_keys([
                'rank', 'period', 'rank_points', 'next_rank', 'maintain',
                'demotion_protection', 'benefits', 'as_of',
            ], null)]];
        }
        $month = $instant->setTimezone('Asia/Tokyo')->startOfMonth();
        $ranks = $this->settings->effectiveAt($month)['ranks'];
        $level = $projection['level'];
        $points = $projection['rank_points'];
        $current = $ranks[$level - 1];
        $next = $level < 7 ? $ranks[$level] : null;
        $skipCount = $projection['skip_count'];

        return ['data' => [
            'enabled' => true,
            'rank' => V2MemberRankGachaCondition::rank($level),
            'period' => ['month' => $month->format('Y-m'), 'resets_at' => $month->addMonth()->utc()->format('Y-m-d\TH:i:s\Z')],
            'rank_points' => $points,
            'next_rank' => $next === null ? null : [
                'code' => $next['code'], 'name' => V2MemberRankGachaCondition::rank($level + 1)['name'],
                'reach_line' => $next['reach_line'], 'remaining' => max(0, $next['reach_line'] - $points),
            ],
            'maintain' => $level === 1 ? null : [
                'line' => $current['maintain_line'], 'remaining' => max(0, $current['maintain_line'] - $points),
                'achieved' => $points >= $current['maintain_line'],
            ],
            'demotion_protection' => $skipCount === 0 ? null : [
                'remaining_judgments' => $skipCount,
                'guaranteed_until' => $month->addMonths($skipCount + 1)->subSecond()->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'benefits' => ['purchase_bonus' => $this->purchaseBenefit($current['purchase_bonus_rate_bp'])],
            'as_of' => $instant->utc()->format('Y-m-d\TH:i:s\Z'),
        ]];
    }

    public function listing(): array
    {
        $instant = CarbonImmutable::now();
        $state = DB::table('member_rank_settings')->where('id', 1)->firstOrFail();
        if (! $state->enabled) {
            return ['data' => []];
        }
        $version = $this->settings->effectiveAt($instant->setTimezone('Asia/Tokyo')->startOfMonth(), (int) $state->revision);

        return ['data' => array_map(fn (array $rank, int $index): array => [
            ...V2MemberRankGachaCondition::rank($index + 1),
            'reach_line' => $rank['reach_line'], 'maintain_line' => $rank['maintain_line'],
            'promotion_bonus' => ['points' => $rank['promotion_bonus'], 'point_type' => 'free'],
            'purchase_bonus' => $this->purchaseBenefit($rank['purchase_bonus_rate_bp']),
        ], $version['ranks'], array_keys($version['ranks']))];
    }

    private function purchaseBenefit(int $basisPoints): array
    {
        return ['rate_percent' => sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100), 'point_type' => 'free'];
    }
}
