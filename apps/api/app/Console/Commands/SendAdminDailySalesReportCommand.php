<?php

namespace App\Console\Commands;

use App\Domain\Admin\Services\DailySalesReportService;
use App\Domain\Notification\Services\DiscordNotificationService;
use Illuminate\Console\Command;

class SendAdminDailySalesReportCommand extends Command
{
    protected $signature = 'admin:daily-sales-report {--date= : YYYY-MM-DDで指定すると対象日を固定して送信します}';

    protected $description = 'Send previous day sales and draw summary to the admin Discord channel.';

    public function handle(DailySalesReportService $reportService, DiscordNotificationService $discord): int
    {
        $date = $this->option('date');
        $report = is_string($date) && $date !== ''
            ? $reportService->buildForDate($date)
            : $reportService->buildPreviousDayReport();
        $message = $reportService->formatDiscordMessage($report);

        $sent = $discord->sendToAdmin($message);

        $this->line($message);
        $this->info($sent ? 'Discord notification sent.' : 'Discord notification skipped.');

        return self::SUCCESS;
    }
}
