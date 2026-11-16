<?php

namespace App\Console\Commands;

use App\Domain\Line\Services\V2LineAutomatedWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class V2LineAutomatedEvents extends Command
{
    protected $signature = 'v2:line:work-automated-events {--limit=10}';
    protected $description = 'Persist automated LINE handoffs; disabled unless separately activated';

    public function handle(V2LineAutomatedWorker $worker): int
    {
        if (! config('v2_line.automated_worker_enabled', false)) {
            $this->info('Automated LINE processing is disabled.');
            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) return self::FAILURE;
        try {
            $worker->run('line-automated:'.Str::uuid(), $limit);
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Automated LINE processing deferred.');
            return self::FAILURE;
        }
    }
}
