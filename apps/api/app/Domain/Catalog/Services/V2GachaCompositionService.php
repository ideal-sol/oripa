<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Support\V2DatabaseTimestamp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class V2GachaCompositionService
{
    public function __construct(
        private readonly V2GachaPublicCodeGenerator $publicCodes,
        private readonly V2LoginProbabilityService $probabilities,
    ) {}

    public function validate(array $input): array
    {
        $useDefault = array_key_exists('use_default_rank_video', $input) ? $input['use_default_rank_video'] : true;
        if (! is_bool($useDefault)) {
            throw $this->invalid();
        }
        unset($input['use_default_rank_video']);
        foreach (is_array($input['ranks'] ?? null) ? $input['ranks'] : [] as $index => $rank) {
            if (is_array($rank)) {
                unset($input['ranks'][$index]['presentation']);
            }
        }
        $fields = ['gacha_type', 'title', 'description', 'notices', 'presentation_asset_id', 'price_points',
            'minimum_exchange_points', 'publish_start_at', 'publish_end_at', 'category_id', 'tag_ids',
            'total_count', 'daily_draw_limit', 'audience_code', 'first_time_eligible_days', 'allowed_draw_counts', 'ranks', 'prizes'];
        if (array_diff(array_keys($input), $fields) !== [] || array_diff($fields, array_keys($input)) !== []) {
            throw $this->invalid();
        }
        $validator = Validator::make($input, [
            'gacha_type' => 'required|in:standard,login_daily,signup_once', 'title' => 'required|string|max:191',
            'description' => 'nullable|string|max:10000', 'notices' => 'nullable|string|max:10000',
            'presentation_asset_id' => 'required|uuid', 'price_points' => 'required|integer|min:0|max:9007199254740991',
            'minimum_exchange_points' => 'nullable|integer|min:0|max:9007199254740991',
            'total_count' => 'nullable|integer|min:1|max:2147483647', 'category_id' => 'nullable|uuid',
            'tag_ids' => 'present|array|max:100', 'tag_ids.*' => 'required|uuid|distinct',
            'daily_draw_limit' => 'required|integer|min:0|max:2147483647',
            'audience_code' => 'required|in:all_users,first_time_users,line_users',
            'first_time_eligible_days' => 'required|integer|min:1|max:365',
            'allowed_draw_counts' => 'required|array|min:1|max:5', 'allowed_draw_counts.*' => 'integer|in:1,5,10,100,1000|distinct',
            'publish_start_at' => 'required|date', 'publish_end_at' => 'nullable|date|after:publish_start_at',
            'ranks' => 'required|array|min:1|max:100', 'ranks.*' => 'array:rank_id,rank_revision_number,video_asset_id',
            'ranks.*.rank_id' => 'required|uuid|distinct', 'ranks.*.rank_revision_number' => 'present|nullable|integer|min:1',
            'ranks.*.video_asset_id' => 'present|nullable|uuid',
            'prizes' => 'required|array|min:1|max:1000',
            'prizes.*' => 'array:name,presentation_asset_id,rank_id,exchange_points,cost_price,initial_inventory,shipping_only,percentage',
            'prizes.*.name' => 'required|string|max:191', 'prizes.*.presentation_asset_id' => 'required|uuid',
            'prizes.*.rank_id' => 'required|uuid', 'prizes.*.exchange_points' => 'required|integer|min:0|max:9007199254740991',
            'prizes.*.cost_price' => 'required|integer|min:0|max:9007199254740991',
            'prizes.*.initial_inventory' => 'required|integer|min:0|max:2147483647',
            'prizes.*.shipping_only' => 'required|boolean', 'prizes.*.percentage' => 'present|nullable|string',
        ]);
        if ($validator->fails()) {
            throw $this->invalid();
        }
        foreach (['price_points', 'total_count', 'minimum_exchange_points', 'daily_draw_limit', 'first_time_eligible_days'] as $field) {
            if ($input[$field] !== null && ! is_int($input[$field])) {
                throw $this->invalid();
            }
        }
        foreach (['publish_start_at', 'publish_end_at'] as $field) {
            if ($input[$field] !== null) {
                if (! is_string($input[$field]) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $input[$field])) {
                    throw $this->invalid();
                }
                $input[$field] = V2DatabaseTimestamp::format(CarbonImmutable::parse($input[$field]));
            }
        }
        foreach ($input['ranks'] as $rank) {
            if ($rank['rank_revision_number'] !== null && ! is_int($rank['rank_revision_number'])) {
                throw $this->invalid();
            }
        }
        foreach ($input['allowed_draw_counts'] as $count) {
            if (! is_int($count)) {
                throw $this->invalid();
            }
        }
        foreach (['title', 'description', 'notices'] as $field) {
            if ($input[$field] !== null && ($input[$field] !== strip_tags($input[$field]) || str_contains($input[$field], chr(0)))) {
                throw $this->invalid();
            }
        }
        $login = $input['gacha_type'] !== 'standard';
        if ($login) {
            if ($input['category_id'] !== null || $input['tag_ids'] !== [] || $input['total_count'] !== null
                || $input['allowed_draw_counts'] !== [1] || $input['audience_code'] !== 'all_users'
                || $input['first_time_eligible_days'] !== 7
                || ($input['gacha_type'] === 'login_daily' && ($input['daily_draw_limit'] !== 1 || $input['minimum_exchange_points'] === null))
                || ($input['gacha_type'] === 'signup_once' && ($input['price_points'] !== 0 || $input['daily_draw_limit'] !== 0 || $input['minimum_exchange_points'] !== null))) {
                throw $this->invalid();
            }
        } elseif ($input['price_points'] < 1 || $input['total_count'] === null || $input['category_id'] === null || $input['minimum_exchange_points'] !== null) {
            throw $this->invalid();
        }
        $rates = [];
        foreach ($input['prizes'] as $prize) {
            if (! in_array($prize['rank_id'], array_column($input['ranks'], 'rank_id'), true)
                || ! is_bool($prize['shipping_only']) || $prize['name'] !== strip_tags($prize['name'])
                || str_contains($prize['name'], chr(0))) {
                throw $this->invalid();
            }
            foreach (['exchange_points', 'cost_price', 'initial_inventory'] as $field) {
                if (! is_int($prize[$field])) {
                    throw $this->invalid();
                }
            }
            if ($login) {
                if ($prize['shipping_only'] || ($input['gacha_type'] === 'login_daily' && $prize['exchange_points'] < $input['minimum_exchange_points'])) {
                    throw $this->invalid();
                }
                $rates[] = V2FixedPercentage::parse($prize['percentage']);
            } elseif ($prize['percentage'] !== null) {
                throw $this->invalid();
            }
        }
        if ($login) {
            V2FixedPercentage::assertTotal($rates);
        } elseif (array_sum(array_column($input['prizes'], 'initial_inventory')) > $input['total_count']) {
            throw $this->invalid();
        }

        return [...$input, 'use_default_rank_video' => $useDefault];
    }

    public function save(array $payload, ?object $gacha = null, ?int $expectedVersionRevision = null): object
    {
        $now = V2DatabaseTimestamp::format(now()->startOfSecond());
        $category = $payload['category_id'] === null ? null : $this->reference('catalog_categories', $payload['category_id']);
        $asset = $this->asset($payload['presentation_asset_id'], 'image');
        $existing = $gacha !== null;
        if ($existing && ($gacha->gacha_type !== $payload['gacha_type'] || $gacha->management_status !== 'draft'
            || $gacha->first_published_at !== null || $gacha->archived_at !== null)) {
            throw new V2CatalogException('CATALOG_GACHA_IDENTITY_IMMUTABLE', 409, 'Only an unpublished Draft composition may be edited.');
        }
        if (! $existing) {
            $publicId = (string) Str::uuid7();
            $identity = str_replace('-', '', $publicId);
            $gachaId = DB::table('catalog_gachas')->insertGetId([
                'public_id' => $publicId, 'public_code' => $this->publicCodes->unique(),
                'code' => 'gacha_'.substr($identity, 0, 26), 'slug' => 'gacha-'.substr($identity, 0, 26),
                'gacha_type' => $payload['gacha_type'], 'category_id' => $category?->id,
                'state' => 'draft', 'management_status' => 'draft', 'sold_count' => 0, 'revision' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $gacha = DB::table('catalog_gachas')->where('id', $gachaId)->firstOrFail();
            if ($payload['use_default_rank_video'] ?? true) {
                $defaultVideo = app(V2CatalogMasterMutationService::class)->initializeRankVideos((int) $gachaId);
                foreach ($payload['ranks'] as &$rankInput) {
                    $rankInput['video_asset_id'] ??= $defaultVideo;
                }
                unset($rankInput);
            }
            $version = null;
        } else {
            $version = DB::table('catalog_gacha_versions')->where('gacha_id', $gacha->id)->where('status', 'draft')
                ->whereNull('archived_at')->orderByDesc('version_number')->lockForUpdate()->first();
            if ($version === null || (int) $version->revision !== $expectedVersionRevision) {
                throw new V2CatalogException('CATALOG_REVISION_CONFLICT', 409, 'The Draft composition has changed.');
            }
            DB::table('catalog_gachas')->where('id', $gacha->id)->update([
                'category_id' => $category?->id, 'revision' => (int) $gacha->revision + 1, 'updated_at' => $now,
            ]);
        }
        $attributes = array_intersect_key($payload, array_flip(['title', 'description', 'notices', 'price_points',
            'total_count', 'minimum_exchange_points', 'daily_draw_limit', 'audience_code', 'first_time_eligible_days', 'publish_start_at', 'publish_end_at']));
        $attributes += ['category_id' => $category?->id, 'presentation_asset_id' => $asset->id,
            'allowed_draw_counts' => json_encode($payload['allowed_draw_counts'], JSON_THROW_ON_ERROR), 'updated_at' => $now];
        if ($version === null) {
            $versionId = DB::table('catalog_gacha_versions')->insertGetId($attributes + [
                'public_id' => (string) Str::uuid7(), 'gacha_id' => $gacha->id, 'version_number' => 1,
                'status' => 'draft', 'revision' => 1, 'created_at' => $now,
            ]);
        } else {
            $versionId = (int) $version->id;
            DB::table('catalog_gacha_versions')->where('id', $versionId)->update($attributes + ['revision' => (int) $version->revision + 1]);
            $relationIds = DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $versionId)->pluck('id');
            DB::table('catalog_probability_entries')->whereIn('gacha_version_prize_id', $relationIds)->delete();
            DB::table('prize_inventories')->whereIn('gacha_version_prize_id', $relationIds)->delete();
            DB::table('catalog_gacha_version_prizes')->where('gacha_version_id', $versionId)->delete();
        }
        DB::table('catalog_gacha_tags')->where('gacha_id', $gacha->id)->delete();
        DB::table('catalog_gacha_version_tags')->where('gacha_version_id', $versionId)->delete();
        foreach ($payload['tag_ids'] as $tagId) {
            $tag = $this->reference('catalog_tags', $tagId);
            DB::table('catalog_gacha_tags')->insert(['gacha_id' => $gacha->id, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('catalog_gacha_version_tags')->insert(['gacha_version_id' => $versionId, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ranks = $this->saveRanks($gacha, $payload, $now);
        $rates = [];
        foreach ($payload['prizes'] as $index => $prizeInput) {
            $prizeAsset = $this->asset($prizeInput['presentation_asset_id'], 'image');
            $publicId = (string) Str::uuid7();
            $content = [
                'gacha_rank_id' => $ranks[$prizeInput['rank_id']], 'presentation_asset_id' => $prizeAsset->id,
                'display_name' => $prizeInput['name'], 'description' => null, 'display_price' => 0,
                'exchange_points' => $prizeInput['exchange_points'], 'shipping_only' => $prizeInput['shipping_only'],
                'cost_price' => $prizeInput['cost_price'], 'is_visible' => true, 'created_at' => $now, 'updated_at' => $now,
            ];
            $prizeId = DB::table('catalog_prizes')->insertGetId($content + [
                'public_id' => $publicId, 'code' => 'prize-'.str_replace('-', '', $publicId), 'gacha_id' => $gacha->id, 'revision' => 1,
            ]);
            $relationId = DB::table('catalog_gacha_version_prizes')->insertGetId($content + [
                'gacha_version_id' => $versionId, 'prize_id' => $prizeId,
                'initial_inventory' => $prizeInput['initial_inventory'], 'sort_order' => $index + 1,
            ]);
            DB::table('prize_inventories')->insert([
                'gacha_version_prize_id' => $relationId, 'gacha_draw_state_id' => null,
                'total_quantity' => $prizeInput['initial_inventory'], 'available_quantity' => $prizeInput['initial_inventory'],
                'awarded_count' => 0, 'withdrawn_quantity' => 0, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($payload['gacha_type'] !== 'standard') {
                $rates[$relationId] = V2FixedPercentage::parse($prizeInput['percentage']);
            }
        }
        if ($payload['gacha_type'] !== 'standard') {
            $this->probabilities->save($versionId, $rates);
            DB::select('SELECT v2_login_validate_composition(?)', [$versionId]);
        }

        return DB::table('catalog_gachas')->where('id', $gacha->id)->firstOrFail();
    }

    private function saveRanks(object $gacha, array $payload, string $now): array
    {
        $ranks = [];
        foreach ($payload['ranks'] as $rankInput) {
            $master = DB::table('catalog_rank_masters')->where('public_id', $rankInput['rank_id'])->lockForUpdate()->first();
            if ($master === null || $master->status !== 'active' || $master->current_revision_id === null) {
                throw $this->invalid();
            }
            $rankRevision = $rankInput['rank_revision_number'] === null ? (int) $master->current_revision_id
                : DB::table('catalog_rank_master_revisions')->where('rank_master_id', $master->id)
                    ->where('revision_number', $rankInput['rank_revision_number'])->value('id');
            if ($rankRevision === null || ($payload['gacha_type'] === 'standard' && (int) $rankRevision !== (int) $master->current_revision_id)) {
                throw $this->invalid();
            }
            $rank = DB::table('catalog_gacha_ranks')->where('gacha_id', $gacha->id)->where('rank_master_id', $master->id)->lockForUpdate()->first();
            $preferred = $payload['gacha_type'] === 'standard' || $rankInput['rank_revision_number'] === null ? null : $rankRevision;
            $rankId = $rank === null ? DB::table('catalog_gacha_ranks')->insertGetId([
                'public_id' => (string) Str::uuid7(), 'gacha_id' => $gacha->id, 'rank_master_id' => $master->id,
                'preferred_rank_revision_id' => $preferred, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]) : (int) $rank->id;
            $videoRevision = null;
            if ($rankInput['video_asset_id'] !== null) {
                $video = $this->asset($rankInput['video_asset_id'], 'video');
                $videoRevision = DB::table('catalog_gacha_rank_video_revisions')->insertGetId([
                    'gacha_rank_id' => $rankId, 'revision_number' => 1 + (int) DB::table('catalog_gacha_rank_video_revisions')->where('gacha_rank_id', $rankId)->max('revision_number'),
                    'video_asset_id' => $video->id, 'created_at' => $now,
                ]);
            }
            if ($rank !== null || $videoRevision !== null) {
                DB::table('catalog_gacha_ranks')->where('id', $rankId)->update([
                    'preferred_rank_revision_id' => $preferred, 'current_video_revision_id' => $videoRevision,
                    'revision' => $rank === null ? 1 : (int) $rank->revision + 1, 'updated_at' => $now,
                ]);
            }
            $ranks[$rankInput['rank_id']] = $rankId;
        }

        return $ranks;
    }

    private function reference(string $table, string $publicId): object
    {
        $row = DB::table($table)->where('public_id', $publicId)->whereNull('archived_at')->where('is_visible', true)->sharedLock()->first();
        if ($row === null) {
            throw $this->invalid();
        }

        return $row;
    }

    private function asset(string $publicId, string $media): object
    {
        $asset = DB::table('catalog_presentation_assets')->where('public_id', $publicId)->whereNull('archived_at')->sharedLock()->first();
        if ($asset === null || $asset->media_type !== $media || ! $asset->is_public) {
            throw $this->invalid();
        }

        return $asset;
    }

    private function invalid(): V2CatalogException
    {
        return new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The complete Gacha composition is invalid.');
    }
}
