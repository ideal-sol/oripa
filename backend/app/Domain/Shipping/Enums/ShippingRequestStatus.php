<?php

namespace App\Domain\Shipping\Enums;

enum ShippingRequestStatus: string
{
    case Requested = 'requested';
    case Packing = 'packing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Returned = 'returned';
    case Canceled = 'canceled';
    case Hold = 'hold';
    case ReturnRequested = 'return_requested';
}
