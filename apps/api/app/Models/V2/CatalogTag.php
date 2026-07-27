<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CatalogTag extends Model
{
    protected $table = 'catalog_tags';

    protected $fillable = [
        'public_id',
        'code',
        'slug',
        'display_name',
        'sort_order',
        'is_visible',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tag): void {
            $tag->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }
}
