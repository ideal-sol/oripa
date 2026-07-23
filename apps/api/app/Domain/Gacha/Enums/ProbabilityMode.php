<?php

namespace App\Domain\Gacha\Enums;

enum ProbabilityMode: string
{
    case Single = 'single';
    case SoldCountStage = 'sold_count_stage';
}
