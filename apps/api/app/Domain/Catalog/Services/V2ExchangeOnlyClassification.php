<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use Illuminate\Support\Facades\DB;

final class V2ExchangeOnlyClassification
{
    public function assertPublishedSelection(array $input, object $prize, object $relation, object $gacha): void
    {
        if ($gacha->first_published_at !== null && isset($input['banner_id'])
            && $this->resolve($input, $prize) !== (bool) $relation->exchange_only) {
            throw new V2CatalogException('CATALOG_GACHA_POST_PUBLISH_FIELD_IMMUTABLE', 409, 'Published prize classification is immutable.');
        }
    }

    /** Resolve the authoritative current banner, never a browser supplied flag. */
    public function resolve(array $input, ?object $existing = null): bool
    {
        $assetId = $input['presentation_asset_id'] ?? null;
        $externalId = array_key_exists('external_id', $input) ? $input['external_id'] : $existing?->external_id;
        if ($existing !== null && ! isset($input['banner_id']) && $externalId === $existing->external_id
            && DB::table('catalog_presentation_assets')->where('id', $existing->presentation_asset_id)->value('public_id') === $assetId) {
            return $this->validated((bool) $existing->exchange_only, $input, $existing);
        }
        if (isset($input['source_prize_id']) && ! isset($input['banner_id'])) {
            $source = DB::table('catalog_prizes as prize')
                ->join('catalog_presentation_assets as asset', 'asset.id', '=', 'prize.presentation_asset_id')
                ->where('prize.public_id', $input['source_prize_id'])->whereNull('prize.archived_at')
                ->where('asset.public_id', $assetId)->where('prize.external_id', $externalId)
                ->lock('FOR SHARE OF prize')->first(['prize.exchange_only']);
            if ($source === null || ($source->exchange_only && (($input['shipping_only'] ?? false) || ($input['exchange_points'] ?? 0) < 1))) {
                throw new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The copied prize identity or classification is invalid.');
            }

            return (bool) $source->exchange_only;
        }
        $query = DB::table('content_banners as banner')
            ->join('content_versions as version', 'version.banner_id', '=', 'banner.id')
            ->join('content_version_assets as link', 'link.content_version_id', '=', 'version.id')
            ->join('catalog_presentation_assets as asset', 'asset.id', '=', 'link.presentation_asset_id')
            ->where('banner.status', '<>', 'archived')->where('link.usage_type', 'image')
            ->whereRaw('version.version_number = (SELECT MAX(v.version_number) FROM content_versions v WHERE v.banner_id = banner.id)')
            ->where('asset.public_id', $assetId)->whereNull('asset.archived_at')->where('asset.is_public', true);
        if (isset($input['banner_id'])) {
            $query->where('banner.public_id', $input['banner_id']);
        }
        $banners = $query->lock('FOR SHARE OF banner')->get(['banner.id', 'banner.exchange_only', 'banner.external_id']);
        $assetHasBanner = $banners->isNotEmpty();
        if ($externalId !== null || isset($input['banner_id'])) {
            $banners = $banners->where('external_id', $externalId);
        }
        if ($banners->count() > 1 || ($banners->isEmpty() && (isset($input['banner_id']) || $assetHasBanner))) {
            throw new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The selected banner, image and external ID must match unambiguously.');
        }
        if ($banners->isEmpty() && $externalId !== null
            && DB::table('content_banners')->where('external_id', $externalId)->where('status', '<>', 'archived')->exists()) {
            throw new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'The registered banner image must match its external ID.');
        }
        $exchangeOnly = $banners->isEmpty() ? false : (bool) $banners->first()->exchange_only;

        return $this->validated($exchangeOnly, $input, $existing);
    }

    private function validated(bool $exchangeOnly, array $input, ?object $existing): bool
    {
        if ($exchangeOnly && (($input['shipping_only'] ?? $existing?->shipping_only ?? false) || ($input['exchange_points'] ?? 0) < 1)) {
            throw new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'Exchange-only prizes require a positive exchange value and cannot be shipping-only.');
        }

        return $exchangeOnly;
    }
}
