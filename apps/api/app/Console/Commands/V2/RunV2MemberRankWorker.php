<?php

namespace App\Console\Commands\V2;

use App\Domain\MemberRank\Services\V2MemberRankMonthlyWorker;
use Illuminate\Console\Command;
use Throwable;

final class RunV2MemberRankWorker extends Command
{
    protected $signature = 'v2:member-rank:work
        {--worker= : Non-secret worker identity}
        {--limit=100 : Maximum users per batch, from 1 to 500}
        {--loop : Discover monthly work continuously without Scheduler}';

    protected $description = 'Materialize pending member rank month judgments';

    public function handle(V2MemberRankMonthlyWorker $worker): int
    {
        $identity = $this->option('worker');
        $limit = $this->option('limit');
        if (! is_string($identity) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/', $identity) !== 1
            || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 500) {
            $this->components->error('A valid non-secret worker identity and limit from 1 to 500 are required.');

            return self::INVALID;
        }
        $stopped = false;
        if (function_exists('pcntl_signal')) {
            $this->trap([SIGTERM, SIGINT], function () use ($worker, &$stopped): void {
                $stopped = true;
                $worker->requestStop();
            });
        }
        try {
            do {
                $processed = $worker->run($identity, (int) $limit);
                if (! $this->option('loop')) {
                    $this->components->info("Processed {$processed} member rank state(s).");
                } elseif (! $stopped && $processed === 0) {
                    sleep(5);
                }
            } while ($this->option('loop') && ! $stopped);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error('Member rank worker stopped; inspect the sanitized monthly job status.');

            return self::FAILURE;
        }
    }
}
