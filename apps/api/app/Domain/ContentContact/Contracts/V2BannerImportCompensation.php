<?php

namespace App\Domain\ContentContact\Contracts;

use Illuminate\Support\Facades\DB;

class V2BannerImportCompensation
{
    public function disposition(V2BannerImportExecution $execution): ?string
    {
        $execution = $execution->revalidate();
        $operation = DB::table('content_banner_asset_operations')->where('row_public_id', $execution->row->public_id)->lockForUpdate()->first();
        if ($operation !== null) {
            $referenced = DB::table('content_version_assets as reference')
                ->join('catalog_presentation_assets as asset', 'asset.id', '=', 'reference.presentation_asset_id')
                ->where('asset.public_id', $operation->asset_public_id)->exists();
            $now = \App\Support\V2DatabaseTimestamp::format(now());
            DB::table('content_banner_asset_operations')->where('id', $operation->id)->update([
                'status' => $referenced ? 'attached' : $operation->status,
                'manual_review_reason' => $referenced ? null : ($operation->manual_review_reason ?? 'unattached_asset'),
                'resolved_at' => $referenced ? $now : null, 'updated_at' => $now,
            ]);
            return $referenced ? null : 'deferred_canonical_review';
        }
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
