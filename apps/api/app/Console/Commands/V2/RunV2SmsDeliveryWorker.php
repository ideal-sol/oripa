<?php

namespace App\Console\Commands\V2;

use App\Domain\Sms\Services\V2SmsDeliveryWorker;
use Illuminate\Console\Command;

final class RunV2SmsDeliveryWorker extends Command
{
    protected $signature = 'v2:identity:work-sms-outbox
        {--worker= : Stable non-secret worker identity}
        {--limit=10 : Maximum messages to claim once}';

    protected $description = 'Process committed V2 SMS verification messages';

    public function handle(V2SmsDeliveryWorker $worker): int
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
            $this->components->error('SMS worker options are invalid.');

            return self::INVALID;
        }

        $processed = $worker->run($workerId, (int) $limit);
        $this->components->info("Processed {$processed} SMS message(s).");

        return self::SUCCESS;
    }
}
