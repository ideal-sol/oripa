<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\ContentContact\Contracts\V2BannerImportCompensation;
use App\Domain\ContentContact\Contracts\V2BannerImportExecution;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

final class V2BannerImportWorker
{
    public function __construct(
        private readonly V2BannerImportService $service,
        private readonly V2BannerImportImageFetcher $fetcher,
        private readonly V2BannerImportCanonicalAdapter $canonical,
        private readonly V2BannerImportCompensation $compensation,
    ) {}

    public function claim(): ?array
    {
        V2BannerImportExecution::assertEnabled();
        return DB::transaction(function (): ?array {
            V2BannerImportExecution::assertEnabled();
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('v2_banner_import_claim', 0))");
            $now = V2DatabaseTimestamp::format(now());
            if (DB::table('content_banner_import_jobs')->where('status', 'processing')->where('lease_expires_at', '>', $now)->exists()) {
                return null;
            }
            $job = DB::table('content_banner_import_jobs as job')->join('idempotency_records as approval', 'approval.id', '=', 'job.idempotency_record_id')
                ->whereIn('job.status', ['queued', 'processing'])->where('job.expires_at', '>', $now)
                ->where('approval.scope', 'content_banner_import')->where('approval.status', 'completed')
                ->whereColumn('approval.resource_public_id', 'job.public_id')
                ->where(function ($query) use ($now): void {
                    $query->whereNotExists(function ($rows): void {
                        $rows->selectRaw('1')->from('content_banner_import_rows as row')->whereColumn('row.job_id', 'job.id')
                            ->whereNotIn('row.status', ['completed', 'failed']);
                    })->orWhereExists(function ($rows) use ($now): void {
                        $rows->selectRaw('1')->from('content_banner_import_rows as row')->whereColumn('row.job_id', 'job.id')
                            ->whereNotIn('row.status', ['completed', 'failed'])->where(function ($ready) use ($now): void {
                                $ready->whereNull('row.next_attempt_at')->orWhere('row.next_attempt_at', '<=', $now);
                            });
                    });
                })->orderBy('job.id')->lock('FOR UPDATE OF job SKIP LOCKED')->first(['job.*']);
            if ($job === null) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            DB::table('content_banner_import_jobs')->where('id', $job->id)->update([
                'status' => 'processing', 'lease_token_hash' => hash('sha256', $token),
                'lease_generation' => $job->lease_generation + 1,
                'lease_expires_at' => V2DatabaseTimestamp::format(now()->addSeconds(120)), 'updated_at' => $now,
            ]);
            return ['id' => $job->public_id, 'token' => $token];
        });
    }

    public function runOnce(): int
    {
        if (! V2BannerImportExecution::enabled()) {
            return 0;
        }
        $this->service->expireSecrets();
        $claim = $this->claim();
        return $claim === null ? 0 : $this->runClaim($claim['id'], $claim['token']);
    }

    public function runClaim(string $publicId, #[SensitiveParameter] string $token): int
    {
        V2BannerImportExecution::assertEnabled();
        $count = 0;
        while (true) {
            $rowId = DB::transaction(function () use ($publicId, $token): ?string {
                $job = $this->ownedJob($publicId, $token);
                $now = V2DatabaseTimestamp::format(now());
                $row = DB::table('content_banner_import_rows')->where('job_id', $job->id)
                    ->whereNotIn('status', ['completed', 'failed'])
                    ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
                    ->orderBy('row_number')->lockForUpdate()->first();
                if ($row === null) {
                    $this->service->finish($job->id);
                    DB::table('content_banner_import_jobs')->where('id', $job->id)->where('status', 'processing')->update([
                        'status' => 'queued', 'lease_token_hash' => null, 'lease_expires_at' => null, 'updated_at' => $now,
                    ]);
                    return null;
                }
                DB::table('content_banner_import_jobs')->where('id', $job->id)->update([
                    'lease_expires_at' => V2DatabaseTimestamp::format(now()->addSeconds(120)), 'updated_at' => $now,
                ]);
                return $row->public_id;
            });
            if ($rowId === null) {
                return $count;
            }
            try {
                DB::transaction(function () use ($rowId, $token): void {
                    $execution = V2BannerImportExecution::fromLease($rowId, $token);
                    if ($execution->row->attempts >= 3) {
                        throw new V2ContentContactException('BANNER_IMPORT_RETRY_EXHAUSTED', 409, '再試行上限です。');
                    }
                    DB::table('content_banner_import_rows')->where('id', $execution->row->id)->update([
                        'attempts' => $execution->row->attempts + 1, 'status' => $execution->row->asset_public_id === null ? 'processing' : 'asset_registered',
                        'next_attempt_at' => null, 'updated_at' => V2DatabaseTimestamp::format(now()),
                    ]);
                });
                $secret = DB::transaction(function () use ($rowId, $token): ?string {
                    $execution = V2BannerImportExecution::fromLease($rowId, $token);
                    if ($this->canonical->recover($execution)) {
                        return null;
                    }
                    if ($execution->row->url_ciphertext === null || ! CarbonImmutable::parse($execution->job->secret_expires_at)->isFuture()) {
                        throw new V2ContentContactException('BANNER_IMPORT_URL_EXPIRED', 409, '画像URLを再入力して再取込してください。');
                    }
                    return $execution->row->url_ciphertext;
                });
                if ($secret !== null) {
                    $image = $this->fetcher->fetch(Crypt::decryptString($secret));
                    unset($secret);
                    DB::transaction(function () use ($rowId, $token): void {
                        $execution = V2BannerImportExecution::fromLease($rowId, $token);
                        DB::table('content_banner_import_rows')->where('id', $execution->row->id)->update([
                            'compensation_status' => $execution->row->compensation_status === null
                                ? 'registration_started' : 'deferred_canonical_review',
                        ]);
                    });
                    DB::transaction(function () use ($rowId, $token, $image): void {
                        $this->canonical->register(V2BannerImportExecution::fromLease($rowId, $token), $image);
                    });
                    unset($image);
                }
                DB::transaction(function () use ($rowId, $token): void {
                    $execution = V2BannerImportExecution::fromLease($rowId, $token);
                    $result = $this->canonical->save($execution);
                    DB::table('content_banner_import_rows')->where('id', $execution->row->id)->update([
                        'status' => 'completed', 'result' => $result['result'], 'banner_public_id' => $result['id'],
                        'error_code' => null, 'url_ciphertext' => null, 'url_deleted_at' => V2DatabaseTimestamp::format(now()),
                        'compensation_status' => $this->compensation->disposition($execution),
                        'updated_at' => V2DatabaseTimestamp::format(now()),
                    ]);
                });
                $count++;
            } catch (Throwable $exception) {
                $code = $exception instanceof V2ContentContactException ? $exception->errorCode : 'BANNER_IMPORT_ROW_FAILED';
                if (in_array($code, ['BANNER_IMPORT_DISABLED', 'BANNER_IMPORT_LEASE_LOST'], true)) {
                    return $count;
                }
                if ($code === 'BANNER_IMPORT_AUTHORIZATION_REVOKED') {
                    DB::transaction(function () use ($publicId, $token, $code): void {
                        $job = $this->ownedJob($publicId, $token);
                        DB::table('content_banner_import_rows')->where('job_id', $job->id)->whereNotIn('status', ['completed', 'failed'])->update([
                            'status' => 'failed', 'result' => 'failed', 'error_code' => $code, 'url_ciphertext' => null,
                            'url_deleted_at' => V2DatabaseTimestamp::format(now()), 'updated_at' => V2DatabaseTimestamp::format(now()),
                            'compensation_status' => DB::raw("CASE WHEN asset_created OR compensation_status IS NOT NULL THEN 'deferred_canonical_review' ELSE NULL END"),
                        ]);
                        $this->service->finish($job->id, true);
                    });
                    return $count;
                }
                DB::transaction(function () use ($rowId, $token, $code): void {
                    $execution = V2BannerImportExecution::fromLease($rowId, $token);
                    $terminal = $execution->row->attempts >= 3 || in_array($code, ['BANNER_IMPORT_URL_EXPIRED', 'BANNER_IMPORT_RETRY_EXHAUSTED'], true);
                    DB::table('content_banner_import_rows')->where('id', $execution->row->id)->update([
                        'status' => $terminal ? 'failed' : ($execution->row->asset_public_id === null ? 'pending' : 'asset_registered'),
                        'result' => $terminal ? 'failed' : null,
                        'error_code' => $this->safeCode($code),
                        'url_ciphertext' => $terminal ? null : $execution->row->url_ciphertext,
                        'url_deleted_at' => $terminal ? V2DatabaseTimestamp::format(now()) : $execution->row->url_deleted_at,
                        'next_attempt_at' => $terminal ? null : V2DatabaseTimestamp::format(now()->addSeconds(30)),
                        'compensation_status' => $this->compensation->disposition($execution),
                        'updated_at' => V2DatabaseTimestamp::format(now()),
                    ]);
                });
            }
        }
    }

    private function ownedJob(string $id, #[SensitiveParameter] string $token): object
    {
        V2BannerImportExecution::assertEnabled();
        $job = DB::table('content_banner_import_jobs')->where('public_id', $id)->lockForUpdate()->first();
        if ($job === null || $job->status !== 'processing' || ! is_string($job->lease_token_hash)
            || ! hash_equals($job->lease_token_hash, hash('sha256', $token)) || ! CarbonImmutable::parse($job->lease_expires_at)->isFuture()) {
            throw new V2ContentContactException('BANNER_IMPORT_LEASE_LOST', 409, '取込の実行権限が失効しました。');
        }
        return $job;
    }

    private function safeCode(string $code): string
    {
        return in_array($code, ['BANNER_IMPORT_URL_EXPIRED', 'BANNER_IMPORT_RETRY_EXHAUSTED', 'BANNER_IMPORT_ASSET_UNAVAILABLE',
            'IMAGE_URL_INVALID', 'IMAGE_HOST_NOT_ALLOWED', 'IMAGE_ADDRESS_NOT_ALLOWED', 'IMAGE_FETCH_FAILED',
            'IMAGE_TOO_LARGE', 'IMAGE_TYPE_NOT_ALLOWED'], true) ? $code : 'BANNER_IMPORT_ROW_FAILED';
    }
}
