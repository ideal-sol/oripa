<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Catalog\Exceptions\V2CatalogException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class V2ScheduledGachaPublishWorker
{
    public function __construct(
        private readonly V2CatalogMasterMutationService $catalog,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function run(string $worker, ?int $limit = null): int
    {
        $workerHash = $this->workerHash($worker);
        $claimSize = $limit ?? (int) config(
            'v2_catalog.scheduled_publish.worker_claim_size'
        );
        if ($claimSize < 1 || $claimSize > 50) {
            throw new RuntimeException(
                'Scheduled Publish Worker claim size is invalid.'
            );
        }

        $schedules = $this->claim($workerHash, $claimSize);
        foreach ($schedules as $schedule) {
            try {
                $this->catalog->activateClaimedGachaPublishSchedule(
                    (string) $schedule->public_id,
                    $workerHash
                );
            } catch (Throwable $exception) {
                $this->releaseFailure(
                    (string) $schedule->public_id,
                    $workerHash,
                    $exception
                );
            }
        }

        return $schedules->count();
    }

    /** @return Collection<int, object> */
    private function claim(string $workerHash, int $limit): Collection
    {
        return DB::transaction(function () use ($workerHash, $limit): Collection {
            $now = $this->databaseNow();
            $maximumAttempts = $this->maximumAttempts();
            $expired = DB::table('catalog_gacha_publish_schedules')
                ->where('status', 'processing')
                ->where('lease_expires_at', '<=', $now)
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();
            foreach ($expired as $schedule) {
                $terminal = (int) $schedule->attempts >= $maximumAttempts;
                DB::table('catalog_gacha_publish_schedules')
                    ->where('id', $schedule->id)
                    ->update([
                        'status' => $terminal ? 'failed' : 'scheduled',
                        'next_attempt_at' => $now,
                        'locked_at' => null,
                        'locked_by_hash' => null,
                        'lease_expires_at' => null,
                        'failed_at' => $terminal ? $now : null,
                        'failure_code' => $terminal ? 'worker_lease_expired' : null,
                        'revision' => (int) $schedule->revision + 1,
                        'updated_at' => $now,
                    ]);
                $this->recordWorkerAudit(
                    $terminal
                        ? 'catalog.gacha.schedule.publish_failed'
                        : 'catalog.gacha.schedule.publish_retry',
                    $schedule,
                    $terminal ? 'failure' : 'pending',
                    'worker_lease_expired',
                    ! $terminal
                );
            }

            $rows = DB::table('catalog_gacha_publish_schedules')
                ->where('status', 'scheduled')
                ->where('next_attempt_at', '<=', $now)
                ->where('attempts', '<', $maximumAttempts)
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();
            $leaseSeconds = (int) config(
                'v2_catalog.scheduled_publish.worker_lease_seconds'
            );
            if ($leaseSeconds < 1 || $leaseSeconds > 900) {
                throw new RuntimeException(
                    'Scheduled Publish Worker lease is invalid.'
                );
            }
            $claimed = [];
            foreach ($rows as $schedule) {
                DB::table('catalog_gacha_publish_schedules')
                    ->where('id', $schedule->id)
                    ->update([
                        'status' => 'processing',
                        'attempts' => (int) $schedule->attempts + 1,
                        'locked_at' => $now,
                        'locked_by_hash' => $workerHash,
                        'lease_expires_at' => $now->addSeconds($leaseSeconds),
                        'started_at' => $schedule->started_at ?? $now,
                        'failed_at' => null,
                        'failure_code' => null,
                        'revision' => (int) $schedule->revision + 1,
                        'updated_at' => $now,
                    ]);
                $claimedSchedule = DB::table('catalog_gacha_publish_schedules')
                    ->where('id', $schedule->id)->firstOrFail();
                $this->recordWorkerAudit(
                    'catalog.gacha.schedule.worker_claimed',
                    $claimedSchedule,
                    'success',
                    null,
                    false
                );
                $claimed[] = $claimedSchedule;
            }

            return new Collection($claimed);
        }, 3);
    }

    private function releaseFailure(
        string $schedulePublicId,
        string $workerHash,
        Throwable $exception
    ): void {
        DB::transaction(function () use (
            $schedulePublicId,
            $workerHash,
            $exception
        ): void {
            $schedule = DB::table('catalog_gacha_publish_schedules')
                ->where('public_id', $schedulePublicId)
                ->lockForUpdate()
                ->first();
            if (
                $schedule === null
                || $schedule->status !== 'processing'
                || ! hash_equals((string) $schedule->locked_by_hash, $workerHash)
            ) {
                return;
            }
            $permanent = $exception instanceof V2CatalogException;
            $terminal = $permanent
                || (int) $schedule->attempts >= $this->maximumAttempts();
            $failureCode = $this->failureCode($exception);
            $nextAttemptAt = $this->databaseNow()->addSeconds(
                $this->retryDelaySeconds((int) $schedule->attempts)
            );
            DB::table('catalog_gacha_publish_schedules')
                ->where('id', $schedule->id)
                ->update([
                    'status' => $terminal ? 'failed' : 'scheduled',
                    'next_attempt_at' => $terminal
                        ? $schedule->next_attempt_at
                        : $nextAttemptAt,
                    'locked_at' => null,
                    'locked_by_hash' => null,
                    'lease_expires_at' => null,
                    'failed_at' => $terminal ? DB::raw('CURRENT_TIMESTAMP') : null,
                    'failure_code' => $terminal ? $failureCode : null,
                    'revision' => (int) $schedule->revision + 1,
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
            $this->recordWorkerAudit(
                $terminal
                    ? 'catalog.gacha.schedule.publish_failed'
                    : 'catalog.gacha.schedule.publish_retry',
                $schedule,
                'failure',
                $failureCode,
                ! $terminal
            );
        }, 3);
    }

    private function maximumAttempts(): int
    {
        $attempts = (int) config('v2_catalog.scheduled_publish.worker_max_attempts');
        if ($attempts !== 3) {
            throw new RuntimeException(
                'Scheduled Publish Worker retry configuration is invalid.'
            );
        }

        return $attempts;
    }

    private function retryDelaySeconds(int $attempt): int
    {
        $base = (int) config('v2_catalog.scheduled_publish.retry_base_seconds');
        if ($base < 1 || $base > 3600) {
            throw new RuntimeException(
                'Scheduled Publish Worker retry delay is invalid.'
            );
        }

        return $base * (2 ** max(0, $attempt - 1));
    }

    private function workerHash(string $worker): string
    {
        if (
            $worker === ''
            || strlen($worker) > 128
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/', $worker) !== 1
        ) {
            throw new RuntimeException(
                'Scheduled Publish Worker identity is invalid.'
            );
        }
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'Scheduled Publish Worker correlation key is unavailable.'
            );
        }

        return hash_hmac('sha256', 'scheduled-publish|'.$worker, $key);
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof V2CatalogException) {
            return strtolower($exception->errorCode);
        }

        return 'scheduled_publish_failed';
    }

    private function databaseNow(): CarbonImmutable
    {
        $value = DB::selectOne('SELECT clock_timestamp() AS occurred_at')?->occurred_at;
        if (! is_string($value)) {
            throw new RuntimeException('DB Server timestamp is unavailable.');
        }

        return CarbonImmutable::parse($value);
    }

    private function recordWorkerAudit(
        string $action,
        object $schedule,
        string $outcome,
        ?string $reasonCode,
        bool $retryScheduled
    ): void {
        $this->audit->record($action, [
            'request_id' => $schedule->request_id,
            'actor_type' => 'system',
            'target_type' => 'gacha_publish_schedule',
            'target_public_id' => $schedule->public_id,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'metadata' => [
                'attempt' => (int) $schedule->attempts,
                'retry_scheduled' => $retryScheduled,
            ],
        ]);
    }
}
