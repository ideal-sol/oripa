<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ContactInquiry extends Model
{
    protected $table = 'contact_inquiries';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'retention_until' => 'immutable_datetime',
        ];
    }
}
