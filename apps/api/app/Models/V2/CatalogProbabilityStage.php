<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class CatalogProbabilityStage extends Model
{
    protected $table = 'catalog_probability_stages';

    protected $fillable = [
        'public_id',
        'probability_version_id',
        'code',
        'display_name',
        'condition_type',
        'min_draw_number',
        'max_draw_number',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $stage): void {
            $stage->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'min_draw_number' => 'integer',
            'max_draw_number' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
