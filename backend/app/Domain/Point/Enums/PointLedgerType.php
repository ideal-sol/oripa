<?php

namespace App\Domain\Point\Enums;

enum PointLedgerType: string
{
    case Purchase = 'purchase';
    case Grant = 'grant';
    case Spend = 'spend';
    case Expire = 'expire';
    case Compensation = 'compensation';
    case Cancel = 'cancel';
    case Exchange = 'exchange';
}
