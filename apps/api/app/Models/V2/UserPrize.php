<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class UserPrize extends Model
{
    protected $table = 'user_prizes';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $prize): void {
            $prize->public_id ??= (string) Str::uuid7();
        });
        static::deleting(static function (): never {
            throw new \LogicException('V2 user prize ownership cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'acquired_at' => 'immutable_datetime',
            'storage_expires_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
        ];
    }
}
