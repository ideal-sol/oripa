<?php

namespace App\Domain\Payment\Enums;

enum PaymentReversalPrizeActionType: string
{
    case Hold = 'hold';
    case ReturnRequested = 'return_requested';
    case HoldReleased = 'hold_released';
    case NoAction = 'no_action';
}
