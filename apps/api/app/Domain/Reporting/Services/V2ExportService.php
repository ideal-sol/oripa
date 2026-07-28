<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Outbox\Services\V2OutboxService;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Reporting\Exceptions\V2ReportingException;
use App\Domain\Reporting\ValueObjects\V2ExportDefinition;
use App\Models\V2\Admin;
use App\Models\V2\ExportJob;
use Carbon\CarbonImmutable;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class V2ExportService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorization,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2OutboxService $outbox,
        private readonly V2AuditLogService $audit,
        private readonly V2ExportRowSource $rows,
        private readonly V2CsvWriter $csv
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function stream(
        V2AdminAuthorizationContext $context,
        array $filters
    ): StreamedResponse {
        $admin = $this->authorization->authorizeReporting($context, true);
        $definition = V2ExportDefinition::from($filters);
        $cutoff = CarbonImmutable::now()->startOfSecond();
        $rowCount = $this->rows->count($definition, $cutoff);
        $maximum = (int) config('v2_reporting.streaming_max_rows');
        $asyncThreshold = (int) config('v2_reporting.async_row_threshold');
        if ($maximum < 1 || $asyncThreshold !== $maximum + 1) {
            throw new \RuntimeException('Reporting Export threshold configuration is invalid.');
        }
        if ($rowCount >= $asyncThreshold) {
            throw new V2ReportingException(
                'EXPORT_ASYNC_REQUIRED',
                422,
                'This Export must be generated as an asynchronous job.'
            );
        }
        $this->auditExport(
            'report.export.started',
            $admin,
            $context,
            $definition,
            ['row_count' => $rowCount, 'mode' => 'stream']
        );
        $filename = sprintf(
            'oripa-v2-%s-%s.csv',
            str_replace('_', '-', $definition->reportType),
            $definition->period->value
        );

        return response()->streamDownload(function () use (
            $definition,
            $cutoff,
            $admin,
            $context,
            $rowCount
        ): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('CSV stream could not be opened.');
            }
            $this->csv->write(
                $stream,
                $this->rows->headers($definition),
                $this->rows->rows($definition, $cutoff)
            );
            fclose($stream);
            $this->auditExport(
                'report.export.succeeded',
                $admin,
                $context,
                $definition,
                ['row_count' => $rowCount, 'mode' => 'stream']
            );
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Request-Id' => $context->requestId,
            'X-Oripa-Api-Version' => '2',
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function createJob(
        V2AdminAuthorizationContext $context,
        string $idempotencyKey,
        array $filters
    ): array {
        $admin = $this->authorization->authorizeReporting($context, true);
        $definition = V2ExportDefinition::from($filters);
        $canonical = $definition->canonical();

        return DB::transaction(function () use (
            $context,
            $admin,
            $idempotencyKey,
            $definition,
            $canonical
        ): array {
            $claim = $this->idempotency->claim(
                'reporting_export',
                'admin',
                $admin->public_id,
                $idempotencyKey,
                $canonical
            );
            if ($claim->replay) {
                $job = ExportJob::query()
                    ->where('public_id', $claim->record->resource_public_id)
                    ->firstOrFail();

                return [...$this->jobResource($job), 'idempotent_replay' => true];
            }
            $cutoff = CarbonImmutable::now()->startOfSecond();
            $job = new ExportJob();
            $job->forceFill([
                'report_type' => $definition->reportType,
                'status' => 'queued',
                'period_type' => $definition->period->type,
                'period_month' => $definition->period->type === 'month'
                    ? $definition->period->value
                    : null,
                'period_date' => $definition->period->type === 'date'
                    ? $definition->period->value
                    : null,
                'qa_filter' => $definition->qaFilter,
                'canonical_filter_hash' => hash(
                    'sha256',
                    json_encode($canonical, JSON_THROW_ON_ERROR)
                ),
                'data_cutoff_at' => $cutoff,
                'query_version' => (string) config('v2_reporting.query_version'),
                'requested_by_admin_id' => $admin->id,
                'request_id' => $context->requestId,
                'idempotency_record_id' => $claim->record->id,
                'attempts' => 0,
                'expires_at' => $cutoff->addHours(
                    (int) config('v2_reporting.job_expiry_hours')
                ),
            ])->save();
            $this->outbox->enqueue(
                'reporting.export',
                'export_job',
                $job->public_id,
                'reporting.export.requested',
                [
                    'export_job_public_id' => $job->public_id,
                    'report_type' => $job->report_type,
                    'query_version' => $job->query_version,
                ],
                'reporting-export-'.$job->public_id
            );
            $this->idempotency->complete(
                $claim->record,
                'export_job',
                $job->public_id,
                ['export_job_id' => $job->public_id]
            );
            $this->auditExport(
                'report.export.requested',
                $admin,
                $context,
                $definition,
                ['export_public_id' => $job->public_id]
            );

            return [...$this->jobResource($job), 'idempotent_replay' => false];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function jobs(
        V2AdminAuthorizationContext $context,
        ?string $cursor,
        int $limit
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $maximum = (int) config('v2_reporting.pagination.maximum');
        if ($limit < 1 || $limit > $maximum) {
            throw new V2ReportingException(
                'REPORTING_LIMIT_INVALID',
                422,
                'The Reporting page limit is invalid.'
            );
        }
        $query = ExportJob::query()->orderBy('id');
        $after = app(V2ReportingCursor::class)->decode($cursor);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        if ($admin->role->value !== 'owner') {
            $query->where('requested_by_admin_id', $admin->id);
        }
        $jobs = $query->limit($limit + 1)->get();
        $hasMore = $jobs->count() > $limit;
        $jobs = $jobs->take($limit);

        return [
            'items' => $jobs->map($this->jobResource(...))->values()->all(),
            'next_cursor' => $hasMore && $jobs->isNotEmpty()
                ? app(V2ReportingCursor::class)->encode((int) $jobs->last()->id)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function job(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $admin = $this->authorization->authorizeReporting($context);
        $job = $this->visibleJob($admin, $publicId);

        return $this->jobResource($job);
    }

    /** @return array<string, mixed> */
    public function download(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): array {
        $admin = $this->authorization->authorizeReporting($context, true);
        $job = $this->visibleJob($admin, $publicId);
        if (
            $job->status !== 'completed'
            || $job->completed_at === null
            || $job->expires_at === null
            || $job->expires_at->isPast()
            || $job->private_object_key === null
        ) {
            throw new V2ReportingException(
                'EXPORT_NOT_DOWNLOADABLE',
                409,
                'The Export is not available for download.'
            );
        }
        $expiresAt = now()->addMinutes((int) config('v2_reporting.signed_url_minutes'));
        $url = URL::temporarySignedRoute(
            'v2.admin.reporting.export-jobs.file',
            $expiresAt,
            ['exportJobId' => $job->public_id]
        );
        $this->auditExportJob('report.export.download_requested', $admin, $context, $job);

        return [
            'export_job_id' => $job->public_id,
            'download_url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
            'sha256' => $job->sha256,
            'byte_size' => $job->byte_size,
        ];
    }

    public function file(
        V2AdminAuthorizationContext $context,
        string $publicId
    ): StreamedResponse {
        $admin = $this->authorization->authorizeReporting($context);
        $job = $this->visibleJob($admin, $publicId);
        if (
            $job->status !== 'completed'
            || $job->expires_at?->isPast() !== false
            || $job->private_object_key === null
        ) {
            throw new V2ReportingException(
                'EXPORT_NOT_DOWNLOADABLE',
                409,
                'The Export is not available for download.'
            );
        }
        $disk = Storage::disk((string) config('v2_reporting.export_disk'));
        if (! $disk->exists($job->private_object_key)) {
            throw new V2ReportingException(
                'EXPORT_FILE_UNAVAILABLE',
                503,
                'The Export file is unavailable.',
                true
            );
        }
        $this->auditExportJob('report.export.downloaded', $admin, $context, $job);
        $filename = sprintf(
            'oripa-v2-%s-%s.csv',
            str_replace('_', '-', $job->report_type),
            $job->period_month ?? $job->period_date?->format('Y-m-d')
        );

        return response()->streamDownload(function () use ($disk, $job): void {
            $source = $disk->readStream($job->private_object_key);
            if (! is_resource($source)) {
                throw new \RuntimeException('Export file could not be read.');
            }
            fpassthru($source);
            fclose($source);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function visibleJob(Admin $admin, string $publicId): ExportJob
    {
        $query = ExportJob::query()->where('public_id', $publicId);
        if ($admin->role->value !== 'owner') {
            $query->where('requested_by_admin_id', $admin->id);
        }
        $job = $query->first();
        if (! $job instanceof ExportJob) {
            throw new V2ReportingException(
                'REPORTING_RESOURCE_NOT_FOUND',
                404,
                'The Reporting resource was not found.'
            );
        }

        return $job;
    }

    /** @return array<string, mixed> */
    private function jobResource(ExportJob $job): array
    {
        return [
            'export_job_id' => $job->public_id,
            'report_type' => $job->report_type,
            'status' => $job->status,
            'period_type' => $job->period_type,
            'month' => $job->period_month,
            'date' => $job->period_date?->format('Y-m-d'),
            'qa_filter' => $job->qa_filter,
            'data_cutoff_at' => $job->data_cutoff_at?->toIso8601String(),
            'query_version' => $job->query_version,
            'row_count' => $job->row_count,
            'byte_size' => $job->byte_size,
            'sha256' => $job->sha256,
            'failure_code' => $job->failure_code,
            'completed_at' => $job->completed_at?->toIso8601String(),
            'expires_at' => $job->expires_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function auditExport(
        string $action,
        Admin $admin,
        V2AdminAuthorizationContext $context,
        V2ExportDefinition $definition,
        array $metadata
    ): void {
        $this->audit->record($action, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'outcome' => 'success',
            'metadata' => [
                'report_type' => $definition->reportType,
                'period' => $definition->period->value,
                'qa_filter' => $definition->qaFilter,
                ...$metadata,
            ],
        ]);
    }

    private function auditExportJob(
        string $action,
        Admin $admin,
        V2AdminAuthorizationContext $context,
        ExportJob $job
    ): void {
        $this->audit->record($action, [
            'request_id' => $context->requestId,
            'actor_type' => 'admin',
            'actor_public_id' => $admin->public_id,
            'actor_role' => $admin->role->value,
            'auth_realm' => 'admin',
            'session_correlation_hash' => $context->sessionCorrelationHash,
            'target_type' => 'export_job',
            'target_public_id' => $job->public_id,
            'outcome' => 'success',
            'metadata' => [
                'report_type' => $job->report_type,
                'period' => $job->period_month ?? $job->period_date?->format('Y-m-d'),
                'qa_filter' => $job->qa_filter,
                'row_count' => $job->row_count,
                'file_checksum' => $job->sha256,
            ],
        ]);
    }
}
