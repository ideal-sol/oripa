<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PrizeExchangeRequest extends Model
{
    public $timestamps = false;

    protected $table = 'prize_exchange_requests';

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
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
