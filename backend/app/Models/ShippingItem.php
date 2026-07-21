<?php

namespace App\Models;

use App\Domain\Shipping\Enums\ShippingRequestStatus;
use Illuminate\Database\Eloquent\Model;

class ShippingItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shipping_request_id',
        'user_prize_id',
        'status',
        'tracking_number',
        'shipped_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShippingRequestStatus::class,
            'shipped_at' => 'datetime',
        ];
    }

    public function shippingRequest()
    {
        return $this->belongsTo(ShippingRequest::class);
    }

    public function userPrize()
    {
        return $this->belongsTo(UserPrize::class);
    }

    public function paymentReversalPrizeActions()
    {
        return $this->hasMany(PaymentReversalPrizeAction::class);
    }
}
