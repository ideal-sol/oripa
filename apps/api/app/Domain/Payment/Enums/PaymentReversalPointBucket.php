<?php

namespace App\Domain\Payment\Enums;

enum PaymentReversalPointBucket: string
{
    case PaidPurchaseFromPaid = 'paid_purchase_from_paid';
    case FreeBonusFromFree = 'free_bonus_from_free';
    case PaidPurchaseShortfallFromFree = 'paid_purchase_shortfall_from_free';
    case Shortfall = 'shortfall';
}
