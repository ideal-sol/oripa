<?php

use App\Domain\Payment\V2\Services\V2FincodeReconciliationService;
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

Artisan::command('v2:fincode:reconcile-due {--limit=100}', function (V2FincodeReconciliationService $service): int {
    $result = $service->reconcileDue((int) $this->option('limit'));
    $this->info(sprintf(
        'Reconciled %d/%d due fincode payments; %d failed.',
        $result['processed'],
        $result['selected'],
        $result['failed'],
    ));

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Reconcile due fincode Konbini and Virtual Account payments.');

Artisan::command('v2:fincode:reconcile-card-registrations {--limit=100}', function (V2FincodeReconciliationService $service): int {
    $result = $service->reconcileCardRegistrations((int) $this->option('limit'));
    $this->info(sprintf(
        'Reconciled %d/%d fincode card registrations; %d expired and %d failed.',
        $result['processed'],
        $result['selected'],
        $result['expired'],
        $result['failed'],
    ));

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Reconcile canonical fincode 3DS2 card registrations.');

Schedule::command('points:expire')->hourly()->withoutOverlapping();
Schedule::command('v2:fincode:reconcile-due')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('v2:fincode:reconcile-card-registrations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('points:snapshot-balances')
    ->dailyAt('00:10')
    ->timezone(config('app.timezone', 'Asia/Tokyo'))
    ->withoutOverlapping();
Schedule::command('v2:points:snapshot-previous-day')
    ->dailyAt('00:20')
    ->timezone('Asia/Tokyo')
    ->withoutOverlapping();
Schedule::command('v2:reporting:work-exports --worker=scheduler --limit=5')
    ->everyMinute()
    ->withoutOverlapping();
Schedule::command('admin:daily-sales-report')
    ->dailyAt('10:00')
    ->timezone(config('services.discord.daily_report_timezone', config('app.timezone', 'Asia/Tokyo')))
    ->withoutOverlapping();
