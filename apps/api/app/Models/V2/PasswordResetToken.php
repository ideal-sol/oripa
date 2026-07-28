<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PasswordResetToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'user_id',
        'token_hash',
        'redirect_path',
        'failed_attempts',
        'expires_at',
        'used_at',
        'revoked_at',
        'created_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            $token->public_id ??= (string) Str::uuid7();
        });
    }
}
