<?php

namespace App\Models;

use App\Domain\Gacha\Enums\DrawResultType;
use Illuminate\Database\Eloquent\Model;

class DrawResult extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'draw_request_id',
        'user_id',
        'gacha_id',
        'draw_sequence_number',
        'rank_id',
        'prize_id',
        'result_type',
        'consumed_point',
        'granted_point',
        'random_value',
        'probability_version_id',
        'probability_version_stage_id',
        'selected_rank_image_url',
        'selected_draw_video_url',
        'is_qa_draw',
        'qa_draw_plan_item_id',
    ];

    protected function casts(): array
    {
        return [
            'result_type' => DrawResultType::class,
            'is_qa_draw' => 'boolean',
        ];
    }

    public function drawRequest()
    {
        return $this->belongsTo(DrawRequest::class);
    }

    public function userPrize()
    {
        return $this->hasOne(UserPrize::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }

    public function rank()
    {
        return $this->belongsTo(GachaRank::class, 'rank_id');
    }

    public function prize()
    {
        return $this->belongsTo(GachaPrize::class, 'prize_id');
    }

    public function probabilityVersion()
    {
        return $this->belongsTo(GachaProbabilityVersion::class, 'probability_version_id');
    }

    public function probabilityVersionStage()
    {
        return $this->belongsTo(GachaProbabilityVersionStage::class, 'probability_version_stage_id');
    }

    public function qaDrawPlanItem()
    {
        return $this->belongsTo(QaDrawPlanItem::class);
    }
}
