<?php

namespace App\Models\V2;

use App\Domain\Catalog\Services\V2GachaPublicCodeGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CatalogGacha extends Model
{
    protected $table = 'catalog_gachas';

    protected $fillable = [
        'public_id',
        'public_code',
        'code',
        'slug',
        'category_id',
        'state',
        'sold_count',
        'published_version_id',
        'revision',
        'archived_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $gacha): void {
            $gacha->public_id ??= (string) Str::uuid7();
            $gacha->public_code ??= app(V2GachaPublicCodeGenerator::class)->unique();
        });
    }

    protected function casts(): array
    {
        return [
            'sold_count' => 'integer',
            'revision' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
