<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RankAsset extends Model
{
    protected $fillable = [
        'title',
        'asset_type',
        'url',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
