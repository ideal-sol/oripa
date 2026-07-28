<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ExternalIdentityTransaction extends Model
{
    protected $table = 'external_identity_transactions';

    protected $fillable = [
        'public_id',
        'provider',
        'purpose',
        'state_hash',
        'nonce_hash',
        'code_verifier_ciphertext',
        'browser_binding_hash',
        'user_id',
        'user_session_hash',
        'return_path',
        'redirect_uri',
        'request_id',
        'status',
        'expires_at',
        'processing_at',
        'used_at',
        'revoked_at',
    ];

    protected $hidden = [
        'id',
        'state_hash',
        'nonce_hash',
        'code_verifier_ciphertext',
        'browser_binding_hash',
        'user_id',
        'user_session_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'processing_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->public_id ??= (string) Str::uuid7();
        });
    }
}
