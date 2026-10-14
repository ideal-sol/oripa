<?php

namespace App\Domain\Integrations\Notion;

use App\Domain\Audit\V2\Services\V2AuditLogService;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Domain\Identity\Services\V2AdminFreshMfaAuthorizer;
use App\Domain\Point\Services\V2PointIdempotencyService;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class NotionSyncService
{
    public function __construct(
        private readonly NotionConfig $config,
        private readonly V2AdminFreshMfaAuthorizer $authorizer,
        private readonly V2PointIdempotencyService $idempotency,
        private readonly V2AuditLogService $audit,
    ) {}

    public function create(V2AdminAuthorizationContext $context, #[SensitiveParameter] string $key): array
    {
        $admin = $this->authorizer->authorizePermission($context, V2Permission::ManageCatalog);
        $this->config->assertReady();
        if ($key === '' || strlen($key) > 255) {
            throw new NotionException('IDEMPOTENCY_KEY_REQUIRED', 422);
        }

        return DB::transaction(function () use ($context, $admin, $key): array {
            $this->lock();
            $claim = $this->idempotency->claim('notion_sync', 'admin', $admin->public_id, $key, []);
            if ($claim->replay) {
                return $this->result($claim->record->resource_public_id);
            }
            $id = $this->enqueue('manual');
            $this->idempotency->complete($claim->record, 'notion_sync_run', $id, ['id' => $id]);
            $this->audit->record('notion.sync.requested', [
                'request_id' => $context->requestId, 'actor_type' => 'admin', 'actor_public_id' => $admin->public_id,
                'actor_role' => $admin->role->value, 'auth_realm' => 'admin',
                'target_type' => 'notion_sync_run', 'target_public_id' => $id, 'metadata' => ['trigger' => 'manual'],
            ]);

            return $this->result($id);
        });
    }

    public function afterBusinessCommit(): void
    {
        if (! $this->config->enabled() || ! $this->config->configured()) {
            return;
        }
        $reserve = function (): void {
            try {
                $this->enqueue('business');
            } catch (Throwable) {
            }
        };
        try {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($reserve);
            } else {
                $reserve();
            }
        } catch (Throwable) {
        }
    }

    public function enqueue(string $trigger): ?string
    {
        if (! $this->config->enabled() || ! $this->config->configured()) {
            return null;
        }
        if (! in_array($trigger, ['manual', 'business', 'periodic'], true)) {
            throw new NotionException('NOTION_REQUEST_INVALID', 422);
        }

        return DB::transaction(function () use ($trigger): ?string {
            $this->config->assertReady();
            $this->lock();
            $active = DB::table('notion_sync_runs')->whereIn('status', ['queued', 'processing'])->value('public_id');
            if ($active !== null) {
                return $active;
            }
            $latest = DB::table('notion_sync_runs')->orderByDesc('id')->first();
            if ($trigger !== 'manual' && $latest?->status === 'failed'
                && CarbonImmutable::parse($latest->completed_at)->addSeconds(config('v2_notion.failure_backoff_seconds'))->isFuture()) {
                return null;
            }
            if ($trigger === 'periodic') {
                $lastSuccess = DB::table('notion_sync_runs')->where('status', 'completed')->max('completed_at');
                if ($lastSuccess !== null && CarbonImmutable::parse($lastSuccess)->addMinutes(config('v2_notion.sync_interval_minutes'))->isFuture()) {
                    return null;
                }
            }
            $id = (string) Str::uuid7();
            $now = V2DatabaseTimestamp::format(now());
            DB::table('notion_sync_runs')->insert([
                'public_id' => $id, 'status' => 'queued', 'trigger' => $trigger, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return $id;
        });
    }

    public function listing(V2AdminAuthorizationContext $context): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadCatalog);

        return ['items' => DB::table('notion_sync_runs')->orderByDesc('id')->limit(50)->pluck('public_id')
            ->map(fn (string $id): array => $this->result($id))->all()];
    }

    public function show(V2AdminAuthorizationContext $context, string $id): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadCatalog);

        return $this->result($id);
    }

    public function status(V2AdminAuthorizationContext $context): array
    {
        $this->authorizer->authorizePermission($context, V2Permission::ReadCatalog);
        $latest = DB::table('notion_sync_runs')->orderByDesc('id')->value('public_id');
        $active = DB::table('notion_sync_runs')->whereIn('status', ['queued', 'processing'])->value('public_id');
        $result = $latest === null ? null : $this->result($latest);

        return ['enabled' => $this->config->enabled(), 'configured' => $this->config->configured(),
            'latest' => $result, 'active' => $active === null ? null : $this->result($active),
            'last_success_at' => $this->date(DB::table('notion_sync_runs')->where('status', 'completed')->max('completed_at')),
            'last_failure_at' => $this->date(DB::table('notion_sync_runs')->where('status', 'failed')->max('completed_at')),
            'error_code' => $this->config->enabled() && ! $this->config->configured() ? 'NOTION_CONFIGURATION_INVALID' : ($result['error_code'] ?? null)];
    }

    public function result(string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new NotionException('NOTION_RUN_NOT_FOUND', 404);
        }
        $run = DB::table('notion_sync_runs')->where('public_id', $id)->first();
        if ($run === null) {
            throw new NotionException('NOTION_RUN_NOT_FOUND', 404);
        }
        $result = ['id' => $id, 'status' => $run->status, 'trigger' => $run->trigger, 'error_code' => $run->error_code];
        foreach (['read', 'updated', 'unchanged', 'duplicate', 'invalid', 'missing', 'failed'] as $counter) {
            $result[$counter.'_count'] = (int) $run->{$counter.'_count'};
        }
        foreach (['created_at', 'started_at', 'completed_at'] as $field) {
            $result[$field] = $this->date($run->$field);
        }
        $result['duplicate_ids'] = DB::table('notion_sync_pages')->where('run_id', $run->id)
            ->where('status', 'duplicate')->whereNotNull('external_id')->distinct()->orderBy('external_id')->limit(200)->pluck('external_id')->all();
        $result['missing_ids'] = $run->missing_count === 0 ? [] : DB::table('notion_sync_snapshot as snapshot')->where('snapshot.run_id', $run->id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('notion_sync_pages as page')
                ->whereColumn('page.run_id', 'snapshot.run_id')->whereColumn('page.external_id', 'snapshot.external_id'))
            ->orderBy('snapshot.external_id')->limit(200)->pluck('snapshot.external_id')->all();

        return $result;
    }

    public function lock(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('v2_notion_enqueue', 0))");
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }
}
