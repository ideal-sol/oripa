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
        'management_status',
        'first_published_at',
        'scheduled_start_at',
        'current_publish_start_at',
        'current_title',
        'current_description',
        'current_notices',
        'current_presentation_asset_id',
        'current_publish_end_at',
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
            'first_published_at' => 'immutable_datetime',
            'scheduled_start_at' => 'immutable_datetime',
            'current_publish_start_at' => 'immutable_datetime',
            'current_publish_end_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }
}
