<?php

namespace App\Domain\PrizeShipping\Services;

final class V2AdminPrizeSnapshotAsset
{
    public static function fromSnapshot(?array $asset): ?array
    {
        if ($asset === null || ! is_string($asset['id'] ?? null)) {
            return null;
        }
        $path = is_string($asset['path'] ?? null) ? $asset['path'] : null;

        return [
            'id' => $asset['id'],
            'media_type' => (string) ($asset['media_type'] ?? ''),
            'mime_type' => (string) ($asset['mime_type'] ?? ''),
            'alt_text' => is_string($asset['alt_text'] ?? null) ? $asset['alt_text'] : null,
            'public_path' => $path,
            'is_public' => $path !== null,
        ];
    }
}
