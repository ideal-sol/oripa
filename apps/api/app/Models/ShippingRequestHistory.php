<?php

namespace App\Models;

use App\Domain\Shipping\Enums\ShippingRequestStatus;
use Illuminate\Database\Eloquent\Model;

class ShippingRequestHistory extends Model
{
    protected $fillable = [
        'shipping_request_id',
        'admin_user_id',
        'from_status',
        'to_status',
        'tracking_number',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => ShippingRequestStatus::class,
            'to_status' => ShippingRequestStatus::class,
        ];
    }

    public function shippingRequest()
    {
        return $this->belongsTo(ShippingRequest::class);
    }

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class);
    }
}
