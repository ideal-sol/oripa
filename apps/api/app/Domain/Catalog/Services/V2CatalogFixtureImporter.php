<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class V2CatalogFixtureImporter
{
    /**
     * @param array<string, mixed> $manifest
     * @return array{public_id: string, checksum: string, imported_count: int, replay: bool}
     */
    public function import(array $manifest): array
    {
        $this->validateManifest($manifest);
        $checksum = hash('sha256', $this->canonicalJson($manifest));
        $existing = DB::table('catalog_import_runs')
            ->where('manifest_checksum', $checksum)
            ->first();
        if ($existing !== null) {
            if ($existing->status !== 'completed') {
                throw new V2CatalogException(
                    'CATALOG_IMPORT_PREVIOUSLY_FAILED',
                    409,
                    'The same Catalog fixture previously failed.'
                );
            }

            $this->initializeDrawState($manifest);

            return [
                'public_id' => $existing->public_id,
                'checksum' => $checksum,
                'imported_count' => (int) $existing->imported_count,
                'replay' => true,
            ];
        }

        $runId = (string) Str::uuid7();
        DB::table('catalog_import_runs')->insert([
            'public_id' => $runId,
            'source_baseline_sha' => $manifest['source_baseline_sha'],
            'import_type' => 'catalog_fixture',
            'manifest_checksum' => $checksum,
            'status' => 'running',
            'tool_version' => (string) config(
                'v2_catalog.fixture_import_tool_version',
                '2.0.0-alpha.1'
            ),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $imported = DB::transaction(fn (): int => $this->importRecords($manifest), 3);
            if ($imported !== (int) $manifest['expected_record_count']) {
                throw new V2CatalogException(
                    'CATALOG_IMPORT_COUNT_MISMATCH',
                    422,
                    'The Catalog fixture record count is invalid.'
                );
            }
            DB::table('catalog_import_runs')->where('public_id', $runId)->update([
                'status' => 'completed',
                'imported_count' => $imported,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->initializeDrawState($manifest);
        } catch (Throwable $exception) {
            DB::table('catalog_import_runs')->where('public_id', $runId)->update([
                'status' => 'failed',
                'failed_count' => 1,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            throw $exception;
        }

        return [
            'public_id' => $runId,
            'checksum' => $checksum,
            'imported_count' => $imported,
            'replay' => false,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function importRecords(array $manifest): int
    {
        $now = now();
        $count = 0;
        foreach ($manifest['categories'] as $category) {
            DB::table('catalog_categories')->insert([
                'public_id' => $category['public_id'],
                'code' => $category['code'],
                'slug' => $category['slug'],
                'display_name' => $category['name'],
                'description' => $category['description'] ?? null,
                'sort_order' => $category['sort_order'],
                'is_visible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }
        foreach ($manifest['tags'] as $tag) {
            DB::table('catalog_tags')->insert([
                'public_id' => $tag['public_id'],
                'code' => $tag['code'],
                'slug' => $tag['slug'],
                'display_name' => $tag['name'],
                'sort_order' => $tag['sort_order'],
                'is_visible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }
        foreach ($manifest['ranks'] as $rank) {
            DB::table('catalog_ranks')->insert([
                'public_id' => $rank['public_id'],
                'code' => $rank['code'],
                'display_name' => $rank['name'],
                'sort_order' => $rank['sort_order'],
                'is_visible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }
        foreach ($manifest['assets'] as $asset) {
            if (hash('sha256', base64_decode($asset['fixture_content_base64'], true))
                !== $asset['checksum_sha256']) {
                throw new V2CatalogException(
                    'CATALOG_ASSET_CHECKSUM_MISMATCH',
                    422,
                    'A Catalog asset checksum does not match.'
                );
            }
            DB::table('catalog_presentation_assets')->insert([
                'public_id' => $asset['public_id'],
                'storage_identifier' => $asset['storage_identifier'],
                'public_path' => $asset['public_path'],
                'checksum_sha256' => $asset['checksum_sha256'],
                'media_type' => $asset['media_type'],
                'mime_type' => $asset['mime_type'],
                'byte_size' => strlen(base64_decode($asset['fixture_content_base64'], true)),
                'alt_text' => $asset['alt_text'] ?? null,
                'is_public' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }
        foreach ($manifest['rank_assets'] as $relation) {
            DB::table('catalog_rank_assets')->insert([
                'rank_id' => $this->id('catalog_ranks', 'code', $relation['rank_code']),
                'presentation_asset_id' => $this->id(
                    'catalog_presentation_assets',
                    'storage_identifier',
                    $relation['asset_storage_identifier']
                ),
                'usage_type' => $relation['usage_type'],
                'sort_order' => $relation['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }
        foreach ($manifest['prizes'] as $prize) {
            DB::table('catalog_prizes')->insert([
                'public_id' => $prize['public_id'],
                'code' => $prize['code'],
                'rank_id' => $this->id('catalog_ranks', 'code', $prize['rank_code']),
                'presentation_asset_id' => $this->id(
                    'catalog_presentation_assets',
                    'storage_identifier',
                    $prize['asset_storage_identifier']
                ),
                'display_name' => $prize['name'],
                'description' => $prize['description'] ?? null,
                'display_price' => $prize['display_price'],
                'exchange_points' => $prize['exchange_points'],
                'is_visible' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }
        foreach ($manifest['gachas'] as $gacha) {
            $gachaId = DB::table('catalog_gachas')->insertGetId([
                'public_id' => $gacha['public_id'],
                'code' => $gacha['code'],
                'slug' => $gacha['slug'],
                'category_id' => $this->id(
                    'catalog_categories',
                    'code',
                    $gacha['category_code']
                ),
                'state' => 'draft',
                'sold_count' => $gacha['sold_count'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
            foreach ($gacha['tag_codes'] as $tagCode) {
                DB::table('catalog_gacha_tags')->insert([
                    'gacha_id' => $gachaId,
                    'tag_id' => $this->id('catalog_tags', 'code', $tagCode),
                ]);
                $count++;
            }
        }
        foreach ($manifest['versions'] as $version) {
            DB::table('catalog_gacha_versions')->insert([
                'public_id' => $version['public_id'],
                'gacha_id' => $this->id('catalog_gachas', 'code', $version['gacha_code']),
                'version_number' => $version['version_number'],
                'status' => 'draft',
                'title' => $version['title'],
                'description' => $version['description'] ?? null,
                'notices' => $version['notices'] ?? null,
                'price_points' => $version['price_points'],
                'total_count' => $version['total_count'],
                'presentation_asset_id' => $this->id(
                    'catalog_presentation_assets',
                    'storage_identifier',
                    $version['asset_storage_identifier']
                ),
                'publish_start_at' => $version['publish_start_at'],
                'publish_end_at' => $version['publish_end_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }
        foreach ($manifest['gacha_prizes'] as $relation) {
            DB::table('catalog_gacha_version_prizes')->insert([
                'gacha_version_id' => $this->gachaVersionId(
                    $relation['gacha_code'],
                    $relation['gacha_version_number']
                ),
                'prize_id' => $this->id('catalog_prizes', 'code', $relation['prize_code']),
                'initial_inventory' => $relation['initial_inventory'],
                'sort_order' => $relation['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
        }
        foreach ($manifest['probability_versions'] as $version) {
            $gachaVersionId = $this->gachaVersionId(
                $version['gacha_code'],
                $version['gacha_version_number']
            );
            $probabilityVersionId = DB::table('catalog_probability_versions')->insertGetId([
                'public_id' => $version['public_id'],
                'gacha_version_id' => $gachaVersionId,
                'version_number' => $version['version_number'],
                'status' => 'draft',
                'snapshot_sha256' => $version['snapshot_sha256'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $count++;
            foreach ($version['stages'] as $stage) {
                $stageId = DB::table('catalog_probability_stages')->insertGetId([
                    'public_id' => $stage['public_id'],
                    'probability_version_id' => $probabilityVersionId,
                    'code' => $stage['code'],
                    'display_name' => $stage['name'],
                    'condition_type' => 'sold_count',
                    'min_draw_number' => $stage['min_draw_number'],
                    'max_draw_number' => $stage['max_draw_number'] ?? null,
                    'sort_order' => $stage['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $count++;
                foreach ($stage['entries'] as $entry) {
                    DB::table('catalog_probability_entries')->insert([
                        'probability_stage_id' => $stageId,
                        'result_type' => $entry['result_type'],
                        'gacha_version_prize_id' => $entry['result_type'] === 'prize'
                            ? $this->gachaPrizeId(
                                $gachaVersionId,
                                $entry['prize_code']
                            )
                            : null,
                        'point_amount' => $entry['result_type'] === 'point_back'
                            ? $entry['point_amount']
                            : null,
                        'probability_ppm' => $entry['probability_ppm'],
                        'sort_order' => $entry['sort_order'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $count++;
                }
                $guarantee = $stage['minimum_guarantee'];
                DB::table('catalog_minimum_guarantees')->insert([
                    'probability_stage_id' => $stageId,
                    'result_type' => $guarantee['result_type'],
                    'gacha_version_prize_id' => $guarantee['result_type'] === 'prize'
                        ? $this->gachaPrizeId(
                            $gachaVersionId,
                            $guarantee['prize_code']
                        )
                        : null,
                    'point_amount' => $guarantee['result_type'] === 'point_back'
                        ? $guarantee['point_amount']
                        : null,
                    'probability_ppm' => $guarantee['probability_ppm'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $count++;
            }
            DB::table('catalog_probability_versions')
                ->where('id', $probabilityVersionId)
                ->update(['status' => 'published', 'updated_at' => $now]);
            $gachaVersionRevision = (int) DB::table('catalog_gacha_versions')
                ->where('id', $gachaVersionId)->value('revision');
            DB::table('catalog_gacha_versions')
                ->where('id', $gachaVersionId)
                ->update([
                    'published_probability_version_id' => $probabilityVersionId,
                    'status' => 'published',
                    'revision' => $gachaVersionRevision + 1,
                    'updated_at' => $now,
                ]);
            $gachaId = (int) DB::table('catalog_gacha_versions')
                ->where('id', $gachaVersionId)->value('gacha_id');
            $gachaRevision = (int) DB::table('catalog_gachas')
                ->where('id', $gachaId)->value('revision');
            DB::table('catalog_gachas')->where('id', $gachaId)->update([
                'state' => 'active',
                'published_version_id' => $gachaVersionId,
                'revision' => $gachaRevision + 1,
                'updated_at' => $now,
            ]);
        }

        return $count;
    }

    /**
     * Mutable Draw state is derived from the immutable published Catalog fixture.
     *
     * @param array<string, mixed> $manifest
     */
    private function initializeDrawState(array $manifest): void
    {
        if (! Schema::hasTable('gacha_draw_states')) {
            return;
        }

        $now = now();
        foreach ($manifest['gachas'] as $gacha) {
            $gachaRow = DB::table('catalog_gachas')
                ->where('code', $gacha['code'])
                ->first(['id', 'published_version_id', 'sold_count']);
            if ($gachaRow === null || $gachaRow->published_version_id === null) {
                continue;
            }
            $version = DB::table('catalog_gacha_versions')
                ->where('id', $gachaRow->published_version_id)
                ->first(['id', 'published_probability_version_id', 'total_count']);
            if ($version === null || $version->published_probability_version_id === null) {
                continue;
            }

            $stateId = DB::table('gacha_draw_states')
                ->where('gacha_id', $gachaRow->id)
                ->value('id');
            if ($stateId === null) {
                $stateId = DB::table('gacha_draw_states')->insertGetId([
                    'gacha_id' => $gachaRow->id,
                    'gacha_version_id' => $version->id,
                    'probability_version_id' => $version->published_probability_version_id,
                    'status' => (int) $gachaRow->sold_count === (int) $version->total_count
                        ? 'sold_out'
                        : 'selling',
                    'total_count' => $version->total_count,
                    'sold_count' => $gachaRow->sold_count,
                    'lock_version' => 0,
                    'started_at' => $now,
                    'sold_out_at' => (int) $gachaRow->sold_count === (int) $version->total_count
                        ? $now
                        : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $relations = DB::table('catalog_gacha_version_prizes')
                ->where('gacha_version_id', $version->id)
                ->orderBy('id')
                ->get(['id', 'initial_inventory']);
            foreach ($relations as $relation) {
                DB::table('prize_inventories')->insertOrIgnore([
                    'gacha_draw_state_id' => $stateId,
                    'gacha_version_prize_id' => $relation->id,
                    'initial_quantity' => $relation->initial_inventory,
                    'won_count' => 0,
                    'lock_version' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function validateManifest(array $manifest): void
    {
        $required = [
            'schema_version',
            'source_baseline_sha',
            'expected_record_count',
            'categories',
            'tags',
            'ranks',
            'assets',
            'rank_assets',
            'prizes',
            'gachas',
            'versions',
            'gacha_prizes',
            'probability_versions',
        ];
        if (array_diff($required, array_keys($manifest)) !== []
            || $manifest['schema_version'] !== '2.0.0-alpha.1'
            || ! preg_match('/^[0-9a-f]{40}$/', (string) $manifest['source_baseline_sha'])
        ) {
            throw new V2CatalogException(
                'INVALID_CATALOG_FIXTURE',
                422,
                'The Catalog fixture manifest is invalid.'
            );
        }
        foreach ($manifest['versions'] as $version) {
            $startsAt = strtotime((string) ($version['publish_start_at'] ?? ''));
            $endsAt = isset($version['publish_end_at'])
                ? strtotime((string) $version['publish_end_at'])
                : null;
            if ($startsAt === false || $endsAt === false
                || ($endsAt !== null && $endsAt <= $startsAt)
                || ! is_int($version['price_points'])
                || $version['price_points'] <= 0
                || ! is_int($version['total_count'])
                || $version['total_count'] <= 0
            ) {
                throw new V2CatalogException(
                    'INVALID_CATALOG_FIXTURE',
                    422,
                    'The Catalog fixture Gacha Version is invalid.'
                );
            }
        }
        foreach ($manifest['probability_versions'] as $version) {
            foreach ($version['stages'] as $stage) {
                $entries = $stage['entries'] ?? [];
                $guarantee = $stage['minimum_guarantee'] ?? null;
                $total = is_array($guarantee)
                    && is_int($guarantee['probability_ppm'] ?? null)
                    ? $guarantee['probability_ppm']
                    : -1;
                foreach ($entries as $entry) {
                    if (! is_int($entry['probability_ppm'] ?? null)
                        || ! in_array(
                            $entry['result_type'] ?? null,
                            ['prize', 'point_back'],
                            true
                        )
                    ) {
                        $total = -1;
                        break;
                    }
                    $total += $entry['probability_ppm'];
                }
                if ($total !== 1_000_000) {
                    throw new V2CatalogException(
                        'INVALID_PROBABILITY_TOTAL',
                        422,
                        'Each Probability Stage must total 1,000,000 ppm.'
                    );
                }
            }
        }
    }

    private function id(string $table, string $column, string $value): int
    {
        $id = DB::table($table)->where($column, $value)->value('id');
        if ($id === null) {
            throw new V2CatalogException(
                'CATALOG_IMPORT_REFERENCE_MISSING',
                422,
                'A Catalog fixture reference is missing.'
            );
        }

        return (int) $id;
    }

    private function gachaVersionId(string $gachaCode, int $versionNumber): int
    {
        $id = DB::table('catalog_gacha_versions as gv')
            ->join('catalog_gachas as g', 'g.id', '=', 'gv.gacha_id')
            ->where('g.code', $gachaCode)
            ->where('gv.version_number', $versionNumber)
            ->value('gv.id');
        if ($id === null) {
            throw new V2CatalogException(
                'CATALOG_IMPORT_REFERENCE_MISSING',
                422,
                'A Catalog fixture Gacha Version reference is missing.'
            );
        }

        return (int) $id;
    }

    private function gachaPrizeId(int $gachaVersionId, string $prizeCode): int
    {
        $id = DB::table('catalog_gacha_version_prizes as gvp')
            ->join('catalog_prizes as p', 'p.id', '=', 'gvp.prize_id')
            ->where('gvp.gacha_version_id', $gachaVersionId)
            ->where('p.code', $prizeCode)
            ->value('gvp.id');
        if ($id === null) {
            throw new V2CatalogException(
                'CATALOG_IMPORT_REFERENCE_MISSING',
                422,
                'A Catalog fixture Prize relation is missing.'
            );
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function canonicalJson(array $value): string
    {
        $canonical = $this->sortRecursively($value);
        try {
            return json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new V2CatalogException(
                'INVALID_CATALOG_FIXTURE',
                422,
                'The Catalog fixture cannot be encoded.'
            );
        }
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
