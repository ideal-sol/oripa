<?php

namespace App\Domain\Identity\Enums;

enum V2AdminState: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
}
