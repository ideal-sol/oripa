<?php

namespace App\Domain\Payment\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
    case Chargeback = 'chargeback';
}
