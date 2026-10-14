<?php

namespace App\Domain\Integrations\Notion;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Support\V2DatabaseTimestamp;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NotionSyncWorker
{
    private bool $stopped = false;

    public function __construct(
        private readonly NotionConfig $config,
        private readonly NotionSyncService $service,
        private readonly NotionSyncEngine $engine,
        private readonly V2AuditLogService $audit,
    ) {}

    public function requestStop(): void
    {
        $this->stopped = true;
    }

    public function runOnce(): int
    {
        if (! $this->config->enabled() || ! $this->config->configured() || $this->stopped) {
            return 0;
        }
        if (DB::transactionLevel() !== 0) {
            throw new NotionException('NOTION_TRANSACTION_OPEN');
        }
        $connection = DB::connection()->getPdo();
        if (! DB::selectOne("SELECT pg_try_advisory_lock(hashtextextended('v2_notion_worker', 0)) AS acquired")->acquired) {
            return 0;
        }
        try {
            DB::transaction(function (): void {
                $this->service->lock();
                $expired = DB::table('notion_sync_runs')->where('status', 'processing')
                    ->where('lease_expires_at', '<=', V2DatabaseTimestamp::format(now()))->lockForUpdate()->first();
                if ($expired !== null) {
                    $this->finish($expired, 'NOTION_LEASE_EXPIRED');
                }
            });
            $this->service->enqueue('periodic');
            $run = DB::transaction(function (): ?object {
                $this->service->lock();
                $run = DB::table('notion_sync_runs')->where('status', 'queued')->lockForUpdate()->first();
                if ($run === null) {
                    return null;
                }
                $run->lease_hash = hash('sha256', random_bytes(32));
                DB::table('notion_sync_runs')->where('id', $run->id)->update([
                    'status' => 'processing', 'lease_hash' => $run->lease_hash, 'attempts' => $run->attempts + 1,
                    'lease_expires_at' => V2DatabaseTimestamp::format(now()->addSeconds(config('v2_notion.lease_seconds'))),
                    'started_at' => V2DatabaseTimestamp::format(now()), 'updated_at' => V2DatabaseTimestamp::format(now()),
                ]);

                return $run;
            });
            if ($run === null) {
                return 0;
            }
            $fence = function () use ($run, $connection): void {
                $this->config->assertReady();
                if ($this->stopped || DB::connection()->getPdo() !== $connection) {
                    throw new NotionException('NOTION_LEASE_LOST');
                }
                $updated = DB::table('notion_sync_runs')->where('id', $run->id)->where('status', 'processing')
                    ->where('lease_hash', $run->lease_hash)->where('lease_expires_at', '>', V2DatabaseTimestamp::format(now()))
                    ->update(['lease_expires_at' => V2DatabaseTimestamp::format(now()->addSeconds(config('v2_notion.lease_seconds'))),
                        'updated_at' => V2DatabaseTimestamp::format(now())]);
                if ($updated !== 1) {
                    throw new NotionException('NOTION_LEASE_LOST');
                }
            };
            $error = null;
            try {
                $this->engine->run($run, $fence);
            } catch (Throwable $exception) {
                $error = $exception instanceof NotionException ? $exception->errorCode : 'NOTION_SYNC_FAILED';
            }
            DB::transaction(function () use ($run, $fence, $error): void {
                $fence();
                $this->finish($run, $error);
            });

            return 1;
        } finally {
            if (DB::connection()->getPdo() === $connection) {
                DB::select("SELECT pg_advisory_unlock(hashtextextended('v2_notion_worker', 0))");
            }
        }
    }

    private function finish(object $run, ?string $error): void
    {
        DB::table('notion_sync_runs')->where('id', $run->id)->update([
            'status' => $error === null ? 'completed' : 'failed', 'error_code' => $error,
            'completed_at' => V2DatabaseTimestamp::format(now()), 'updated_at' => V2DatabaseTimestamp::format(now()),
            'lease_hash' => null, 'lease_expires_at' => null,
        ]);
        $result = $this->service->result($run->public_id);
        $this->audit->record($error === null ? 'notion.sync.completed' : 'notion.sync.failed', [
            'actor_type' => 'system', 'target_type' => 'notion_sync_run', 'target_public_id' => $run->public_id,
            'outcome' => $error === null ? 'success' : 'failure',
            'metadata' => array_intersect_key($result, array_flip(['trigger', 'read_count', 'updated_count', 'unchanged_count',
                'duplicate_count', 'invalid_count', 'missing_count', 'failed_count', 'error_code'])),
        ]);
    }
}
