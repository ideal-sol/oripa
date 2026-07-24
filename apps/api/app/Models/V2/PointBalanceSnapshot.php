<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class PointBalanceSnapshot extends Model
{
    protected $table = 'point_balance_snapshots';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'immutable_date',
            'source_cutoff_at' => 'immutable_datetime',
            'is_base_date' => 'boolean',
            'generated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
