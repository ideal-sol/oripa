<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\V2CatalogException;
use App\Domain\Draw\Services\V2CryptographicRandomSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class V2RankVideoService
{
    public function normalize(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 10) {
            throw $this->invalid();
        }
        $normalized = [];
        $seen = [];
        $total = 0;
        foreach ($items as $item) {
            if (! is_array($item) || count($item) !== 2
                || ! is_string($item['video_asset_id'] ?? null) || ! Str::isUuid($item['video_asset_id'])
                || ! is_string($item['weight_percent'] ?? null)
                || ! preg_match('/^(100(?:\.0{1,2})?|(?:0|[1-9][0-9]?)(?:\.[0-9]{1,2})?)$/D', $item['weight_percent'])) {
                throw $this->invalid();
            }
            $identifier = strtolower($item['video_asset_id']);
            $parts = explode('.', $item['weight_percent']);
            $units = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            if ($units < 1 || isset($seen[$identifier])) {
                throw $this->invalid();
            }
            $seen[$identifier] = true;
            $total += $units;
            $normalized[] = ['video_asset_id' => $identifier, 'weight_percent' => $this->format($units)];
        }
        if ($total !== 10000) {
            throw $this->invalid();
        }

        return $normalized;
    }

    public function create(int $rankId, array $items, string $createdAt): int
    {
        $items = $this->normalize($items);
        $assets = DB::table('catalog_presentation_assets as asset')
            ->join('catalog_rank_effect_materials as material', 'material.presentation_asset_id', '=', 'asset.id')
            ->whereIn('asset.public_id', array_column($items, 'video_asset_id'))
            ->where('asset.media_type', 'video')->where('asset.is_public', true)->whereNull('asset.archived_at')
            ->orderBy('asset.id')->sharedLock()->get(['asset.id', 'asset.public_id'])->keyBy('public_id');
        if ($assets->count() !== count($items)) {
            throw $this->invalid();
        }
        $rows = [];
        foreach ($items as $index => $item) {
            $rows[] = [
                'video_asset_id' => $assets[$item['video_asset_id']]->id,
                'weight_units' => (int) str_replace('.', '', $item['weight_percent']),
                'sort_order' => $index + 1,
            ];
        }
        $representative = $rows[0];
        foreach ($rows as $row) {
            if ($row['weight_units'] > $representative['weight_units']) {
                $representative = $row;
            }
        }
        $revisionId = DB::table('catalog_gacha_rank_video_revisions')->insertGetId([
            'gacha_rank_id' => $rankId,
            'revision_number' => 1 + (int) DB::table('catalog_gacha_rank_video_revisions')->where('gacha_rank_id', $rankId)->max('revision_number'),
            'video_asset_id' => $representative['video_asset_id'], 'video_count' => count($rows), 'created_at' => $createdAt,
        ]);
        if (count($rows) > 1) {
            DB::table('catalog_gacha_rank_video_items')->insert(array_map(
                fn (array $row): array => ['video_revision_id' => $revisionId, ...$row], $rows
            ));
        }

        return (int) $revisionId;
    }

    public function load(array $revisionIds): array
    {
        $sets = [];
        $rows = DB::table('catalog_gacha_rank_video_items as item')
            ->join('catalog_presentation_assets as asset', 'asset.id', '=', 'item.video_asset_id')
            ->whereIn('item.video_revision_id', array_unique(array_filter($revisionIds)))
            ->orderBy('item.video_revision_id')->orderBy('item.sort_order')
            ->get(['item.*', 'asset.public_id', 'asset.public_path', 'asset.checksum_sha256', 'asset.media_type', 'asset.mime_type', 'asset.alt_text']);
        foreach ($rows as $row) {
            $sets[(int) $row->video_revision_id][] = [
                'id' => (int) $row->id, 'weight_units' => (int) $row->weight_units, 'sort_order' => (int) $row->sort_order,
                'video' => [
                    'id' => $row->public_id, 'path' => '/api/v2/catalog/presentation-assets/'.$row->public_id.'/content',
                    'checksum_sha256' => $row->checksum_sha256, 'media_type' => $row->media_type,
                    'mime_type' => $row->mime_type, 'alt_text' => $row->alt_text,
                ],
                'admin_video' => ['id' => $row->public_id, 'path' => $row->public_path ?? '/api/v2/catalog/presentation-assets/'.$row->public_id.'/content',
                    'mime_type' => $row->mime_type, 'alt_text' => $row->alt_text],
            ];
        }

        return $sets;
    }

    public function admin(array $items): array
    {
        return array_map(fn (array $item): array => [
            'video' => $item['admin_video'], 'weight_percent' => $this->format($item['weight_units']), 'sort_order' => $item['sort_order'],
        ], $items);
    }

    public function input(array $items): array
    {
        return array_map(fn (array $item): array => [
            'video_asset_id' => $item['video']['id'], 'weight_percent' => $this->format($item['weight_units']),
        ], $items);
    }

    public function select(array $items, V2CryptographicRandomSource $random): array
    {
        if ($items === [] || array_sum(array_column($items, 'weight_units')) !== 10000) {
            throw $this->invalid();
        }
        $ticket = count($items) === 1 ? 1 : $random->integer(1, 10000);
        foreach ($items as $item) {
            $ticket -= $item['weight_units'];
            if ($ticket <= 0) {
                return $item;
            }
        }
        throw $this->invalid();
    }

    public function audit(array $items, int $revision): array
    {
        $data = ['revision' => $revision, 'video_count' => count($items)];
        foreach ($items as $index => $item) {
            $data['video_'.($index + 1).'_id'] = $item['video_asset_id'];
            $data['video_'.($index + 1).'_weight'] = $item['weight_percent'];
        }

        return $data;
    }

    private function format(int $units): string
    {
        return intdiv($units, 100).'.'.str_pad((string) ($units % 100), 2, '0', STR_PAD_LEFT);
    }

    private function invalid(): V2CatalogException
    {
        return new V2CatalogException('CATALOG_MUTATION_INVALID', 422, 'Rank videos require 1–10 distinct materials totaling 100.00%.');
    }
}
