<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CatalogPrize extends Model
{
    protected $table = 'catalog_prizes';

    protected $fillable = [
        'public_id',
        'code',
        'rank_id',
        'presentation_asset_id',
        'display_name',
        'description',
        'display_price',
        'exchange_points',
        'is_visible',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $prize): void {
            $prize->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'display_price' => 'integer',
            'exchange_points' => 'integer',
            'is_visible' => 'boolean',
        ];
    }
}
