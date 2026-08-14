<?php

namespace App\Models\V2;

use Illuminate\Database\Eloquent\Model;

final class PrizeInventoryAdjustment extends Model
{
    public $timestamps = false;

    protected $table = 'prize_inventory_adjustments';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'before_total_quantity' => 'integer',
            'before_awarded_count' => 'integer',
            'before_available_quantity' => 'integer',
            'before_withdrawn_quantity' => 'integer',
            'before_lock_version' => 'integer',
            'after_total_quantity' => 'integer',
            'after_awarded_count' => 'integer',
            'after_available_quantity' => 'integer',
            'after_withdrawn_quantity' => 'integer',
            'after_lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
