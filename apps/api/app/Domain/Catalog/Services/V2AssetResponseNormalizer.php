<?php

namespace App\Domain\Catalog\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class V2AssetResponseNormalizer
{
    private const PATH_FIELDS = ['path', 'public_path', 'content_path', 'public_url'];

    public function __construct(private readonly V2AssetPublicPathResolver $resolver) {}

    public function normalize(array $response): array
    {
        if (! $this->resolver->enabled()) {
            return $response;
        }
        $ids = [];
        $this->collect($response, $ids);
        if ($ids === []) {
            return $response;
        }
        $keys = DB::table('catalog_presentation_assets')
            ->whereIn('public_id', array_keys($ids))
            ->pluck('storage_identifier', 'public_id')->all();

        return $this->rewrite($response, $keys);
    }

    private function isAsset(array $value): bool
    {
        return (isset($value['mime_type']) || isset($value['checksum_sha256'])
                || array_key_exists('public_path', $value) || array_key_exists('public_url', $value))
            && array_intersect(self::PATH_FIELDS, array_keys($value)) !== [];
    }

    private function collect(array $value, array &$ids): void
    {
        if ($this->isAsset($value)) {
            if (! isset($value['id']) || ! is_string($value['id'])
                || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value['id'])) {
                throw new InvalidArgumentException('Invalid Asset identity.');
            }
            $ids[strtolower($value['id'])] = true;
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collect($child, $ids);
            }
        }
    }

    private function rewrite(array $value, array $keys): array
    {
        if ($this->isAsset($value)) {
            $key = $keys[strtolower($value['id'])] ?? null;
            if (! is_string($key)) {
                throw new InvalidArgumentException('Asset identity could not be resolved.');
            }
            foreach (self::PATH_FIELDS as $field) {
                if (array_key_exists($field, $value)) {
                    $value[$field] = $field === 'public_url'
                        ? $this->resolver->url($key) : $this->resolver->path($key);
                }
            }
        }
        foreach ($value as $field => $child) {
            if (is_array($child)) {
                $value[$field] = $this->rewrite($child, $keys);
            }
        }
        if (array_key_exists('image_url', $value) && isset($value['asset']['path'])) {
            $value['image_url'] = $value['asset']['path'];
        }

        return $value;
    }
}
