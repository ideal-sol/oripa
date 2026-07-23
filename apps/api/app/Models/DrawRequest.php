<?php

namespace App\Models;

use App\Domain\Gacha\Enums\DrawRequestStatus;
use Illuminate\Database\Eloquent\Model;

class DrawRequest extends Model
{
    protected $fillable = [
        'user_id',
        'gacha_id',
        'draw_count',
        'idempotency_key',
        'status',
        'consumed_point_total',
        'is_qa_draw',
        'qa_test_user_mode_id',
        'qa_draw_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DrawRequestStatus::class,
            'is_qa_draw' => 'boolean',
        ];
    }

    public function results()
    {
        return $this->hasMany(DrawResult::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }

    public function qaTestUserMode()
    {
        return $this->belongsTo(QaTestUserMode::class);
    }

    public function qaDrawPlan()
    {
        return $this->belongsTo(QaDrawPlan::class);
    }

    public function qaDrawExecution()
    {
        return $this->hasOne(QaDrawExecution::class);
    }
}
