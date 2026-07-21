<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QaTestUserMode extends Model
{
    protected $fillable = [
        'user_id',
        'is_enabled',
        'reason',
        'starts_at',
        'ends_at',
        'enabled_by_admin_user_id',
        'disabled_by_admin_user_id',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function enabledByAdminUser()
    {
        return $this->belongsTo(AdminUser::class, 'enabled_by_admin_user_id');
    }

    public function disabledByAdminUser()
    {
        return $this->belongsTo(AdminUser::class, 'disabled_by_admin_user_id');
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
