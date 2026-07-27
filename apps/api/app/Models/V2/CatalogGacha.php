<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CatalogGacha extends Model
{
    protected $table = 'catalog_gachas';

    protected $fillable = [
        'public_id',
        'code',
        'slug',
        'category_id',
        'state',
        'sold_count',
        'published_version_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $gacha): void {
            $gacha->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'sold_count' => 'integer',
        ];
    }
}
