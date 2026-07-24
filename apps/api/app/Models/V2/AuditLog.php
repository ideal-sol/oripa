<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Illuminate\Support\Str;

final class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->public_id ??= (string) Str::uuid();
        });
        static::updating(static function (): never {
            throw new LogicException('V2 audit records are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('V2 audit records are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'business_date' => 'immutable_date',
            'before_redacted' => 'object',
            'after_redacted' => 'object',
            'metadata_redacted' => 'object',
            'created_at' => 'immutable_datetime',
        ];
    }
}
