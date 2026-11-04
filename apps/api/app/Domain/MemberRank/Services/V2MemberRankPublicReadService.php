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
                'demotion_protection', 'benefits', 'as_of', 'point_basis', 'breakdown', 'promotion_bonus', 'transferred',
            ], null)]];
        }
        $month = $instant->setTimezone('Asia/Tokyo')->startOfMonth();
        $ranks = $this->settings->effectiveAt($month)['ranks'];
        $level = $projection['level'];
        $points = $projection['rank_points'];
        $current = $ranks[$level - 1];
        $next = $level < 7 ? $ranks[$level] : null;

        return ['data' => [
            'enabled' => true,
            'point_basis' => 'cumulative',
            'transferred' => app(V2MemberRankTransferService::class)->transferred($userId),
            'promotion_bonus' => ['received' => $level > 1 && DB::table('member_rank_achievements')
                ->where('user_id', $userId)->where('level', $level)->where('bonus_amount', '>', 0)->exists()],
            'breakdown' => [
                'recent' => DB::table('member_rank_point_entries')->where('user_id', $userId)->where('reason', 'payment')
                    ->orderByDesc('granted_at')->orderByDesc('id')->limit(3)->get()
                    ->map(fn (object $entry): array => [
                        'occurred_at' => CarbonImmutable::parse($entry->granted_at)->utc()->toIso8601String(),
                        'kind' => 'purchase', 'points' => (int) $entry->applied_delta,
                        'label' => 'コイン購入（'.number_format((int) $entry->applied_delta).'コイン）',
                    ])->all(),
                'total' => $points,
            ],
            'rank' => V2MemberRankGachaCondition::rank($level),
            'period' => null,
            'rank_points' => $points,
            'next_rank' => $next === null ? null : [
                'code' => $next['code'], 'name' => V2MemberRankGachaCondition::rank($level + 1)['name'],
                'reach_line' => $next['reach_line'], 'remaining' => max(0, $next['reach_line'] - $points),
            ],
            'maintain' => null,
            'demotion_protection' => null,
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
            'reach_line' => $rank['reach_line'], 'maintain_line' => null,
            'promotion_bonus' => ['points' => $rank['promotion_bonus'], 'point_type' => 'free'],
            'purchase_bonus' => $this->purchaseBenefit($rank['purchase_bonus_rate_bp']),
        ], $version['ranks'], array_keys($version['ranks']))];
    }

    private function purchaseBenefit(int $basisPoints): array
    {
        return ['rate_percent' => sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100), 'point_type' => 'free'];
    }
}
