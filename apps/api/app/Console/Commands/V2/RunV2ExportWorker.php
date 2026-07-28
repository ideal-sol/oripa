<?php

namespace App\Console\Commands\V2;

use App\Domain\Reporting\Services\V2ExportWorker;
use Illuminate\Console\Command;

final class RunV2ExportWorker extends Command
{
    protected $signature = 'v2:reporting:work-exports
        {--worker= : Stable non-secret worker identity}
        {--limit= : Maximum jobs to claim once}';

    protected $description = 'Process committed V2 Reporting Export Jobs';

    public function handle(V2ExportWorker $worker): int
    {
        $workerId = $this->option('worker');
        if (! is_string($workerId) || $workerId === '') {
            $this->components->error('A non-secret worker identity is required.');

            return self::INVALID;
        }
        $limit = $this->option('limit');
        if ($limit !== null && (! is_numeric($limit) || (int) $limit < 1)) {
            $this->components->error('The claim limit is invalid.');

            return self::INVALID;
        }
        $processed = $worker->run($workerId, $limit === null ? null : (int) $limit);
        $this->components->info("Processed {$processed} Export Job(s).");

        return self::SUCCESS;
    }
}
