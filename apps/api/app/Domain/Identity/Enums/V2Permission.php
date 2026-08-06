<?php

namespace App\Domain\Identity\Enums;

enum V2Permission: string
{
    case ReadAdminIdentity = 'identity.admin.read';
    case ManageAdminIdentity = 'identity.admin.manage';
    case RevokeAdminSession = 'identity.admin.session.revoke';
    case ManageLineMessaging = 'identity.line.manage';
    case ReadPointLedger = 'point.ledger.read';
    case RequestPointAdjustment = 'point.adjustment.request';
    case ApproveFreePointAdjustment = 'point.adjustment.free.approve';
    case ApprovePaidPointAdjustment = 'point.adjustment.paid.approve';
    case ManagePointAdjustment = 'point.adjustment.manage';
    case ReadReferralSettings = 'referral.settings.read';
    case ManageReferralSettings = 'referral.settings.manage';
    case ReadCatalog = 'catalog.read';
    case ManageCatalog = 'catalog.manage';
    case PublishCatalog = 'catalog.publish';
    case ManageShippingRequest = 'shipping.request.manage';
    case ManageQaDraw = 'qa.draw.manage';
    case ReadFinancialReporting = 'reporting.financial.read';
    case ExportFinancialReporting = 'reporting.financial.export';
    case ReadContent = 'content.read';
    case ManageContent = 'content.manage';
    case PublishContent = 'content.publish';
    case ReadContact = 'contact.read';
    case ManageContact = 'contact.manage';
}
