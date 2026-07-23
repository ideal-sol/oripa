<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineFriendLink extends Model
{
    protected $fillable = [
        'user_id',
        'line_user_id',
        'status',
        'link_code',
        'reward_point_amount',
        'followed_at',
        'linked_at',
        'blocked_at',
        'rewarded_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reward_point_amount' => 'integer',
            'followed_at' => 'datetime',
            'linked_at' => 'datetime',
            'blocked_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
