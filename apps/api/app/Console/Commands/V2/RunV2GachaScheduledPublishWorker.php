<?php

namespace App\Console\Commands\V2;

use App\Domain\Catalog\Services\V2ScheduledGachaPublishWorker;
use Illuminate\Console\Command;

final class RunV2GachaScheduledPublishWorker extends Command
{
    protected $signature = 'v2:catalog:work-scheduled-publishes
        {--worker= : Stable non-secret worker identity}
        {--limit= : Maximum schedules to claim once}';

    protected $description = 'Activate due V2 Gacha Publish Schedules';

    public function handle(V2ScheduledGachaPublishWorker $worker): int
    {
        $workerId = $this->option('worker');
        if (! is_string($workerId) || $workerId === '') {
            $this->components->error(
                'A non-secret worker identity is required.'
            );

            return self::INVALID;
        }
        $limit = $this->option('limit');
        if ($limit !== null && (! is_numeric($limit) || (int) $limit < 1)) {
            $this->components->error('The claim limit is invalid.');

            return self::INVALID;
        }
        $processed = $worker->run(
            $workerId,
            $limit === null ? null : (int) $limit
        );
        $this->components->info(
            "Processed {$processed} Gacha Publish Schedule(s)."
        );

        return self::SUCCESS;
    }
}
