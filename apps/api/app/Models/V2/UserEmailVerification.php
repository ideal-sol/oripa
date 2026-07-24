<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class UserEmailVerification extends Model
{
    public $timestamps = false;

    protected $table = 'user_email_verifications';

    protected $fillable = [
        'user_id',
        'token_hash',
        'redirect_path',
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
