<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CatalogCategory extends Model
{
    protected $table = 'catalog_categories';

    protected $fillable = [
        'public_id',
        'code',
        'slug',
        'display_name',
        'description',
        'sort_order',
        'is_visible',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            $category->public_id ??= (string) Str::uuid7();
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
