<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class IdempotencyRecord extends Model
{
    public $timestamps = false;

    protected $table = 'idempotency_records';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'response_data' => 'array',
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
