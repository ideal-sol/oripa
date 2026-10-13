<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\ContentContact\Contracts\V2BannerImportExecution;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Domain\Reporting\Services\V2CsvWriter;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class V2BannerImportService
{
    public function __construct(
        private readonly V2AdminFreshMfaAuthorizer $authorizer,
        private readonly V2BannerImportCsv $csv,
        private readonly V2BannerImportPlan $plan,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
    ) {}

    public function create(V2AdminAuthorizationContext $context, #[SensitiveParameter] array $input, #[SensitiveParameter] string $key): array
    {
        $admin = $this->authorizer->authorizePermission($context, V2Permission::ManageContent);
        V2BannerImportExecution::assertEnabled();
        $parsed = $this->csv->parse($input);
        $request = ['file_sha256' => $parsed['sha256'], 'default_category_id' => $input['default_category_id'],
            'refresh_existing_images' => $input['refresh_existing_images'] ?? false];
        return DB::transaction(function () use ($context, $admin, $input, $parsed, $key, $request): array {
            V2BannerImportExecution::assertEnabled();
            $existing = DB::table('idempotency_records')->where('scope', 'content_banner_import')
                ->where('actor_type', 'admin')->where('actor_public_id', $admin->public_id)
                ->where('key_hash', hash('sha256', $key))->first();
            if ($existing !== null) {
                $claim = $this->idempotency->claim('content_banner_import', 'admin', $admin->public_id, $key, $request);
                if ($claim->replay) {
                    return $this->result($claim->record->resource_public_id);
                }
            }
            $rows = $this->plan->build($parsed, $input['default_category_id'], $request['refresh_existing_images']);
            $claim = $this->idempotency->claim('content_banner_import', 'admin', $admin->public_id, $key, $request);
            if ($claim->replay) {
                return $this->result($claim->record->resource_public_id);
            }
            $ttl = config('v2_banner_import.url_ttl_seconds');
            if (! is_int($ttl) || $ttl < 1 || $ttl > 1800) {
                throw new V2ContentContactException('BANNER_IMPORT_CONFIGURATION_INVALID', 503, '取込設定を確認してください。');
            }
            $publicId = (string) Str::uuid7();
            $now = V2DatabaseTimestamp::format(now());
            $jobId = DB::table('content_banner_import_jobs')->insertGetId([
                'public_id' => $publicId, 'requested_by_admin_id' => $admin->id,
                'idempotency_record_id' => $claim->record->id, 'request_id' => $context->requestId,
                'file_sha256' => $parsed['sha256'], 'refresh_existing_images' => $request['refresh_existing_images'],
                'status' => 'queued', 'total' => count($rows),
                'secret_expires_at' => V2DatabaseTimestamp::format(now()->addSeconds($ttl)),
                'expires_at' => V2DatabaseTimestamp::format(now()->addDays(30)),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($rows as $row) {
                $url = $row['url'];
                unset($row['url']);
                DB::table('content_banner_import_rows')->insert([
                    ...$row, 'public_id' => (string) Str::uuid7(), 'job_id' => $jobId,
                    'url_ciphertext' => $url === null ? null : Crypt::encryptString($url),
                    'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->idempotency->complete($claim->record, 'banner_import_job', $publicId, ['id' => $publicId]);
            $this->audit->record('content.banner_import.created', [
                'request_id' => $context->requestId, 'actor_type' => 'admin', 'actor_public_id' => $admin->public_id,
                'actor_role' => $admin->role->value, 'auth_realm' => 'admin', 'target_type' => 'banner_import_job',
                'target_public_id' => $publicId, 'metadata' => ['file_sha256' => $parsed['sha256'], 'row_count' => count($rows)],
            ]);
            return $this->result($publicId);
        });
    }

    public function listing(V2AdminAuthorizationContext $context): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadContent);
        if (! V2BannerImportExecution::enabled()) {
            return ['enabled' => false, 'items' => []];
        }
        $ids = DB::table('content_banner_import_jobs')->where('expires_at', '>', V2DatabaseTimestamp::format(now()))
            ->orderByDesc('id')->limit(50)->pluck('public_id');
        return ['enabled' => true, 'items' => $ids->map(fn (string $id): array => $this->result($id, false))->all()];
    }

    public function show(V2AdminAuthorizationContext $context, string $publicId): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadContent);
        V2BannerImportExecution::assertEnabled();
        return $this->result($publicId);
    }

    public function failureCsv(V2AdminAuthorizationContext $context, string $publicId): string
    {
        $job = $this->show($context, $publicId);
        $stream = fopen('php://temp', 'w+');
        try {
            $rows = [];
            foreach ($job['rows'] as $row) {
                if ($row['status'] === 'failed') {
                    $rows[] = ['管理ID' => $row['external_id'], 'タイトル' => $row['title'], '画像URL' => '',
                        'カテゴリ' => $row['category'], '失敗理由' => $row['error_code']];
                }
            }
            app(V2CsvWriter::class)->write($stream, ['管理ID', 'タイトル', '画像URL', 'カテゴリ', '失敗理由'], $rows);
            rewind($stream);
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function result(string $publicId, bool $includeRows = true): array
    {
        $job = DB::table('content_banner_import_jobs')->where('public_id', $publicId)
            ->where('expires_at', '>', V2DatabaseTimestamp::format(now()))->first();
        if ($job === null) {
            throw new V2ContentContactException('BANNER_IMPORT_NOT_FOUND', 404, '取込履歴が見つかりません。');
        }
        $rows = DB::table('content_banner_import_rows')->where('job_id', $job->id)->orderBy('row_number')
            ->get(['row_number', 'external_id', 'title', 'category_name', 'status', 'result', 'error_code']);
        return ['id' => $job->public_id, 'status' => $job->status, 'total' => (int) $job->total,
            'processed' => $rows->whereIn('status', ['completed', 'failed'])->count(),
            'created' => $rows->where('result', 'created')->count(), 'updated' => $rows->where('result', 'updated')->count(),
            'unchanged' => $rows->where('result', 'unchanged')->count(), 'failed' => $rows->where('status', 'failed')->count(),
            'created_at' => CarbonImmutable::parse($job->created_at)->toIso8601String(),
            'completed_at' => $job->completed_at === null ? null : CarbonImmutable::parse($job->completed_at)->toIso8601String(),
            'rows' => $includeRows ? $rows->map(static fn (object $row): array => [
                'row' => (int) $row->row_number, 'external_id' => $row->external_id, 'title' => $row->title,
                'category' => $row->category_name, 'status' => $row->status, 'result' => $row->result, 'error_code' => $row->error_code,
            ])->all() : []];
    }

    public function expireSecrets(): void
    {
        $now = V2DatabaseTimestamp::format(now());
        DB::table('content_banner_import_rows')->whereNotNull('url_ciphertext')
            ->whereIn('job_id', DB::table('content_banner_import_jobs')->select('id')
                ->where('secret_expires_at', '<=', $now))
            ->update(['url_ciphertext' => null, 'url_deleted_at' => $now, 'updated_at' => $now]);
        $this->expireHistory();
    }

    private function expireHistory(): void
    {
        $now = V2DatabaseTimestamp::format(now());
        DB::transaction(function () use ($now): void {
            $jobs = DB::table('content_banner_import_jobs')->where('expires_at', '<=', $now)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now);
                })->lockForUpdate()->get(['id', 'public_id']);
            DB::table('content_banner_asset_operations')->whereIn('job_public_id', $jobs->pluck('public_id'))
                ->where('status', '<>', 'attached')->update([
                    'status' => 'manual_review', 'manual_review_reason' => DB::raw("COALESCE(manual_review_reason, 'history_expired')"),
                    'updated_at' => $now,
                ]);
            DB::table('content_banner_import_jobs')->whereIn('id', $jobs->pluck('id'))->delete();
        });
    }

    public function finish(int $jobId, bool $failed = false): void
    {
        $job = DB::table('content_banner_import_jobs')->where('id', $jobId)->lockForUpdate()->firstOrFail();
        if (in_array($job->status, ['completed', 'failed'], true)) {
            return;
        }
        if (DB::table('content_banner_import_rows')->where('job_id', $jobId)->whereNotIn('status', ['completed', 'failed'])->exists()) {
            return;
        }
        $now = V2DatabaseTimestamp::format(now());
        DB::table('content_banner_import_jobs')->where('id', $jobId)->update([
            'status' => $failed ? 'failed' : 'completed', 'completed_at' => $now,
            'expires_at' => V2DatabaseTimestamp::format(now()->addDays(30)),
            'lease_token_hash' => null, 'lease_expires_at' => null, 'updated_at' => $now,
        ]);
        DB::table('content_banner_import_rows')->where('job_id', $jobId)->update(['url_ciphertext' => null, 'url_deleted_at' => $now]);
        $summary = $this->result($job->public_id, false);
        $this->audit->record('content.banner_import.completed', [
            'request_id' => $job->request_id, 'actor_type' => 'system', 'target_type' => 'banner_import_job',
            'target_public_id' => $job->public_id, 'outcome' => $failed ? 'failure' : 'success',
            'metadata' => ['file_sha256' => $job->file_sha256, 'created_count' => $summary['created'],
                'asset_review_count' => DB::table('content_banner_import_rows')->where('job_id', $jobId)
                    ->whereNotNull('compensation_status')->count(),
                'updated_count' => $summary['updated'], 'unchanged_count' => $summary['unchanged'], 'failed_count' => $summary['failed']],
        ]);
    }
}
