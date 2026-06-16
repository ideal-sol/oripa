<?php

namespace App\Domain\Admin\Enums;

enum AdminRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
}
