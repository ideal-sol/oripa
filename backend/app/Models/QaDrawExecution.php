<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QaDrawExecution extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'qa_test_user_mode_id',
        'qa_draw_plan_id',
        'draw_request_id',
        'user_id',
        'gacha_id',
        'draw_count',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function mode()
    {
        return $this->belongsTo(QaTestUserMode::class, 'qa_test_user_mode_id');
    }

    public function plan()
    {
        return $this->belongsTo(QaDrawPlan::class, 'qa_draw_plan_id');
    }

    public function drawRequest()
    {
        return $this->belongsTo(DrawRequest::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }
}
