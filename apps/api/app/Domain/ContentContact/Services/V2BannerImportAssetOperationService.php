<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\ContentContact\Contracts\V2BannerImportExecution;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Support\V2DatabaseTimestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

class V2BannerImportAssetOperationService
{
    public function __construct(private readonly V2ContentContactAdminService $canonical, private readonly V2AuditLogService $audit) {}

    public function recordReuse(V2BannerImportExecution $execution, object $asset): void
    {
        $execution = $execution->revalidate();
        $disk = config('filesystems.default');
        $now = V2DatabaseTimestamp::format(now());
        $id = DB::table('content_banner_asset_operations')->insertGetId([
            'public_id' => (string) Str::uuid7(), 'job_public_id' => $execution->job->public_id,
            'row_public_id' => $execution->row->public_id, 'asset_public_id' => $asset->public_id,
            'actor_public_id' => $execution->admin->public_id, 'request_id' => $execution->job->request_id,
            'operation_type' => 'reuse', 'storage_disk' => $disk, 'storage_generation' => $this->destination($disk),
            'object_key' => $asset->storage_identifier, 'checksum_sha256' => $asset->checksum_sha256,
            'mime_type' => $asset->mime_type, 'byte_size' => $asset->byte_size, 'status' => 'registered',
            'lease_generation' => $execution->job->lease_generation, 'last_result' => 'existing_asset_reused',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->audit($execution, DB::table('content_banner_asset_operations')->where('id', $id)->first(), 'existing_asset_reused');
    }

    public function recover(string $rowId, #[SensitiveParameter] string $token): bool
    {
        $this->outsideTransaction();
        $operation = DB::transaction(function () use ($rowId, $token): ?object {
            $execution = V2BannerImportExecution::fromLease($rowId, $token)->heartbeat();
            return $this->operation($execution);
        });
        if ($operation === null || ($operation->status === 'prepared' && $operation->put_attempts === 0)) {
            return false;
        }
        if ($operation->operation_type !== 'create') {
            throw $this->unavailable();
        }
        $this->reconcile($rowId, $token, $operation);
        return true;
    }

    public function register(string $rowId, #[SensitiveParameter] string $token, #[SensitiveParameter] array $image): void
    {
        $this->outsideTransaction();
        $operation = DB::transaction(function () use ($rowId, $token, $image): object {
            $execution = V2BannerImportExecution::fromLease($rowId, $token)->heartbeat();
            $prepared = $this->canonical->prepareBannerImportAsset($execution, $image);
            $operation = $this->operation($execution);
            if ($operation !== null) {
                if (! hash_equals($operation->checksum_sha256, $prepared['checksum_sha256'])
                    || $operation->mime_type !== $prepared['mime_type'] || $operation->byte_size !== $prepared['byte_size']) {
                    throw $this->unavailable();
                }
                return $operation;
            }
            $disk = config('filesystems.default');
            $generation = $this->destination($disk);
            $now = V2DatabaseTimestamp::format(now());
            $id = DB::table('content_banner_asset_operations')->insertGetId([
                ...$prepared, 'public_id' => (string) Str::uuid7(), 'job_public_id' => $execution->job->public_id,
                'row_public_id' => $execution->row->public_id, 'actor_public_id' => $execution->admin->public_id,
                'request_id' => $execution->job->request_id, 'storage_disk' => $disk, 'storage_generation' => $generation,
                'lease_generation' => $execution->job->lease_generation, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $operation = DB::table('content_banner_asset_operations')->where('id', $id)->first();
            $this->audit($execution, $operation, 'intent_committed');
            return $operation;
        });
        if ($operation->put_attempts > 0) {
            $this->reconcile($rowId, $token, $operation);
            return;
        }
        $state = $this->inspect($operation);
        if ($state !== 'missing') {
            $this->finalizeInspection($rowId, $token, $operation, $state);
            return;
        }
        $operation = DB::transaction(function () use ($rowId, $token, $operation): object {
            $execution = V2BannerImportExecution::fromLease($rowId, $token);
            $current = $this->operation($execution);
            if ($current === null || $current->public_id !== $operation->public_id || $current->status !== 'prepared'
                || $current->put_attempts !== 0 || $current->storage_generation !== $this->destination($current->storage_disk)) {
                throw $this->unavailable();
            }
            DB::table('content_banner_asset_operations')->where('id', $current->id)->update([
                'status' => 'writing', 'put_attempts' => 1, 'lease_generation' => $execution->job->lease_generation,
                'last_result' => 'put_started', 'updated_at' => V2DatabaseTimestamp::format(now()),
            ]);
            $this->audit($execution, $current, 'put_started');
            return DB::table('content_banner_asset_operations')->where('id', $current->id)->first();
        });
        $this->outsideTransaction();
        DB::transaction(fn () => V2BannerImportExecution::fromLease($rowId, $token)->heartbeat());
        try {
            if (! Storage::disk($operation->storage_disk)->put($operation->object_key, $image['bytes'], ['ContentType' => $operation->mime_type])) {
                throw $this->unavailable();
            }
        } catch (Throwable) {
            $this->finalizeInspection($rowId, $token, $operation, 'put_unknown');
            return;
        }
        $this->reconcile($rowId, $token, $operation);
    }

    private function reconcile(string $rowId, #[SensitiveParameter] string $token, object $operation): void
    {
        $this->outsideTransaction();
        $this->finalizeInspection($rowId, $token, $operation, $this->inspect($operation));
    }

    private function inspect(object $operation): string
    {
        $this->outsideTransaction();
        $stream = null;
        try {
            if (! hash_equals($operation->storage_generation, $this->destination($operation->storage_disk))) {
                return 'destination_changed';
            }
            $disk = Storage::disk($operation->storage_disk);
            if (! $disk->fileExists($operation->object_key)) {
                return 'missing';
            }
            if ($disk->size($operation->object_key) !== $operation->byte_size || $disk->mimeType($operation->object_key) !== $operation->mime_type) {
                return 'mismatch';
            }
            $stream = $disk->readStream($operation->object_key);
            if (! is_resource($stream)) {
                return 'read_unknown';
            }
            $deadline = microtime(true) + 20;
            $bytes = '';
            while (! feof($stream)) {
                if (microtime(true) >= $deadline) {
                    return 'read_unknown';
                }
                $chunk = @fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    return 'read_unknown';
                }
                $bytes .= $chunk;
                if (strlen($bytes) > $operation->byte_size) {
                    return 'mismatch';
                }
            }
            if (strlen($bytes) !== $operation->byte_size) {
                return 'mismatch';
            }
            return hash_equals($operation->checksum_sha256, hash('sha256', $bytes))
                && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $operation->mime_type ? 'matched' : 'mismatch';
        } catch (Throwable) {
            return 'read_unknown';
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function finalizeInspection(string $rowId, #[SensitiveParameter] string $token, object $operation, string $state): void
    {
        $this->outsideTransaction();
        DB::transaction(function () use ($rowId, $token, $operation, $state): void {
            $execution = V2BannerImportExecution::fromLease($rowId, $token);
            $current = $this->operation($execution);
            if ($current === null || $current->public_id !== $operation->public_id
                || ($state === 'matched' && $current->storage_generation !== $this->destination($current->storage_disk))) {
                throw $this->unavailable();
            }
            if ($current->status === 'attached') {
                throw $this->unavailable();
            }
            $now = V2DatabaseTimestamp::format(now());
            DB::table('content_banner_asset_operations')->where('id', $current->id)->update([
                'status' => $state === 'matched' ? 'writing' : 'manual_review',
                'lease_generation' => $execution->job->lease_generation,
                'reconcile_attempts' => $current->reconcile_attempts + 1,
                'last_result' => $state, 'manual_review_reason' => $state === 'matched' ? null : $state,
                'last_reconciled_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit($execution, $current, $state);
            if ($state !== 'matched') {
                DB::table('content_banner_import_rows')->where('id', $execution->row->id)->update(['compensation_status' => 'deferred_canonical_review']);
                return;
            }
            $result = $this->canonical->finalizeBannerImportAsset($execution, $current->public_id);
            if ($result['id'] !== $current->asset_public_id) {
                throw $this->unavailable();
            }
            DB::table('content_banner_import_rows')->where('id', $execution->row->id)->update([
                'asset_public_id' => $current->asset_public_id, 'asset_created' => true, 'image_sha256' => $current->checksum_sha256,
                'status' => 'asset_registered', 'url_ciphertext' => null, 'url_deleted_at' => $now,
                'compensation_status' => null, 'updated_at' => $now,
            ]);
            DB::table('content_banner_asset_operations')->where('id', $current->id)->update(['status' => 'registered']);
        });
        if ($state !== 'matched') {
            throw $this->unavailable();
        }
    }

    private function operation(V2BannerImportExecution $execution): ?object
    {
        return DB::table('content_banner_asset_operations')->where('row_public_id', $execution->row->public_id)->lockForUpdate()->first();
    }

    private function destination(mixed $disk): string
    {
        $generation = config('v2_banner_import.storage_generation');
        $settings = is_string($disk) ? config('filesystems.disks.'.$disk) : null;
        if (! is_string($disk) || ! is_array($settings) || ! is_string($generation) || $generation === ''
            || ! in_array($settings['driver'] ?? null, ['local', 's3'], true)) {
            throw $this->unavailable();
        }
        if ($settings['driver'] === 's3') {
            foreach (['connect_timeout' => 5, 'timeout' => 20, 'read_timeout' => 20] as $name => $maximum) {
                $timeout = $settings['http'][$name] ?? null;
                if ((! is_int($timeout) && ! is_float($timeout)) || $timeout <= 0 || $timeout > $maximum) {
                    throw $this->unavailable();
                }
            }
            if (($settings['retries'] ?? null) !== 0) {
                throw $this->unavailable();
            }
        }
        return hash('sha256', json_encode([$generation, $disk, array_intersect_key($settings,
            array_flip(['driver', 'root', 'bucket', 'region', 'endpoint', 'use_path_style_endpoint']))], JSON_THROW_ON_ERROR));
    }

    private function audit(V2BannerImportExecution $execution, object $operation, string $result): void
    {
        $this->audit->record('content.banner_import.asset_operation', [...$execution->auditAttributes(),
            'target_type' => 'banner_asset_operation', 'target_public_id' => $operation->public_id,
            'metadata' => ['result' => $result, 'job_id' => $execution->job->public_id,
                'row_id' => $execution->row->public_id, 'lease_generation' => $execution->job->lease_generation],
        ]);
    }

    private function outsideTransaction(): void
    {
        V2BannerImportExecution::assertEnabled();
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Banner asset storage requires independently committed intent.');
        }
    }

    private function unavailable(): V2ContentContactException
    {
        return new V2ContentContactException('BANNER_IMPORT_ASSET_UNAVAILABLE', 409, '画像の登録状態を確認してください。');
    }
}
