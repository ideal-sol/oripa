<?php

namespace App\Domain\ContentContact\Contracts;

use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Domain\Identity\Enums\V2AdminState;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2PermissionAuthorizer;
use App\Models\V2\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class V2BannerImportExecution
{
    private function __construct(
        public object $job,
        public object $row,
        public Admin $admin,
        #[SensitiveParameter] private string $leaseToken,
    ) {}

    public static function enabled(): bool
    {
        return config('v2_banner_import.enabled') === true;
    }

    public static function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new V2ContentContactException('BANNER_IMPORT_DISABLED', 503, 'バナーCSV取込は無効です。');
        }
    }

    public static function fromLease(string $rowId, #[SensitiveParameter] string $leaseToken): self
    {
        self::assertEnabled();
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Banner import execution requires a transaction.');
        }
        $jobId = DB::table('content_banner_import_rows')->where('public_id', $rowId)->value('job_id');
        $job = DB::table('content_banner_import_jobs')->where('id', $jobId)->lockForUpdate()->first();
        $row = DB::table('content_banner_import_rows')->where('public_id', $rowId)->lockForUpdate()->first();
        if ($job === null || $row === null || $job->status !== 'processing'
            || ! is_string($job->lease_token_hash) || ! hash_equals($job->lease_token_hash, hash('sha256', $leaseToken))
            || ! CarbonImmutable::parse($job->lease_expires_at)->isFuture()
            || in_array($row->status, ['completed', 'failed'], true)) {
            throw new V2ContentContactException('BANNER_IMPORT_LEASE_LOST', 409, '取込の実行権限が失効しました。');
        }
        $approved = DB::table('idempotency_records')
            ->where('id', $job->idempotency_record_id)->where('scope', 'content_banner_import')
            ->where('status', 'completed')->where('resource_public_id', $job->public_id)->first();
        $admin = Admin::query()->whereKey($job->requested_by_admin_id)->lockForUpdate()->first();
        if ($approved === null || $admin === null || $approved->actor_type !== 'admin'
            || $approved->actor_public_id !== $admin->public_id
            || $admin->state !== V2AdminState::Active
            || ! app(V2PermissionAuthorizer::class)->allows($admin->role, V2Permission::ManageContent)) {
            throw new V2ContentContactException('BANNER_IMPORT_AUTHORIZATION_REVOKED', 403, '起票者の取込権限が失効しました。');
        }
        return new self($job, $row, $admin, $leaseToken);
    }

    public function revalidate(): self
    {
        return self::fromLease($this->row->public_id, $this->leaseToken);
    }

    public function heartbeat(): self
    {
        $execution = $this->revalidate();
        DB::table('content_banner_import_jobs')->where('id', $execution->job->id)->update([
            'lease_expires_at' => \App\Support\V2DatabaseTimestamp::format(now()->addSeconds(120)),
            'updated_at' => \App\Support\V2DatabaseTimestamp::format(now()),
        ]);
        return $execution;
    }

    public function auditAttributes(): array
    {
        return [
            'request_id' => $this->job->request_id,
            'actor_type' => 'admin',
            'actor_public_id' => $this->admin->public_id,
            'actor_role' => $this->admin->role->value,
            'auth_realm' => 'admin',
        ];
    }
}
