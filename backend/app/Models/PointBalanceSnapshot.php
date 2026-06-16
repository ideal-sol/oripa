<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointBalanceSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'snapshot_date',
        'paid_unused_balance',
        'free_unused_balance',
        'is_base_date',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'is_base_date' => 'boolean',
        ];
    }
}
