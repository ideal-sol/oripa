<?php

namespace App\Models;

use App\Domain\Admin\Enums\AdminRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class AdminUser extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => AdminRole::class,
            'is_active' => 'boolean',
            'email_verified_at' => 'datetime',
        ];
    }

    public function paymentReversals()
    {
        return $this->hasMany(PaymentReversal::class);
    }

    public function enabledQaTestUserModes()
    {
        return $this->hasMany(QaTestUserMode::class, 'enabled_by_admin_user_id');
    }

    public function disabledQaTestUserModes()
    {
        return $this->hasMany(QaTestUserMode::class, 'disabled_by_admin_user_id');
    }

    public function createdQaDrawPlans()
    {
        return $this->hasMany(QaDrawPlan::class, 'created_by_admin_user_id');
    }

    public function updatedQaDrawPlans()
    {
        return $this->hasMany(QaDrawPlan::class, 'updated_by_admin_user_id');
    }
}
