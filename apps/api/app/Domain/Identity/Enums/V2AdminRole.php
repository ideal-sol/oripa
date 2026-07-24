<?php

namespace App\Domain\Identity\Enums;

enum V2AdminRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
}
