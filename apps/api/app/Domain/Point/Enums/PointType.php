<?php

namespace App\Domain\Point\Enums;

enum PointType: string
{
    case Paid = 'paid';
    case Free = 'free';
}
