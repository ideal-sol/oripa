<?php

namespace App\Models;

use App\Domain\Shipping\Enums\ShippingRequestStatus;
use Illuminate\Database\Eloquent\Model;

class ShippingRequest extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'recipient_name',
        'postal_code',
        'prefecture',
        'city',
        'address_line1',
        'address_line2',
        'phone_number',
        'tracking_number',
        'requested_at',
        'shipped_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShippingRequestStatus::class,
            'requested_at' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(ShippingItem::class);
    }

    public function histories()
    {
        return $this->hasMany(ShippingRequestHistory::class);
    }
}
