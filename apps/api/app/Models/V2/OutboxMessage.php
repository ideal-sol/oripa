<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class OutboxMessage extends Model
{
    protected $table = 'outbox_messages';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            $message->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
