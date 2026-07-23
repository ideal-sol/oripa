<?php

namespace App\Domain\Admin\Services;

class SalesManagementCsvService
{
    public function __construct(private readonly SalesManagementReportService $reportService)
    {
    }

    public function monthlySales(int $year, int $month): string
    {
        $report = $this->reportService->monthlySales($year, $month);
        $rows = [];

        foreach ($report['days'] as $day) {
            $methods = collect($day['methods'])
                ->map(fn (array $method): string => sprintf('%s:%d円/%d件', $method['payment_method'], $method['amount'], $method['count']))
                ->implode(' | ');

            $rows[] = [
                $day['date'],
                $day['total_amount'],
                $day['refund_amount'],
                $day['chargeback_amount'],
                $day['net_amount'],
                $day['payment_count'],
                $day['refund_count'],
                $day['chargeback_count'],
                $methods,
            ];
        }

        return $this->csv([
            '対象日',
            '総売上',
            '返金金額',
            'チャージバック金額',
            '純売上',
            '決済件数',
            '返金件数',
            'チャージバック件数',
            '決済方法別売上',
        ], $rows);
    }

    public function dailyPayments(string $date): string
    {
        $report = $this->reportService->dailyPayments($date, 10000);

        return $this->csv([
            '決済日時',
            '決済ID',
            'ユーザー',
            'メールアドレス',
            '購入プラン',
            '決済方法',
            'provider',
            'ステータス',
            '売上金額',
            '返金日',
            'チャージバック日',
        ], collect($report['data'])->map(fn (array $row): array => [
            $row['paid_at'],
            $row['id'],
            $row['user']['name'] ?? '',
            $row['user']['email'] ?? '',
            $row['purchase_plan']['name'] ?? '',
            $row['payment_method'],
            $row['provider'],
            $row['status'],
            $row['amount'],
            $row['refunded_at'],
            $row['chargeback_at'],
        ])->all());
    }

    public function dailyAdjustments(string $date): string
    {
        $report = $this->reportService->dailyAdjustments($date);

        return $this->csv([
            '発生日',
            '種別',
            '決済ID',
            '元決済日',
            'ユーザー',
            'メールアドレス',
            '購入プラン',
            '決済方法',
            'provider',
            'ステータス',
            '返金金額',
            'チャージバック金額',
        ], collect($report['data'])->map(fn (array $row): array => [
            $row['occurred_at'],
            $row['type'] === 'chargeback' ? 'チャージバック' : '返金',
            $row['payment_id'],
            $row['original_paid_at'],
            $row['user']['name'] ?? '',
            $row['user']['email'] ?? '',
            $row['purchase_plan']['name'] ?? '',
            $row['payment_method'],
            $row['provider'],
            $row['status'],
            $row['type'] === 'refund' ? $row['amount'] : 0,
            $row['type'] === 'chargeback' ? $row['amount'] : 0,
        ])->all());
    }

    public function monthlyPointConsumption(int $year, int $month): string
    {
        $report = $this->reportService->monthlyPointConsumption($year, $month);
        $rows = [];

        foreach ($report['days'] as $day) {
            if ($day['gachas'] === []) {
                $rows[] = [
                    $day['date'],
                    $day['paid_point_total'],
                    $day['free_point_total'],
                    '',
                    '',
                    '',
                    '',
                    '',
                ];

                continue;
            }

            foreach ($day['gachas'] as $gacha) {
                $rows[] = [
                    $day['date'],
                    $day['paid_point_total'],
                    $day['free_point_total'],
                    $gacha['gacha_id'],
                    $gacha['gacha_title'],
                    $gacha['paid_point'],
                    $gacha['free_point'],
                    $gacha['draw_count'],
                ];
            }
        }

        return $this->csv([
            '対象日',
            '有償ポイント消費合計',
            '無償ポイント消費合計',
            'ガチャID',
            'ガチャ名',
            'ガチャ有償ポイント消費',
            'ガチャ無償ポイント消費',
            '抽選口数',
        ], $rows);
    }

    public function dailyPointConsumption(string $date): string
    {
        $report = $this->reportService->dailyPointConsumption($date, 10000);

        return $this->csv([
            '日時',
            'draw_request ID',
            'ユーザー',
            'メールアドレス',
            'ガチャID',
            'ガチャ名',
            '抽選回数',
            '有償ポイント',
            '無償ポイント',
            'ステータス',
        ], collect($report['data'])->map(fn (array $row): array => [
            $row['datetime'],
            $row['draw_request_id'],
            $row['user']['name'] ?? '',
            $row['user']['email'] ?? '',
            $row['gacha']['id'] ?? '',
            $row['gacha']['title'] ?? '',
            $row['draw_count'],
            $row['paid_point'],
            $row['free_point'],
            $row['status'],
        ])->all());
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return "\xEF\xBB\xBF".$content;
    }
}
