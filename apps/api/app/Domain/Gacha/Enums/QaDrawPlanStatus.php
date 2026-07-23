<?php

namespace App\Domain\Gacha\Enums;

enum QaDrawPlanStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Disabled = 'disabled';
}
