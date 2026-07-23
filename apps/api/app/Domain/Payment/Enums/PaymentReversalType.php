<?php

namespace App\Domain\Payment\Enums;

enum PaymentReversalType: string
{
    case Refund = 'refund';
    case Chargeback = 'chargeback';
}
