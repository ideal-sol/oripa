<?php

namespace App\Console\Commands\V2;

use App\Domain\Integrations\Notion\NotionSyncWorker;
use Illuminate\Console\Command;
use Throwable;

final class RunV2NotionSyncWorker extends Command
{
    protected $signature = 'v2:notion:sync {--loop : Check queued and periodic work every five seconds}';

    protected $description = 'Process one Notion inventory sync and check the periodic interval';

    public function handle(NotionSyncWorker $worker): int
    {
        try {
            $stopped = false;
            if (function_exists('pcntl_signal')) {
                $this->trap([SIGTERM, SIGINT], function () use ($worker, &$stopped): void {
                    $stopped = true;
                    $worker->requestStop();
                });
            }
            do {
                $worker->runOnce();
                if ($this->option('loop') && ! $stopped) { sleep(5); }
            } while ($this->option('loop') && ! $stopped);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error('Notion worker stopped; inspect the sanitized sync status.');

            return self::FAILURE;
        }
    }
}
