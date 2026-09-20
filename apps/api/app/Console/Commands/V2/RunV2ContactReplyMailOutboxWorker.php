<?php

namespace App\Console\Commands\V2;

use App\Domain\Mail\Services\V2ContactReplyMailOutboxWorker;
use Illuminate\Console\Command;

final class RunV2ContactReplyMailOutboxWorker extends Command
{
    protected $signature = 'v2:contact:work-reply-mail-outbox {--worker=} {--limit=10}';

    protected $description = 'Process new Contact reply email events without automatic mail retries';

    public function handle(V2ContactReplyMailOutboxWorker $worker): int
    {
        $identity = $this->option('worker');
        $limit = $this->option('limit');
        if (! is_string($identity) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/', $identity)
            || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            return self::INVALID;
        }
        $count = $worker->run($identity, (int) $limit);
        $this->components->info("Processed {$count} Contact reply event(s).");
        return self::SUCCESS;
    }
}
