<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;

final class V2GachaPrizeRules
{
    public static function assertRankReady(object $master, ?object $gachaRank): void
    {
        if ($master->status !== 'active') {
            throw new V2CatalogException('CATALOG_RANK_INACTIVE', 409, 'Inactive Rank Masters cannot receive Prizes.');
        }
        if ($gachaRank === null || $gachaRank->current_video_revision_id === null) {
            throw new V2CatalogException('CATALOG_GACHA_RANK_VIDEO_REQUIRED', 409, 'Select an animation video before registering a Prize.');
        }
    }

    public static function assertCapacity(int $snapshot, int $operational, int $totalCount): void
    {
        if ($snapshot > $totalCount || $operational > $totalCount) {
            throw new V2CatalogException('CATALOG_GACHA_INVENTORY_TOTAL_CONFLICT', 409, 'Aggregate Prize inventory cannot exceed the Gacha total count.');
        }
    }
}
