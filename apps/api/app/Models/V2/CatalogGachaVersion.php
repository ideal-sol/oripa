<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

final class CatalogGachaVersion extends Model
{
    protected $table = 'catalog_gacha_versions';

    protected $fillable = [
        'public_id',
        'gacha_id',
        'version_number',
        'status',
        'title',
        'description',
        'notices',
        'price_points',
        'total_count',
        'presentation_asset_id',
        'published_probability_version_id',
        'publish_start_at',
        'publish_end_at',
        'published_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->public_id ??= (string) Str::uuid7();
        });
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') === 'published') {
                throw new LogicException('Published Gacha Version is immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->status === 'published') {
                throw new LogicException('Published Gacha Version is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'price_points' => 'integer',
            'total_count' => 'integer',
            'publish_start_at' => 'immutable_datetime',
            'publish_end_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
