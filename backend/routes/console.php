<?php

use App\Domain\Point\Services\PointExpirationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('points:expire {--limit=1000}', function (PointExpirationService $service): int {
    $result = $service->expire((int) $this->option('limit'));

    $this->info(sprintf(
        'Expired %d point lots / %d points.',
        $result['expired_lot_count'],
        $result['expired_point_amount'],
    ));

    return 0;
})->purpose('Expire free point lots whose expiration time has passed.');

Schedule::command('points:expire')->hourly()->withoutOverlapping();
Schedule::command('admin:daily-sales-report')
    ->dailyAt('10:00')
    ->timezone(config('services.discord.daily_report_timezone', config('app.timezone', 'Asia/Tokyo')))
    ->withoutOverlapping();
