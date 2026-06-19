<?php

namespace App\Domain\Admin\Services;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use App\Domain\Gacha\Enums\DrawResultType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\DrawRequest;
use App\Models\DrawResult;
use App\Models\Gacha;
use App\Models\GachaRank;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailySalesReportService
{
    public function buildPreviousDayReport(?CarbonInterface $now = null): array
    {
        $timezone = (string) config('services.discord.daily_report_timezone', config('app.timezone', 'Asia/Tokyo'));
        $base = $now
            ? CarbonImmutable::instance($now)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);
        $date = $base->subDay()->toDateString();

        return $this->buildForDate($date, $timezone);
    }

    public function buildForDate(string $date, ?string $timezone = null): array
    {
        $timezone ??= (string) config('services.discord.daily_report_timezone', config('app.timezone', 'Asia/Tokyo'));
        $start = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $end = $start->endOfDay();

        $paymentTotal = (int) Payment::query()
            ->where('status', PaymentStatus::Succeeded->value)
            ->whereBetween('paid_at', [$start, $end])
            ->sum('amount');

        $gachaSummaries = $this->gachaSummaries($start, $end);

        return [
            'date' => $start->toDateString(),
            'timezone' => $timezone,
            'payment_total_amount' => $paymentTotal,
            'gacha_sales_point_total' => $gachaSummaries->sum('sales_point'),
            'gacha_draw_count_total' => $gachaSummaries->sum('draw_count'),
            'gachas' => $gachaSummaries->values()->all(),
        ];
    }

    public function formatDiscordMessage(array $report): string
    {
        $lines = [
            sprintf('【日次売上レポート】%s (%s)', $report['date'], $report['timezone']),
            sprintf('前日の総売上(決済): %s円', number_format((int) $report['payment_total_amount'])),
            sprintf('ガチャ消費売上合計: %spt', number_format((int) $report['gacha_sales_point_total'])),
            sprintf('ガチャ抽選口数合計: %s口', number_format((int) $report['gacha_draw_count_total'])),
        ];

        if (count($report['gachas']) === 0) {
            $lines[] = '前日のガチャ抽選はありません。';

            return implode("\n", $lines);
        }

        foreach ($report['gachas'] as $gacha) {
            $lines[] = '';
            $lines[] = sprintf(
                '■ %s: 売上 %spt / 抽選 %s口 / 残り %s口',
                $gacha['title'],
                number_format((int) $gacha['sales_point']),
                number_format((int) $gacha['draw_count']),
                number_format((int) $gacha['remaining_draw_count']),
            );

            if (count($gacha['ranks']) === 0) {
                $lines[] = '  ランク景品の排出はありません。';
                continue;
            }

            foreach ($gacha['ranks'] as $rank) {
                $lines[] = sprintf(
                    '  - %s: 排出 %s個 / 残り景品 %s個',
                    $rank['display_name'],
                    number_format((int) $rank['emitted_count']),
                    number_format((int) $rank['remaining_prize_count']),
                );
            }
        }

        return implode("\n", $lines);
    }

    private function gachaSummaries(CarbonInterface $start, CarbonInterface $end): Collection
    {
        $drawsByGacha = DrawRequest::query()
            ->select('gacha_id')
            ->selectRaw('COALESCE(SUM(consumed_point_total), 0) as sales_point')
            ->selectRaw('COALESCE(SUM(draw_count), 0) as draw_count')
            ->where('status', DrawRequestStatus::Completed->value)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('gacha_id')
            ->get()
            ->keyBy('gacha_id');

        if ($drawsByGacha->isEmpty()) {
            return collect();
        }

        $emittedByRank = DrawResult::query()
            ->select('gacha_id', 'rank_id')
            ->selectRaw('COUNT(*) as emitted_count')
            ->where('result_type', DrawResultType::Prize->value)
            ->whereNotNull('rank_id')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('gacha_id', 'rank_id')
            ->get()
            ->keyBy(fn (DrawResult $result): string => $result->gacha_id.'-'.$result->rank_id);

        $remainingByRank = DB::table('gacha_prizes')
            ->select('gacha_id', 'rank_id')
            ->selectRaw('COALESCE(SUM(GREATEST(max_win_count - won_count, 0)), 0) as remaining_prize_count')
            ->whereIn('gacha_id', $drawsByGacha->keys())
            ->groupBy('gacha_id', 'rank_id')
            ->get()
            ->keyBy(fn (object $row): string => $row->gacha_id.'-'.$row->rank_id);

        $ranksByGacha = GachaRank::query()
            ->whereIn('gacha_id', $drawsByGacha->keys())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('gacha_id');

        return Gacha::query()
            ->whereIn('id', $drawsByGacha->keys())
            ->orderBy('id')
            ->get()
            ->map(function (Gacha $gacha) use ($drawsByGacha, $emittedByRank, $remainingByRank, $ranksByGacha): array {
                $drawSummary = $drawsByGacha->get($gacha->id);
                $remainingDrawCount = max(0, (int) $gacha->total_count - (int) $gacha->sold_count);

                return [
                    'id' => $gacha->id,
                    'title' => $gacha->title,
                    'sales_point' => (int) $drawSummary->sales_point,
                    'draw_count' => (int) $drawSummary->draw_count,
                    'remaining_draw_count' => $remainingDrawCount,
                    'ranks' => $ranksByGacha->get($gacha->id, collect())
                        ->map(function (GachaRank $rank) use ($emittedByRank, $remainingByRank): array {
                            $key = $rank->gacha_id.'-'.$rank->id;

                            return [
                                'id' => $rank->id,
                                'display_name' => $rank->display_name,
                                'emitted_count' => (int) ($emittedByRank->get($key)?->emitted_count ?? 0),
                                'remaining_prize_count' => (int) ($remainingByRank->get($key)?->remaining_prize_count ?? 0),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            });
    }
}
