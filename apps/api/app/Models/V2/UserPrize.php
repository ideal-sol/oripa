<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

final class UserPrize extends Model
{
    protected $table = 'user_prizes';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $prize): void {
            $prize->public_id ??= (string) Str::uuid7();
        });
        static::updating(static function (): never {
            throw new LogicException('V2 user prize ownership is append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('V2 user prize ownership is append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'acquired_at' => 'immutable_datetime',
        ];
    }
}
