<?php

namespace App\Domain\ContentContact\Services;

use App\Domain\ContentContact\Contracts\V2BannerImportExecution;
use App\Domain\ContentContact\Exceptions\V2ContentContactException;
use App\Support\V2DatabaseTimestamp;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class V2BannerImportCanonicalAdapter
{
    public function __construct(private readonly V2ContentContactAdminService $canonical) {}

    public function recover(V2BannerImportExecution $execution): bool
    {
        $execution = $execution->revalidate();
        if (! $execution->row->fetch_image || $execution->row->asset_public_id !== null) {
            return true;
        }
        $id = DB::table('idempotency_records')->where('scope', 'content_banner_mutation')
            ->where('actor_type', 'admin')->where('actor_public_id', $execution->admin->public_id)
            ->where('key_hash', hash('sha256', 'banner-import-asset:'.$execution->row->public_id))
            ->where('status', 'completed')->where('resource_type', 'catalog_presentation_asset')->value('resource_public_id');
        if ($id === null) {
            return false;
        }
        $asset = DB::table('catalog_presentation_assets')->where('public_id', $id)->whereNull('archived_at')->first();
        if ($asset === null) {
            throw new V2ContentContactException('BANNER_IMPORT_ASSET_UNAVAILABLE', 409, '登録済み画像を確認してください。');
        }
        $this->recordAsset($execution, $id, $asset->checksum_sha256, true);
        return true;
    }

    public function register(V2BannerImportExecution $execution, #[SensitiveParameter] array $image): void
    {
        $execution = $execution->revalidate();
        if ($this->recover($execution)) {
            return;
        }
        $banner = DB::table('content_banners')->where('external_id', $execution->row->external_id)
            ->where('status', '<>', 'archived')->lockForUpdate()->first();
        $asset = null;
        if ($banner !== null) {
            $version = DB::table('content_versions')->where('banner_id', $banner->id)->orderByDesc('version_number')->value('id');
            $asset = DB::table('content_version_assets as link')->join('catalog_presentation_assets as asset', 'asset.id', '=', 'link.presentation_asset_id')
                ->where('link.content_version_id', $version)->where('link.usage_type', 'image')->whereNull('asset.archived_at')
                ->first(['asset.public_id', 'asset.checksum_sha256']);
        }
        if ($asset !== null && hash_equals($asset->checksum_sha256, $image['checksum_sha256'])) {
            $this->recordAsset($execution, $asset->public_id, $image['checksum_sha256'], false);
            return;
        }
        $uploaded = $this->canonical->uploadBannerImportAsset($execution, [
            'mime_type' => $image['mime_type'], 'content_base64' => base64_encode($image['bytes']),
        ]);
        $execution = $execution->revalidate();
        $this->recordAsset($execution, $uploaded['id'], $image['checksum_sha256'], true);
    }

    public function save(V2BannerImportExecution $execution): array
    {
        $execution = $execution->revalidate();
        $result = $this->canonical->saveBannerImportRow($execution);
        $execution->revalidate();
        return $result;
    }

    private function recordAsset(V2BannerImportExecution $execution, string $id, string $checksum, bool $created): void
    {
        DB::table('content_banner_import_rows')->where('id', $execution->row->id)->update([
            'asset_public_id' => $id, 'asset_created' => $created, 'image_sha256' => $checksum,
            'compensation_status' => $execution->row->compensation_status === 'registration_started'
                ? null : $execution->row->compensation_status,
            'status' => 'asset_registered', 'url_ciphertext' => null,
            'url_deleted_at' => V2DatabaseTimestamp::format(now()), 'updated_at' => V2DatabaseTimestamp::format(now()),
        ]);
    }
}
