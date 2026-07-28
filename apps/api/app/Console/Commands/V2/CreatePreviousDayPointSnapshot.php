<?php

namespace App\Console\Commands\V2;

use App\Domain\Point\Services\V2PointSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class CreatePreviousDayPointSnapshot extends Command
{
    protected $signature = 'v2:points:snapshot-previous-day';

    protected $description = 'Generate the previous JST business-day snapshot from the V2 Ledger cutoff';

    public function handle(V2PointSnapshotService $snapshots): int
    {
        $date = CarbonImmutable::now('Asia/Tokyo')->subDay()->toDateString();
        $snapshot = $snapshots->generate($date);
        $this->components->info(
            sprintf(
                'Generated V2 Point Snapshot %s (%s).',
                $snapshot->snapshot_date->format('Y-m-d'),
                $snapshot->checksum
            )
        );

        return self::SUCCESS;
    }
}
