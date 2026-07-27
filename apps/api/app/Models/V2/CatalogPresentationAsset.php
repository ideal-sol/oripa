<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CatalogPresentationAsset extends Model
{
    protected $table = 'catalog_presentation_assets';

    protected $fillable = [
        'public_id',
        'storage_identifier',
        'public_path',
        'checksum_sha256',
        'media_type',
        'mime_type',
        'byte_size',
        'alt_text',
        'is_public',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            $asset->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'is_public' => 'boolean',
        ];
    }
}
