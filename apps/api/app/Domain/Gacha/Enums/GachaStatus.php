<?php

namespace App\Domain\Gacha\Enums;

enum GachaStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case SoldOut = 'sold_out';
    case Ended = 'ended';
    case Hidden = 'hidden';
}
