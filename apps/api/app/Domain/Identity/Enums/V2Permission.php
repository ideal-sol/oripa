<?php

namespace App\Domain\Identity\Enums;

enum V2Permission: string
{
    case ReadAdminIdentity = 'identity.admin.read';
    case ManageAdminIdentity = 'identity.admin.manage';
    case RevokeAdminSession = 'identity.admin.session.revoke';
    case ReadPointLedger = 'point.ledger.read';
    case RequestPointAdjustment = 'point.adjustment.request';
    case ApproveFreePointAdjustment = 'point.adjustment.free.approve';
    case ApprovePaidPointAdjustment = 'point.adjustment.paid.approve';
}
