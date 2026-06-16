<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shipping_request_id',
        'user_prize_id',
    ];

    public function shippingRequest()
    {
        return $this->belongsTo(ShippingRequest::class);
    }

    public function userPrize()
    {
        return $this->belongsTo(UserPrize::class);
    }
}
