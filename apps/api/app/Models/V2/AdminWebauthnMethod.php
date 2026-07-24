<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class AdminWebauthnMethod extends Model
{
    protected $table = 'admin_webauthn_credentials';

    protected $fillable = [
        'admin_id',
        'credential_id',
        'public_key',
        'sign_count',
        'label',
        'transports',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'credential_id',
        'public_key',
    ];

    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'transports' => 'array',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
