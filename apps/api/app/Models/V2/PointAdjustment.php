<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PointAdjustment extends Model
{
    protected $table = 'point_adjustments';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            $adjustment->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expire_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
