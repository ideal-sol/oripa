<?php

namespace App\Domain\Payment\Enums;

enum PaymentReversalPrizeActionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Released = 'released';
    case Canceled = 'canceled';
}
