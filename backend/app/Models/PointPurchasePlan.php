<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointPurchasePlan extends Model
{
    protected $fillable = [
        'name',
        'amount',
        'paid_point_amount',
        'free_point_amount',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_point_amount' => 'integer',
            'free_point_amount' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
