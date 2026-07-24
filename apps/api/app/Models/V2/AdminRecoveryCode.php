<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class AdminRecoveryCode extends Model
{
    protected $table = 'admin_recovery_codes';
    public $timestamps = false;

    protected $fillable = [
        'admin_id',
        'code_hash',
        'used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
