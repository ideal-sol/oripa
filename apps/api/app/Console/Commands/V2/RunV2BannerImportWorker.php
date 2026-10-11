<?php

namespace App\Console\Commands\V2;

use App\Domain\ContentContact\Services\V2BannerImportService;
use App\Domain\ContentContact\Services\V2BannerImportWorker;
use Illuminate\Console\Command;

final class RunV2BannerImportWorker extends Command
{
    protected $signature = 'v2:content:work-banner-imports {--purge-only : Remove expired secrets and history without processing rows}';

    protected $description = 'Process one approved Banner CSV Import Job';

    public function handle(V2BannerImportWorker $worker, V2BannerImportService $service): int
    {
        if ($this->option('purge-only')) {
            $service->expireSecrets();
            $this->components->info('Expired import secrets and history removed.');
            return self::SUCCESS;
        }
        try {
            $processed = $worker->runOnce();
            $this->components->info("Completed {$processed} Banner Import Row(s).");
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->components->error('Banner import processing stopped; inspect the sanitized job status.');
            return self::FAILURE;
        }
    }
}
