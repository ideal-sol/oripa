<?php

namespace App\Domain\Gacha\Enums;

enum DrawResultType: string
{
    case Prize = 'prize';
    case PointBack = 'point_back';
}
