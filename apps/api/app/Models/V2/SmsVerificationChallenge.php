<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SmsVerificationChallenge extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'phone_ciphertext',
        'phone_hmac',
        'code_hash',
        'purpose',
        'failed_attempts',
        'expires_at',
        'sent_at',
        'used_at',
        'revoked_at',
    ];

    protected $hidden = ['phone_ciphertext', 'phone_hmac', 'code_hash'];

    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $challenge): void {
            $challenge->public_id ??= (string) Str::uuid7();
        });
    }
}
