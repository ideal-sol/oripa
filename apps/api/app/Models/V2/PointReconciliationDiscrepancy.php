<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PointReconciliationDiscrepancy extends Model
{
    public $timestamps = false;

    protected $table = 'point_reconciliation_discrepancies';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('V2 point reconciliation discrepancies are append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('V2 point reconciliation discrepancies are append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'source_ids' => 'array',
            'resolved' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
