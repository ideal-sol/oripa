<?php

namespace App\Models;

use App\Domain\Point\Enums\PointType;
use Illuminate\Database\Eloquent\Model;

class PointAdjustment extends Model
{
    protected $fillable = [
        'user_id',
        'admin_user_id',
        'adjustment_type',
        'point_type',
        'amount',
        'expire_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'point_type' => PointType::class,
            'expire_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class);
    }
}
