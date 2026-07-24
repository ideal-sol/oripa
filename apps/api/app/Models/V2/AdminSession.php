<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class AdminSession extends Model
{
    protected $table = 'admin_sessions';
    protected $primaryKey = 'session_id_hash';
    public $incrementing = false;
    public $timestamps = false;
    protected $keyType = 'string';

    protected $fillable = [
        'session_id_hash',
        'admin_id',
        'mfa_verified_at',
        'last_activity_at',
        'idle_expires_at',
        'absolute_expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'session_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'mfa_verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'idle_expires_at' => 'immutable_datetime',
            'absolute_expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
