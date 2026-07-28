<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ExternalIdentityAccount extends Model
{
    protected $table = 'external_identity_accounts';

    protected $fillable = [
        'public_id',
        'user_id',
        'provider',
        'issuer',
        'subject_hash',
        'linked_at',
        'last_authenticated_at',
        'revoked_at',
    ];

    protected $hidden = [
        'id',
        'user_id',
        'subject_hash',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'immutable_datetime',
            'last_authenticated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->public_id ??= (string) Str::uuid7();
        });
    }
}
