<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GachaRank extends Model
{
    use HasFactory;

    protected $fillable = [
        'gacha_id',
        'rank_key',
        'display_name',
        'description',
        'image_url',
        'rank_image_asset_id',
        'draw_video_url',
        'draw_video_asset_id',
        'result_image_url',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
        ];
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }

    public function prizes()
    {
        return $this->hasMany(GachaPrize::class, 'rank_id');
    }

    public function rankImageAsset()
    {
        return $this->belongsTo(RankAsset::class, 'rank_image_asset_id');
    }

    public function rankImageAssets()
    {
        return $this->belongsToMany(RankAsset::class, 'gacha_rank_assets')
            ->withPivot(['usage_type', 'sort_order'])
            ->wherePivot('usage_type', 'image')
            ->orderByPivot('sort_order')
            ->orderBy('rank_assets.id')
            ->withTimestamps();
    }

    public function drawVideoAsset()
    {
        return $this->belongsTo(RankAsset::class, 'draw_video_asset_id');
    }

    public function drawVideoAssets()
    {
        return $this->belongsToMany(RankAsset::class, 'gacha_rank_assets')
            ->withPivot(['usage_type', 'sort_order'])
            ->wherePivot('usage_type', 'video')
            ->orderByPivot('sort_order')
            ->orderBy('rank_assets.id')
            ->withTimestamps();
    }

    public function effectiveImageUrl(): ?string
    {
        if ($this->relationLoaded('rankImageAssets')) {
            $asset = $this->rankImageAssets->firstWhere('is_active', true);

            if ($asset) {
                return $asset->url;
            }
        }

        return $this->rankImageAsset?->url ?? $this->image_url;
    }

    public function effectiveDrawVideoUrl(): ?string
    {
        if ($this->relationLoaded('drawVideoAssets')) {
            $asset = $this->drawVideoAssets->firstWhere('is_active', true);

            if ($asset) {
                return $asset->url;
            }
        }

        return $this->drawVideoAsset?->url ?? $this->draw_video_url;
    }
}
