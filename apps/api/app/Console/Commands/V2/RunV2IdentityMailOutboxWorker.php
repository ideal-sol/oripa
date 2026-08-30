<?php

namespace App\Console\Commands\V2;

use App\Domain\Mail\Services\V2IdentityMailOutboxWorker;
use Illuminate\Console\Command;

final class RunV2IdentityMailOutboxWorker extends Command
{
    protected $signature = 'v2:identity:work-mail-outbox
        {--worker= : Stable non-secret worker identity}
        {--limit=10 : Maximum messages to claim once}';

    protected $description = 'Process committed V2 Identity security mail messages';

    public function handle(V2IdentityMailOutboxWorker $worker): int
    {
        $workerId = $this->option('worker');
        $limit = $this->option('limit');
        if (
            ! is_string($workerId)
            || $workerId === ''
            || ! is_numeric($limit)
            || (int) $limit < 1
            || (int) $limit > 100
        ) {
            $this->components->error('Identity Mail worker options are invalid.');

            return self::INVALID;
        }

        $processed = $worker->run($workerId, (int) $limit);
        $this->components->info("Processed {$processed} Identity Mail message(s).");

        return self::SUCCESS;
    }
}
