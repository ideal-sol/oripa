<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class UserEmailChangeRequest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'user_id',
        'new_email_display',
        'new_email_normalized',
        'token_hash',
        'initiating_session_hash',
        'redirect_path',
        'failed_attempts',
        'expires_at',
        'used_at',
        'revoked_at',
        'created_at',
    ];

    protected $hidden = [
        'new_email_display',
        'new_email_normalized',
        'token_hash',
        'initiating_session_hash',
    ];

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
        static::creating(function (self $request): void {
            $request->public_id ??= (string) Str::uuid7();
        });
    }
}
