<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class ShippingRequest extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $table = 'shipping_requests';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            $request->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'response_data' => 'array',
            'requested_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
        ];
    }
}
