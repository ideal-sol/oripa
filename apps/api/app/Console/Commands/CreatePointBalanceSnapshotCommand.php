<?php

namespace App\Console\Commands;

use App\Domain\Point\Services\PointBalanceSnapshotService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class CreatePointBalanceSnapshotCommand extends Command
{
    protected $signature = 'points:snapshot-balances {--date= : Snapshot date in YYYY-MM-DD format. Defaults to yesterday in Asia/Tokyo.}';

    protected $description = 'Create or update the daily paid/free point balance snapshot from current point lot balances.';

    public function handle(PointBalanceSnapshotService $service): int
    {
        try {
            $result = $service->createForDate($this->option('date') ?: null);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Failed to create point balance snapshot: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Snapshot %s saved. paid=%d free=%d base_date=%s',
            $result['snapshot_date'],
            $result['paid_unused_balance'],
            $result['free_unused_balance'],
            $result['is_base_date'] ? 'true' : 'false',
        ));

        return self::SUCCESS;
    }
}
