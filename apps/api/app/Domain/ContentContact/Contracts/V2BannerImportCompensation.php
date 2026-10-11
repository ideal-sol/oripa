<?php

namespace App\Domain\ContentContact\Contracts;

use Illuminate\Support\Facades\DB;

class V2BannerImportCompensation
{
    public function disposition(V2BannerImportExecution $execution): ?string
    {
        $execution = $execution->revalidate();
        if ($execution->row->compensation_status !== null) {
            return 'deferred_canonical_review';
        }
        if (! $execution->row->asset_created) {
            return null;
        }
        $referenced = DB::table('content_version_assets as reference')
            ->join('catalog_presentation_assets as asset', 'asset.id', '=', 'reference.presentation_asset_id')
            ->where('asset.public_id', $execution->row->asset_public_id)->exists();
        return $referenced ? null : 'deferred_canonical_review';
    }
}
