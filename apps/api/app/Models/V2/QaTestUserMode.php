<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class QaTestUserMode extends Model
{
    protected $table = 'qa_test_user_modes';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $mode): void {
            $mode->public_id ??= (string) Str::uuid7();
        });
        static::deleting(static function (): never {
            throw new \LogicException('V2 QA Test User Mode cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }
}
