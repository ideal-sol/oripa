<?php

namespace App\Domain\Identity\Enums;

enum V2Permission: string
{
    case ReadAdminIdentity = 'identity.admin.read';
    case ManageAdminIdentity = 'identity.admin.manage';
    case RevokeAdminSession = 'identity.admin.session.revoke';
}
