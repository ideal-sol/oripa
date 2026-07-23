<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralSetting extends Model
{
    protected $fillable = [
        'reward_point_amount',
        'reward_expiration_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'reward_point_amount' => 'integer',
            'reward_expiration_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'reward_point_amount' => 0,
                'reward_expiration_days' => (int) config('oripa.free_point_expiration_days', 180),
                'is_active' => true,
            ],
        );
    }
}
