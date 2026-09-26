<?php

namespace App\Console\Commands\V2;

use App\Domain\PrizeShipping\Services\V2PrizeShippingService;
use Illuminate\Console\Command;

final class ExpireShippingOnlyPrizesCommand extends Command
{
    protected $signature = 'v2:prizes:expire-shipping-only {--limit=1000}';

    protected $description = 'Expire stored shipping-only prizes after their existing storage deadline';

    public function handle(V2PrizeShippingService $prizes): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $this->info('Expired shipping-only prizes: '.$prizes->expireShippingOnly($limit));

        return self::SUCCESS;
    }
}
