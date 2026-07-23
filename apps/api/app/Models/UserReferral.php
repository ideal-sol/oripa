<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReferral extends Model
{
    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'referral_code',
        'status',
        'reward_point_amount',
        'reward_expiration_days',
        'rewarded_at',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'reward_point_amount' => 'integer',
            'reward_expiration_days' => 'integer',
            'rewarded_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
