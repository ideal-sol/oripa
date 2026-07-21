<?php

namespace App\Models;

use App\Domain\Gacha\Enums\QaDrawPlanStatus;
use Illuminate\Database\Eloquent\Model;

class QaDrawPlan extends Model
{
    protected $fillable = [
        'user_id',
        'gacha_id',
        'status',
        'title',
        'reason',
        'starts_at',
        'ends_at',
        'created_by_admin_user_id',
        'updated_by_admin_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QaDrawPlanStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function gacha()
    {
        return $this->belongsTo(Gacha::class);
    }

    public function items()
    {
        return $this->hasMany(QaDrawPlanItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function createdByAdminUser()
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_user_id');
    }

    public function updatedByAdminUser()
    {
        return $this->belongsTo(AdminUser::class, 'updated_by_admin_user_id');
    }

    public function drawRequests()
    {
        return $this->hasMany(DrawRequest::class);
    }

    public function executions()
    {
        return $this->hasMany(QaDrawExecution::class);
    }
}
