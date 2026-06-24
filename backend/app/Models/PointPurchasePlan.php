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
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_point_amount' => 'integer',
            'free_point_amount' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function scopeCurrentlyAvailable($query)
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }
}
