<?php

namespace App\Domain\Shipping\Enums;

enum UserPrizeStatus: string
{
    case Stored = 'stored';
    case ShippingRequested = 'shipping_requested';
    case Shipped = 'shipped';
    case Converted = 'converted';
    case Expired = 'expired';
    case Held = 'held';
}
