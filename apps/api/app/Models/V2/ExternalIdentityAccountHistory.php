<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ExternalIdentityAccountHistory extends Model
{
    protected $table = 'external_identity_account_histories';
    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'external_identity_account_id',
        'action',
        'request_id',
        'occurred_at',
        'created_at',
    ];

    protected $hidden = [
        'id',
        'external_identity_account_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $history): void {
            $history->public_id ??= (string) Str::uuid7();
        });
    }
}
