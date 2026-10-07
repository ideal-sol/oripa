<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Identity\Contracts\V2AdminAuthorizationContext;
use App\Domain\Identity\Enums\V2Permission;
use App\Support\V2DatabaseTimestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait V2PrizeImportMutations
{
    public function previewPrizeImport(V2AdminAuthorizationContext $context, string $gachaId, string $versionId, array $input): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ManageCatalog);
        $csv = app(V2PrizeImportCsv::class)->parse($input, false);

        return DB::transaction(function () use ($gachaId, $versionId, $input, $csv): array {
            [$gacha, $version] = $this->prizeImportContext($gachaId, $versionId, $input['expected_version_revision'], false);
            $builder = app(V2PrizeImportPlan::class);
            $plan = $builder->build($gacha, $version, $csv, false);
            $builder->assertValid($plan);

            return $plan['public'];
        });
    }

    public function applyPrizeImport(V2AdminAuthorizationContext $context, string $gachaId, string $versionId, string $key, array $input): array
    {
        $admin = $this->authorization->authorizePermission($context, V2Permission::ManageCatalog);
        $csv = app(V2PrizeImportCsv::class)->parse($input, true);

        return $this->execute($context, $admin, 'gacha_version', 'prize_import', $key, [
            'gacha_id' => $gachaId, 'version_id' => $versionId, 'file_name' => $csv['file_name'],
            'file_sha256' => $csv['sha256'], 'expected_version_revision' => $input['expected_version_revision'],
            'plan_checksum' => $input['plan_checksum'],
        ], 200, function () use ($context, $admin, $gachaId, $versionId, $input, $csv): object {
            [$gacha, $version] = $this->prizeImportContext($gachaId, $versionId, $input['expected_version_revision'], true);
            $builder = app(V2PrizeImportPlan::class);
            $plan = $builder->build($gacha, $version, $csv, true);
            if (! hash_equals($plan['public']['plan_checksum'], $input['plan_checksum'])) {
                throw new V2CatalogException('CATALOG_PRIZE_IMPORT_PLAN_STALE', 409, '取込対象が変更されました。もう一度プレビューしてください。');
            }
            $builder->assertValid($plan);
            $importId = (string) Str::uuid7();
            app(V2PrizeExternalIdService::class)->assertDistinct(array_column($plan['operations'], 'externalId'));
            $creates = [];
            foreach ($plan['operations'] as $operation) {
                if ($operation['action'] === 'create') {
                    $after = $operation['after'];
                    $creates[] = ['gachaRank' => $operation['gachaRank'], 'asset' => $operation['asset'],
                        'payload' => [...$after, 'external_id' => $operation['externalId'],
                            'total_inventory' => $after['quantity'], 'is_active' => true]];
                }
            }
            $externalIdChanges = [];
            foreach (array_chunk($creates, 100) as $chunk) {
                foreach ($this->insertRankPrizes($gacha, $version, $chunk) as $prize) {
                    $externalIdChanges[] = ['prize' => $prize, 'before' => null, 'after' => $prize->external_id];
                }
            }
            app(V2PrizeExternalIdService::class)->recordChanges($externalIdChanges, $context);
            $inventoryAudits = [];
            foreach ($plan['operations'] as $operation) {
                if ($operation['action'] !== 'update') {
                    continue;
                }
                $after = $operation['after'];
                $payload = [
                    'external_id' => $operation['externalId'], 'name' => $after['name'] ?? $operation['relation']->display_name,
                    'total_inventory' => $after['quantity'], 'exchange_points' => $after['exchange_points'],
                    'cost_price' => $after['cost_price'], 'shipping_only' => $after['shipping_only'],
                    'is_active' => $operation['prize'] === null ? true : (bool) $operation['relation']->is_visible,
                    'sort_order' => $after['sort_order'],
                ];
                $prize = $operation['prize'];
                $relation = $operation['relation'];
                $inventory = $operation['inventory'];
                $quantityChanged = $after['quantity'] !== (int) ($inventory?->total_quantity ?? $relation->initial_inventory);
                if ($quantityChanged) {
                    $this->adjustOperationalInventory($gacha, $version, $prize, $relation, $inventory, [
                        ...$payload, 'adjust_inventory' => true,
                        'available_inventory' => $after['quantity'] - (int) ($inventory?->awarded_count ?? 0) - (int) ($inventory?->withdrawn_quantity ?? 0),
                        'expected_inventory_revision' => (int) ($inventory?->lock_version ?? 0),
                        'inventory_reason' => 'CSV取込（'.$importId.'）',
                    ], $context, $admin, 'prize-import:'.$importId.':'.$prize->public_id, $inventoryAudits);
                }
                $now = V2DatabaseTimestamp::format(now()->startOfSecond());
                $economics = ['exchange_points' => $after['exchange_points'], 'cost_price' => $after['cost_price'],
                    'shipping_only' => $after['shipping_only'], 'updated_at' => $now];
                DB::table('catalog_prizes')->where('id', $prize->id)->update([
                    ...$economics, 'revision' => (int) $prize->revision + 1,
                ]);
                DB::table('catalog_gacha_version_prizes')->where('id', $relation->id)->update([
                    ...$economics, 'initial_inventory' => $after['quantity'], 'sort_order' => $after['sort_order'],
                ]);
            }
            $this->audit->recordBatch($inventoryAudits);
            $this->assertGachaInventoryCapacity((int) $version->id, (int) $version->total_count);
            $this->incrementGachaVersionRevision($version);
            $summary = $plan['public']['summary'];
            $this->recordAudit('catalog.gacha.prizes.imported', $context, $admin, 'gacha_version', 'prize_import', 'success', 'prize_import_completed', $version->public_id, [
                'import_id' => $importId, 'file_name' => $csv['file_name'], 'file_sha256' => $csv['sha256'],
                'create_count' => $summary['create'], 'update_count' => $summary['update'], 'unchanged_count' => $summary['unchanged'],
            ]);

            return (object) ['id' => $importId, 'gacha_version_id' => $version->public_id,
                'gacha_version_revision' => (int) $version->revision + 1, 'summary' => $summary];
        }, true, fn (object $result): array => (array) $result, false);
    }

    public function prizeImportHistory(V2AdminAuthorizationContext $context, string $gachaId, string $versionId, mixed $before): array
    {
        $this->authorization->authorizePermission($context, V2Permission::ManageCatalog);
        $gacha = $this->find('catalog_gachas', $gachaId, false);
        $version = $this->find('catalog_gacha_versions', $versionId, false);
        if ((int) $version->gacha_id !== (int) $gacha->id) {
            throw $this->notFound();
        }
        if ($before !== null && (! is_string($before) || ! preg_match('/\A[1-9][0-9]{0,18}\z/', $before))) {
            throw $this->validationException();
        }
        $rows = DB::table('audit_logs')->where('action_code', 'catalog.gacha.prizes.imported')
            ->where('target_public_id', $version->public_id)->where('outcome', 'success')
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(51)->get();
        $items = $rows->take(50)->map(function (object $row): array {
            $metadata = json_decode($row->metadata_redacted, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => $metadata['import_id'], 'actor_public_id' => $row->actor_public_id,
                'occurred_at' => \Carbon\CarbonImmutable::parse($row->occurred_at)->toIso8601String(), 'file_name' => $metadata['file_name'],
                'summary' => ['create' => $metadata['create_count'], 'update' => $metadata['update_count'], 'unchanged' => $metadata['unchanged_count']]];
        })->all();

        return ['items' => $items, 'next_before' => $rows->count() > 50 ? (string) $rows[49]->id : null];
    }

    private function prizeImportContext(string $gachaId, string $versionId, int $expected, bool $lock): array
    {
        $gacha = $this->find('catalog_gachas', $gachaId, $lock);
        $version = $this->find('catalog_gacha_versions', $versionId, $lock);
        if ($gacha->gacha_type !== 'standard') {
            throw new V2CatalogException('CATALOG_MUTATION_INVALID', 409, '通常ガチャのみCSVを取り込めます。');
        }
        $this->assertGachaAvailable($gacha);
        $this->assertBeforeFirstPublication($gacha);
        $this->assertGachaVersionMutable($version, (int) $gacha->id, $expected);

        return [$gacha, $version];
    }

    private function insertRankPrize(object $gacha, object $version, object $gachaRank, ?object $asset, array $payload): object
    {
        return $this->insertRankPrizes($gacha, $version, [compact('gachaRank', 'asset', 'payload')])[0];
    }

    private function insertRankPrizes(object $gacha, object $version, array $entries): array
    {
        $now = V2DatabaseTimestamp::format(now()->startOfSecond());
        $masters = [];
        $snapshots = [];
        foreach ($entries as $entry) {
            $payload = $entry['payload'];
            $publicId = (string) Str::uuid7();
            $shared = ['rank_id' => null, 'gacha_rank_id' => $entry['gachaRank']->id, 'presentation_asset_id' => $entry['asset']?->id,
                'display_name' => $payload['name'], 'description' => null, 'display_price' => 0,
                'exchange_points' => $payload['exchange_points'], 'shipping_only' => $payload['shipping_only'] ?? false,
                'cost_price' => $payload['cost_price'], 'is_visible' => $payload['is_active'], 'created_at' => $now, 'updated_at' => $now];
            $masters[] = [...$shared, 'public_id' => $publicId, 'code' => 'prize-'.str_replace('-', '', $publicId),
                'external_id' => $payload['external_id'] ?? null, 'gacha_id' => $gacha->id, 'revision' => 1, 'archived_at' => null];
            $snapshots[$publicId] = [...$shared, 'gacha_version_id' => $version->id,
                'rank_code' => null, 'rank_display_name' => null, 'rank_sort_order' => null,
                'initial_inventory' => $payload['total_inventory'], 'sort_order' => $payload['sort_order']];
        }
        $prizes = $this->insertPrizeRowsReturning('catalog_prizes', $masters);
        $relations = [];
        foreach ($prizes as $prize) {
            $relations[] = [...$snapshots[$prize->public_id], 'prize_id' => $prize->id];
        }
        $inventories = [];
        foreach ($this->insertPrizeRowsReturning('catalog_gacha_version_prizes', $relations) as $relation) {
            $inventories[] = ['gacha_draw_state_id' => null, 'gacha_version_prize_id' => $relation->id,
                'total_quantity' => $relation->initial_inventory, 'awarded_count' => 0,
                'available_quantity' => $relation->initial_inventory, 'withdrawn_quantity' => 0,
                'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('prize_inventories')->insert($inventories);

        return $prizes;
    }

    private function insertPrizeRowsReturning(string $table, array $rows): array
    {
        $query = DB::table($table);
        $sql = $query->getGrammar()->compileInsert($query, $rows).' returning *';

        return DB::select($sql, array_merge(...array_map('array_values', $rows)));
    }
}
