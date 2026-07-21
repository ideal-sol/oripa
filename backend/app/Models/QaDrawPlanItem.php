<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QaDrawPlanItem extends Model
{
    protected $fillable = [
        'qa_draw_plan_id',
        'sort_order',
        'gacha_prize_id',
        'quantity',
        'consumed_count',
        'rank_image_asset_id',
        'draw_video_asset_id',
    ];

    public function plan()
    {
        return $this->belongsTo(QaDrawPlan::class, 'qa_draw_plan_id');
    }

    public function prize()
    {
        return $this->belongsTo(GachaPrize::class, 'gacha_prize_id');
    }

    public function rankImageAsset()
    {
        return $this->belongsTo(RankAsset::class, 'rank_image_asset_id');
    }

    public function drawVideoAsset()
    {
        return $this->belongsTo(RankAsset::class, 'draw_video_asset_id');
    }

    public function drawResults()
    {
        return $this->hasMany(DrawResult::class);
    }
}
