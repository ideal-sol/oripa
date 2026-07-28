<?php

namespace App\Providers;

use App\Domain\Identity\Contracts\V2GoogleOidcTransport;
use App\Domain\Identity\Services\V2GoogleOidcHttpTransport;
use App\Domain\Notification\Contracts\SmsSender;
use App\Domain\Notification\Services\LogSmsSender;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(V2GoogleOidcTransport::class, V2GoogleOidcHttpTransport::class);
        $this->app->bind(SmsSender::class, function (): SmsSender {
            return match ((string) config('services.sms.driver', 'log')) {
                'log' => new LogSmsSender(),
                default => throw new RuntimeException('Unsupported SMS driver configured.'),
            };
        });
    }

    public function boot(): void
    {
    }
}
