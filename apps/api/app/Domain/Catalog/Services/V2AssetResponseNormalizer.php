<?php

namespace App\Domain\Catalog\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class V2AssetResponseNormalizer
{
    private const PATH_FIELDS = ['path', 'public_path', 'content_path', 'public_url'];

    private const ASSET_FIELDS = [
        'asset', 'presentation_asset', 'lineup_image', 'result_image', 'current_video',
        'result_image_snapshot', 'rank_lineup_image', 'video_snapshot',
        'thumbnail', 'image_asset', 'video_asset',
    ];

    public function __construct(private readonly V2AssetPublicPathResolver $resolver) {}

    public function normalize(array $response, ?bool $assetResource = null): array
    {
        if (! $this->resolver->enabled()) {
            return $response;
        }
        $assetResource ??= array_diff(['id', 'public_url', 'mime_type', 'byte_size'], array_keys($response)) === [];
        $ids = [];
        $this->collect($response, $ids, $assetResource);
        if ($ids === []) {
            return $response;
        }
        $keys = DB::table('catalog_presentation_assets')
            ->whereIn('public_id', array_keys($ids))
            ->pluck('storage_identifier', 'public_id')->all();

        return $this->rewrite($response, $keys, $assetResource);
    }

    private function isAsset(array $value, bool $assetResource, string $field, string $parent): bool
    {
        if (array_intersect(self::PATH_FIELDS, array_keys($value)) === []) {
            return false;
        }

        return in_array($field, self::ASSET_FIELDS, true)
            || ($field === 'image' && in_array($parent, ['presentation', 'prize', 'animation'], true))
            || ($field === 'video' && in_array($parent, ['animation', 'ranks', 'videos'], true))
            || ($field === 'videos' && $parent === 'ranks')
            || ($assetResource && in_array($field, ['', 'data', 'items'], true));
    }

    private function collect(array $value, array &$ids, bool $assetResource, string $field = '', string $parent = ''): void
    {
        if ($this->isAsset($value, $assetResource, $field, $parent)) {
            if (! isset($value['id']) || ! is_string($value['id'])
                || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value['id'])) {
                throw new InvalidArgumentException('Invalid Asset identity.');
            }
            $ids[strtolower($value['id'])] = true;
        }
        foreach ($value as $name => $child) {
            if (is_array($child)) {
                $this->collect($child, $ids, $assetResource,
                    is_string($name) ? $name : $field, is_string($name) ? $field : $parent);
            }
        }
    }

    private function rewrite(array $value, array $keys, bool $assetResource, string $field = '', string $parent = ''): array
    {
        if ($this->isAsset($value, $assetResource, $field, $parent)) {
            $key = $keys[strtolower($value['id'])] ?? null;
            if (! is_string($key)) {
                throw new InvalidArgumentException('Asset identity could not be resolved.');
            }
            foreach (self::PATH_FIELDS as $pathField) {
                if (array_key_exists($pathField, $value)) {
                    $value[$pathField] = $pathField === 'public_url'
                        ? $this->resolver->url($key) : $this->resolver->path($key);
                }
            }
        }
        foreach ($value as $name => $child) {
            if (is_array($child)) {
                $value[$name] = $this->rewrite($child, $keys, $assetResource,
                    is_string($name) ? $name : $field, is_string($name) ? $field : $parent);
            }
        }
        if (array_key_exists('image_url', $value) && isset($value['asset']['path'])) {
            $value['image_url'] = $value['asset']['path'];
        }

        return $value;
    }
}
