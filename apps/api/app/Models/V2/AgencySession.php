<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class AgencySession extends Model
{
    protected $table = 'agency_sessions';
    protected $primaryKey = 'session_id_hash';
    public $incrementing = false;
    public $timestamps = false;
    protected $keyType = 'string';
    protected $guarded = ['*'];
    protected $hidden = ['session_id_hash'];

    protected function casts(): array
    {
        return array_fill_keys([
            'created_at', 'last_activity_at', 'idle_expires_at', 'absolute_expires_at', 'revoked_at',
        ], 'immutable_datetime');
    }
}
