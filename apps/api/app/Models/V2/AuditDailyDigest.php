<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AuditDailyDigest extends Model
{
    public $timestamps = false;

    protected $table = 'audit_daily_digests';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('V2 audit digests are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('V2 audit digests are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'generated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
