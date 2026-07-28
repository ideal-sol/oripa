<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Reporting\ValueObjects\V2ExportDefinition;
use App\Models\V2\ExportJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class V2ExportWorker
{
    public function __construct(
        private readonly V2ExportRowSource $rows,
        private readonly V2CsvWriter $csv,
        private readonly V2AuditLogService $audit
    ) {
    }

    public function run(string $worker, ?int $limit = null): int
    {
        $this->assertWorker($worker);
        $this->expireCompletedJobs();
        $jobs = $this->claim(
            $worker,
            $limit ?? (int) config('v2_reporting.worker_claim_size')
        );
        foreach ($jobs as $job) {
            $this->process($job, $worker);
        }

        return $jobs->count();
    }

    /** @return Collection<int, ExportJob> */
    public function claim(string $worker, int $limit): Collection
    {
        $this->assertWorker($worker);
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('Export Worker claim size is invalid.');
        }

        return DB::transaction(function () use ($worker, $limit): Collection {
            $now = now()->startOfSecond();
            $jobs = ExportJob::query()
                ->where(function ($query) use ($now): void {
                    $query
                        ->where('status', 'queued')
                        ->orWhere(function ($expired) use ($now): void {
                            $expired
                                ->where('status', 'processing')
                                ->where('lease_expires_at', '<=', $now);
                        });
                })
                ->where('expires_at', '>', $now)
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();
            $claimed = [];
            foreach ($jobs as $job) {
                $job->forceFill([
                    'status' => 'processing',
                    'attempts' => $job->attempts + 1,
                    'locked_at' => $now,
                    'locked_by' => $worker,
                    'lease_expires_at' => $now->copy()->addSeconds(
                        (int) config('v2_reporting.worker_lease_seconds')
                    ),
                    'started_at' => $job->started_at ?? $now,
                    'failed_at' => null,
                    'failure_code' => null,
                ])->save();
                $claimed[] = $job->refresh();
            }

            return new Collection($claimed);
        }, 3);
    }

    public function process(ExportJob $job, string $worker): void
    {
        $this->assertWorker($worker);
        $temporary = tmpfile();
        if ($temporary === false) {
            $this->fail($job->public_id, $worker, 'temporary_file_unavailable');

            return;
        }
        try {
            $definition = V2ExportDefinition::from([
                'report_type' => $job->report_type,
                'period_type' => $job->period_type,
                'month' => $job->period_month,
                'date' => $job->period_date?->format('Y-m-d'),
                'qa_filter' => $job->qa_filter,
            ]);
            $rowCount = $this->csv->write(
                $temporary,
                $this->rows->headers($definition),
                $this->rows->rows(
                    $definition,
                    CarbonImmutable::parse($job->data_cutoff_at)
                )
            );
            $statistics = fstat($temporary);
            if (! is_array($statistics) || ! isset($statistics['size'])) {
                throw new RuntimeException('Export file statistics are unavailable.');
            }
            rewind($temporary);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $temporary);
            $sha256 = hash_final($hash);
            rewind($temporary);
            $key = sprintf(
                '%s/%s/%s.csv',
                trim((string) config('v2_reporting.private_prefix'), '/'),
                $job->public_id,
                $sha256
            );
            $disk = Storage::disk((string) config('v2_reporting.export_disk'));
            if (! $disk->put($key, $temporary, ['visibility' => 'private'])) {
                throw new RuntimeException('Export storage write failed.');
            }
            $this->complete(
                $job->public_id,
                $worker,
                $key,
                $rowCount,
                (int) $statistics['size'],
                $sha256
            );
        } catch (\Throwable $exception) {
            $this->fail(
                $job->public_id,
                $worker,
                $this->failureCode($exception)
            );
        } finally {
            fclose($temporary);
        }
    }

    private function complete(
        string $publicId,
        string $worker,
        string $key,
        int $rowCount,
        int $byteSize,
        string $sha256
    ): void {
        DB::transaction(function () use (
            $publicId,
            $worker,
            $key,
            $rowCount,
            $byteSize,
            $sha256
        ): void {
            $job = $this->leasedJob($publicId, $worker);
            $job->forceFill([
                'status' => 'completed',
                'row_count' => $rowCount,
                'byte_size' => $byteSize,
                'sha256' => $sha256,
                'private_object_key' => $key,
                'locked_at' => null,
                'locked_by' => null,
                'lease_expires_at' => null,
                'completed_at' => now()->startOfSecond(),
                'failed_at' => null,
                'failure_code' => null,
            ])->save();
            $this->audit->record('report.export.succeeded', [
                'request_id' => $job->request_id,
                'actor_type' => 'system',
                'target_type' => 'export_job',
                'target_public_id' => $job->public_id,
                'outcome' => 'success',
                'metadata' => [
                    'report_type' => $job->report_type,
                    'period' => $job->period_month ?? $job->period_date?->format('Y-m-d'),
                    'qa_filter' => $job->qa_filter,
                    'row_count' => $rowCount,
                    'file_checksum' => $sha256,
                ],
            ]);
        }, 3);
    }

    private function fail(string $publicId, string $worker, string $failureCode): void
    {
        DB::transaction(function () use ($publicId, $worker, $failureCode): void {
            $job = $this->leasedJob($publicId, $worker);
            $maximum = (int) config('v2_reporting.worker_max_attempts');
            $terminal = $job->attempts >= $maximum;
            $job->forceFill([
                'status' => $terminal ? 'failed' : 'queued',
                'locked_at' => null,
                'locked_by' => null,
                'lease_expires_at' => null,
                'failed_at' => $terminal ? now()->startOfSecond() : null,
                'failure_code' => $failureCode,
            ])->save();
            $this->audit->record('report.export.failed', [
                'request_id' => $job->request_id,
                'actor_type' => 'system',
                'target_type' => 'export_job',
                'target_public_id' => $job->public_id,
                'outcome' => 'failure',
                'reason_code' => $failureCode,
                'metadata' => [
                    'report_type' => $job->report_type,
                    'attempt' => $job->attempts,
                    'retry_scheduled' => ! $terminal,
                ],
            ]);
        }, 3);
    }

    private function expireCompletedJobs(): void
    {
        ExportJob::query()
            ->where('status', 'completed')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function (Collection $jobs): void {
                foreach ($jobs as $job) {
                    DB::transaction(function () use ($job): void {
                        $locked = ExportJob::query()
                            ->whereKey($job->id)
                            ->where('status', 'completed')
                            ->lockForUpdate()
                            ->first();
                        if (! $locked instanceof ExportJob || $locked->expires_at?->isFuture()) {
                            return;
                        }
                        if ($locked->private_object_key !== null) {
                            Storage::disk((string) config('v2_reporting.export_disk'))
                                ->delete($locked->private_object_key);
                        }
                        $locked->forceFill([
                            'status' => 'expired',
                            'private_object_key' => null,
                        ])->save();
                        $this->audit->record('report.export.expired', [
                            'request_id' => $locked->request_id,
                            'actor_type' => 'system',
                            'target_type' => 'export_job',
                            'target_public_id' => $locked->public_id,
                            'outcome' => 'success',
                        ]);
                    }, 3);
                }
            });
    }

    private function leasedJob(string $publicId, string $worker): ExportJob
    {
        $job = ExportJob::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->firstOrFail();
        if (
            $job->status !== 'processing'
            || $job->locked_by === null
            || ! hash_equals($job->locked_by, $worker)
            || $job->lease_expires_at === null
            || $job->lease_expires_at->isPast()
        ) {
            throw new RuntimeException('Export Worker lease is invalid.');
        }

        return $job;
    }

    private function failureCode(\Throwable $exception): string
    {
        return match (true) {
            str_contains(strtolower($exception->getMessage()), 'storage') =>
                'object_storage_unavailable',
            str_contains(strtolower($exception->getMessage()), 'temporary') =>
                'temporary_file_unavailable',
            default => 'export_generation_failed',
        };
    }

    private function assertWorker(string $worker): void
    {
        if (
            $worker === ''
            || strlen($worker) > 128
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/', $worker) !== 1
        ) {
            throw new RuntimeException('Export Worker identity is invalid.');
        }
    }
}
