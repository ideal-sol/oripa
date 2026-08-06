<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class UserReferral extends Model
{
    protected $table = 'user_referrals';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'reward_enabled' => 'boolean',
            'referrer_point_amount' => 'integer',
            'referred_user_point_amount' => 'integer',
            'reward_expiration_days' => 'integer',
            'rewarded_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
