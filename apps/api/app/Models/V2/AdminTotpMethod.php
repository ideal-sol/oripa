<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class AdminTotpMethod extends Model
{
    protected $table = 'admin_totp_methods';

    protected $fillable = [
        'admin_id',
        'secret_ciphertext',
        'encryption_key_version',
        'last_used_time_step',
        'confirmed_at',
        'revoked_at',
    ];

    protected $hidden = [
        'secret_ciphertext',
        'encryption_key_version',
    ];

    protected function casts(): array
    {
        return [
            'secret_ciphertext' => 'encrypted',
            'last_used_time_step' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
