<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class PointReconciliationRun extends Model
{
    protected $table = 'point_reconciliation_runs';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $run->public_id ??= (string) Str::uuid7();
        });
    }

    protected function casts(): array
    {
        return [
            'target_date' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
