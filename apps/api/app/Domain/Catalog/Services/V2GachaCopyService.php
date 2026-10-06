<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2GachaCopyService
{
    public function projection(string $identifier, bool $copy): array
    {
        return DB::transaction(function () use ($identifier, $copy): array {
            $gacha = DB::table('catalog_gachas')->where(Str::isUuid($identifier) ? 'public_id' : 'public_code', $identifier)->sharedLock()->first();
            if ($gacha === null || $gacha->archived_at !== null
                || ! in_array($gacha->management_status, $copy ? ['draft', 'published', 'sales_paused'] : ['draft', 'scheduled', 'published', 'sales_paused'], true)) {
                throw new V2CatalogException('CATALOG_RESOURCE_NOT_FOUND', 404, 'The composition source is unavailable.');
            }
            $published = in_array($gacha->management_status, ['published', 'sales_paused'], true);
            $version = DB::table('catalog_gacha_versions')->where('gacha_id', $gacha->id)->whereNull('archived_at')
                ->when($published, fn ($query) => $query->where('id', $gacha->published_version_id))
                ->when(! $published, fn ($query) => $query->where('status', 'draft'))->orderByDesc('version_number')->firstOrFail();
            $relations = DB::table('catalog_gacha_version_prizes as relation')->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
                ->where('relation.gacha_version_id', $version->id)->orderBy('relation.sort_order')->orderBy('relation.id')
                ->get(['relation.*', 'prize.external_id', 'prize.display_name as current_name', 'prize.presentation_asset_id as current_asset_id']);
            $ranks = [];
            $prizes = [];
            $probabilityId = $gacha->gacha_type === 'standard' ? null : DB::table('catalog_probability_versions')
                ->where('gacha_version_id', $version->id)->whereNull('archived_at')->value('id');
            $rates = $probabilityId === null ? [] : app(V2LoginProbabilityService::class)->snapshot((int) $probabilityId, (int) $version->id)['rates'];
            foreach ($relations as $relation) {
                if ($relation->gacha_rank_id === null) {
                    throw new V2CatalogException('CATALOG_MUTATION_INVALID', 409, 'Legacy rank configuration must be reconciled before copying.');
                }
                $rank = DB::table('catalog_gacha_ranks')->where('id', $relation->gacha_rank_id)->firstOrFail();
                $master = DB::table('catalog_rank_masters')->where('id', $rank->rank_master_id)->sharedLock()->firstOrFail();
                $revisionId = $relation->published_rank_revision_id ?? $rank->preferred_rank_revision_id ?? $master->current_revision_id;
                $videoRevisionId = $relation->published_video_revision_id ?? $rank->current_video_revision_id;
                $rankRevision = DB::table('catalog_rank_master_revisions')->where('id', $revisionId)->firstOrFail();
                $ranks[$master->public_id] = [
                    'rank_id' => $master->public_id,
                    'rank_revision_number' => (int) $rankRevision->revision_number,
                    'video_asset_id' => $this->publicId('catalog_presentation_assets', $videoRevisionId === null ? null
                        : DB::table('catalog_gacha_rank_video_revisions')->where('id', $videoRevisionId)->value('video_asset_id')),
                    'presentation' => [
                        'name' => $rankRevision->rank_name,
                        'lineup_image' => $this->assetSnapshot((int) $rankRevision->lineup_image_asset_id),
                        'result_image' => $this->assetSnapshot((int) $rankRevision->result_image_asset_id),
                    ],
                ];
                $prizes[] = [
                    'external_id' => $relation->external_id,
                    'name' => $published ? $relation->current_name : $relation->display_name,
                    'presentation_asset_id' => $this->publicId('catalog_presentation_assets', $published ? $relation->current_asset_id : $relation->presentation_asset_id),
                    'rank_id' => $master->public_id, 'exchange_points' => (int) $relation->exchange_points,
                    'cost_price' => (int) $relation->cost_price, 'initial_inventory' => (int) $relation->initial_inventory,
                    'shipping_only' => (bool) $relation->shipping_only,
                    'percentage' => isset($rates[$relation->id]) ? V2FixedPercentage::format($rates[$relation->id]) : null,
                ];
            }

            return [
                'use_default_rank_video' => false,
                'gacha_type' => $gacha->gacha_type, 'title' => $published ? $gacha->current_title : $version->title,
                'description' => $published ? $gacha->current_description : $version->description,
                'notices' => $published ? $gacha->current_notices : $version->notices,
                'presentation_asset_id' => $this->publicId('catalog_presentation_assets', $published ? $gacha->current_presentation_asset_id : $version->presentation_asset_id),
                'price_points' => (int) $version->price_points, 'minimum_exchange_points' => $version->minimum_exchange_points === null ? null : (int) $version->minimum_exchange_points,
                'publish_start_at' => $copy ? null : $this->date($published ? $gacha->current_publish_start_at : $version->publish_start_at),
                'publish_end_at' => $copy ? null : $this->date($published ? $gacha->current_publish_end_at : $version->publish_end_at),
                'category_id' => $this->publicId('catalog_categories', $published ? $gacha->category_id : $version->category_id),
                'tag_ids' => $published ? DB::table('catalog_gacha_tags as relation')->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')->where('relation.gacha_id', $gacha->id)->orderBy('tag.id')->pluck('tag.public_id')->all()
                    : DB::table('catalog_gacha_version_tags as relation')->join('catalog_tags as tag', 'tag.id', '=', 'relation.tag_id')->where('relation.gacha_version_id', $version->id)->orderBy('tag.id')->pluck('tag.public_id')->all(),
                'total_count' => $version->total_count === null ? null : (int) $version->total_count,
                'daily_draw_limit' => (int) $version->daily_draw_limit, 'audience_code' => $version->audience_code,
                'first_time_eligible_days' => (int) $version->first_time_eligible_days,
                'allowed_draw_counts' => json_decode($version->allowed_draw_counts, true, 512, JSON_THROW_ON_ERROR),
                'ranks' => array_values($ranks), 'prizes' => $prizes,
            ];
        });
    }

    private function assetSnapshot(int $id): array
    {
        $asset = DB::table('catalog_presentation_assets')->where('id', $id)->firstOrFail();

        return ['id' => $asset->public_id, 'path' => $asset->public_path, 'mime_type' => $asset->mime_type, 'alt_text' => $asset->alt_text];
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toIso8601String();
    }

    private function publicId(string $table, ?int $id): ?string
    {
        return $id === null ? null : DB::table($table)->where('id', $id)->value('public_id');
    }
}
