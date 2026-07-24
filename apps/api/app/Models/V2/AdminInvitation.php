<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class AdminInvitation extends Model
{
    public $timestamps = false;

    protected $table = 'admin_invitations';

    protected $fillable = [
        'admin_id',
        'token_hash',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
