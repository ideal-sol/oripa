<?php

namespace App\Domain\Gacha\Enums;

enum DrawRequestStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
