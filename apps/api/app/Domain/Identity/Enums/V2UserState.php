<?php

namespace App\Domain\Identity\Enums;

enum V2UserState: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Restricted = 'restricted';
    case Suspended = 'suspended';
    case Closed = 'closed';
    case Anonymized = 'anonymized';
}
