<?php

namespace App\Console\Commands\V2;

use App\Domain\Point\Services\V2PointService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class ExpirePointLots extends Command
{
    protected $signature = 'v2:points:expire {--cutoff= : Inclusive ISO-8601 cutoff. Defaults to now.}';

    protected $description = 'Expire due paid and free V2 point lots transactionally';

    public function handle(V2PointService $points): int
    {
        $cutoff = CarbonImmutable::parse($this->option('cutoff') ?? now())->startOfSecond();
        $count = $points->expire($cutoff);
        $this->info(sprintf('Expired %d point lots through %s.', $count, $cutoff->toIso8601String()));

        return self::SUCCESS;
    }
}
