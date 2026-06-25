<?php

namespace App\Domain\Point\Enums;

enum PointLotSourceType: string
{
    case Purchase = 'purchase';
    case Campaign = 'campaign';
    case MinimumGuarantee = 'minimum_guarantee';
    case Compensation = 'compensation';
    case Exchange = 'exchange';
    case Referral = 'referral';
    case LineFriend = 'line_friend';
}
