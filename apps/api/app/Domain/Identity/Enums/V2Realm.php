<?php

namespace App\Domain\Identity\Enums;

enum V2Realm: string
{
    case User = 'user';
    case Admin = 'admin';
    case Webhook = 'webhook';
    case Unknown = 'unknown';
}
