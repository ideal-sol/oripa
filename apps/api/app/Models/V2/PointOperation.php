<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

final class PointOperation extends Model
{
    public $timestamps = false;

    protected $table = 'point_operations';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $operation): void {
            $operation->public_id ??= (string) Str::uuid7();
        });
        static::updating(static function (): never {
            throw new LogicException('V2 point operations are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('V2 point operations are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'is_qa' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'business_date' => 'immutable_date',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
