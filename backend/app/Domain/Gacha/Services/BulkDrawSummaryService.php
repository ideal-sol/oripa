<?php

namespace App\Domain\Gacha\Services;

use App\Models\DrawRequest;
use App\Models\DrawResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkDrawSummaryService
{
    public function build(DrawRequest $drawRequest): array
    {
        $prizeCounts = DrawResult::query()
            ->where('draw_request_id', $drawRequest->id)
            ->whereNotNull('prize_id')
            ->join('gacha_prizes', 'gacha_prizes.id', '=', 'draw_results.prize_id')
            ->join('gacha_ranks', 'gacha_ranks.id', '=', 'draw_results.rank_id')
            ->groupBy([
                'gacha_prizes.name',
                'gacha_prizes.image_url',
                'gacha_ranks.rank_key',
                'gacha_ranks.display_name',
                'gacha_ranks.sort_order',
            ])
            ->orderBy('gacha_ranks.sort_order')
            ->orderBy('gacha_prizes.name')
            ->get([
                'gacha_prizes.name as prize_name',
                'gacha_prizes.image_url as prize_image_url',
                'gacha_ranks.rank_key',
                'gacha_ranks.display_name as rank_name',
                'gacha_ranks.sort_order as rank_sort_order',
                DB::raw('COUNT(*)::integer as win_count'),
            ]);

        $rankCounts = $prizeCounts
            ->groupBy('rank_key')
            ->map(function (Collection $rows): array {
                $first = $rows->first();

                return [
                    'rank_key' => $first->rank_key,
                    'rank_name' => $first->rank_name,
                    'win_count' => $rows->sum(fn (object $row): int => (int) $row->win_count),
                ];
            })
            ->values()
            ->all();

        $highestRankSortOrder = $prizeCounts->min('rank_sort_order');
        $highRankLimit = (int) config('oripa.bulk_draw.high_rank_result_limit', 20);
        $highRankQuery = DrawResult::query()
            ->where('draw_request_id', $drawRequest->id)
            ->whereNotNull('draw_results.prize_id')
            ->join('gacha_prizes', 'gacha_prizes.id', '=', 'draw_results.prize_id')
            ->join('gacha_ranks', 'gacha_ranks.id', '=', 'draw_results.rank_id');

        if ($highestRankSortOrder !== null) {
            $highRankQuery->where('gacha_ranks.sort_order', $highestRankSortOrder);
        } else {
            $highRankQuery->whereRaw('1 = 0');
        }

        $highRankResults = $highRankQuery
            ->orderBy('draw_results.draw_sequence_number')
            ->limit($highRankLimit + 1)
            ->get([
                'draw_results.draw_sequence_number',
                'draw_results.selected_rank_image_url',
                'draw_results.selected_draw_video_url',
                'gacha_prizes.name as prize_name',
                'gacha_prizes.image_url as prize_image_url',
                'gacha_ranks.rank_key',
                'gacha_ranks.display_name as rank_name',
            ]);

        return [
            'prize_counts' => $prizeCounts
                ->map(fn (object $row): array => [
                    'prize_name' => $row->prize_name,
                    'prize_image_url' => $row->prize_image_url,
                    'rank_key' => $row->rank_key,
                    'rank_name' => $row->rank_name,
                    'win_count' => (int) $row->win_count,
                ])
                ->all(),
            'rank_counts' => $rankCounts,
            'high_rank_results' => $highRankResults
                ->take($highRankLimit)
                ->map(fn (object $row): array => [
                    'draw_sequence_number' => (int) $row->draw_sequence_number,
                    'prize_name' => $row->prize_name,
                    'prize_image_url' => $row->prize_image_url,
                    'rank_key' => $row->rank_key,
                    'rank_name' => $row->rank_name,
                    'rank_image_url' => $row->selected_rank_image_url,
                    'draw_video_url' => $row->selected_draw_video_url,
                ])
                ->all(),
            'high_rank_results_truncated' => $highRankResults->count() > $highRankLimit,
        ];
    }
}
