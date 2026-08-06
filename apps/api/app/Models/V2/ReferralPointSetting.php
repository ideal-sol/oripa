<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class ReferralPointSetting extends Model
{
    protected $table = 'referral_point_settings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'referrer_point_amount' => 'integer',
            'referred_user_point_amount' => 'integer',
            'reward_expiration_days' => 'integer',
            'revision' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
