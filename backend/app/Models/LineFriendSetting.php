<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineFriendSetting extends Model
{
    protected $fillable = [
        'friend_add_url',
        'reward_point_amount',
        'reward_expiration_days',
        'is_active',
        'auto_reply_message',
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
                'friend_add_url' => config('services.line.friend_add_url'),
                'reward_point_amount' => 0,
                'reward_expiration_days' => (int) config('oripa.free_point_expiration_days', 180),
                'is_active' => true,
                'auto_reply_message' => 'LINE連携コードを送信してください。',
            ],
        );
    }
}
