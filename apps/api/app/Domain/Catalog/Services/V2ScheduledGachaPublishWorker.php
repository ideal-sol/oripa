<?php

namespace App\Domain\Catalog\Services;

use RuntimeException;

final class V2ScheduledGachaPublishWorker
{
    public function run(string $worker, ?int $limit = null): int
    {
        if (trim($worker) === '') {
            throw new RuntimeException(
                'Scheduled Publish Worker identity is invalid.'
            );
        }
        $claimSize = $limit ?? (int) config(
            'v2_catalog.scheduled_publish.worker_claim_size'
        );
        if ($claimSize < 1 || $claimSize > 50) {
            throw new RuntimeException(
                'Scheduled Publish Worker claim size is invalid.'
            );
        }

        return 0;
    }
}
