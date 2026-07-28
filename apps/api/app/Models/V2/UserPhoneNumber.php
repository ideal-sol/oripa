<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class UserPhoneNumber extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'phone_ciphertext',
        'phone_hmac',
        'verified_at',
        'revoked_at',
    ];

    protected $hidden = ['phone_ciphertext', 'phone_hmac'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $phone): void {
            $phone->public_id ??= (string) Str::uuid7();
        });
    }
}
