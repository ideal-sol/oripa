<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

final class CatalogProbabilityVersion extends Model
{
    protected $table = 'catalog_probability_versions';

    protected $fillable = [
        'public_id',
        'gacha_version_id',
        'version_number',
        'status',
        'snapshot_sha256',
        'published_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->public_id ??= (string) Str::uuid7();
        });
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') === 'published') {
                throw new LogicException('Published Probability Version is immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->status === 'published') {
                throw new LogicException('Published Probability Version is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }
}
