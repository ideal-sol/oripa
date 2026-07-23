<?php

namespace App\Domain\Payment\Enums;

enum PaymentReversalStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case ReviewRequired = 'review_required';
}
