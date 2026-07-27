<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

final class DrawResult extends Model
{
    public $timestamps = false;

    protected $table = 'draw_results';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $result): void {
            $result->public_id ??= (string) Str::uuid7();
        });
        static::updating(static function (): never {
            throw new LogicException('V2 draw results are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('V2 draw results are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'request_sequence' => 'integer',
            'draw_sequence_number' => 'integer',
            'consumed_points' => 'integer',
            'point_back_amount' => 'integer',
            'random_value' => 'integer',
            'display_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
