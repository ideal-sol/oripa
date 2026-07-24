<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class UserRememberDevice extends Model
{
    protected $table = 'user_remember_devices';

    protected $fillable = [
        'user_id',
        'selector',
        'token_hash',
        'rotation_counter',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'replay_detected_at',
    ];

    protected $hidden = [
        'selector',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'rotation_counter' => 'integer',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'replay_detected_at' => 'immutable_datetime',
        ];
    }
}
