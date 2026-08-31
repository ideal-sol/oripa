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
            $imported = DB::transaction(function () use ($manifest): int {
                $count = $this->importRecords($manifest);
                $this->initializeDrawState($manifest);

                return $count;
            }, 3);
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
        foreach ($manifest['gachas'] as $gacha) {
            $gachaId = DB::table('catalog_gachas')->insertGetId([
                'public_id' => $gacha['public_id'],
                'public_code' => $gacha['public_code'],
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
        foreach ($manifest['ranks'] as $rank) {
            DB::table('catalog_ranks')->insert([
                'public_id' => $rank['public_id'],
                'gacha_id' => $this->id(
                    'catalog_gachas',
                    'code',
                    $rank['gacha_code']
                ),
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
            $assetId = $this->id(
                'catalog_presentation_assets',
                'storage_identifier',
                $relation['asset_storage_identifier']
            );
            DB::table('catalog_rank_assets')->insert([
                'rank_id' => $this->rankId(
                    $relation['gacha_code'],
                    $relation['rank_code']
                ),
                'presentation_asset_id' => $assetId,
                'usage_type' => $relation['usage_type'],
                'sort_order' => $relation['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('catalog_rank_effect_materials')->insertOrIgnore([
                'presentation_asset_id' => $assetId,
                'created_at' => $now,
            ]);
            $count++;
        }
        $this->createCanonicalFixtureRanks($now);
        foreach ($manifest['prizes'] as $prize) {
            $gachaCode = $this->fixturePrizeGachaCode($manifest, $prize['code']);
            DB::table('catalog_prizes')->insert([
                'public_id' => $prize['public_id'],
                'code' => $prize['code'],
                'gacha_id' => $this->id(
                    'catalog_gachas',
                    'code',
                    $gachaCode
                ),
                'rank_id' => null,
                'gacha_rank_id' => $this->gachaRankId($gachaCode, $prize['rank_code']),
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
        foreach ($manifest['versions'] as $version) {
            DB::table('catalog_gacha_versions')->insert([
                'public_id' => $version['public_id'],
                'gacha_id' => $this->id('catalog_gachas', 'code', $version['gacha_code']),
                'category_id' => DB::table('catalog_gachas')
                    ->where('code', $version['gacha_code'])->value('category_id'),
                'version_number' => $version['version_number'],
                'status' => 'draft',
                'title' => $version['title'],
                'description' => $version['description'] ?? null,
                'notices' => $version['notices'] ?? null,
                'price_points' => $version['price_points'],
                'total_count' => $version['total_count'],
                'daily_draw_limit' => $version['daily_draw_limit'] ?? 0,
                'audience_code' => $version['audience_code'] ?? 'all_users',
                'first_time_eligible_days' => $version['first_time_eligible_days'] ?? 7,
                'allowed_draw_counts' => json_encode(
                    $version['allowed_draw_counts'] ?? [1, 5, 10],
                    JSON_THROW_ON_ERROR
                ),
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
            $versionId = $this->gachaVersionId(
                $version['gacha_code'],
                $version['version_number']
            );
            $gachaId = $this->id('catalog_gachas', 'code', $version['gacha_code']);
            foreach (DB::table('catalog_gacha_tags')->where('gacha_id', $gachaId)->pluck('tag_id') as $tagId) {
                DB::table('catalog_gacha_version_tags')->insert([
                    'gacha_version_id' => $versionId,
                    'tag_id' => $tagId,
                ]);
                $count++;
            }
        }
        foreach ($manifest['gacha_prizes'] as $relation) {
            $prize = DB::table('catalog_prizes')
                ->where('code', $relation['prize_code'])
                ->first();
            if ($prize === null) {
                throw new V2CatalogException(
                    'CATALOG_IMPORT_REFERENCE_MISSING',
                    422,
                    'A Catalog fixture Prize reference is missing.'
                );
            }
            DB::table('catalog_gacha_version_prizes')->insert([
                'gacha_version_id' => $this->gachaVersionId(
                    $relation['gacha_code'],
                    $relation['gacha_version_number']
                ),
                'prize_id' => $prize->id,
                'rank_id' => null,
                'gacha_rank_id' => $prize->gacha_rank_id,
                'rank_code' => null,
                'rank_display_name' => null,
                'rank_sort_order' => null,
                'presentation_asset_id' => $prize->presentation_asset_id,
                'display_name' => $prize->display_name,
                'description' => $prize->description,
                'display_price' => $prize->display_price,
                'exchange_points' => $prize->exchange_points,
                'cost_price' => $prize->cost_price,
                'is_visible' => $prize->is_visible,
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
                ->update([
                    'status' => 'published',
                    'revision' => 2,
                    'updated_at' => $now,
                ]);
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
                ->first([
                    'id',
                    'published_version_id',
                    'active_draw_state_id',
                    'sold_count',
                    'revision',
                    'current_title',
                ]);
            if ($gachaRow === null) {
                continue;
            }
            $versionQuery = DB::table('catalog_gacha_versions')
                ->where('gacha_id', $gachaRow->id)
                ->where('status', 'published');
            if ($gachaRow->published_version_id !== null) {
                $versionQuery->where('id', $gachaRow->published_version_id);
            } else {
                $versionQuery->orderByDesc('version_number');
            }
            $version = $versionQuery->first([
                'id',
                'published_probability_version_id',
                'total_count',
                'title',
                'description',
                'notices',
                'presentation_asset_id',
                'publish_start_at',
                'publish_end_at',
                'published_at',
            ]);
            if ($version === null || $version->published_probability_version_id === null) {
                continue;
            }

            $stateId = DB::table('gacha_draw_states')
                ->where('gacha_version_id', $version->id)
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
            if (
                (int) ($gachaRow->published_version_id ?? 0) !== (int) $version->id
                || (int) ($gachaRow->active_draw_state_id ?? 0) !== (int) $stateId
                || $gachaRow->current_title === null
            ) {
                DB::table('catalog_gachas')->where('id', $gachaRow->id)->update([
                    'state' => 'active',
                    'management_status' => 'published',
                    'published_version_id' => $version->id,
                    'active_draw_state_id' => $stateId,
                    'first_published_at' => $version->published_at ?? $now,
                    'scheduled_start_at' => null,
                    'current_publish_start_at' => $version->publish_start_at,
                    'current_title' => $version->title,
                    'current_description' => $version->description,
                    'current_notices' => $version->notices,
                    'current_presentation_asset_id' =>
                        $version->presentation_asset_id,
                    'current_publish_end_at' => $version->publish_end_at,
                    'revision' => (int) $gachaRow->revision + 1,
                    'updated_at' => $now,
                ]);
            }
            $gachaRanks = DB::table('catalog_gacha_ranks')
                ->where('gacha_id', $gachaRow->id)
                ->whereNull('first_published_at')
                ->orderBy('id')
                ->get(['id', 'revision']);
            foreach ($gachaRanks as $gachaRank) {
                DB::table('catalog_gacha_ranks')->where('id', $gachaRank->id)->update([
                    'first_published_at' => $version->published_at ?? $now,
                    'revision' => (int) $gachaRank->revision + 1,
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
                    'total_quantity' => $relation->initial_inventory,
                    'awarded_count' => 0,
                    'available_quantity' => $relation->initial_inventory,
                    'withdrawn_quantity' => 0,
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
                || ! is_int($version['daily_draw_limit'] ?? 0)
                || ($version['daily_draw_limit'] ?? 0) < 0
                || ! in_array(
                    $version['audience_code'] ?? 'all_users',
                    ['all_users', 'first_time_users', 'line_users'],
                    true
                )
                || ! is_int($version['first_time_eligible_days'] ?? 7)
                || ($version['first_time_eligible_days'] ?? 7) < 1
                || ! $this->validAllowedDrawCounts(
                    $version['allowed_draw_counts'] ?? [1, 5, 10]
                )
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

    private function validAllowedDrawCounts(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        return $value === array_values(array_filter(
            [1, 5, 10, 100, 1000],
            static fn (int $count): bool => in_array($count, $value, true)
        )) && in_array(1, $value, true);
    }

    private function createCanonicalFixtureRanks(mixed $now): void
    {
        $fallbackImage = DB::table('catalog_presentation_assets')
            ->where('media_type', 'image')
            ->where('is_public', true)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first(['id']);
        $fallbackVideo = DB::table('catalog_presentation_assets')
            ->where('media_type', 'video')
            ->where('is_public', true)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first(['id']);
        $ranks = DB::table('catalog_ranks')->orderBy('id')->get();
        foreach ($ranks as $rank) {
            $assets = DB::table('catalog_rank_assets as relation')
                ->join(
                    'catalog_presentation_assets as asset',
                    'asset.id',
                    '=',
                    'relation.presentation_asset_id'
                )
                ->where('relation.rank_id', $rank->id)
                ->orderBy('relation.sort_order')
                ->get(['relation.usage_type', 'asset.id', 'asset.media_type'])
                ->keyBy('usage_type');
            $lineup = $assets->get('image')
                ?? $assets->get('result_image')
                ?? $fallbackImage;
            $result = $assets->get('result_image')
                ?? $assets->get('image')
                ?? $fallbackImage;
            $video = $assets->get('video') ?? $fallbackVideo;
            if ($lineup === null || $result === null || $video === null) {
                throw new V2CatalogException(
                    'INVALID_CATALOG_FIXTURE',
                    422,
                    'Each fixture Rank requires Canonical image and video Assets.'
                );
            }
            $masterId = DB::table('catalog_rank_masters')->insertGetId([
                'public_id' => $rank->public_id,
                'current_revision_id' => null,
                'status' => 'active',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $masterRevisionId = DB::table('catalog_rank_master_revisions')->insertGetId([
                'rank_master_id' => $masterId,
                'revision_number' => 1,
                'rank_name' => $rank->display_name,
                'lineup_image_asset_id' => $lineup->id,
                'result_image_asset_id' => $result->id,
                'show_total_stock' => false,
                'display_order' => $rank->sort_order,
                'created_at' => $now,
            ]);
            DB::table('catalog_rank_masters')->where('id', $masterId)->update([
                'current_revision_id' => $masterRevisionId,
                'updated_at' => $now,
            ]);
            $gachaRankId = DB::table('catalog_gacha_ranks')->insertGetId([
                'public_id' => (string) Str::uuid7(),
                'gacha_id' => $rank->gacha_id,
                'rank_master_id' => $masterId,
                'current_video_revision_id' => null,
                'first_published_at' => null,
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $videoRevisionId = DB::table('catalog_gacha_rank_video_revisions')->insertGetId([
                'gacha_rank_id' => $gachaRankId,
                'revision_number' => 1,
                'video_asset_id' => $video->id,
                'created_at' => $now,
            ]);
            DB::table('catalog_gacha_ranks')->where('id', $gachaRankId)->update([
                'current_video_revision_id' => $videoRevisionId,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param array<string, mixed> $manifest */
    private function fixturePrizeGachaCode(array $manifest, string $prizeCode): string
    {
        $gachaCodes = collect($manifest['gacha_prizes'])
            ->where('prize_code', $prizeCode)
            ->pluck('gacha_code')
            ->unique()
            ->values();
        if ($gachaCodes->count() !== 1) {
            throw new V2CatalogException(
                'INVALID_CATALOG_FIXTURE',
                422,
                'Each Catalog fixture Prize must belong to exactly one Gacha.'
            );
        }

        return (string) $gachaCodes->first();
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

    private function rankId(string $gachaCode, string $rankCode): int
    {
        $id = DB::table('catalog_ranks as rank')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'rank.gacha_id')
            ->where('gacha.code', $gachaCode)
            ->where('rank.code', $rankCode)
            ->value('rank.id');
        if ($id === null) {
            throw new V2CatalogException(
                'CATALOG_IMPORT_REFERENCE_MISSING',
                422,
                'A Catalog fixture Rank reference is missing.'
            );
        }

        return (int) $id;
    }

    private function gachaRankId(string $gachaCode, string $rankCode): int
    {
        $id = DB::table('catalog_gacha_ranks as gacha_rank')
            ->join(
                'catalog_rank_masters as master',
                'master.id',
                '=',
                'gacha_rank.rank_master_id'
            )
            ->join('catalog_ranks as rank', 'rank.public_id', '=', 'master.public_id')
            ->join('catalog_gachas as gacha', 'gacha.id', '=', 'gacha_rank.gacha_id')
            ->where('gacha.code', $gachaCode)
            ->where('rank.code', $rankCode)
            ->value('gacha_rank.id');
        if ($id === null) {
            throw new V2CatalogException(
                'CATALOG_IMPORT_REFERENCE_MISSING',
                422,
                'A Canonical fixture Gacha Rank reference is missing.'
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
