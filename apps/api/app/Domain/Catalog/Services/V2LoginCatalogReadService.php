<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Draw\Services\V2DrawEligibilityService;
use App\Models\V2\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class V2LoginCatalogReadService
{
    public function listing(): array
    {
        return ['items' => $this->query(CarbonImmutable::now())->orderBy('gacha.public_id')
            ->get()->map(fn (object $row): array => $this->summary($row))->all()];
    }

    public function detail(string $publicId, User $user): array
    {
        $occurredAt = CarbonImmutable::now()->startOfSecond();
        $row = $this->query($occurredAt)->where('gacha.public_id', $publicId)->first();
        if ($row === null) {
            throw new V2CatalogException('CATALOG_NOT_FOUND', 404, 'The login Gacha is unavailable.');
        }
        $version = (object) ['publish_start_at' => $row->publish_start_at];
        $eligibility = app(V2DrawEligibilityService::class)->evaluateLogin($user, $row, $version, $occurredAt);
        $prizes = [];
        $ranks = [];
        $relations = DB::table('catalog_gacha_version_prizes as relation')
            ->join('catalog_prizes as prize', 'prize.id', '=', 'relation.prize_id')
            ->join('catalog_gacha_ranks as rank', 'rank.id', '=', 'relation.gacha_rank_id')
            ->join('catalog_rank_masters as master', 'master.id', '=', 'rank.rank_master_id')
            ->join('catalog_rank_master_revisions as revision', 'revision.id', '=', 'relation.published_rank_revision_id')
            ->join('catalog_gacha_rank_video_revisions as video', 'video.id', '=', 'relation.published_video_revision_id')
            ->where('relation.gacha_version_id', $row->version_id)->orderBy('revision.display_order')->orderBy('relation.sort_order')
            ->get(['relation.*', 'prize.public_id as prize_public_id', 'master.public_id as rank_public_id',
                'revision.rank_name', 'revision.lineup_image_asset_id', 'revision.result_image_asset_id', 'video.video_asset_id']);
        foreach ($relations as $relation) {
            $prizes[] = [
                'id' => $relation->prize_public_id, 'name' => $relation->display_name,
                'exchange_points' => (int) $relation->exchange_points, 'rank_id' => $relation->rank_public_id,
                'asset' => $this->asset($relation->presentation_asset_id),
            ];
            $ranks[$relation->rank_public_id] = [
                'id' => $relation->rank_public_id, 'name' => $relation->rank_name,
                'lineup_image' => $this->asset($relation->lineup_image_asset_id),
                'result_image' => $this->asset($relation->result_image_asset_id), 'video' => $this->asset($relation->video_asset_id),
            ];
        }

        return ['data' => $this->summary($row) + [
            'description' => $row->description, 'notices' => $row->notices, 'eligibility' => $eligibility,
            'prizes' => $prizes, 'ranks' => array_values($ranks),
        ]];
    }

    private function query(CarbonImmutable $occurredAt): Builder
    {
        return DB::table('catalog_gachas as gacha')
            ->join('catalog_gacha_versions as version', 'version.id', '=', 'gacha.published_version_id')
            ->join('catalog_probability_versions as probability', 'probability.id', '=', 'version.published_probability_version_id')
            ->join('gacha_draw_states as state', 'state.id', '=', 'gacha.active_draw_state_id')
            ->whereIn('gacha.gacha_type', ['login_daily', 'signup_once'])->where('gacha.management_status', 'published')
            ->where('gacha.state', 'active')->where('gacha.sales_paused', false)->whereNull('gacha.archived_at')
            ->where('version.status', 'published')->whereNull('version.archived_at')
            ->where('probability.status', 'published')->whereNull('probability.archived_at')
            ->whereColumn('version.gacha_id', 'gacha.id')->whereColumn('state.gacha_id', 'gacha.id')
            ->whereColumn('probability.gacha_version_id', 'version.id')->whereColumn('state.gacha_version_id', 'version.id')
            ->whereColumn('state.probability_version_id', 'probability.id')->where('state.status', 'selling')
            ->where('version.publish_start_at', '<=', $occurredAt->toIso8601String())
            ->where(fn (Builder $query) => $query->whereNull('version.publish_end_at')->orWhere('version.publish_end_at', '>', $occurredAt->toIso8601String()))
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('catalog_gacha_version_prizes as relation')->whereColumn('relation.gacha_version_id', 'version.id'))
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('catalog_gacha_version_prizes as relation')
                    ->leftJoin('prize_inventories as inventory', 'inventory.gacha_version_prize_id', '=', 'relation.id')
                    ->whereColumn('relation.gacha_version_id', 'version.id')
                    ->where(fn (Builder $invalid) => $invalid->whereNull('inventory.id')->orWhereNull('inventory.gacha_draw_state_id')->orWhere('inventory.available_quantity', '<=', 0)
                        ->orWhereColumn('inventory.gacha_draw_state_id', '<>', 'state.id'));
            })
            ->select(['gacha.id', 'gacha.public_id', 'gacha.public_code', 'gacha.gacha_type', 'version.id as version_id',
                'version.title', 'version.description', 'version.notices', 'version.price_points',
                'version.presentation_asset_id', 'version.publish_start_at', 'version.publish_end_at']);
    }

    private function summary(object $row): array
    {
        return [
            'id' => $row->public_id, 'public_code' => $row->public_code, 'gacha_type' => $row->gacha_type,
            'title' => $row->title, 'thumbnail' => $this->asset($row->presentation_asset_id), 'price_points' => (int) $row->price_points,
            'publish_start_at' => CarbonImmutable::parse($row->publish_start_at)->toIso8601ZuluString(),
            'publish_end_at' => $row->publish_end_at === null ? null : CarbonImmutable::parse($row->publish_end_at)->toIso8601ZuluString(),
            'availability' => 'available',
        ];
    }

    private function asset(?int $assetId): ?array
    {
        $asset = $assetId === null ? null : DB::table('catalog_presentation_assets')->where('id', $assetId)
            ->where('is_public', true)->whereNull('archived_at')->first();
        if ($asset === null) {
            return null;
        }

        return [
            'id' => $asset->public_id, 'path' => $asset->media_type === 'video'
                ? '/api/v2/catalog/presentation-assets/'.$asset->public_id.'/content' : '/api/v2/content/assets/'.$asset->public_id,
            'checksum_sha256' => $asset->checksum_sha256, 'media_type' => $asset->media_type,
            'mime_type' => $asset->mime_type, 'alt_text' => $asset->alt_text,
        ];
    }
}
