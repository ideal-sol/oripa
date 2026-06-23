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

    public function imageRanks()
    {
        return $this->belongsToMany(GachaRank::class, 'gacha_rank_assets')
            ->withPivot(['usage_type', 'sort_order'])
            ->wherePivot('usage_type', 'image')
            ->withTimestamps();
    }

    public function videoRanks()
    {
        return $this->belongsToMany(GachaRank::class, 'gacha_rank_assets')
            ->withPivot(['usage_type', 'sort_order'])
            ->wherePivot('usage_type', 'video')
            ->withTimestamps();
    }
}
