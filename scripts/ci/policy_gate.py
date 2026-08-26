#!/usr/bin/env python3
"""Repository governance checks for Platform CI."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from typing import Iterable


LANE_POLICY_SPEC = importlib.util.spec_from_file_location(
    "oripa_lane_policy", Path(__file__).with_name("lane_policy.py")
)
if LANE_POLICY_SPEC is None or LANE_POLICY_SPEC.loader is None:
    raise RuntimeError("lane policy module is unavailable")
lane_policy = importlib.util.module_from_spec(LANE_POLICY_SPEC)
LANE_POLICY_SPEC.loader.exec_module(lane_policy)

STOREFRONT_ARTIFACT_SPEC = importlib.util.spec_from_file_location(
    "oripa_storefront_contract_artifact",
    Path(__file__).resolve().parents[1] / "release" / "storefront_contract_artifact.py",
)
if STOREFRONT_ARTIFACT_SPEC is None or STOREFRONT_ARTIFACT_SPEC.loader is None:
    raise RuntimeError("Storefront artifact policy module is unavailable")
storefront_artifact = importlib.util.module_from_spec(STOREFRONT_ARTIFACT_SPEC)
STOREFRONT_ARTIFACT_SPEC.loader.exec_module(storefront_artifact)


FULL_SHA = re.compile(r"^[0-9a-f]{40}$")
TASK_ID = re.compile(r"^(?:[A-Z]+-[0-9]+[A-Z]?|STORE-SITE-[0-9]+)$")
ACTION_REF = re.compile(
    r"^\s*(?:-\s*)?uses:\s+([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)"
    r"(?:/[A-Za-z0-9_.-]+)*"
    r"@([0-9a-f]{40})(?:\s+#.*)?$"
)
REQUIRED_REPOSITORY_FILES = {
    "AGENTS.md",
    ".github/CODEOWNERS",
    ".github/ISSUE_TEMPLATE/task.yml",
    ".github/ISSUE_TEMPLATE/config.yml",
    ".github/pull_request_template.md",
    "apps/api/AGENTS.md",
    "apps/admin/AGENTS.md",
    "packages/AGENTS.md",
    "openapi/AGENTS.md",
    "infrastructure/AGENTS.md",
    "docs/AGENTS.md",
    "legacy/v1/AGENTS.md",
    "legacy/v1-frontend/AGENTS.md",
    "legacy/v1-frontend/README.md",
    "docs/architecture/README.md",
    "infrastructure/github-app/self_review_policy.py",
    "infrastructure/github-app/task_policy.py",
    "scripts/ci/lane_policy.py",
}
STOREFRONT_CLIENT_REQUIRED_FILES = {
    "packages/storefront-client/.gitignore",
    "packages/storefront-client/README.md",
    "packages/storefront-client/package.json",
    "packages/storefront-client/eslint.config.mjs",
    "packages/storefront-client/tsconfig.json",
    "packages/storefront-client/tsconfig.build.json",
    "packages/storefront-client/scripts/check-generated.mjs",
    "packages/storefront-client/src/browser.ts",
    "packages/storefront-client/src/catalog.ts",
    "packages/storefront-client/src/constants.ts",
    "packages/storefront-client/src/draw.ts",
    "packages/storefront-client/src/errors.ts",
    "packages/storefront-client/src/generated/public.ts",
    "packages/storefront-client/src/identity.ts",
    "packages/storefront-client/src/index.ts",
    "packages/storefront-client/src/server.ts",
    "packages/storefront-client/src/transport.ts",
    "packages/storefront-client/src/types.ts",
    "packages/storefront-client/test/client.test.mjs",
}
SITE_SCHEMA_REQUIRED_FILES = {
    "packages/site-schema/.gitignore",
    "packages/site-schema/README.md",
    "packages/site-schema/package.json",
    "packages/site-schema/eslint.config.mjs",
    "packages/site-schema/tsconfig.json",
    "packages/site-schema/tsconfig.build.json",
    "packages/site-schema/schema/site-manifest.schema.json",
    "packages/site-schema/scripts/generate-types.mjs",
    "packages/site-schema/src/compatibility.ts",
    "packages/site-schema/src/errors.ts",
    "packages/site-schema/src/generated/site-manifest.ts",
    "packages/site-schema/src/generated/schema.ts",
    "packages/site-schema/src/index.ts",
    "packages/site-schema/src/validator.ts",
    "packages/site-schema/test/fixtures/positive/minimal.json",
    "packages/site-schema/test/fixtures/positive/requires-capability.json",
    "packages/site-schema/test/fixtures/negative/family-major.json",
    "packages/site-schema/test/fixtures/negative/invalid-semver.json",
    "packages/site-schema/test/fixtures/negative/secret-field.json",
    "packages/site-schema/test/fixtures/negative/unknown-field.json",
    "packages/site-schema/test/site-schema.test.mjs",
}
STOREFRONT_TESTKIT_REQUIRED_FILES = {
    "packages/storefront-testkit/.gitignore",
    "packages/storefront-testkit/README.md",
    "packages/storefront-testkit/package.json",
    "packages/storefront-testkit/eslint.config.mjs",
    "packages/storefront-testkit/tsconfig.json",
    "packages/storefront-testkit/tsconfig.build.json",
    "packages/storefront-testkit/scripts/check-exports.mjs",
    "packages/storefront-testkit/scripts/check-network-boundary.mjs",
    "packages/storefront-testkit/scripts/generate-public-contract.mjs",
    "packages/storefront-testkit/src/assertions.ts",
    "packages/storefront-testkit/src/errors.ts",
    "packages/storefront-testkit/src/fixtures.ts",
    "packages/storefront-testkit/src/generated/public-contract.ts",
    "packages/storefront-testkit/src/index.ts",
    "packages/storefront-testkit/src/mock.ts",
    "packages/storefront-testkit/test/testkit.test.mjs",
}
WORKSPACE_REQUIRED_FILES = {
    ".dockerignore",
    ".github/dependabot.yml",
    "package.json",
    "pnpm-workspace.yaml",
    "pnpm-lock.yaml",
    "apps/README.md",
    "apps/api/README.md",
    "apps/admin/README.md",
    "apps/admin/Dockerfile",
    "apps/admin/package.json",
    "apps/admin/next.config.ts",
    "apps/admin/tsconfig.json",
    "apps/admin/eslint.config.mjs",
    "apps/admin/next-env.d.ts",
    "apps/admin/src/app/layout.tsx",
    "apps/admin/src/app/page.tsx",
    "apps/admin/src/app/api/health/route.ts",
    "apps/api/AGENTS.md",
    "apps/admin/AGENTS.md",
    "packages/README.md",
    "packages/platform/README.md",
    "packages/platform/package.json",
    *STOREFRONT_CLIENT_REQUIRED_FILES,
    *SITE_SCHEMA_REQUIRED_FILES,
    *STOREFRONT_TESTKIT_REQUIRED_FILES,
    "packages/AGENTS.md",
    "openapi/README.md",
    "openapi/AGENTS.md",
    "openapi/redocly.yaml",
    "openapi/components/common.yaml",
    "openapi/public/openapi.yaml",
    "openapi/admin/openapi.yaml",
    "openapi/webhook/openapi.yaml",
    "openapi/bundled/public.openapi.json",
    "openapi/bundled/admin.openapi.json",
    "openapi/bundled/webhook.openapi.json",
    "infrastructure/README.md",
    "infrastructure/AGENTS.md",
    "deployments/README.md",
    "manifests/README.md",
    "manifests/schemas/release-manifest.schema.json",
    "manifests/schemas/deployment-manifest.schema.json",
    "manifests/examples/release-manifest.example.json",
    "manifests/examples/deployment-manifest.example.json",
    "legacy/README.md",
    "legacy/v1/README.md",
    "legacy/v1/AGENTS.md",
    "docs/operations/repository-layout/README.md",
    "docker-compose.yml",
    "docker-compose.v2.yml",
}
API_APPLICATION_REQUIRED_FILES = {
    "apps/api/.env.example",
    "apps/api/artisan",
    "apps/api/composer.json",
    "apps/api/composer.lock",
    "apps/api/phpunit.xml",
    "apps/api/routes/api.php",
    "apps/api/tests/TestCase.php",
}
V2_DATABASE_REQUIRED_FILES = {
    ".ci/baselines/v1-migrations.json",
    "apps/api/database/migrations-v2/README.md",
    "docker-compose.v2.yml",
    "docs/operations/database/README.md",
    "scripts/db/README.md",
    "scripts/db/v2_database.py",
    "tests/db/test_v2_database.py",
}
RELEASE_ARTIFACT_REQUIRED_FILES = {
    "apps/admin/Dockerfile",
    "apps/admin/next.config.ts",
    "infra/docker/backend/Dockerfile",
    "docs/operations/releases/platform-alpha-artifact.md",
    "scripts/release/README.md",
    "scripts/release/platform_artifact.py",
    "scripts/release/storefront_contract_artifact.py",
    "tests/release/test_platform_artifact.py",
    "tests/release/test_storefront_contract_artifact.py",
    "manifests/storefront-contract-releases.json",
    "docs/operations/releases/storefront-contract-artifact.md",
}
PREVIEW_IMAGE_PIPELINE_REQUIRED_FILES = {
    ".github/workflows/preview-image-build.yml",
    "infrastructure/github-app/oripa-github-app-api",
    "scripts/ops/preview_image_artifact.py",
    "tests/ops/test_preview_image_pipeline.py",
    "docs/operations/deployment/preview-image-build.md",
}
MIG_061G_V2_IDENTITY_FILES = {
    "apps/api/app/Domain/Identity/Exceptions/V2AdminUserReadException.php",
    "apps/api/app/Domain/Identity/Services/V2AdminUserReadService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminUserController.php",
    "apps/api/database/migrations-v2/2026_08_18_000031_add_display_name_to_v2_users.php",
    "apps/api/tests/Unit/V2AdminUserReadServiceTest.php",
    "apps/api/tests/V2/AdminUserReadModelApiTest.php",
    "apps/api/tests/V2/ZAdminUserReadModelPerformanceTest.php",
}
MIG_061W_V2_IDENTITY_FILES = {
    "apps/api/database/migrations-v2/2026_08_26_000039_add_v2_line_settings_management.php",
}
MIG_061X_V2_IDENTITY_FILES = {
    "apps/api/database/migrations-v2/2026_08_27_000040_update_v2_session_timeout_constraints.php",
}
MIG_062B_V2_IDENTITY_FILES = {
    "apps/api/app/Domain/Identity/Exceptions/V2UserTagException.php",
    "apps/api/app/Domain/Identity/Services/V2UserTagService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminUserTagController.php",
    "apps/api/database/migrations-v2/2026_08_28_000041_create_v2_user_tag_management.php",
    "apps/api/database/migrations-v2/2026_08_28_000042_normalize_v2_user_tag_check_constraint.php",
    "apps/api/tests/V2/AdminUserTagManagementTest.php",
}
MIG_062K_V2_IDENTITY_FILES = {
    "apps/api/app/Domain/Identity/Exceptions/V2AdminUserStateException.php",
    "apps/api/app/Domain/Identity/Services/V2AdminUserStateService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminUserStateController.php",
    "apps/api/database/migrations-v2/2026_09_02_000047_add_v2_user_state_revision.php",
    "apps/api/tests/V2/AdminUserStateManagementTest.php",
}
MIG_073_V2_IDENTITY_FILES = {
    "apps/api/database/migrations-v2/2026_09_17_000063_allow_v2_closed_user_email_reregistration.php",
}
V2_IDENTITY_REQUIRED_FILES = {
    "apps/api/app/Auth/V2RealmSessionGuard.php",
    "apps/api/app/Domain/Identity/Enums/V2AdminRole.php",
    "apps/api/app/Domain/Identity/Enums/V2AdminState.php",
    "apps/api/app/Domain/Identity/Enums/V2Permission.php",
    "apps/api/app/Domain/Identity/Enums/V2Realm.php",
    "apps/api/app/Domain/Identity/Enums/V2UserState.php",
    "apps/api/app/Domain/Identity/Services/V2MfaPolicy.php",
    "apps/api/app/Domain/Identity/Services/V2PasswordPolicy.php",
    "apps/api/app/Domain/Identity/Services/V2PermissionAuthorizer.php",
    "apps/api/app/Domain/Identity/Services/V2RealmBoundary.php",
    "apps/api/app/Domain/Identity/Services/V2SessionPolicy.php",
    "apps/api/app/Domain/Identity/Services/V2UserAuthenticationService.php",
    "apps/api/database/migrations-v2/2026_09_22_000066_add_v2_verification_failed_user_state.php",
    "apps/api/app/Domain/Identity/Services/V2AdminAuthenticationService.php",
    "apps/api/app/Domain/Identity/Services/V2AdminAuthenticationPolicyService.php",
    "apps/api/app/Domain/Identity/Services/V2TotpService.php",
    "apps/api/app/Domain/Identity/Services/V2WebauthnService.php",
    "apps/api/app/Domain/Identity/Services/V2RecoveryCodeService.php",
    "apps/api/app/Domain/Identity/Contracts/V2SecurityEventSink.php",
    "apps/api/app/Domain/Identity/Contracts/V2GoogleOidcTransport.php",
    "apps/api/app/Domain/Identity/Contracts/V2SuspiciousRecoveryBoundary.php",
    "apps/api/app/Domain/Identity/Services/V2ExplicitSuspiciousRecoveryBoundary.php",
    "apps/api/app/Domain/Identity/Services/V2IdentityCorrelation.php",
    "apps/api/app/Domain/Identity/Services/V2ExternalIdentityService.php",
    "apps/api/app/Domain/Identity/Services/V2GoogleIdTokenVerifier.php",
    "apps/api/app/Domain/Identity/Services/V2GoogleOidcHttpTransport.php",
    "apps/api/app/Domain/Identity/Services/V2PasswordRecoveryService.php",
    "apps/api/app/Domain/Identity/Services/V2PhoneNormalizer.php",
    "apps/api/app/Domain/Identity/Services/V2SmsVerificationService.php",
    "apps/api/app/Console/Commands/V2/CreateInitialOwnerInvitation.php",
    "apps/api/app/Http/Controllers/V2/V2PublicAuthController.php",
    "apps/api/app/Http/Controllers/V2/V2AdminAuthController.php",
    "apps/api/app/Http/Controllers/V2/V2AdminAuthenticationPolicyController.php",
    "apps/api/app/Http/Controllers/V2/V2AdminPermissionController.php",
    "apps/api/app/Http/Middleware/V2/EnforceV2BrowserSecurity.php",
    "apps/api/app/Http/Middleware/V2/EnforceV2Realm.php",
    "apps/api/app/Models/V2/Admin.php",
    "apps/api/app/Models/V2/AdminAuthenticationPolicy.php",
    "apps/api/app/Models/V2/AdminRecoveryCode.php",
    "apps/api/app/Models/V2/AdminSession.php",
    "apps/api/app/Models/V2/AdminTotpMethod.php",
    "apps/api/app/Models/V2/AdminWebauthnMethod.php",
    "apps/api/app/Models/V2/User.php",
    "apps/api/app/Models/V2/ExternalIdentityAccount.php",
    "apps/api/app/Models/V2/ExternalIdentityAccountHistory.php",
    "apps/api/app/Models/V2/ExternalIdentityTransaction.php",
    "apps/api/app/Models/V2/PasswordResetToken.php",
    "apps/api/app/Models/V2/SmsVerificationChallenge.php",
    "apps/api/app/Models/V2/UserPhoneNumber.php",
    "apps/api/app/Models/V2/UserRememberDevice.php",
    "apps/api/app/Models/V2/UserSession.php",
    "apps/api/app/Domain/Identity/Contracts/V2ExternalIdentityProvider.php",
    "apps/api/app/Domain/Identity/Contracts/V2LineOidcTransport.php",
    "apps/api/app/Domain/Identity/Services/V2ExternalIdentityProviderRegistry.php",
    "apps/api/app/Domain/Identity/Services/V2GoogleExternalIdentityProvider.php",
    "apps/api/app/Domain/Identity/Services/V2LineExternalIdentityProvider.php",
    "apps/api/app/Domain/Identity/Services/V2LineOidcHttpTransport.php",
    "apps/api/app/Domain/Identity/Services/V2VerifiedExternalIdentity.php",
    "apps/api/app/Providers/V2AuthorizationServiceProvider.php",
    "apps/api/config/v2_identity.php",
    "apps/api/phpunit.v2.xml",
    "apps/api/database/migrations-v2/2026_07_24_000001_create_v2_identity_accounts.php",
    "apps/api/database/migrations-v2/2026_07_24_000002_create_v2_identity_sessions.php",
    "apps/api/database/migrations-v2/2026_07_24_000003_create_v2_admin_mfa_methods.php",
    "apps/api/database/migrations-v2/2026_07_24_000004_create_v2_authentication_flows.php",
    "apps/api/database/migrations-v2/2026_08_03_000014_create_v2_password_reset_sms_verification.php",
    "apps/api/database/migrations-v2/2026_08_04_000015_create_v2_external_identity_google_oidc.php",
    "apps/api/database/migrations-v2/2026_08_07_000018_add_line_external_identity_provider.php",
    "apps/api/database/migrations-v2/2026_08_07_000019_create_line_messaging_follow_foundation.php",
    "apps/api/database/migrations-v2/2026_08_07_000020_add_line_friend_reward_enabled.php",
    "apps/api/database/migrations-v2/2026_08_17_000030_create_v2_admin_authentication_policy.php",
    *MIG_061W_V2_IDENTITY_FILES,
    *MIG_061X_V2_IDENTITY_FILES,
    *MIG_062B_V2_IDENTITY_FILES,
    *MIG_062K_V2_IDENTITY_FILES,
    *MIG_073_V2_IDENTITY_FILES,
    "apps/api/app/Domain/Line/Services/V2LineFriendService.php",
    "apps/api/app/Domain/Line/Contracts/V2LineMessagingTransport.php",
    "apps/api/app/Domain/Line/Exceptions/V2LineMessagingException.php",
    "apps/api/app/Domain/Line/Services/V2LineMessageTemplate.php",
    "apps/api/app/Domain/Line/Services/V2LineMessagingHttpTransport.php",
    "apps/api/app/Domain/Line/Services/V2LineMessagingSettingService.php",
    "apps/api/app/Domain/Line/ValueObjects/V2LineReplyResult.php",
    "apps/api/app/Http/Controllers/V2/V2LineMessagingWebhookController.php",
    "apps/api/app/Http/Controllers/V2/V2AdminLineMessagingController.php",
    "apps/api/app/Models/V2/LineFriendship.php",
    "apps/api/app/Models/V2/LineMessagingSetting.php",
    "apps/api/app/Models/V2/LinePendingFollow.php",
    "apps/api/app/Models/V2/LineWebhookEvent.php",
    "apps/api/config/v2_line.php",
    "apps/api/routes/webhook.php",
    "apps/api/tests/V2/LineMessagingVerticalSliceTest.php",
    "apps/api/tests/V2/ZLineMessagingConcurrencyTest.php",
    "apps/api/tests/V2/AuthenticationFlowTest.php",
    "apps/api/tests/V2/BrowserSecurityTest.php",
    "apps/api/tests/V2/AdminMfaPolicyTest.php",
    "apps/api/tests/V2/IdentitySchemaTest.php",
    "apps/api/tests/V2/PasswordPolicyTest.php",
    "apps/api/tests/V2/PasswordResetSmsVerificationTest.php",
    "apps/api/tests/V2/ZIdentityRecoveryConcurrencyTest.php",
    "apps/api/tests/V2/GoogleOidcVerticalSliceTest.php",
    "apps/api/tests/V2/LineLoginVerticalSliceTest.php",
    "apps/api/tests/V2/ZExternalIdentityConcurrencyTest.php",
    "apps/api/tests/V2/PermissionBoundaryTest.php",
    "apps/api/tests/V2/AdminPermissionContractTest.php",
    "apps/api/tests/V2/RealmSeparationTest.php",
    "docs/operations/identity-recovery/README.md",
    "docs/operations/external-identity/README.md",
    *MIG_061G_V2_IDENTITY_FILES,
}
V2_AUDIT_OUTBOX_REQUIRED_FILES = {
    "apps/api/app/Domain/Audit/V2/Services/V2AuditChainVerifier.php",
    "apps/api/app/Domain/Audit/V2/Services/V2AuditDailyDigestService.php",
    "apps/api/app/Domain/Audit/V2/Services/V2AuditHasher.php",
    "apps/api/app/Domain/Audit/V2/Services/V2AuditLogService.php",
    "apps/api/app/Domain/Audit/V2/Services/V2AuditRedactor.php",
    "apps/api/app/Domain/Audit/V2/Services/V2PersistentSecurityEventSink.php",
    "apps/api/app/Domain/Outbox/Services/V2OutboxEmailVerificationNotifier.php",
    "apps/api/app/Domain/Outbox/Services/V2OutboxService.php",
    "apps/api/app/Models/V2/AuditDailyDigest.php",
    "apps/api/app/Models/V2/AuditLog.php",
    "apps/api/app/Models/V2/OutboxMessage.php",
    "apps/api/config/v2_audit.php",
    "apps/api/config/v2_outbox.php",
    "apps/api/database/migrations-v2/2026_07_24_000005_create_v2_audit_outbox_foundation.php",
    "apps/api/tests/V2/AuditOutboxFoundationTest.php",
    "docs/operations/audit-outbox/README.md",
}
MIG_061H_V2_POINT_FILES = {
    "apps/api/app/Domain/Point/Exceptions/V2AdminPointAdjustmentException.php",
    "apps/api/app/Domain/Point/Services/V2AdminPointAdjustmentService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminUserPointAdjustmentController.php",
    "apps/api/tests/Unit/V2AdminPointAdjustmentServiceTest.php",
    "apps/api/tests/V2/AdminUserPointAdjustmentApiTest.php",
    "apps/api/tests/V2/ZAdminUserPointAdjustmentConcurrencyTest.php",
}
MIG_061T_V2_POINT_FILES = {
    "apps/api/app/Domain/Referral/Exceptions/V2ReferralException.php",
    "apps/api/app/Domain/Referral/Services/V2ReferralPointSettingService.php",
    "apps/api/app/Domain/Referral/Services/V2ReferralRewardService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminReferralPointSettingController.php",
    "apps/api/app/Models/V2/ReferralPointSetting.php",
    "apps/api/app/Models/V2/UserReferral.php",
    "apps/api/database/migrations-v2/2026_08_24_000037_create_v2_referral_point_settings.php",
    "apps/api/tests/V2/ReferralPointSettingsTest.php",
}
MIG_062Y_V2_POINT_FILES = {
    "apps/api/app/Console/Commands/V2/ExpirePointLots.php",
    "apps/api/app/Domain/Point/Services/V2CoinExpiryPolicy.php",
    "apps/api/database/migrations-v2/2026_09_09_000054_add_v2_coin_expiry_core.php",
}
V2_POINT_REQUIRED_FILES = {
    "apps/api/app/Domain/Point/Exceptions/V2PointException.php",
    "apps/api/app/Domain/Point/Services/V2PointIdempotencyService.php",
    "apps/api/app/Domain/Point/Services/V2PointLedgerService.php",
    "apps/api/app/Domain/Point/Services/V2PointReconciliationService.php",
    "apps/api/app/Domain/Point/Services/V2PointService.php",
    "apps/api/app/Domain/Point/Services/V2PointSnapshotService.php",
    "apps/api/app/Domain/Point/Services/V2PointTransactionRunner.php",
    "apps/api/app/Domain/Point/ValueObjects/V2IdempotencyClaim.php",
    "apps/api/app/Models/V2/IdempotencyRecord.php",
    "apps/api/app/Models/V2/PointAdjustment.php",
    "apps/api/app/Models/V2/PointBalanceSnapshot.php",
    "apps/api/app/Models/V2/PointLedgerEntry.php",
    "apps/api/app/Models/V2/PointLot.php",
    "apps/api/app/Models/V2/PointOperation.php",
    "apps/api/app/Models/V2/PointReconciliationDiscrepancy.php",
    "apps/api/app/Models/V2/PointReconciliationRun.php",
    "apps/api/app/Models/V2/Wallet.php",
    "apps/api/config/v2_point.php",
    "apps/api/database/migrations-v2/2026_07_24_000006_create_v2_point_model_foundation.php",
    "apps/api/tests/V2/PointModelFoundationTest.php",
    "docs/operations/point-model/README.md",
    *MIG_061H_V2_POINT_FILES,
    *MIG_061T_V2_POINT_FILES,
    *MIG_062Y_V2_POINT_FILES,
}
MIG_061V_V2_PAYMENT_FILES = {
    "apps/api/app/Domain/Payment/V2/Exceptions/V2PointPurchasePlanException.php",
    "apps/api/app/Domain/Payment/V2/Services/V2PointPurchaseEligibilityService.php",
    "apps/api/app/Domain/Payment/V2/Services/V2PointPurchasePlanService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminPointPurchasePlanController.php",
    "apps/api/database/migrations-v2/2026_08_25_000038_add_v2_point_purchase_management.php",
    "apps/api/tests/V2/AdminPointPurchasePlanManagementTest.php",
}
MIG_062D_V2_PAYMENT_FILES = {
    "apps/api/database/migrations-v2/2026_08_29_000043_add_v2_point_purchase_plan_target_tag.php",
}
MIG_063A_V2_PAYMENT_FILES = {
    "apps/api/app/Domain/Payment/V2/Services/V2LimitedBonusCampaignService.php",
    "apps/api/database/migrations-v2/2026_09_10_000055_add_v2_limited_bonus_domain_core.php",
    "apps/api/tests/V2/LimitedBonusDomainCoreTest.php",
}
V2_PAYMENT_REQUIRED_FILES = {
    "apps/api/app/Domain/Payment/V2/Exceptions/V2AdminPaymentReadException.php",
    "apps/api/app/Domain/Payment/V2/Exceptions/V2FincodeException.php",
    "apps/api/app/Domain/Payment/V2/Exceptions/V2PaymentException.php",
    "apps/api/app/Domain/Payment/V2/Services/V2AdminPaymentReadService.php",
    "apps/api/app/Domain/Payment/V2/Services/V2FincodeCardService.php",
    "apps/api/app/Domain/Payment/V2/Services/V2FincodeClient.php",
    "apps/api/app/Domain/Payment/V2/Services/V2FincodePaymentService.php",
    "apps/api/app/Domain/Payment/V2/Services/V2FincodeWebhookService.php",
    "apps/api/app/Domain/Payment/V2/Services/V2PaymentService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminPaymentController.php",
    "apps/api/app/Http/Controllers/V2/V2FincodeWebhookController.php",
    "apps/api/app/Http/Controllers/V2/V2PaymentController.php",
    "apps/api/config/v2_fincode.php",
    "apps/api/config/v2_payment.php",
    "apps/api/database/migrations-v2/2026_07_25_000007_create_v2_payment_model_foundation.php",
    "apps/api/database/migrations-v2/2026_09_21_000065_add_fincode_payment_backend_core.php",
    "apps/api/tests/V2/PaymentModelFoundationTest.php",
    "apps/api/tests/V2/FincodePaymentBackendTest.php",
    "docs/operations/payment-model/README.md",
    *MIG_061V_V2_PAYMENT_FILES,
    *MIG_062D_V2_PAYMENT_FILES,
    *MIG_063A_V2_PAYMENT_FILES,
}
MIG_061I_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_08_19_000032_add_v2_gacha_core_management_fields.php",
}
MIG_061K_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_08_20_000033_add_v2_gacha_rank_prize_management.php",
    "apps/api/tests/V2/AdminGachaRankPrizeManagementTest.php",
}
MIG_061L_V2_CATALOG_FILES = {
    "apps/api/tests/V2/AdminGachaUsageHistoryTest.php",
}
MIG_061R_V2_CATALOG_FILES = {
    "apps/api/app/Domain/Catalog/Services/V2GachaPublicCodeGenerator.php",
    "apps/api/database/migrations-v2/2026_08_23_000036_add_v2_gacha_external_public_code.php",
    "apps/api/tests/V2/AdminGachaMasterEditTest.php",
}
MIG_061S_V2_CATALOG_FILES = {
    "apps/api/tests/V2/AdminRankEffectSettingsTest.php",
}
MIG_062H_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_08_30_000044_add_v2_gacha_registration_eligibility_and_management_state.php",
}
MIG_062I_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_08_31_000045_add_v2_gacha_allowed_draw_counts.php",
}
MIG_062L_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_09_03_000048_add_v2_gacha_prize_ownership_snapshots.php",
    "apps/api/tests/V2/AdminGachaPrizeOwnershipTest.php",
}
MIG_063D_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_09_11_000056_allow_v2_published_category_tag_presentation_edits.php",
}
MIG_063E_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_09_12_000057_scope_v2_gacha_rank_codes.php",
}
OPS_019_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_09_12_000060_reconcile_preview_gacha_capacity.php",
    "apps/api/tests/V2/GachaCapacityForwardReconciliationTest.php",
}
MIG_067_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_09_13_000058_canonicalize_v2_gacha_lifecycle_inventory_capacity.php",
}
MIG_068_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_09_14_000059_internalize_v2_canonical_probability_publish.php",
}
MIG_072_V2_CATALOG_FILES = {
    "apps/api/database/migrations-v2/2026_09_15_000061_allow_v2_gacha_unpublished_draft_restore.php",
    "apps/api/database/migrations-v2/2026_09_16_000062_allow_v2_direct_terminal_gacha_deactivation.php",
}
V2_CATALOG_REQUIRED_FILES = {
    "apps/api/app/Domain/Catalog/Services/V2AdminCatalogReadService.php",
    "apps/api/app/Domain/Catalog/Services/V2CatalogMasterMutationService.php",
    "apps/api/app/Domain/Catalog/Services/V2ScheduledGachaPublishWorker.php",
    "apps/api/app/Domain/Catalog/Services/V2CatalogMutationRateLimiter.php",
    "apps/api/app/Console/Commands/V2/RunV2GachaScheduledPublishWorker.php",
    "apps/api/app/Domain/Catalog/Exceptions/V2CatalogException.php",
    "apps/api/app/Domain/Catalog/Services/V2CatalogFixtureImporter.php",
    "apps/api/app/Domain/Catalog/Services/V2CatalogReadService.php",
    "apps/api/app/Http/Controllers/V2/V2CatalogController.php",
    "apps/api/app/Http/Controllers/V2/V2AdminCatalogController.php",
    "apps/api/config/v2_catalog.php",
    "apps/api/database/migrations-v2/2026_07_28_000008_create_v2_catalog_probability_foundation.php",
    "apps/api/database/migrations-v2/2026_08_05_000016_add_v2_catalog_master_mutation_foundation.php",
    "apps/api/database/migrations-v2/2026_08_06_000017_add_v2_catalog_prize_asset_mutation_foundation.php",
    "apps/api/database/migrations-v2/2026_08_08_000021_add_v2_gacha_draft_management.php",
    "apps/api/database/migrations-v2/2026_08_09_000022_add_v2_probability_draft_management.php",
    "apps/api/database/migrations-v2/2026_08_10_000023_protect_v2_published_probability_relations.php",
    "apps/api/database/migrations-v2/2026_08_11_000024_guard_v2_gacha_probability_selection.php",
    "apps/api/database/migrations-v2/2026_08_12_000025_add_v2_gacha_immediate_publish_activation.php",
    "apps/api/database/migrations-v2/2026_08_13_000026_create_v2_gacha_publish_schedules.php",
    "apps/api/database/migrations-v2/2026_08_14_000027_add_v2_gacha_sales_pause.php",
    "apps/api/database/migrations-v2/2026_08_15_000028_add_v2_gacha_public_deactivation.php",
    "apps/api/tests/V2/CatalogProbabilityFoundationTest.php",
    "apps/api/tests/V2/GachaDetailPresentationContractTest.php",
    "apps/api/tests/V2/AdminCatalogReadTest.php",
    "apps/api/tests/V2/AdminCatalogMutationTest.php",
    "apps/api/tests/V2/AdminGachaDraftManagementTest.php",
    "apps/api/tests/V2/AdminGachaPublishPreflightTest.php",
    "apps/api/tests/V2/AdminProbabilityDraftManagementTest.php",
    "apps/api/tests/V2/ZAdminProbabilityConcurrencyTest.php",
    "apps/api/tests/V2/ZAdminGachaProbabilitySelectionConcurrencyTest.php",
    "apps/api/tests/V2/Fixtures/catalog-alpha.json",
    "apps/api/tests/V2/V1CatalogProbabilityCharacterizationTest.php",
    "docs/operations/catalog-probability/README.md",
    "openapi/bundled/public.openapi.json",
    "openapi/admin/openapi.yaml",
    "openapi/bundled/admin.openapi.json",
    "openapi/public/openapi.yaml",
    "packages/storefront-client/src/catalog.ts",
    "packages/storefront-client/src/generated/public.ts",
    "packages/storefront-testkit/src/fixtures.ts",
    "packages/storefront-testkit/src/generated/public-contract.ts",
    *MIG_061I_V2_CATALOG_FILES,
    *MIG_061K_V2_CATALOG_FILES,
    *MIG_061L_V2_CATALOG_FILES,
    *MIG_061R_V2_CATALOG_FILES,
    *MIG_061S_V2_CATALOG_FILES,
    *MIG_062H_V2_CATALOG_FILES,
    *MIG_062I_V2_CATALOG_FILES,
    *MIG_062L_V2_CATALOG_FILES,
    *MIG_063D_V2_CATALOG_FILES,
    *MIG_063E_V2_CATALOG_FILES,
    *OPS_019_V2_CATALOG_FILES,
    *MIG_067_V2_CATALOG_FILES,
    *MIG_068_V2_CATALOG_FILES,
    *MIG_072_V2_CATALOG_FILES,
}
MIG_062J_V2_DRAW_FILES = {
    "apps/api/database/migrations-v2/2026_09_01_000046_allow_v2_partial_remaining_draw_execution.php",
}
V2_DRAW_REQUIRED_FILES = {
    "apps/api/app/Domain/Draw/Exceptions/V2DrawException.php",
    "apps/api/app/Domain/Draw/Services/V2CryptographicRandomSource.php",
    "apps/api/app/Domain/Draw/Services/V2DrawEligibilityService.php",
    "apps/api/app/Domain/Draw/Services/V2DrawService.php",
    "apps/api/app/Domain/Draw/Services/V2DrawTransactionRunner.php",
    "apps/api/app/Http/Controllers/V2/V2DrawController.php",
    "apps/api/app/Models/V2/DrawRequest.php",
    "apps/api/app/Models/V2/DrawResult.php",
    "apps/api/app/Models/V2/GachaDrawState.php",
    "apps/api/app/Models/V2/PrizeInventory.php",
    "apps/api/app/Models/V2/UserPrize.php",
    "apps/api/config/v2_draw.php",
    "apps/api/database/migrations-v2/2026_07_29_000009_create_v2_draw_vertical_slice.php",
    "apps/api/tests/V2/DrawVerticalSliceTest.php",
    "apps/api/tests/V2/V1DrawCharacterizationTest.php",
    "apps/api/tests/V2/Fixtures/v1-draw-characterization.json",
    "apps/api/tests/V2/ZDrawConcurrencyLoadTest.php",
    "docs/operations/draw/README.md",
    "openapi/bundled/public.openapi.json",
    "openapi/public/openapi.yaml",
    "packages/storefront-client/src/draw.ts",
    "packages/storefront-client/src/generated/public.ts",
    "packages/storefront-testkit/src/fixtures.ts",
    "packages/storefront-testkit/src/generated/public-contract.ts",
    *MIG_062J_V2_DRAW_FILES,
}
MIG_062N_V2_PRIZE_SHIPPING_FILES = {
    "apps/api/app/Domain/PrizeShipping/Services/V2AdminUserPrizeReadService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminUserPrizeController.php",
    "apps/api/tests/V2/AdminUserPrizeReadTest.php",
}
V2_PRIZE_SHIPPING_REQUIRED_FILES = {
    "apps/api/app/Domain/PrizeShipping/Exceptions/V2PrizeShippingException.php",
    "apps/api/app/Domain/PrizeShipping/Services/V2PrizeShippingService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminShippingController.php",
    "apps/api/app/Http/Controllers/V2/V2PrizeShippingController.php",
    "apps/api/app/Models/V2/PrizeExchangeRequest.php",
    "apps/api/app/Models/V2/ShippingAddress.php",
    "apps/api/app/Models/V2/ShippingRequest.php",
    "apps/api/config/v2_prize_shipping.php",
    "apps/api/database/migrations-v2/2026_07_30_000010_create_v2_prize_shipping_vertical_slice.php",
    "apps/api/tests/V2/PrizeShippingVerticalSliceTest.php",
    "docs/operations/prize-shipping/README.md",
    "openapi/admin/openapi.yaml",
    "openapi/bundled/admin.openapi.json",
    "openapi/bundled/public.openapi.json",
    "openapi/public/openapi.yaml",
    "packages/storefront-client/src/generated/public.ts",
    "packages/storefront-client/src/prize-shipping.ts",
    "packages/storefront-testkit/src/fixtures.ts",
    "packages/storefront-testkit/src/generated/public-contract.ts",
    *MIG_062N_V2_PRIZE_SHIPPING_FILES,
}
MIG_062M_V2_QA_DRAW_FILES = {
    "apps/api/app/Models/V2/QaGachaGuaranteeAssignment.php",
    "apps/api/database/migrations-v2/2026_09_04_000049_integrate_v2_qa_test_user_guarantees.php",
    "apps/api/tests/V2/QaTestUserGuaranteeIntegrationTest.php",
    "apps/api/tests/V2/ZQaTestUserGuaranteeConcurrencyTest.php",
}
V2_QA_DRAW_REQUIRED_FILES = {
    "apps/api/app/Domain/Identity/Contracts/V2AdminAuthorizationContext.php",
    "apps/api/app/Domain/Identity/Services/V2AdminFreshMfaAuthorizer.php",
    "apps/api/app/Domain/Identity/Services/V2AdminReauthenticationService.php",
    "apps/api/app/Domain/QaDraw/Exceptions/V2QaDrawException.php",
    "apps/api/app/Domain/QaDraw/Services/V2QaDrawAdminService.php",
    "apps/api/app/Domain/QaDraw/Services/V2QaPlanManagementService.php",
    "apps/api/app/Domain/QaDraw/Services/V2QaDrawResolver.php",
    "apps/api/app/Http/Controllers/V2/V2AdminQaDrawController.php",
    "apps/api/app/Http/Controllers/V2/V2AdminAuthController.php",
    "apps/api/app/Models/V2/QaDrawExecution.php",
    "apps/api/app/Models/V2/QaDrawPlan.php",
    "apps/api/app/Models/V2/QaDrawPlanAssignment.php",
    "apps/api/app/Models/V2/QaDrawPlanItem.php",
    "apps/api/app/Models/V2/QaTestUserMode.php",
    "apps/api/config/v2_qa_draw.php",
    "apps/api/config/v2_identity.php",
    "apps/api/database/migrations-v2/2026_07_31_000011_create_v2_qa_draw_vertical_slice.php",
    "apps/api/database/migrations-v2/2026_08_16_000029_add_v2_qa_plan_management.php",
    "apps/api/tests/V2/QaDrawVerticalSliceTest.php",
    "apps/api/tests/V2/QaPlanManagementTest.php",
    "apps/api/tests/V2/AdminFreshMfaQaTest.php",
    "apps/api/tests/V2/ZQaDrawConcurrencyLoadTest.php",
    "apps/api/tests/V2/ZQaPlanManagementConcurrencyTest.php",
    "docs/operations/qa-draw/README.md",
    "openapi/admin/openapi.yaml",
    "openapi/bundled/admin.openapi.json",
    *MIG_062M_V2_QA_DRAW_FILES,
}
V2_REPORTING_REQUIRED_FILES = {
    "apps/api/app/Console/Commands/V2/CreatePreviousDayPointSnapshot.php",
    "apps/api/app/Console/Commands/V2/RunV2ExportWorker.php",
    "apps/api/app/Domain/Reporting/Exceptions/V2ReportingException.php",
    "apps/api/app/Domain/Reporting/Services/V2CsvWriter.php",
    "apps/api/app/Domain/Reporting/Services/V2ExportRowSource.php",
    "apps/api/app/Domain/Reporting/Services/V2ExportService.php",
    "apps/api/app/Domain/Reporting/Services/V2ExportWorker.php",
    "apps/api/app/Domain/Reporting/Services/V2ReportingCursor.php",
    "apps/api/app/Domain/Reporting/Services/V2ReportingService.php",
    "apps/api/app/Domain/Reporting/ValueObjects/V2ExportDefinition.php",
    "apps/api/app/Domain/Reporting/ValueObjects/V2ReportingPeriod.php",
    "apps/api/app/Http/Controllers/V2/V2AdminReportingController.php",
    "apps/api/app/Models/V2/ExportJob.php",
    "apps/api/config/v2_reporting.php",
    "apps/api/database/migrations-v2/2026_08_01_000012_create_v2_reporting_export_foundation.php",
    "apps/api/routes/admin.php",
    "apps/api/routes/console.php",
    "apps/api/tests/V2/ReportingExportVerticalSliceTest.php",
    "apps/api/tests/Unit/V2ReportingServiceDashboardAggregationTest.php",
    "apps/api/tests/V2/DashboardSalesAggregationApiTest.php",
    "apps/api/tests/V2/ZDashboardSalesAggregationPerformanceTest.php",
    "apps/api/tests/V2/ZReportingExportPerformanceTest.php",
    "docs/operations/reporting/README.md",
    "openapi/admin/openapi.yaml",
    "openapi/bundled/admin.openapi.json",
}
MIG_061P_V2_CONTENT_FILES = {
    "apps/api/database/migrations-v2/2026_08_21_000034_add_v2_banner_management.php",
    "apps/api/tests/V2/AdminBannerManagementTest.php",
}
MIG_061Q_V2_CONTENT_FILES = {
    "apps/api/database/migrations-v2/2026_08_22_000035_add_v2_page_management.php",
    "apps/api/tests/V2/AdminPageManagementTest.php",
}
V2_CONTENT_CONTACT_REQUIRED_FILES = {
    "apps/api/app/Domain/ContentContact/Exceptions/V2ContentContactException.php",
    "apps/api/app/Domain/ContentContact/Services/V2ContentCursor.php",
    "apps/api/app/Domain/ContentContact/Services/V2ContactService.php",
    "apps/api/app/Domain/ContentContact/Services/V2ContentContactAdminService.php",
    "apps/api/app/Domain/ContentContact/Services/V2ContentHtmlSanitizer.php",
    "apps/api/app/Domain/ContentContact/Services/V2ContentReadService.php",
    "apps/api/app/Http/Controllers/V2/V2AdminContentContactController.php",
    "apps/api/app/Http/Controllers/V2/V2ContentContactController.php",
    "apps/api/app/Models/V2/ContactInquiry.php",
    "apps/api/app/Models/V2/ContentBanner.php",
    "apps/api/app/Models/V2/ContentNotice.php",
    "apps/api/app/Models/V2/ContentStaticPage.php",
    "apps/api/app/Models/V2/ContentVersion.php",
    "apps/api/config/v2_content_contact.php",
    "apps/api/database/migrations-v2/2026_08_02_000013_create_v2_content_contact_vertical_slice.php",
    "apps/api/tests/V2/ContentContactVerticalSliceTest.php",
    "apps/api/tests/V2/AdminAnnouncementManagementTest.php",
    "apps/api/tests/V2/AdminContactManagementTest.php",
    "apps/api/tests/V2/ZContentContactPerformanceTest.php",
    "docs/operations/content-contact/README.md",
    "openapi/admin/openapi.yaml",
    "openapi/bundled/admin.openapi.json",
    "openapi/bundled/public.openapi.json",
    "openapi/public/openapi.yaml",
    "packages/storefront-client/src/content-contact.ts",
    "packages/storefront-testkit/src/fixtures.ts",
    *MIG_061P_V2_CONTENT_FILES,
    *MIG_061Q_V2_CONTENT_FILES,
}
LEGACY_FRONTEND_REQUIRED_FILES = {
    "legacy/v1-frontend/.env.example",
    "legacy/v1-frontend/AGENTS.md",
    "legacy/v1-frontend/README.md",
    "legacy/v1-frontend/package.json",
    "legacy/v1-frontend/pnpm-lock.yaml",
    "legacy/v1-frontend/next.config.ts",
    "legacy/v1-frontend/tsconfig.json",
    "legacy/v1-frontend/src/app/page.tsx",
}
MIG_061D_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-navigation-hierarchy.spec.ts",
    "apps/admin/src/app/announcements/new/page.tsx",
    "apps/admin/src/app/announcements/page.tsx",
    "apps/admin/src/app/banners/new/page.tsx",
    "apps/admin/src/app/banners/page.tsx",
    "apps/admin/src/app/catalog/gachas/history/page.tsx",
    "apps/admin/src/app/catalog/gachas/new/page.tsx",
    "apps/admin/src/app/catalog/gachas/simulation/page.tsx",
    "apps/admin/src/app/purchase-plans/new/page.tsx",
    "apps/admin/src/app/purchase-plans/page.tsx",
    "apps/admin/src/app/settings/pages/page.tsx",
    "apps/admin/src/app/settings/referral/page.tsx",
    "apps/admin/src/app/users/history/page.tsx",
    "apps/admin/src/app/users/page.tsx",
    "apps/admin/test/admin-navigation-hierarchy.test.tsx",
}
MIG_061E_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/dashboard-sales-layout.spec.ts",
    "apps/admin/src/components/shell/dashboard-sales-layout.tsx",
    "apps/admin/test/dashboard-sales-layout.test.tsx",
}
MIG_061F_ADMIN_SKELETON_FILES = {
    "apps/admin/src/components/shell/use-dashboard-sales-data.ts",
}
MIG_061G_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-user-read-model.spec.ts",
    "apps/admin/src/app/users/[userPublicId]/gacha-history/page.tsx",
    "apps/admin/src/app/users/[userPublicId]/page.tsx",
    "apps/admin/src/components/users/admin-user-read-workspace.tsx",
    "apps/admin/src/components/users/use-admin-user-read-model.ts",
    "apps/admin/test/admin-user-read-model.test.tsx",
}
MIG_061H_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-user-point-adjustment.spec.ts",
    "apps/admin/src/components/users/admin-user-point-adjustment-modal.tsx",
    "apps/admin/test/admin-user-point-adjustment.test.tsx",
}
MIG_061K_ADMIN_SKELETON_FILES = {
    "apps/admin/src/components/catalog/catalog-gacha-rank-prize-manager.tsx",
    "apps/admin/test/catalog-gacha-rank-prize.test.tsx",
}
MIG_061L_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-gacha-usage-history.spec.ts",
    "apps/admin/src/components/catalog/catalog-gacha-usage-history.tsx",
    "apps/admin/test/catalog-gacha-usage-history.test.tsx",
}
MIG_061M_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-gacha-profit-simulation.spec.ts",
    "apps/admin/src/components/catalog/catalog-gacha-profit-simulation.tsx",
    "apps/admin/src/lib/catalog/gacha-profit-simulation.ts",
    "apps/admin/test/catalog-gacha-profit-simulation.test.tsx",
}
MIG_061N_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-announcement-management.spec.ts",
    "apps/admin/src/app/announcements/[announcementPublicId]/page.tsx",
    "apps/admin/src/components/announcements/announcement-management-workspace.tsx",
    "apps/admin/test/admin-announcement-management.test.tsx",
}
MIG_061O_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-contact-management.spec.ts",
    "apps/admin/src/app/contacts/[contactPublicId]/page.tsx",
    "apps/admin/src/components/contacts/contact-management-workspace.tsx",
    "apps/admin/test/admin-contact-management.test.tsx",
}
MIG_061P_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-banner-management.spec.ts",
    "apps/admin/src/components/banners/banner-management-workspace.tsx",
    "apps/admin/test/admin-banner-management.test.tsx",
}
MIG_061Q_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-page-management.spec.ts",
    "apps/admin/src/app/settings/pages/[pagePublicId]/page.tsx",
    "apps/admin/src/app/settings/pages/new/page.tsx",
    "apps/admin/src/components/pages/page-management-workspace.tsx",
    "apps/admin/test/admin-page-management.test.tsx",
}
MIG_061R_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-gacha-master-edit.spec.ts",
    "apps/admin/src/app/gachas/[gachaPublicCode]/edit/page.tsx",
}
MIG_061S_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-rank-effect-settings.spec.ts",
    "apps/admin/src/components/catalog/rank-effect-settings-workspace.tsx",
    "apps/admin/test/rank-effect-settings.test.tsx",
}
MIG_061T_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-referral-point-settings.spec.ts",
    "apps/admin/src/components/settings/referral-point-settings.tsx",
    "apps/admin/test/referral-point-settings.test.tsx",
}
MIG_061V_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-point-purchase-management.spec.ts",
    "apps/admin/src/app/purchase-plans/[planPublicId]/page.tsx",
    "apps/admin/src/components/point-purchases/point-purchase-management-workspace.tsx",
    "apps/admin/test/admin-point-purchase-management.test.tsx",
}
MIG_062B_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-user-tag-management.spec.ts",
    "apps/admin/src/app/users/tags/page.tsx",
    "apps/admin/src/components/users/admin-user-tag-management.tsx",
    "apps/admin/test/admin-user-tag-management.test.tsx",
}
MIG_062K_ADMIN_SKELETON_FILES = {
    "apps/admin/src/components/users/admin-user-state-management.tsx",
    "apps/admin/test/admin-user-state-management.test.tsx",
}
MIG_062M_ADMIN_SKELETON_FILES = {
    "apps/admin/src/components/catalog/catalog-gacha-qa-guarantee-manager.tsx",
    "apps/admin/src/components/users/admin-user-qa-test-mode.tsx",
    "apps/admin/test/admin-user-qa-test-mode.test.tsx",
    "apps/admin/test/catalog-gacha-qa-guarantee.test.tsx",
}
MIG_062N_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-user-prize-management.spec.ts",
    "apps/admin/src/app/user-prizes/[userPrizeId]/page.tsx",
    "apps/admin/src/app/user-prizes/page.tsx",
    "apps/admin/src/components/user-prizes/admin-user-prize-detail.tsx",
    "apps/admin/src/components/user-prizes/admin-user-prize-list.tsx",
    "apps/admin/test/admin-user-prize-management.test.tsx",
}
MIG_062Q_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-gacha-lifecycle.spec.ts",
    "apps/admin/test/catalog-gacha-lifecycle.test.tsx",
}
MIG_078_ADMIN_SKELETON_FILES = {
    "apps/admin/src/app/settings/mail/[templateKey]/page.tsx",
    "apps/admin/src/app/settings/mail/page.tsx",
    "apps/admin/src/components/mail/mail-template-workspace.tsx",
    "apps/admin/src/components/rich-text/rich-text-editor.tsx",
    "apps/admin/test/admin-mail-template-management.test.tsx",
}
MIG_084_ADMIN_SKELETON_FILES = {
    "apps/admin/e2e/admin-payment-history.spec.ts",
    "apps/admin/src/app/payments/page.tsx",
    "apps/admin/src/components/payments/admin-payment-history.tsx",
    "apps/admin/test/admin-payment-history.test.tsx",
}
ADMIN_SKELETON_FILES = {
    "apps/admin/AGENTS.md",
    "apps/admin/README.md",
    "apps/admin/Dockerfile",
    "apps/admin/e2e/admin-auth-shell.spec.ts",
    "apps/admin/package.json",
    "apps/admin/playwright.config.ts",
    "apps/admin/scripts/generate-admin-contract.mjs",
    "apps/admin/next.config.ts",
    "apps/admin/tsconfig.json",
    "apps/admin/eslint.config.mjs",
    "apps/admin/next-env.d.ts",
    "apps/admin/src/app/auth/enroll/page.tsx",
    "apps/admin/src/app/auth/invitation/page.tsx",
    "apps/admin/src/app/auth/mfa/page.tsx",
    "apps/admin/src/app/auth/recovery/page.tsx",
    "apps/admin/src/app/error.tsx",
    "apps/admin/src/app/forbidden/page.tsx",
    "apps/admin/src/app/globals.css",
    "apps/admin/src/app/layout.tsx",
    "apps/admin/src/app/loading.tsx",
    "apps/admin/src/app/login/page.tsx",
    "apps/admin/src/app/not-found.tsx",
    "apps/admin/src/app/page.tsx",
    "apps/admin/src/app/api/health/route.ts",
    "apps/admin/src/app/catalog/page.tsx",
    "apps/admin/src/app/catalog/[...segments]/page.tsx",
    "apps/admin/src/app/contacts/page.tsx",
    "apps/admin/src/app/content/page.tsx",
    "apps/admin/src/app/qa/page.tsx",
    "apps/admin/src/app/reports/page.tsx",
    "apps/admin/src/app/shipping/page.tsx",
    "apps/admin/src/app/settings/line/page.tsx",
    "apps/admin/src/app/settings/authentication/page.tsx",
    "apps/admin/src/components/auth/admin-auth-provider.tsx",
    "apps/admin/src/components/auth/admin-authentication-settings.tsx",
    "apps/admin/src/components/auth/auth-frame.tsx",
    "apps/admin/src/components/auth/auth-status.tsx",
    "apps/admin/src/components/auth/enrollment-form.tsx",
    "apps/admin/src/components/auth/fresh-mfa-dialog.tsx",
    "apps/admin/src/components/auth/login-form.tsx",
    "apps/admin/src/components/auth/invitation-form.tsx",
    "apps/admin/src/components/auth/mfa-form.tsx",
    "apps/admin/src/components/auth/recovery-panel.tsx",
    "apps/admin/src/components/auth/route-guard.tsx",
    "apps/admin/src/components/catalog/catalog-api-error-boundary.tsx",
    "apps/admin/src/components/catalog/catalog-breadcrumb.tsx",
    "apps/admin/src/components/catalog/catalog-confirmation-dialog.tsx",
    "apps/admin/src/components/catalog/catalog-conflict-boundary.tsx",
    "apps/admin/src/components/catalog/catalog-controls.tsx",
    "apps/admin/src/components/catalog/catalog-data-table.tsx",
    "apps/admin/src/components/catalog/catalog-gacha-forms.tsx",
    "apps/admin/src/components/catalog/gacha-publish-preflight-panel.tsx",
    "apps/admin/src/components/catalog/catalog-gacha-workspace.tsx",
    "apps/admin/src/components/catalog/catalog-probability-workspace.tsx",
    "apps/admin/src/components/catalog/catalog-mutation-form.tsx",
    "apps/admin/src/components/catalog/catalog-overview.tsx",
    "apps/admin/src/components/catalog/catalog-prize-asset-mutation-form.tsx",
    "apps/admin/src/components/catalog/catalog-section-navigation.tsx",
    "apps/admin/src/components/catalog/catalog-workspace.tsx",
    "apps/admin/src/components/catalog/cursor-pagination.tsx",
    "apps/admin/src/components/catalog/public-asset-preview.tsx",
    "apps/admin/src/components/catalog/status-badge.tsx",
    "apps/admin/src/components/navigation/admin-navigation.tsx",
    "apps/admin/src/components/navigation/breadcrumb.tsx",
    "apps/admin/src/components/navigation/navigation-icon.tsx",
    "apps/admin/src/components/line/line-messaging-settings.tsx",
    "apps/admin/src/components/permissions/permission-gate.tsx",
    "apps/admin/src/components/permissions/permission-provider.tsx",
    "apps/admin/src/components/permissions/protected-admin-route.tsx",
    "apps/admin/src/components/qa/qa-execution-panel.tsx",
    "apps/admin/src/components/qa/qa-management-workspace.tsx",
    "apps/admin/src/components/shell/admin-page-header.tsx",
    "apps/admin/src/components/shell/admin-shell.tsx",
    "apps/admin/src/components/shell/dashboard-module-card.tsx",
    "apps/admin/src/components/shell/dashboard-home.tsx",
    "apps/admin/src/components/shell/module-placeholder.tsx",
    "apps/admin/src/components/shell/module-route-page.tsx",
    "apps/admin/src/lib/admin-api/client.ts",
    "apps/admin/src/lib/admin-api/generated.ts",
    "apps/admin/src/lib/admin-api/webauthn.ts",
    "apps/admin/src/lib/catalog/catalog-registry.ts",
    "apps/admin/src/lib/permissions/admin-navigation.ts",
    "apps/admin/src/proxy.ts",
    "apps/admin/test/admin-api-client.test.ts",
    "apps/admin/test/admin-authentication-settings.test.tsx",
    "apps/admin/test/auth-components.test.tsx",
    "apps/admin/test/catalog-read.test.tsx",
    "apps/admin/test/catalog-gacha.test.tsx",
    "apps/admin/test/gacha-publish-preflight.test.tsx",
    "apps/admin/test/catalog-probability.test.tsx",
    "apps/admin/test/permission-provider.test.tsx",
    "apps/admin/test/permissions-navigation.test.tsx",
    "apps/admin/test/qa-management.test.tsx",
    "apps/admin/test/line-messaging-settings.test.tsx",
    "apps/admin/test/security-shell.test.tsx",
    "apps/admin/test/setup.ts",
    "apps/admin/test/webauthn.test.ts",
    "apps/admin/vitest.config.ts",
    *MIG_061D_ADMIN_SKELETON_FILES,
    *MIG_061E_ADMIN_SKELETON_FILES,
    *MIG_061F_ADMIN_SKELETON_FILES,
    *MIG_061G_ADMIN_SKELETON_FILES,
    *MIG_061H_ADMIN_SKELETON_FILES,
    *MIG_061K_ADMIN_SKELETON_FILES,
    *MIG_061L_ADMIN_SKELETON_FILES,
    *MIG_061M_ADMIN_SKELETON_FILES,
    *MIG_061N_ADMIN_SKELETON_FILES,
    *MIG_061O_ADMIN_SKELETON_FILES,
    *MIG_061P_ADMIN_SKELETON_FILES,
    *MIG_061Q_ADMIN_SKELETON_FILES,
    *MIG_061R_ADMIN_SKELETON_FILES,
    *MIG_061S_ADMIN_SKELETON_FILES,
    *MIG_061T_ADMIN_SKELETON_FILES,
    *MIG_061V_ADMIN_SKELETON_FILES,
    *MIG_062B_ADMIN_SKELETON_FILES,
    *MIG_062K_ADMIN_SKELETON_FILES,
    *MIG_062M_ADMIN_SKELETON_FILES,
    *MIG_062N_ADMIN_SKELETON_FILES,
    *MIG_062Q_ADMIN_SKELETON_FILES,
    *MIG_078_ADMIN_SKELETON_FILES,
    *MIG_084_ADMIN_SKELETON_FILES,
}
PACKAGE_SKELETONS = {
    "packages/platform/package.json": "@oripa/platform",
}
ADMIN_DEPENDENCY_VERSIONS = {
    "@tiptap/extension-image": "3.30.3",
    "@tiptap/extension-table": "3.30.3",
    "@tiptap/extension-text-align": "3.30.3",
    "@tiptap/react": "3.30.3",
    "@tiptap/starter-kit": "3.30.3",
    "lucide-react": "0.468.0",
    "next": "16.2.11",
    "react": "19.2.7",
    "react-dom": "19.2.7",
}
ADMIN_DEV_DEPENDENCY_VERSIONS = {
    "@playwright/test": "1.62.0",
    "@testing-library/jest-dom": "6.9.1",
    "@testing-library/react": "16.3.2",
    "@types/node": "25.9.2",
    "@types/react": "19.2.17",
    "@types/react-dom": "19.2.3",
    "eslint": "9.39.4",
    "eslint-config-next": "16.2.11",
    "jsdom": "30.0.0",
    "typescript": "6.0.3",
    "vitest": "4.1.10",
}
WORKSPACE_REQUIRED_FILES.update(
    ADMIN_SKELETON_FILES | {".github/workflows/platform-ci.yml"}
)
ROOT_DEV_DEPENDENCY_VERSIONS = {
    "@redocly/cli": "2.40.0",
}
STOREFRONT_CLIENT_DEV_DEPENDENCY_VERSIONS = {
    "eslint": "9.39.4",
    "openapi-typescript": "7.13.0",
    "typescript": "5.9.3",
    "typescript-eslint": "8.65.0",
}
SITE_SCHEMA_DEPENDENCY_VERSIONS = {
    "ajv": "8.20.0",
    "semver": "7.8.5",
}
SITE_SCHEMA_DEV_DEPENDENCY_VERSIONS = {
    "@types/semver": "7.7.1",
    "eslint": "9.39.4",
    "typescript": "5.9.3",
    "typescript-eslint": "8.65.0",
}
STOREFRONT_TESTKIT_DEV_DEPENDENCY_VERSIONS = {
    "eslint": "9.39.4",
    "typescript": "5.9.3",
    "typescript-eslint": "8.65.0",
}
BOUNDARY_READMES = {
    "apps/README.md",
    "apps/api/README.md",
    "apps/admin/README.md",
    "packages/README.md",
    "packages/platform/README.md",
    "packages/storefront-client/README.md",
    "packages/site-schema/README.md",
    "packages/storefront-testkit/README.md",
    "openapi/README.md",
    "infrastructure/README.md",
    "deployments/README.md",
    "manifests/README.md",
    "legacy/README.md",
    "legacy/v1/README.md",
}
BOUNDARY_HEADINGS = {
    "Responsibility",
    "Ownership",
    "Planned Components",
    "Allowed Scope",
    "Forbidden Scope",
    "Status",
}
RELEASE_MANIFEST_REQUIRED = {
    "schema_version",
    "platform",
    "contracts",
    "packages",
    "images",
    "database",
    "runtimes",
    "rollback_classification",
    "required_checks",
    "known_issues_asset",
    "sbom_assets",
    "provenance_asset",
    "secret_scan",
    "production_go",
}
DEPLOYMENT_MANIFEST_REQUIRED = {
    "schema_version",
    "site_id",
    "environment",
    "platform_version",
    "package_versions",
    "image_digest",
    "migration_revision",
    "deployed_at",
    "approved_by",
    "source_release_manifest",
}
SEMANTIC_VERSION = re.compile(
    r"^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$"
)
REQUIRED_PR_HEADINGS = {
    "Task",
    "Summary",
    "Specification sources",
    "Scope",
    "Verification performed",
    "Verification not performed",
}
CURRENT_SECURITY = (
    "V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md"
)
OBSOLETE_SECURITY = (
    "V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_2026-07-22.md"
)
CURRENT_GOVERNANCE = "V2_CODEX_GIT_CI_GOVERNANCE_FINAL_REV2_2026-07-23.md"
CURRENT_RELEASE_GATES = "V2_RELEASE_GATES_FINAL_REV1_2026-07-23.md"


class PolicyFailure(RuntimeError):
    """A deterministic policy violation."""


def run_git(repository: Path, *arguments: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(repository), *arguments],
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )
    if result.returncode:
        raise PolicyFailure(f"git command failed: {' '.join(arguments)}")
    return result.stdout


def tracked_paths(repository: Path) -> list[str]:
    return [
        line
        for line in run_git(repository, "ls-files").splitlines()
        if line.strip()
    ]


def changed_paths(repository: Path, base_sha: str, head_sha: str) -> list[str]:
    if not FULL_SHA.fullmatch(base_sha) or not FULL_SHA.fullmatch(head_sha):
        raise PolicyFailure("pull request base or head SHA is not full length")
    output = run_git(repository, "diff", "--name-only", f"{base_sha}...{head_sha}")
    return sorted(line for line in output.splitlines() if line)


def markdown_headings(body: str) -> set[str]:
    return {
        match.group(1).strip()
        for match in re.finditer(r"^#{2,3}\s+(.+?)\s*$", body, re.MULTILINE)
    }


def metadata_value(body: str, label: str) -> str:
    match = re.search(
        rf"^-\s+{re.escape(label)}:\s*`?([^`\n]+?)`?\s*$",
        body,
        re.MULTILINE,
    )
    if not match:
        raise PolicyFailure(f"pull request metadata missing: {label}")
    return match.group(1).strip()


def section_bullets(body: str, heading: str) -> list[str]:
    match = re.search(
        rf"^###\s+{re.escape(heading)}\s*$([\s\S]*?)(?=^#{{2,3}}\s+|\Z)",
        body,
        re.MULTILINE,
    )
    if not match:
        raise PolicyFailure(f"pull request section missing: {heading}")
    values = []
    for line in match.group(1).splitlines():
        item = re.match(r"^\s*-\s+(.+?)\s*$", line)
        if not item:
            continue
        value = item.group(1).strip().strip("`")
        if value.startswith("/"):
            value = value[1:]
        if value and value != "-":
            values.append(value)
    if not values:
        raise PolicyFailure(f"pull request section is empty: {heading}")
    return values


def declared_path_allowed(path: str, declared_paths: Iterable[str]) -> bool:
    for declared in declared_paths:
        if path == declared:
            return True
        if declared.endswith("/**") and path.startswith(f"{declared[:-3]}/"):
            return True
    return False


def validate_pr_body(
    body: str,
    title: str,
    actual_changed_paths: Iterable[str],
    expected_base_sha: str,
) -> None:
    headings = markdown_headings(body)
    missing_headings = sorted(REQUIRED_PR_HEADINGS - headings)
    if missing_headings:
        raise PolicyFailure(
            "pull request headings missing: " + ", ".join(missing_headings)
        )

    task_id = metadata_value(body, "Task ID")
    risk = metadata_value(body, "Risk")
    base_sha = metadata_value(body, "Base SHA")
    if not TASK_ID.fullmatch(task_id) or task_id not in title:
        raise PolicyFailure("pull request Task ID is invalid or absent from title")
    if risk not in {"R1", "R2", "R3", "R4"}:
        raise PolicyFailure("pull request Risk must be R1 through R4")
    if base_sha != expected_base_sha or not FULL_SHA.fullmatch(base_sha):
        raise PolicyFailure("pull request Base SHA does not match the event base")

    declared_changed = set(section_bullets(body, "Changed files"))
    allowed = set(section_bullets(body, "Allowed paths"))
    actual = set(actual_changed_paths)
    if declared_changed != actual:
        raise PolicyFailure("declared Changed files do not match the Git diff")
    if not all(declared_path_allowed(path, allowed) for path in actual):
        raise PolicyFailure("Git diff includes a path outside declared Allowed paths")
    try:
        lane_policy.validate_pr_lane(body, actual)
    except lane_policy.LanePolicyFailure as error:
        raise PolicyFailure(str(error)) from error


def validate_dangerous_paths(paths: Iterable[str]) -> None:
    findings = []
    for path in paths:
        lowered = path.lower()
        name = Path(lowered).name
        if name == ".env" or (
            name.startswith(".env.")
            and not name.endswith((".example", ".template", ".sample"))
        ):
            findings.append(path)
        if name in {"id_rsa", "id_ed25519", "credentials.json"}:
            findings.append(path)
        if lowered.endswith((".pem", ".key", ".p12", ".pfx")):
            findings.append(path)
        if re.search(r"(?:^|/)(?:dump|backup)[^/]*\.(?:sql|zip|tar|gz)$", lowered):
            findings.append(path)
    if findings:
        raise PolicyFailure(
            "dangerous tracked paths: " + ", ".join(sorted(set(findings)))
        )


def validate_workflow_text(path: str, text: str) -> None:
    if "pull_request_target" in text:
        raise PolicyFailure(f"{path}: pull_request_target is prohibited")
    if re.search(r"^\s*permissions:\s*(?:write-all|read-all)\s*$", text, re.MULTILINE):
        raise PolicyFailure(f"{path}: workflow permissions must be explicit")
    for match in re.finditer(
        r"^(\s+)(actions|checks|contents|deployments|id-token|issues|packages|"
        r"pull-requests|security-events|statuses):\s*write\s*$",
        text,
        re.MULTILINE,
    ):
        indent, permission = match.groups()
        codeql_job_upload = (
            path == ".github/workflows/codeql.yml"
            and permission == "security-events"
            and len(indent) >= 6
            and "github/codeql-action/analyze@" in text
        )
        if not codeql_job_upload:
            raise PolicyFailure(f"{path}: write workflow permission is prohibited")
    if "permissions:" not in text or not re.search(
        r"^\s+contents:\s*read\s*$", text, re.MULTILINE
    ):
        raise PolicyFailure(f"{path}: read-only contents permission is required")
    if "timeout-minutes:" not in text:
        raise PolicyFailure(f"{path}: every workflow requires job timeouts")
    if "concurrency:" not in text:
        raise PolicyFailure(f"{path}: workflow concurrency is required")
    if "secrets." in text:
        raise PolicyFailure(f"{path}: policy workflow must not consume secrets")

    for line in text.splitlines():
        if "uses:" not in line:
            continue
        match = ACTION_REF.fullmatch(line)
        if not match:
            raise PolicyFailure(f"{path}: action is not pinned to a full SHA")

    in_run_block = False
    run_indent = 0
    for line in text.splitlines():
        indent = len(line) - len(line.lstrip())
        if re.match(r"^\s*run:\s*", line):
            in_run_block = True
            run_indent = indent
        elif in_run_block and line.strip() and indent <= run_indent:
            in_run_block = False
        if in_run_block and "${{ github.event.pull_request." in line:
            raise PolicyFailure(
                f"{path}: untrusted pull request input appears in a shell block"
            )


def validate_preview_image_pipeline(repository: Path, paths: Iterable[str]) -> None:
    missing = sorted(PREVIEW_IMAGE_PIPELINE_REQUIRED_FILES - set(paths))
    if missing:
        raise PolicyFailure(
            "required Preview image pipeline files missing: " + ", ".join(missing)
        )
    workflow = (
        repository / ".github/workflows/preview-image-build.yml"
    ).read_text(encoding="utf-8")
    required_workflow = {
        "checks: read",
        "preview_image_artifact.py target --field platform",
        "preview_image_artifact.py host-check",
        "OCI_REVISION=${INPUT_HEAD_SHA}",
        "retention-days: 1",
        "compression-level: 0",
        "external pull request rejected",
        "pull request head mismatch",
        "required checks not successful",
        "storefront_contract_artifact.py build",
        "storefront_contract_artifact.py verify",
        "actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02",
    }
    missing_workflow = sorted(item for item in required_workflow if item not in workflow)
    if missing_workflow:
        raise PolicyFailure(
            "Preview image workflow boundary missing: " + ", ".join(missing_workflow)
        )
    if not re.search(r"^\s+runs-on:\s*ubuntu-24\.04\s*$", workflow, re.MULTILINE):
        raise PolicyFailure("Preview image workflow must use the GitHub-hosted x64 runner")
    if "actions: write" in workflow or "packages: write" in workflow:
        raise PolicyFailure("Preview image workflow permissions are too broad")

    helper = (repository / "scripts/ops/preview_image_artifact.py").read_text(
        encoding="utf-8"
    )
    if any(
        obsolete in workflow + helper
        for obsolete in ("ubuntu-24.04-arm", "linux/arm64", "linux-arm64")
    ):
        raise PolicyFailure("Preview image pipeline contains an obsolete ARM64 target")
    if "docker build" in helper or '["docker", "image", "load"' not in helper:
        raise PolicyFailure("Preview host helper may only load verified images")
    wrapper = (
        repository / "infrastructure/github-app/oripa-github-app-api"
    ).read_text(encoding="utf-8")
    for required in (
        "outer_artifact_digest_mismatch",
        "inner_artifact_verification_failed",
        "pull_request_identity_mismatch",
        "workflow_run_rejected",
    ):
        if required not in wrapper:
            raise PolicyFailure(f"GitHub App artifact boundary missing: {required}")
    runbook = (
        repository / "docs/operations/deployment/preview-image-build.md"
    ).read_text(encoding="utf-8")
    if "--no-build --no-deps api admin" not in runbook:
        raise PolicyFailure("Preview deployment must prohibit host builds")


def validate_basic_structures(repository: Path, paths: Iterable[str]) -> None:
    for relative in paths:
        path = repository / relative
        if path.suffix == ".json":
            try:
                json.loads(path.read_text(encoding="utf-8"))
            except (UnicodeError, json.JSONDecodeError) as error:
                raise PolicyFailure(f"{relative}: invalid JSON") from error
        elif path.suffix in {".yml", ".yaml"}:
            text = path.read_text(encoding="utf-8")
            if not text.strip() or "\t" in text:
                raise PolicyFailure(f"{relative}: invalid basic YAML structure")
        elif path.suffix == ".toml":
            text = path.read_text(encoding="utf-8")
            for number, line in enumerate(text.splitlines(), 1):
                stripped = line.strip()
                if not stripped or stripped.startswith("#"):
                    continue
                if (
                    re.fullmatch(r"\[[A-Za-z0-9_.\"/-]+\]", stripped)
                    or "=" in stripped
                ):
                    continue
                raise PolicyFailure(f"{relative}:{number}: invalid basic TOML line")
        elif path.suffix == ".md":
            try:
                text = path.read_text(encoding="utf-8")
            except UnicodeError as error:
                raise PolicyFailure(f"{relative}: invalid UTF-8 Markdown") from error
            if not text.strip():
                raise PolicyFailure(f"{relative}: empty Markdown")


def load_json(repository: Path, relative: str) -> dict:
    try:
        value = json.loads((repository / relative).read_text(encoding="utf-8"))
    except (UnicodeError, json.JSONDecodeError) as error:
        raise PolicyFailure(f"{relative}: invalid JSON") from error
    if not isinstance(value, dict):
        raise PolicyFailure(f"{relative}: top-level value must be an object")
    return value


def storefront_release_governance(repository: Path) -> dict:
    value = load_json(repository, "manifests/storefront-contract-releases.json")
    try:
        storefront_artifact.validate_governance(value)
    except storefront_artifact.ArtifactError as error:
        raise PolicyFailure(f"Storefront release governance is invalid: {error}") from error
    alpha_24 = next(
        (row for row in value["immutable_history"] if row.get("bundle_version") == "2.0.0-alpha.24"),
        None,
    )
    if not isinstance(alpha_24, dict) or {
        "manifest_sha256": alpha_24.get("manifest_sha256"),
        "source_commit": alpha_24.get("source_commit"),
        "handoff_status": alpha_24.get("handoff_status"),
        "release_mode": alpha_24.get("release_mode"),
        "public_openapi": alpha_24.get("public_openapi"),
        "client": (alpha_24.get("packages") or {}).get("@oripa/storefront-client"),
        "schema": (alpha_24.get("packages") or {}).get("@oripa/site-schema"),
        "testkit": (alpha_24.get("packages") or {}).get("@oripa/storefront-testkit"),
    } != {
        "manifest_sha256": "f71edc9e1c9e9215381d01b00ca066ff8bd2678e8cad92d28fce5981145aad94",
        "source_commit": "209252d9fcbad42090677f5a7bece52c5a5d3597",
        "handoff_status": "released",
        "release_mode": "package-only",
        "public_openapi": {
            "version": "2.0.0-alpha.23",
            "sha256": "5c735fe26514d5bfb47b3515ead108bf473fd5e1f81e0936b7e1986290904043",
            "operation_count": 54,
        },
        "client": {
            "version": "2.0.0-alpha.24",
            "sha256": "fbe156fbbc9f27a07e4017cc9bea3a9cdcd71aa2943e03fb48236bb48bbda259",
            "minimum_public_api_contract": "2.0.0-alpha.23",
            "required_capabilities": [
                "draw.browser-mutation.v2",
                "gacha.catalog-display.v2",
                "gacha.presentation.v2",
                "prize.fulfillment-browser-mutation.v2",
                "user-draw-history.read.v2",
                "user-point.read.v2",
                "user-prize.presentation.v2",
            ],
        },
        "schema": {
            "version": "2.0.0-alpha.23",
            "sha256": "b4ca0ddb0ec8a6f4bda6dfec40fb5f3f5098a837160310be64de97cab36740c2",
            "source_bundle_version": "2.0.0-alpha.23",
            "source_tree": "11f6bee77dd463c2f90352537f817404cf3042bd",
        },
        "testkit": {
            "version": "2.0.0-alpha.24",
            "sha256": "3dc1c3488342846580a2a75372f5d9fff8a510b29d1fad2db468e7276b9efc78",
            "storefront_client_version": "2.0.0-alpha.24",
            "site_schema_version": "2.0.0-alpha.23",
            "public_api_operation_count": 54,
        },
    }:
        raise PolicyFailure("immutable Storefront alpha.24 identity changed")
    return value


def storefront_release_source(repository: Path) -> dict:
    return storefront_artifact.release_source(storefront_release_governance(repository))


def validate_workspace_configuration(repository: Path) -> None:
    release = storefront_release_source(repository)
    package = load_json(repository, "package.json")
    if package.get("name") != "@oripa/platform-workspace":
        raise PolicyFailure("package.json: workspace name is invalid")
    if package.get("version") != release["application_versions"]["workspace"]:
        raise PolicyFailure("package.json: V2 workspace version is invalid")
    if package.get("private") is not True:
        raise PolicyFailure("package.json: root workspace must be private")
    if package.get("packageManager") != "pnpm@10.12.1":
        raise PolicyFailure("package.json: packageManager must match the V1 lockfile")
    if package.get("engines") != {"node": "22.22.3", "pnpm": "10.12.1"}:
        raise PolicyFailure("package.json: Node and pnpm engines must be exact")
    if package.get("dependencies"):
        raise PolicyFailure("package.json: root runtime dependencies are prohibited")
    if package.get("devDependencies") != ROOT_DEV_DEPENDENCY_VERSIONS:
        raise PolicyFailure(
            "package.json: only the pinned OpenAPI validation tool is allowed"
        )
    if package.get("pnpm") != {
        "overrides": {
            "brace-expansion": "5.0.9",
            "fast-uri": "3.1.5",
            "js-yaml": "4.3.1",
            "minimatch": "10.2.5",
            "nanoid": "3.3.18",
            "postcss": "8.5.23",
            "sharp": "0.35.0",
        }
    }:
        raise PolicyFailure("package.json: audited exact pnpm overrides are invalid")

    workspace_text = (repository / "pnpm-workspace.yaml").read_text(encoding="utf-8")
    members = {
        match.group(1).strip().strip("'\"")
        for match in re.finditer(r"^\s*-\s+(.+?)\s*$", workspace_text, re.MULTILINE)
    }
    expected = {"apps/admin", "packages/*"}
    if members != expected:
        raise PolicyFailure(
            "pnpm-workspace.yaml: workspace members must be apps/admin and packages/*"
        )
    if re.search(
        r"(?:^|/)(?:apps/api|backend|frontend|legacy/v1-frontend)(?:/|$)",
        "\n".join(members),
    ):
        raise PolicyFailure(
            "pnpm-workspace.yaml: API and V1 paths must not enter V2 workspace"
        )

    lock_text = (repository / "pnpm-lock.yaml").read_text(encoding="utf-8")
    if not lock_text.startswith("lockfileVersion: '9.0'\n"):
        raise PolicyFailure("pnpm-lock.yaml: lockfileVersion 9.0 is required")
    importer_text = lock_text.split("\npackages:\n", 1)[0]
    importers = set(
        re.findall(r"^  ([A-Za-z0-9@._/-]+):(?: \{\})?$", importer_text, re.MULTILINE)
    )
    expected_importers = {
        ".",
        "apps/admin",
        "packages/platform",
        "packages/site-schema",
        "packages/storefront-client",
        "packages/storefront-testkit",
    }
    if importers != expected_importers:
        raise PolicyFailure("pnpm-lock.yaml: workspace importers are invalid")
    if "legacy/v1-frontend" in importer_text or "apps/api" in importer_text:
        raise PolicyFailure("pnpm-lock.yaml: excluded paths entered the V2 lockfile")

    dependabot = (repository / ".github/dependabot.yml").read_text(encoding="utf-8")
    npm_directories = set(
        re.findall(
            r"package-ecosystem:\s*npm\s+directory:\s*([^ \n]+)",
            dependabot,
            re.MULTILINE,
        )
    )
    if npm_directories != {"/", "/legacy/v1-frontend"}:
        raise PolicyFailure(
            ".github/dependabot.yml: Root and Legacy npm scopes must remain separate"
        )


def validate_exact_dependency_versions(
    package: dict,
    expected_dependencies: dict[str, str],
    expected_dev_dependencies: dict[str, str],
    relative: str,
) -> None:
    if package.get("dependencies", {}) != expected_dependencies:
        raise PolicyFailure(f"{relative}: exact runtime dependencies are invalid")
    if package.get("devDependencies", {}) != expected_dev_dependencies:
        raise PolicyFailure(f"{relative}: exact development dependencies are invalid")
    for section in ("dependencies", "devDependencies"):
        for name, version in package.get(section, {}).items():
            exact_version = (
                version.removeprefix("workspace:")
                if version.startswith("workspace:")
                else version
            )
            if not SEMANTIC_VERSION.fullmatch(exact_version):
                raise PolicyFailure(
                    f"{relative}: dependency {name} must use an exact version"
                )
            if version.startswith("workspace:") and not name.startswith("@oripa/"):
                raise PolicyFailure(
                    f"{relative}: workspace protocol is limited to first-party packages"
                )


def validate_admin_skeleton(repository: Path, paths: Iterable[str]) -> None:
    release = storefront_release_source(repository)
    path_set = set(paths)
    actual = {path for path in path_set if path.startswith("apps/admin/")}
    unexpected = sorted(actual - ADMIN_SKELETON_FILES)
    missing = sorted(ADMIN_SKELETON_FILES - actual)
    if missing:
        raise PolicyFailure("Admin Skeleton files missing: " + ", ".join(missing))
    if unexpected:
        raise PolicyFailure(
            "Admin Skeleton contains unapproved application files: "
            + ", ".join(unexpected)
        )

    package = load_json(repository, "apps/admin/package.json")
    if (
        package.get("name") != "@oripa/admin"
        or package.get("version") != release["application_versions"]["admin"]
        or package.get("private") is not True
        or package.get("packageManager") != "pnpm@10.12.1"
        or package.get("engines") != {"node": "22.22.3", "pnpm": "10.12.1"}
    ):
        raise PolicyFailure("apps/admin/package.json: Admin App identity is invalid")
    validate_exact_dependency_versions(
        package,
        ADMIN_DEPENDENCY_VERSIONS,
        ADMIN_DEV_DEPENDENCY_VERSIONS,
        "apps/admin/package.json",
    )
    required_scripts = {
        "build",
        "dev",
        "generate",
        "generate:check",
        "lint",
        "start",
        "test",
        "test:e2e",
        "typecheck",
    }
    if set(package.get("scripts", {})) != required_scripts:
        raise PolicyFailure("apps/admin/package.json: required scripts are invalid")

    production_source = "\n".join(
        (repository / relative).read_text(encoding="utf-8", errors="replace")
        for relative in sorted(actual)
        if (
            relative.startswith("apps/admin/src/")
            or relative == "apps/admin/scripts/generate-admin-contract.mjs"
        )
        and relative.endswith((".ts", ".tsx", ".mjs"))
    )
    for prohibited in (
        "admin-dashboard",
        "legacy/v1-frontend",
        "Math.random",
        "cookies(",
        "localStorage",
        "sessionStorage",
        "NEXT_PUBLIC_",
    ):
        if prohibited in production_source:
            raise PolicyFailure(
                f"apps/admin: Auth Shell contains prohibited implementation: {prohibited}"
            )
    client = (repository / "apps/admin/src/lib/admin-api/client.ts").read_text(
        encoding="utf-8"
    )
    for required in (
        'const ADMIN_CSRF_COOKIE = "__Host-oripa_admin_xsrf"',
        'credentials: "include"',
        '"X-XSRF-TOKEN"',
        '"X-Request-Id"',
        '"application/problem+json"',
        'redirect: "error"',
        "AbortSignal",
        "path.startsWith(\"/auth/\")",
    ):
        if required not in client:
            raise PolicyFailure(f"apps/admin: Admin API boundary missing {required}")
    if 'headers.set("Authorization"' in client:
        raise PolicyFailure("apps/admin: bearer Authorization storage is prohibited")
    for required in (
        'path.startsWith("/catalog/")',
        "listCatalogCategories",
        "listCatalogTags",
        "listCatalogRanks",
        "listCatalogPrizes",
        "listCatalogPresentationAssets",
    ):
        if required not in client:
            raise PolicyFailure(f"apps/admin: Catalog API boundary missing {required}")

    generated = (
        repository / "apps/admin/src/lib/admin-api/generated.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "Generated from openapi/bundled/admin.openapi.json",
        'ADMIN_API_BASE_PATH = "/admin/api/v2"',
        "AdminMfaVerifyRequest",
        "AdminReauthenticationRequest",
        "RecoveryCodes",
        "AdminEffectivePermissions",
        "AdminPermissionCode",
        "AdminCatalogCategory",
        "AdminCatalogPrize",
        "AdminCatalogPresentationAsset",
    ):
        if required not in generated:
            raise PolicyFailure(f"apps/admin: generated Admin contract missing {required}")

    navigation = (
        repository / "apps/admin/src/lib/permissions/admin-navigation.ts"
    ).read_text(encoding="utf-8")
    for required in (
        '"catalog.read"',
        '"qa.draw.manage"',
        '"shipping.request.manage"',
        '"reporting.financial.read"',
        '"content.read"',
        '"contact.read"',
        "validateNavigation",
        "navigationForPermissions",
    ):
        if required not in navigation:
            raise PolicyFailure(f"apps/admin: permission registry missing {required}")
    for prohibited in (
        "role ===",
        'role ==',
        '"owner"',
        '"admin"',
        '"operator"',
    ):
        if prohibited in navigation:
            raise PolicyFailure(
                f"apps/admin: navigation must not authorize by role: {prohibited}"
            )

    permission_provider = (
        repository / "apps/admin/src/components/permissions/permission-provider.tsx"
    ).read_text(encoding="utf-8")
    protected_route = (
        repository / "apps/admin/src/components/permissions/protected-admin-route.tsx"
    ).read_text(encoding="utf-8")
    for required in (
        "getPermissions",
        "ADMIN_PERMISSION_CODES",
        "response.role !== admin.role",
        "expireSession",
        '"forbidden"',
        '"rate_limited"',
    ):
        if required not in permission_provider:
            raise PolicyFailure(f"apps/admin: PermissionProvider missing {required}")
    for required in (
        "hasPermission(permission)",
        "アクセスできません",
        "安全のため業務モジュールを非表示",
    ):
        if required not in protected_route:
            raise PolicyFailure(f"apps/admin: ProtectedAdminRoute missing {required}")

    proxy = (repository / "apps/admin/src/proxy.ts").read_text(encoding="utf-8")
    for required in (
        "ADMIN_ALLOWED_HOSTS",
        "Content-Security-Policy",
        "frame-ancestors 'none'",
        '"X-Frame-Options": "DENY"',
        '"Cache-Control": "private, no-store"',
        '"X-Robots-Tag": "noindex, nofollow, noarchive"',
    ):
        if required not in proxy:
            raise PolicyFailure(f"apps/admin: security response boundary missing {required}")
    layout = (repository / "apps/admin/src/app/layout.tsx").read_text(
        encoding="utf-8"
    )
    if (
        "index: false" not in layout
        or "follow: false" not in layout
        or 'dynamic = "force-dynamic"' not in layout
        or "await headers()" not in layout
    ):
        raise PolicyFailure("apps/admin: noindex and nofollow metadata are required")
    health = (repository / "apps/admin/src/app/api/health/route.ts").read_text(
        encoding="utf-8"
    )
    if (
        "export function GET" not in health
        or 'status: "ok"' not in health
        or "production_ready: false" not in health
    ):
        raise PolicyFailure("apps/admin: deterministic Skeleton health is required")

    catalog_source = "\n".join(
        (repository / relative).read_text(encoding="utf-8", errors="replace")
        for relative in sorted(actual)
        if relative.startswith("apps/admin/src/components/catalog/")
        or relative.startswith("apps/admin/src/lib/catalog/")
    )
    for required in (
        "ProtectedAdminRoute",
        'permission="catalog.read"',
        "CatalogSectionNavigation",
        "CursorPagination",
        "PublicAssetPreview",
        "safePublicPath",
        "presentation-assets",
    ):
        if required not in catalog_source:
            raise PolicyFailure(f"apps/admin: Catalog read UI missing {required}")
    for prohibited in ("autoplay", "http://", "https://"):
        if prohibited in catalog_source:
            raise PolicyFailure(
                f"apps/admin: Catalog read UI exposes prohibited {prohibited}"
            )
    rank_prize_path = (
        "apps/admin/src/components/catalog/catalog-gacha-rank-prize-manager.tsx"
    )
    profit_simulation_path = (
        "apps/admin/src/components/catalog/catalog-gacha-profit-simulation.tsx"
    )
    catalog_cost_sources = {
        relative: (repository / relative).read_text(
            encoding="utf-8", errors="replace"
        )
        for relative in sorted(actual)
        if relative.startswith("apps/admin/src/components/catalog/")
        or relative.startswith("apps/admin/src/lib/catalog/")
    }
    if any(
        "cost_price" in source
        for relative, source in catalog_cost_sources.items()
        if relative not in {rank_prize_path, profit_simulation_path}
    ):
        raise PolicyFailure("apps/admin: Catalog read UI exposes prohibited cost_price")
    rank_prize_source = catalog_cost_sources.get(rank_prize_path, "")
    if rank_prize_source.count("cost_price") != 4 or "原価" not in rank_prize_source:
        raise PolicyFailure(
            "apps/admin: Draft Gacha Prize cost must remain exactly scoped"
        )
    profit_simulation_source = catalog_cost_sources.get(profit_simulation_path, "")
    if (
        profit_simulation_source.count("cost_price") != 1
        or "入力内容と結果は保存されません" not in profit_simulation_source
    ):
        raise PolicyFailure(
            "apps/admin: Profit simulation cost must remain read-only and exactly scoped"
        )

    workflow = (
        repository / ".github/workflows/platform-ci.yml"
    ).read_text(encoding="utf-8")
    for required in (
        "pnpm admin:generate:check",
        "pnpm admin:typecheck",
        "pnpm admin:lint",
        "pnpm admin:test",
        "pnpm admin:build",
    ):
        if required not in workflow:
            raise PolicyFailure(f"platform-ci Admin Auth Shell verification missing {required}")


def validate_package_skeletons(repository: Path) -> None:
    release = storefront_release_source(repository)
    for relative, expected_name in PACKAGE_SKELETONS.items():
        package = load_json(repository, relative)
        if (
            package.get("name") != expected_name
            or package.get("version") != release["platform_version"]
            or package.get("private") is not True
        ):
            raise PolicyFailure(f"{relative}: Package Skeleton identity is invalid")
        forbidden = {
            "bin",
            "dependencies",
            "devDependencies",
            "exports",
            "main",
            "module",
            "optionalDependencies",
            "peerDependencies",
            "scripts",
        }
        present = sorted(forbidden & set(package))
        if present:
            raise PolicyFailure(
                f"{relative}: Skeleton must not define implementation: "
                + ", ".join(present)
            )


def validate_storefront_client(repository: Path, paths: Iterable[str]) -> None:
    release = storefront_release_source(repository)
    path_set = set(paths)
    missing = sorted(STOREFRONT_CLIENT_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "Storefront Client files missing: " + ", ".join(missing)
        )

    package = load_json(repository, "packages/storefront-client/package.json")
    identity = {
        "name": package.get("name"),
        "version": package.get("version"),
        "private": package.get("private"),
        "type": package.get("type"),
        "sideEffects": package.get("sideEffects"),
        "packageManager": package.get("packageManager"),
        "engines": package.get("engines"),
        "files": package.get("files"),
    }
    if identity != {
        "name": "@oripa/storefront-client",
        "version": release["packages"]["@oripa/storefront-client"]["version"],
        "private": True,
        "type": "module",
        "sideEffects": False,
        "packageManager": "pnpm@10.12.1",
        "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
        "files": ["dist"],
    }:
        raise PolicyFailure(
            "packages/storefront-client/package.json: Alpha identity is invalid"
        )
    validate_exact_dependency_versions(
        package,
        {},
        STOREFRONT_CLIENT_DEV_DEPENDENCY_VERSIONS,
        "packages/storefront-client/package.json",
    )
    expected_scripts = {
        "build",
        "generate",
        "generate:check",
        "lint",
        "test",
        "typecheck",
    }
    if set(package.get("scripts", {})) != expected_scripts:
        raise PolicyFailure(
            "packages/storefront-client/package.json: scripts are invalid"
        )
    if set(package.get("exports", {})) != {
        ".",
        "./browser",
        "./server",
        "./types",
    }:
        raise PolicyFailure(
            "packages/storefront-client/package.json: public exports are invalid"
        )
    if package.get("oripaCompatibility") != {
        "family": 2,
        "apiMajor": 2,
        "minimumPublicApiContract": release["contract_versions"]["public"],
        "requiredCapabilities": [
            "draw.browser-mutation.v2",
            "gacha.catalog-display.v2",
            "gacha.presentation.v2",
            "payment.fincode.v2",
            "prize.fulfillment-browser-mutation.v2",
            "user-draw-history.read.v2",
            "user-point.read.v2",
            "user-prize.presentation.v2",
        ],
    }:
        raise PolicyFailure(
            "packages/storefront-client/package.json: compatibility metadata is invalid"
        )
    if (
        repository / "packages/storefront-client/.gitignore"
    ).read_text(encoding="utf-8").strip() != "/dist/":
        raise PolicyFailure(
            "packages/storefront-client/.gitignore: only dist output must be ignored"
        )

    generated = (
        repository / "packages/storefront-client/src/generated/public.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "This file was auto-generated by openapi-typescript.",
        "registerUser:",
        "loginUser:",
        "logoutUser:",
        "resendUserEmailVerification:",
        "verifyUserEmail:",
        "getUserSession:",
    ):
        if required not in generated:
            raise PolicyFailure(
                "packages/storefront-client: generated Public API types are invalid"
            )

    public_surface = "\n".join(
        (repository / relative).read_text(encoding="utf-8")
        for relative in (
            "packages/storefront-client/src/index.ts",
            "packages/storefront-client/src/types.ts",
            "packages/storefront-client/src/browser.ts",
            "packages/storefront-client/src/server.ts",
        )
    )
    if re.search(r"\b(?:Admin|Webhook)", public_surface):
        raise PolicyFailure(
            "packages/storefront-client: Admin or Webhook type is publicly exported"
        )

    browser = (
        repository / "packages/storefront-client/src/browser.ts"
    ).read_text(encoding="utf-8")
    if 'credentials: "include"' not in browser:
        raise PolicyFailure(
            "packages/storefront-client: Browser credentials must be include"
        )
    transport = (
        repository / "packages/storefront-client/src/transport.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "X-Oripa-Client-Version",
        "X-Oripa-Site-Version",
        "Idempotency-Key",
        "AbortSignal",
        "RETRYABLE_STATUS",
        "csrf_initializer",
        "application/problem+json",
    ):
        if required not in transport and required not in (
            repository / "packages/storefront-client/src/constants.ts"
        ).read_text(encoding="utf-8"):
            raise PolicyFailure(
                f"packages/storefront-client: transport boundary missing {required}"
            )


def validate_site_schema(repository: Path, paths: Iterable[str]) -> None:
    release = storefront_release_source(repository)
    path_set = set(paths)
    missing = sorted(SITE_SCHEMA_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure("Site Schema files missing: " + ", ".join(missing))

    package = load_json(repository, "packages/site-schema/package.json")
    identity = {
        "name": package.get("name"),
        "version": package.get("version"),
        "private": package.get("private"),
        "type": package.get("type"),
        "sideEffects": package.get("sideEffects"),
        "packageManager": package.get("packageManager"),
        "engines": package.get("engines"),
        "files": package.get("files"),
    }
    if identity != {
        "name": "@oripa/site-schema",
        "version": release["packages"]["@oripa/site-schema"]["version"],
        "private": True,
        "type": "module",
        "sideEffects": False,
        "packageManager": "pnpm@10.12.1",
        "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
        "files": ["dist", "schema"],
    }:
        raise PolicyFailure("packages/site-schema/package.json: Alpha identity is invalid")
    validate_exact_dependency_versions(
        package,
        SITE_SCHEMA_DEPENDENCY_VERSIONS,
        SITE_SCHEMA_DEV_DEPENDENCY_VERSIONS,
        "packages/site-schema/package.json",
    )
    if set(package.get("scripts", {})) != {
        "build",
        "generate",
        "generate:check",
        "lint",
        "test",
        "typecheck",
    }:
        raise PolicyFailure("packages/site-schema/package.json: scripts are invalid")
    if set(package.get("exports", {})) != {".", "./schema"}:
        raise PolicyFailure("packages/site-schema/package.json: exports are invalid")
    if package.get("oripaCompatibility") != {
        "family": 2,
        "currentSchemaVersion": "2.0.0-alpha.1",
        "testedSchemaVersions": ["2.0.0-alpha.1"],
        "nMinusOneStatus": "pending-first-minor",
    }:
        raise PolicyFailure(
            "packages/site-schema/package.json: compatibility metadata is invalid"
        )
    if (
        repository / "packages/site-schema/.gitignore"
    ).read_text(encoding="utf-8").strip() != "/dist/":
        raise PolicyFailure("packages/site-schema/.gitignore: only dist may be ignored")

    schema = load_json(repository, "packages/site-schema/schema/site-manifest.schema.json")
    if schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
        raise PolicyFailure("Site Manifest must use JSON Schema Draft 2020-12")
    if schema.get("additionalProperties") is not False:
        raise PolicyFailure("Site Manifest must reject unknown fields")
    if set(schema.get("required", [])) != {
        "schema_version",
        "site_version",
        "compatibility",
        "public",
    }:
        raise PolicyFailure("Site Manifest required fields are invalid")
    properties = schema.get("properties", {})
    compatibility = properties.get("compatibility", {})
    public = properties.get("public", {})
    features = public.get("properties", {}).get("features", {})
    for name, value in (
        ("compatibility", compatibility),
        ("public", public),
        ("public.features", features),
    ):
        if value.get("type") != "object" or value.get("additionalProperties") is not False:
            raise PolicyFailure(f"Site Manifest {name} must be a strict object")
    if compatibility.get("properties", {}).get("family", {}).get("const") != 2:
        raise PolicyFailure("Site Manifest Core Compatibility Family must be 2")
    if (
        features.get("properties", {}).get("enabled", {}).get("default") != []
    ):
        raise PolicyFailure("Site Manifest Feature default must be empty")
    definition_text = json.dumps(schema, sort_keys=True)
    for prohibited in (
        "api_token",
        "cookie",
        "credential",
        "database",
        "password",
        "provider",
        "secret",
    ):
        if prohibited in definition_text.lower():
            raise PolicyFailure(
                f"Site Manifest exposes prohibited field or definition: {prohibited}"
            )

    generated = (
        repository / "packages/site-schema/src/generated/site-manifest.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "generated from schema/site-manifest.schema.json",
        'readonly schema_version: "2.0.0-alpha.1";',
        "readonly family: 2;",
        "readonly required_capabilities: ReadonlyArray<string>;",
    ):
        if required not in generated:
            raise PolicyFailure(
                "packages/site-schema: generated Site Manifest type is invalid"
            )


def validate_storefront_testkit(repository: Path, paths: Iterable[str]) -> None:
    release = storefront_release_source(repository)
    release_packages = release["packages"]
    path_set = set(paths)
    missing = sorted(STOREFRONT_TESTKIT_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure("Storefront Testkit files missing: " + ", ".join(missing))

    package = load_json(repository, "packages/storefront-testkit/package.json")
    identity = {
        "name": package.get("name"),
        "version": package.get("version"),
        "private": package.get("private"),
        "type": package.get("type"),
        "sideEffects": package.get("sideEffects"),
        "packageManager": package.get("packageManager"),
        "engines": package.get("engines"),
        "files": package.get("files"),
    }
    if identity != {
        "name": "@oripa/storefront-testkit",
        "version": release_packages["@oripa/storefront-testkit"]["version"],
        "private": True,
        "type": "module",
        "sideEffects": False,
        "packageManager": "pnpm@10.12.1",
        "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
        "files": ["dist"],
    }:
        raise PolicyFailure(
            "packages/storefront-testkit/package.json: Alpha identity is invalid"
        )
    validate_exact_dependency_versions(
        package,
        {
            "@oripa/site-schema": "workspace:" + release_packages["@oripa/site-schema"]["version"],
            "@oripa/storefront-client": "workspace:" + release_packages["@oripa/storefront-client"]["version"],
        },
        STOREFRONT_TESTKIT_DEV_DEPENDENCY_VERSIONS,
        "packages/storefront-testkit/package.json",
    )
    if set(package.get("scripts", {})) != {
        "build",
        "exports:check",
        "generate",
        "generate:check",
        "lint",
        "network:check",
        "test",
        "typecheck",
    }:
        raise PolicyFailure(
            "packages/storefront-testkit/package.json: scripts are invalid"
        )
    if set(package.get("exports", {})) != {
        ".",
        "./assertions",
        "./fixtures",
        "./mock",
    }:
        raise PolicyFailure(
            "packages/storefront-testkit/package.json: exports are invalid"
        )
    if package.get("oripaCompatibility") != {
        "family": 2,
        "storefrontClientVersion": release_packages["@oripa/storefront-client"]["version"],
        "siteSchemaVersion": release_packages["@oripa/site-schema"]["version"],
        "publicApiOperationCount": release["public_api_operation_count"],
    }:
        raise PolicyFailure(
            "packages/storefront-testkit/package.json: compatibility metadata is invalid"
        )
    if (
        repository / "packages/storefront-testkit/.gitignore"
    ).read_text(encoding="utf-8").strip() != "/dist/":
        raise PolicyFailure(
            "packages/storefront-testkit/.gitignore: only dist may be ignored"
        )

    generated = (
        repository / "packages/storefront-testkit/src/generated/public-contract.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "generated from openapi/bundled/public.openapi.json",
        'openapi: "3.1.1"',
        f"operation_count: {release['public_api_operation_count']}",
        '"completeGoogleOidc","completeLineLogin","completePaymentCardRegistration","confirmPasswordReset","createContactInquiry","createDraw","createPayment","createPaymentCardRegistrationIntent","createShippingAddress","createShippingRequest","deletePaymentCard","deleteShippingAddress","exchangeUserPrizes","getContentNotice","getContentStaticPage","getDrawRequest","getGacha","getGachaBySlug","getGachaPresentation","getLineFriendState","getPayment","getPaymentCardUiBootstrap","getShippingAddress","getShippingRequest","getSmsVerificationStatus","getUserPrize","getUserSession","getWallet","listContentBanners","listContentFooterPages","listContentNotices","listDrawHistory","listExternalIdentities","listGachaCategories","listGachaTags","listGachas","listMyPayments","listPaymentCards","listPointLedgerEntries","listPointProducts","listShippingAddresses","listShippingRequests","listUserPrizes","loginUser","logoutUser","normalizeFincodePaymentFailureReturn","normalizeFincodePaymentReturn","reauthenticateUserPassword","registerUser","requestPasswordReset","resendSmsVerification","resendUserEmailVerification","resumeUnpaidPayment","sendSmsVerification","startGoogleIdentityLink","startGoogleLogin","startGoogleReauthentication","startLineIdentityLink","startLineLogin","startLineReauthentication","unlinkGoogleIdentity","unlinkLineIdentity","updateShippingAddress","verifySmsCode","verifyUserEmail"',
        "bundle_sha256:",
    ):
        if required not in generated:
            raise PolicyFailure(
                "packages/storefront-testkit: generated Public Contract Fixture is invalid"
            )

    public_surface = "\n".join(
        (repository / relative).read_text(encoding="utf-8")
        for relative in (
            "packages/storefront-testkit/src/index.ts",
            "packages/storefront-testkit/src/assertions.ts",
            "packages/storefront-testkit/src/fixtures.ts",
            "packages/storefront-testkit/src/mock.ts",
        )
    )
    if re.search(r"\b(?:Admin|Webhook|Provider)(?:Type|Client|Fixture|Request)", public_surface):
        raise PolicyFailure(
            "packages/storefront-testkit: forbidden surface is publicly exported"
        )
    for prohibited in (
        "globalThis.fetch(",
        "node:http",
        "node:https",
        "node:net",
        "node:tls",
        "undici",
        "XMLHttpRequest",
        "WebSocket",
    ):
        if prohibited in public_surface:
            raise PolicyFailure(
                "packages/storefront-testkit: real network access is prohibited"
            )

    mock = (
        repository / "packages/storefront-testkit/src/mock.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "UnexpectedMockRequestError",
        "requests.push(request)",
        "queue.shift()",
        'kind: "network-error"',
        'kind: "pending"',
        "credentials: init?.credentials",
    ):
        if required not in mock:
            raise PolicyFailure(
                f"packages/storefront-testkit: Mock Transport missing {required}"
            )
    test_source = (
        repository / "packages/storefront-testkit/test/testkit.test.mjs"
    ).read_text(encoding="utf-8")
    if test_source.count("test(") < 12 or "assert." not in test_source:
        raise PolicyFailure(
            "packages/storefront-testkit: substantive assertions are required"
        )
    if re.search(r"\b(?:test|describe|it)\.(?:skip|todo)\b", test_source):
        raise PolicyFailure(
            "packages/storefront-testkit: skipped or no-op tests are prohibited"
        )


def validate_compose_skeletons(repository: Path) -> None:
    v1 = (repository / "docker-compose.yml").read_text(encoding="utf-8")
    for required in ("./apps/api", "./legacy/v1-frontend", "postgres:", "redis:"):
        if required not in v1:
            raise PolicyFailure(f"docker-compose.yml: V1 reference missing {required}")
    if "non-production characterization only" not in v1:
        raise PolicyFailure("docker-compose.yml: V1 non-Production purpose is missing")

    v2 = (repository / "docker-compose.v2.yml").read_text(encoding="utf-8")
    for required in (
        "api:",
        "admin:",
        "postgres:",
        "redis:",
        "healthcheck:",
        "V2_PUBLIC_ORIGIN: ${V2_PUBLIC_ORIGIN:-http://localhost:3000}",
    ):
        if required not in v2:
            raise PolicyFailure(f"docker-compose.v2.yml: required value missing {required}")
    for prohibited in ("legacy/v1-frontend", "container_name:"):
        if prohibited in v2:
            raise PolicyFailure(
                f"docker-compose.v2.yml: prohibited value present {prohibited}"
            )
    if "non-production-skeleton" not in v2 and "never a Production" not in v2:
        raise PolicyFailure("docker-compose.v2.yml: non-Production purpose is missing")
    dockerignore = (repository / ".dockerignore").read_text(encoding="utf-8")
    if not re.search(r"^legacy/v1-frontend$", dockerignore, re.MULTILINE):
        raise PolicyFailure(".dockerignore: Legacy Frontend root-context exclusion missing")


def migration_content_set(repository: Path, relative: str) -> tuple[int, str]:
    files = sorted((repository / relative).glob("*.php"))
    digests = sorted(hashlib.sha256(path.read_bytes()).hexdigest() for path in files)
    payload = ("\n".join(digests) + ("\n" if digests else "")).encode()
    return len(files), hashlib.sha256(payload).hexdigest()


def validate_v2_database_boundary(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(V2_DATABASE_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure("required V2 database baseline files missing: " + ", ".join(missing))

    baseline_path = repository / ".ci/baselines/v1-migrations.json"
    baseline = json.loads(baseline_path.read_text(encoding="utf-8"))
    if baseline.get("schema_version") != "1.0":
        raise PolicyFailure("V1 migration baseline schema is invalid")
    if baseline.get("path") != "apps/api/database/migrations":
        raise PolicyFailure("V1 migration baseline path is invalid")
    count, checksum = migration_content_set(repository, baseline["path"])
    if baseline.get("file_count") != count:
        raise PolicyFailure("V1 migration file count changed")
    if baseline.get("content_sha256_set") != checksum:
        raise PolicyFailure("V1 migration content checksum changed")

    migration_root = repository / "apps/api/database/migrations-v2"
    if not migration_root.is_dir():
        raise PolicyFailure("V2 Migration Path is missing")
    migration_readme = (migration_root / "README.md").read_text(encoding="utf-8")
    for required in (
        "scripts/db/v2_database.py",
        "apps/api/database/migrations-v2",
        "Production",
        "apps/api/database/migrations",
    ):
        if required not in migration_readme:
            raise PolicyFailure(f"V2 Migration Path instructions missing {required}")

    compose = (repository / "docker-compose.v2.yml").read_text(encoding="utf-8")
    for required in (
        "postgres:17-alpine",
        "redis:7-alpine",
        "${V2_DB_DATABASE:?",
        "${V2_DB_USERNAME:?",
        "${V2_DB_PASSWORD:?",
        "${V2_REDIS_PASSWORD:?",
        "${V2_AUDIT_HMAC_KEY:?",
        "${V2_PII_CORRELATION_KEY:?",
        "v2_postgres:/var/lib/postgresql/data",
        "v2_redis:/data",
        "v2_private:",
        "v2_api_egress:",
        "V2_API_EGRESS_SUBNET",
        "internal: true",
    ):
        if required not in compose:
            raise PolicyFailure(f"V2 database Compose boundary missing {required}")
    for prohibited in (
        "container_name:",
        "tenant_id",
        "oripa_postgres_data",
        "oripa_redis_data",
        "v2_skeleton_only",
    ):
        if prohibited in compose:
            raise PolicyFailure(f"V2 database Compose contains prohibited {prohibited}")
    service_blocks = {}
    for service in ("api", "admin", "postgres", "redis"):
        block = re.search(
            rf"(?ms)^  {service}:\n(?P<body>.*?)(?=^  [a-zA-Z0-9_-]+:\n|^networks:)",
            compose,
        )
        if not block:
            raise PolicyFailure(f"V2 database Compose service missing {service}")
        service_blocks[service] = block.group("body")

    for service in ("api", "admin", "postgres", "redis"):
        if "v2_api_egress" in service_blocks[service]:
            raise PolicyFailure(
                f"V2 {service} create-phase egress attachment is prohibited"
            )

    private_network = re.search(
        r"(?ms)^  v2_private:\n(?P<body>.*?)(?=^  [a-zA-Z0-9_-]+:\n|^volumes:)",
        compose,
    )
    egress_network = re.search(
        r"(?ms)^  v2_api_egress:\n(?P<body>.*?)(?=^  [a-zA-Z0-9_-]+:\n|^volumes:)",
        compose,
    )
    if not private_network or "internal: true" not in private_network.group("body"):
        raise PolicyFailure("V2 private network must remain internal")
    if (
        not egress_network
        or "driver: bridge" not in egress_network.group("body")
        or "V2_API_EGRESS_SUBNET" not in egress_network.group("body")
        or "internal: true" in egress_network.group("body")
    ):
        raise PolicyFailure("V2 API egress network boundary is invalid")

    for service in ("postgres", "redis"):
        if re.search(r"(?m)^\s{4}ports:", service_blocks[service]):
            raise PolicyFailure(f"V2 {service} Host Port publication is prohibited")

    runner = (repository / "scripts/db/v2_database.py").read_text(encoding="utf-8")
    for required in (
        'MIGRATION_PATH = "apps/api/database/migrations-v2"',
        'V1_MIGRATION_PATH = "apps/api/database/migrations"',
        "Production or unexpected environment is prohibited",
        "V1 Compose Project is prohibited",
        "V1 Migration Path is prohibited",
        "Unexpected Database or Redis Host",
        "V2 Audit HMAC key",
        "V2 PII correlation key",
        "Database and Redis Host Ports are prohibited",
        "Refusing to remove an unscoped Volume",
    ):
        if required not in runner:
            raise PolicyFailure(f"V2 database Guard missing {required}")
    if "docker system prune" in runner or "docker compose down -v" in runner:
        raise PolicyFailure("V2 database Guard contains an unscoped destructive command")

    workflow = (
        repository / ".github/workflows/platform-ci.yml"
    ).read_text(encoding="utf-8")
    for required in (
        "--path=database/migrations",
        "scripts/db/v2_database.py smoke",
        "--migration-path apps/api/database/migrations-v2",
        "tests/db",
    ):
        if required not in workflow:
            raise PolicyFailure(f"platform-ci V2 database verification missing {required}")


def validate_v2_identity_boundary(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(V2_IDENTITY_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure("required V2 Identity files missing: " + ", ".join(missing))

    migration_files = sorted(
        path.name
        for path in (repository / "apps/api/database/migrations-v2").glob("*.php")
    )
    expected_migrations = [
        "2026_07_24_000001_create_v2_identity_accounts.php",
        "2026_07_24_000002_create_v2_identity_sessions.php",
        "2026_07_24_000003_create_v2_admin_mfa_methods.php",
        "2026_07_24_000004_create_v2_authentication_flows.php",
        "2026_07_24_000005_create_v2_audit_outbox_foundation.php",
        "2026_07_24_000006_create_v2_point_model_foundation.php",
        "2026_07_25_000007_create_v2_payment_model_foundation.php",
        "2026_07_28_000008_create_v2_catalog_probability_foundation.php",
        "2026_07_29_000009_create_v2_draw_vertical_slice.php",
        "2026_07_30_000010_create_v2_prize_shipping_vertical_slice.php",
        "2026_07_31_000011_create_v2_qa_draw_vertical_slice.php",
        "2026_08_01_000012_create_v2_reporting_export_foundation.php",
        "2026_08_02_000013_create_v2_content_contact_vertical_slice.php",
        "2026_08_03_000014_create_v2_password_reset_sms_verification.php",
        "2026_08_04_000015_create_v2_external_identity_google_oidc.php",
        "2026_08_05_000016_add_v2_catalog_master_mutation_foundation.php",
        "2026_08_06_000017_add_v2_catalog_prize_asset_mutation_foundation.php",
        "2026_08_07_000018_add_line_external_identity_provider.php",
        "2026_08_07_000019_create_line_messaging_follow_foundation.php",
        "2026_08_07_000020_add_line_friend_reward_enabled.php",
        "2026_08_08_000021_add_v2_gacha_draft_management.php",
        "2026_08_09_000022_add_v2_probability_draft_management.php",
        "2026_08_10_000023_protect_v2_published_probability_relations.php",
        "2026_08_11_000024_guard_v2_gacha_probability_selection.php",
        "2026_08_12_000025_add_v2_gacha_immediate_publish_activation.php",
        "2026_08_13_000026_create_v2_gacha_publish_schedules.php",
        "2026_08_14_000027_add_v2_gacha_sales_pause.php",
        "2026_08_15_000028_add_v2_gacha_public_deactivation.php",
        "2026_08_16_000029_add_v2_qa_plan_management.php",
        "2026_08_17_000030_create_v2_admin_authentication_policy.php",
        "2026_08_18_000031_add_display_name_to_v2_users.php",
        "2026_08_19_000032_add_v2_gacha_core_management_fields.php",
        "2026_08_20_000033_add_v2_gacha_rank_prize_management.php",
        "2026_08_21_000034_add_v2_banner_management.php",
        "2026_08_22_000035_add_v2_page_management.php",
        "2026_08_23_000036_add_v2_gacha_external_public_code.php",
        "2026_08_24_000037_create_v2_referral_point_settings.php",
        "2026_08_25_000038_add_v2_point_purchase_management.php",
        "2026_08_26_000039_add_v2_line_settings_management.php",
        "2026_08_27_000040_update_v2_session_timeout_constraints.php",
        "2026_08_28_000041_create_v2_user_tag_management.php",
        "2026_08_28_000042_normalize_v2_user_tag_check_constraint.php",
        "2026_08_29_000043_add_v2_point_purchase_plan_target_tag.php",
        "2026_08_30_000044_add_v2_gacha_registration_eligibility_and_management_state.php",
        "2026_08_31_000045_add_v2_gacha_allowed_draw_counts.php",
        "2026_09_01_000046_allow_v2_partial_remaining_draw_execution.php",
        "2026_09_02_000047_add_v2_user_state_revision.php",
        "2026_09_03_000048_add_v2_gacha_prize_ownership_snapshots.php",
        "2026_09_04_000049_integrate_v2_qa_test_user_guarantees.php",
        "2026_09_05_000050_add_v2_static_page_footer_visibility.php",
        "2026_09_06_000051_add_v2_banner_top_presentation.php",
        "2026_09_07_000052_add_v2_gacha_lifecycle_presentation.php",
        "2026_09_08_000053_operational_gacha_inventory.php",
        "2026_09_09_000054_add_v2_coin_expiry_core.php",
        "2026_09_10_000055_add_v2_limited_bonus_domain_core.php",
        "2026_09_11_000056_allow_v2_published_category_tag_presentation_edits.php",
        "2026_09_12_000057_scope_v2_gacha_rank_codes.php",
        "2026_09_12_000060_reconcile_preview_gacha_capacity.php",
        "2026_09_13_000058_canonicalize_v2_gacha_lifecycle_inventory_capacity.php",
        "2026_09_14_000059_internalize_v2_canonical_probability_publish.php",
        "2026_09_15_000061_allow_v2_gacha_unpublished_draft_restore.php",
        "2026_09_16_000062_allow_v2_direct_terminal_gacha_deactivation.php",
        "2026_09_17_000063_allow_v2_closed_user_email_reregistration.php",
        "2026_09_18_000064_add_v2_mail_templates.php",
        "2026_09_21_000065_add_fincode_payment_backend_core.php",
        "2026_09_22_000066_add_v2_verification_failed_user_state.php",
    ]
    if migration_files != expected_migrations:
        raise PolicyFailure("V2 Identity migration set is not exact")

    identity_migrations = "\n".join(
        (repository / "apps/api/database/migrations-v2" / name).read_text(
            encoding="utf-8"
        )
        for name in [
            *expected_migrations[:4],
            "2026_08_03_000014_create_v2_password_reset_sms_verification.php",
            "2026_08_04_000015_create_v2_external_identity_google_oidc.php",
            "2026_08_07_000018_add_line_external_identity_provider.php",
            "2026_09_17_000063_allow_v2_closed_user_email_reregistration.php",
        ]
    )
    for required in (
        "users",
        "admins",
        "user_sessions",
        "admin_sessions",
        "user_remember_devices",
        "admin_webauthn_credentials",
        "admin_totp_methods",
        "admin_recovery_codes",
        "user_email_verifications",
        "admin_invitations",
        "users_verified_email_unique",
        "password_hash",
        "session_id_hash",
        "secret_ciphertext",
        "code_hash",
        "public_key",
        "token_hash",
        "requires_mfa_enrollment",
        "pending_verification",
        "anonymized",
        "owner",
        "operator",
        "password_reset_tokens",
        "user_phone_numbers",
        "sms_verification_challenges",
        "user_phone_numbers_verified_unique",
        "reauthenticated_at",
        "external_identity_accounts",
        "external_identity_transactions",
        "external_identity_account_histories",
        "password_login_enabled",
        "subject_hash",
        "code_verifier_ciphertext",
    ):
        if required not in identity_migrations:
            raise PolicyFailure(f"V2 Identity migration boundary missing {required}")
    for prohibited in (
        "tenant_id",
        "admin_sms",
        "admin_email_mfa",
        "audit_logs",
        "outbox",
        "point_ledgers",
        "payments",
    ):
        if prohibited in identity_migrations:
            raise PolicyFailure(f"V2 Identity migration contains prohibited {prohibited}")

    auth = (repository / "apps/api/config/auth.php").read_text(encoding="utf-8")
    for required in (
        "'v2_user'",
        "'v2_admin'",
        "'v2_realm_session'",
        "'realm' => 'user'",
        "'realm' => 'admin'",
        "App\\Models\\V2\\User::class",
        "App\\Models\\V2\\Admin::class",
    ):
        if required not in auth:
            raise PolicyFailure(f"V2 Auth separation missing {required}")

    config = (repository / "apps/api/config/v2_identity.php").read_text(
        encoding="utf-8"
    )
    for required in (
        "__Host-oripa_user_session",
        "__Host-oripa_admin_session",
        "__Host-oripa_user_xsrf",
        "__Host-oripa_admin_xsrf",
        "'idle_minutes' => 720",
        "'absolute_minutes' => 1440",
        "'idle_minutes' => 360",
        "'absolute_minutes' => 720",
        "'same_site' => 'lax'",
        "'same_site' => 'strict'",
        "'remember' => false",
        "'algorithm' => 'argon2id'",
        "'user_login_failure' => [5, 900]",
        "'admin_login_failure' => [5, 900]",
        "'mfa_verify' => [5, 300]",
        "'password_reset_account' => [3, 3600]",
        "'password_reset_ip' => [10, 3600]",
        "'password_reset_confirm' => [5, 1800]",
        "'sms_phone_hour' => [3, 3600]",
        "'sms_phone_day' => [10, 86400]",
        "'sms_ip' => [5, 3600]",
        "'sms_verify' => [5, 300]",
        "'oidc_login_start' => [10, 600]",
        "'oidc_link_start' => [5, 600]",
        "'oidc_unlink' => [5, 3600]",
    ):
        if required not in config:
            raise PolicyFailure(f"V2 Identity secure default missing {required}")

    password_policy = (
        repository
        / "apps/api/app/Domain/Identity/Services/V2PasswordPolicy.php"
    ).read_text(encoding="utf-8")
    for required in (
        "MIN_LENGTH = 8",
        "MAX_LENGTH = 128",
        "PASSWORD_ARGON2ID",
        "password_needs_rehash",
        "COMMON_PASSWORD_HASHES",
        "#[SensitiveParameter]",
    ):
        if required not in password_policy:
            raise PolicyFailure(f"V2 Password Policy missing {required}")

    mfa_policy = (
        repository / "apps/api/app/Domain/Identity/Services/V2MfaPolicy.php"
    ).read_text(encoding="utf-8")
    if (
        "$authenticatorCount >= 2 && $activeWebauthnCredentials >= 1"
        not in mfa_policy
        or "$authenticatorCount >= 1" not in mfa_policy
    ):
        raise PolicyFailure("V2 Admin MFA secure default is incomplete")

    realm = (
        repository / "apps/api/app/Domain/Identity/Services/V2RealmBoundary.php"
    ).read_text(encoding="utf-8")
    for required in (
        "Unknown HTTP surface is denied",
        "Realm switching is denied",
        "Multiple authenticated realms are denied",
        "Browser sessions are denied on webhook surfaces",
        "Admin realm access is denied",
    ):
        if required not in realm:
            raise PolicyFailure(f"V2 Realm boundary missing {required}")

    guard = (
        repository / "apps/api/app/Auth/V2RealmSessionGuard.php"
    ).read_text(encoding="utf-8")
    for required in (
        "hashSessionId",
        "session_id_hash",
        "idle_expires_at",
        "absolute_expires_at",
        "mfa_verified_at",
        "return false",
        "requires_mfa_enrollment",
    ):
        if required not in guard:
            raise PolicyFailure(f"V2 Realm Session Guard missing {required}")

    permission = (
        repository
        / "apps/api/app/Domain/Identity/Services/V2PermissionAuthorizer.php"
    ).read_text(encoding="utf-8")
    if (
        "tryFrom" not in permission
        or "return false" not in permission
        or "effectivePermissions" not in permission
        or permission.count("'catalog.read'") != 3
    ):
        raise PolicyFailure("V2 Permission boundary is not deny-by-default")

    admin_bundle = load_json(repository, "openapi/bundled/admin.openapi.json")
    permission_operation = admin_bundle.get("paths", {}).get(
        "/auth/permissions", {}
    ).get("get", {})
    if permission_operation.get("operationId") != "getAdminEffectivePermissions":
        raise PolicyFailure("Admin effective Permission contract is missing")
    permission_schema = (
        admin_bundle.get("components", {})
        .get("schemas", {})
        .get("AdminEffectivePermissions", {})
    )
    if set(permission_schema.get("required", [])) != {
        "role",
        "permissions",
        "request_id",
    }:
        raise PolicyFailure("Admin effective Permission response is not exact")

    admin_routes = (repository / "apps/api/routes/admin.php").read_text(
        encoding="utf-8"
    )
    controller = (
        repository
        / "apps/api/app/Http/Controllers/V2/V2AdminPermissionController.php"
    ).read_text(encoding="utf-8")
    if (
        "'/permissions'"
        not in admin_routes
        or "V2AdminPermissionController::class" not in admin_routes
        or "effectivePermissions($admin->role)" not in controller
        or "'Cache-Control' => 'private, no-store'" not in controller
    ):
        raise PolicyFailure("Admin effective Permission endpoint is incomplete")

    workflow = (
        repository / ".github/workflows/platform-ci.yml"
    ).read_text(encoding="utf-8")
    runner = (repository / "scripts/db/v2_database.py").read_text(encoding="utf-8")
    for required in (
        "EXPECTED_V2_SCHEMA_INVENTORY",
        '"phpunit.v2.xml"',
        "run_identity_tests",
    ):
        if required not in runner:
            raise PolicyFailure(f"V2 Identity DB verification missing {required}")
    if "mig041-v2-" not in workflow:
        if (
            "mig041a-v2-" not in workflow
            and "mig042-v2-" not in workflow
            and "mig043-v2-" not in workflow
            and "mig044-v2-" not in workflow
            and "mig050-v2-" not in workflow
            and "mig051-v2-" not in workflow
            and "mig052-v2-" not in workflow
            and "mig053-v2-" not in workflow
            and "mig054-v2-" not in workflow
        ):
            raise PolicyFailure("platform-ci V2 Identity project boundary is missing")

    authentication_sources = "\n".join(
        (repository / relative).read_text(encoding="utf-8")
        for relative in (
            "apps/api/app/Domain/Identity/Services/V2UserAuthenticationService.php",
            "apps/api/app/Domain/Identity/Services/V2AdminAuthenticationService.php",
            "apps/api/app/Domain/Identity/Services/V2TotpService.php",
            "apps/api/app/Domain/Identity/Services/V2WebauthnService.php",
            "apps/api/app/Domain/Identity/Services/V2RecoveryCodeService.php",
            "apps/api/app/Http/Middleware/V2/EnforceV2BrowserSecurity.php",
        )
    )
    for required in (
        "INVALID_CREDENTIALS",
        "INVALID_MFA_CODE",
        "hash('sha256'",
        "random_bytes",
        "USER_VERIFICATION_REQUIREMENT_REQUIRED",
        "cross-site",
        "application/json",
        "recovery_code_use",
    ):
        if required not in authentication_sources:
            raise PolicyFailure(f"V2 Authentication secure flow missing {required}")

    public_contract = (
        repository / "openapi/bundled/public.openapi.json"
    ).read_text(encoding="utf-8")
    admin_contract = (
        repository / "openapi/bundled/admin.openapi.json"
    ).read_text(encoding="utf-8")
    for operation_id in (
        "registerUser",
        "loginUser",
        "logoutUser",
        "resendUserEmailVerification",
        "verifyUserEmail",
        "getUserSession",
        "startGoogleLogin",
        "completeGoogleOidc",
        "listExternalIdentities",
        "startGoogleIdentityLink",
        "startGoogleReauthentication",
        "reauthenticateUserPassword",
        "unlinkGoogleIdentity",
        "startLineLogin",
        "completeLineLogin",
        "startLineIdentityLink",
        "startLineReauthentication",
        "unlinkLineIdentity",
    ):
        if operation_id not in public_contract:
            raise PolicyFailure(f"Public Authentication Contract missing {operation_id}")
    if "beginAdminLogin" in public_contract or "verifyAdminMfa" in public_contract:
        raise PolicyFailure("Admin Authentication leaked into Public Contract")
    oidc_sources = "\n".join(
        (repository / relative).read_text(encoding="utf-8")
        for relative in (
            "apps/api/app/Domain/Identity/Services/V2ExternalIdentityService.php",
            "apps/api/app/Domain/Identity/Services/V2GoogleExternalIdentityProvider.php",
            "apps/api/app/Domain/Identity/Services/V2GoogleIdTokenVerifier.php",
            "apps/api/app/Domain/Identity/Services/V2GoogleOidcHttpTransport.php",
        )
    )
    for required in (
        "https://accounts.google.com",
        "https://oauth2.googleapis.com/token",
        "https://www.googleapis.com/oauth2/v3/certs",
        "code_challenge_method",
        "RS256",
        "email_verified",
        "subject_hash",
        "password_login_enabled",
    ):
        if required not in oidc_sources:
            raise PolicyFailure(f"V2 Google OIDC boundary missing {required}")
    for prohibited in (
        "access_token",
        "refresh_token",
        "raw_subject",
        "tenant_id",
        "Math.random",
    ):
        if prohibited in oidc_sources:
            raise PolicyFailure(f"V2 Google OIDC boundary contains prohibited {prohibited}")
    line_sources = "\n".join(
        (repository / relative).read_text(encoding="utf-8")
        for relative in (
            "apps/api/app/Domain/Identity/Services/V2ExternalIdentityService.php",
            "apps/api/app/Domain/Identity/Services/V2LineExternalIdentityProvider.php",
            "apps/api/app/Domain/Identity/Services/V2LineOidcHttpTransport.php",
            "apps/api/database/migrations-v2/2026_08_07_000018_add_line_external_identity_provider.php",
            "apps/api/database/migrations-v2/2026_08_07_000019_create_line_messaging_follow_foundation.php",
            "apps/api/database/migrations-v2/2026_08_07_000020_add_line_friend_reward_enabled.php",
            "apps/api/database/migrations-v2/2026_08_26_000039_add_v2_line_settings_management.php",
            "apps/api/app/Domain/Line/Services/V2LineFriendService.php",
            "apps/api/app/Domain/Line/Services/V2LineMessagingHttpTransport.php",
            "apps/api/app/Domain/Line/Services/V2LineMessagingSettingService.php",
            "apps/api/app/Http/Controllers/V2/V2LineMessagingWebhookController.php",
            "apps/api/config/v2_line.php",
        )
    )
    for required in (
        "https://access.line.me/oauth2/v2.1/authorize",
        "https://api.line.me/oauth2/v2.1/token",
        "https://api.line.me/oauth2/v2.1/verify",
        "https://access.line.me",
        "code_challenge_method",
        "email_scope_enabled",
        "subject_hash",
        "provider = 'google' OR provider = 'line'",
        "LINE_MESSAGING_CHANNEL_SECRET",
        "LINE_MESSAGING_CHANNEL_ACCESS_TOKEN",
        "reward_enabled",
        "MAX_REWARD_POINT_AMOUNT",
        "X-Line-Signature",
        "webhookEventId",
        "line_pending_follows",
        "ManageLineMessaging",
        "https://api.line.me/v2/bot/message/reply",
    ):
        if required not in line_sources:
            raise PolicyFailure(f"V2 LINE Login boundary missing {required}")
    for prohibited in (
        "refresh_token",
        "raw_subject",
        "tenant_id",
        "discovery_url",
        "Math.random",
        "/push",
        "/broadcast",
        "LINE_MESSAGING_CHANNEL_ID",
    ):
        if prohibited in line_sources:
            raise PolicyFailure(f"V2 LINE Login boundary contains prohibited {prohibited}")
    composer_manifest = json.loads(
        (repository / "apps/api/composer.json").read_text(encoding="utf-8")
    )
    if composer_manifest.get("require", {}).get("firebase/php-jwt") != "^7.1":
        raise PolicyFailure("V2 Google OIDC JWT manifest constraint is not approved")
    composer_lock = json.loads(
        (repository / "apps/api/composer.lock").read_text(encoding="utf-8")
    )
    jwt_versions = [
        package.get("version")
        for package in composer_lock.get("packages", [])
        if package.get("name") == "firebase/php-jwt"
    ]
    if jwt_versions != ["v7.1.0"]:
        raise PolicyFailure("V2 Google OIDC JWT resolved version is not exactly v7.1.0")
    for operation_id in (
        "beginAdminLogin",
        "verifyAdminMfa",
        "beginAdminTotpEnrollment",
        "createAdminWebauthnOptions",
        "regenerateAdminRecoveryCodes",
    ):
        if operation_id not in admin_contract:
            raise PolicyFailure(f"Admin Authentication Contract missing {operation_id}")


def validate_v2_audit_outbox_boundary(
    repository: Path, paths: Iterable[str]
) -> None:
    path_set = set(paths)
    missing = sorted(V2_AUDIT_OUTBOX_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required V2 Audit／Outbox files missing: " + ", ".join(missing)
        )

    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_07_24_000005_create_v2_audit_outbox_foundation.php"
    ).read_text(encoding="utf-8")
    for required in (
        "audit_logs",
        "audit_daily_digests",
        "outbox_messages",
        "previous_hash",
        "record_hash",
        "hmac_key_version",
        "deduplication_key",
        "lease_expires_at",
        "FOR EACH ROW EXECUTE FUNCTION v2_reject_audit_mutation",
        "BEFORE TRUNCATE",
        "REVOKE UPDATE, DELETE, TRUNCATE",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 Audit／Outbox migration missing {required}")
    if "tenant_id" in migration:
        raise PolicyFailure("V2 Audit／Outbox migration contains tenant_id")

    audit_config = (repository / "apps/api/config/v2_audit.php").read_text(
        encoding="utf-8"
    )
    compose = (repository / "docker-compose.v2.yml").read_text(encoding="utf-8")
    runner = (repository / "scripts/db/v2_database.py").read_text(encoding="utf-8")
    for source, required in (
        (audit_config, "V2_AUDIT_HMAC_KEY"),
        (compose, "${V2_AUDIT_HMAC_KEY:?"),
        (runner, '"V2_AUDIT_HMAC_KEY"'),
        (runner, "V2 Audit HMAC key"),
    ):
        if required not in source:
            raise PolicyFailure(f"V2 Audit HMAC boundary missing {required}")

    provider = (
        repository / "apps/api/app/Providers/V2AuthorizationServiceProvider.php"
    ).read_text(encoding="utf-8")
    if "V2PersistentSecurityEventSink::class" not in provider:
        raise PolicyFailure("V2 persistent Audit binding is missing")
    if (
        "V2EmailVerificationNotifier::class" not in provider
        or "V2MailEmailVerificationNotifier::class" not in provider
        or "V2OutboxEmailVerificationNotifier::class" in provider
    ):
        raise PolicyFailure("V2 direct email verification binding is missing")

    outbox = (
        repository / "apps/api/app/Domain/Outbox/Services/V2OutboxService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "DB::transactionLevel() < 1",
        "deduplication_key",
        "FOR UPDATE SKIP LOCKED",
        "lease_expires_at",
        "markDelivered",
        "retry",
        "markFailed",
    ):
        if required not in outbox:
            raise PolicyFailure(f"V2 Outbox service missing {required}")
    notifier = (
        repository
        / "apps/api/app/Domain/Outbox/Services/"
        "V2OutboxEmailVerificationNotifier.php"
    ).read_text(encoding="utf-8")
    for required in (
        "Crypt::encryptString",
        "message_ciphertext",
        "identity.email-verification",
    ):
        if required not in notifier:
            raise PolicyFailure(f"V2 encrypted notification boundary missing {required}")

    tests = (
        repository / "apps/api/tests/V2/AuditOutboxFoundationTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_audit_concurrent_writes",
        "test_hash_chain_detects_tampering",
        "test_database_rejects_audit_update_delete_and_truncate",
        "test_outbox_requires_transaction_rolls_back_and_deduplicates",
        "test_outbox_concurrent_claim",
        "test_outbox_claim_lease_retry_success_and_failure_boundaries",
        "test_authentication_and_mfa_security_events_are_persisted",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 Audit／Outbox test missing {required}")


def validate_v2_point_boundary(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(V2_POINT_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required V2 Point Model files missing: " + ", ".join(missing)
        )

    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_07_24_000006_create_v2_point_model_foundation.php"
    ).read_text(encoding="utf-8")
    for required in (
        "wallets",
        "point_operations",
        "point_lots",
        "point_ledger_entries",
        "point_adjustments",
        "point_balance_snapshots",
        "point_reconciliation_runs",
        "point_reconciliation_discrepancies",
        "idempotency_records",
        "paid_reserved_balance <= paid_balance",
        "free_reserved_balance <= free_balance",
        "$table->string('business_key', 191)->unique()",
        "v2_reject_point_immutable_mutation",
        "BEFORE TRUNCATE",
        "REVOKE UPDATE, DELETE, TRUNCATE",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 Point migration missing {required}")
    for prohibited in (
        "tenant_id",
        "payment_adjustments",
        "point_lot_reservations",
        "float",
        "decimal",
    ):
        if prohibited in migration:
            raise PolicyFailure(f"V2 Point migration contains prohibited {prohibited}")

    expiry_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_09_09_000054_add_v2_coin_expiry_core.php"
    ).read_text(encoding="utf-8")
    for required in (
        "legacy_no_expiry",
        "where('point_type', 'paid')",
        "whereNull('expire_at')",
        "v2_reject_new_null_point_expiry",
        "point_lots_new_expiry_guard",
        "v2_reject_point_expiry_mutation",
        "point_lots_expiry_immutable_guard",
        "v2_reject_new_null_adjustment_grant_expiry",
        "point_adjustments_new_expiry_guard",
        "point_adjustments_expiry_immutable_guard",
        "expire_at ASC NULLS LAST, granted_at ASC, id ASC",
        "point_lots_expiration_candidates",
        "expired_paid_amount",
        "point_snapshots_values_check",
    ):
        if required not in expiry_migration:
            raise PolicyFailure(f"V2 Coin Expiry migration missing {required}")
    for prohibited in (
        "UPDATE point_lots SET expire_at",
        "UPDATE point_adjustments SET expire_at",
        "DROP TABLE point_lots",
    ):
        if prohibited in expiry_migration:
            raise PolicyFailure(
                f"V2 Coin Expiry migration contains prohibited {prohibited}"
            )

    expiry_policy = (
        repository
        / "apps/api/app/Domain/Point/Services/V2CoinExpiryPolicy.php"
    ).read_text(encoding="utf-8")
    for required in (
        "public const EXPIRY_DAYS = 180",
        "CarbonImmutable::instance($grantedAt)->startOfSecond()->addDays(self::EXPIRY_DAYS)",
    ):
        if required not in expiry_policy:
            raise PolicyFailure(f"V2 Coin Expiry policy missing {required}")

    service = (
        repository
        / "apps/api/app/Domain/Point/Services/V2PointService.php"
    ).read_text(encoding="utf-8")
    wallet_lock = service.find("lockWallet(")
    lot_lock = service.find("lockConsumableLots(", wallet_lock)
    if wallet_lock < 0 or lot_lock < wallet_lock:
        raise PolicyFailure("V2 Point service does not lock Wallet before ordered Lots")
    for required in (
        "lockForUpdate()",
        "orderByRaw('expire_at ASC NULLS LAST')",
        "orWhere('expire_at', '>', $occurred->toIso8601String())",
        "orderBy('granted_at')",
        "orderBy('id')",
        "'point.free_granted'",
        "'point.consumed'",
        "'point.expired'",
        "INSUFFICIENT_POINT_BALANCE",
    ):
        if required not in service:
            raise PolicyFailure(f"V2 Point service missing {required}")
    if "SKIP LOCKED" in service or "skipLocked" in service:
        raise PolicyFailure("V2 Point Lot consumption must not use SKIP LOCKED")
    if "grantPaid" in service or "adjustPaid" in service:
        raise PolicyFailure("V2 paid Point grant is prohibited before Payment Model")

    point_config = (repository / "apps/api/config/v2_point.php").read_text(
        encoding="utf-8"
    )
    for required in (
        "'business_timezone' => 'Asia/Tokyo'",
        "'max_attempts' => 3",
        "'40001'",
        "'40P01'",
        "'normal_source' => 'succeeded_payment_only'",
        "'enabled' => false",
        "'all_lots' => ['expire_at_nulls_last', 'granted_at', 'id']",
        "'expiry_days' => 180",
    ):
        if required not in point_config:
            raise PolicyFailure(f"V2 Point configuration missing {required}")

    snapshot = (
        repository
        / "apps/api/app/Domain/Point/Services/V2PointSnapshotService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "where('occurred_at', '<', $cutoff)",
        "'ledger_cutoff'",
        "['03-31', '09-30']",
        "'expired_paid_amount'",
        "'point.snapshot_generated'",
    ):
        if required not in snapshot:
            raise PolicyFailure(f"V2 Point snapshot boundary missing {required}")

    reconciliation = (
        repository
        / "apps/api/app/Domain/Point/Services/V2PointReconciliationService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "wallet_lot",
        "wallet_ledger",
        "'resolved' => false",
        "'automatic_repair' => false",
        "'point.reconciliation_completed'",
    ):
        if required not in reconciliation:
            raise PolicyFailure(f"V2 Point reconciliation boundary missing {required}")

    permissions = (
        repository / "apps/api/app/Domain/Identity/Services/V2PermissionAuthorizer.php"
    ).read_text(encoding="utf-8")
    if permissions.count("'point.adjustment.paid.approve'") != 1:
        raise PolicyFailure("paid Point approval must be Owner-only")

    readme = (repository / "docs/operations/point-model/README.md").read_text(
        encoding="utf-8"
    )
    for required in (
        "MIG-044",
        "payment_adjustment_id",
        "Ownerによる自己承認を禁止しない",
        "Walletの予約残高とReservation履歴を照合対象に含める",
    ):
        if required not in readme:
            raise PolicyFailure(f"V2 Point operational boundary missing {required}")

    tests = (repository / "apps/api/tests/V2/PointModelFoundationTest.php").read_text(
        encoding="utf-8"
    )
    for required in (
        "test_wallet_and_lot_constraints",
        "test_consumption_uses_cross_type_fefo_and_legacy_no_expiry_last",
        "test_paid_and_free_expiry_are_idempotent_and_legacy_paid_survives",
        "test_read_and_spend_exclude_due_lots_before_expiration_worker_runs",
        "test_transaction_rollback",
        "test_idempotency_replays",
        "test_same_wallet_concurrent_consumption",
        "test_consumption_and_expiry_conflict",
        "test_deadlock_and_serialization_failures",
        "test_ledger_rebuild_and_snapshot",
        "test_reconciliation_detects_discrepancy_without_repair",
        "test_no_paid_grant_or_public_api",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 Point test missing {required}")


def validate_v2_payment_boundary(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(V2_PAYMENT_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required V2 Payment Model files missing: " + ", ".join(missing)
        )

    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_07_25_000007_create_v2_payment_model_foundation.php"
    ).read_text(encoding="utf-8")
    for required in (
        "point_purchase_plans",
        "payments",
        "payment_status_histories",
        "payment_point_grants",
        "payment_provider_events",
        "payment_provider_event_attempts",
        "payment_provider_operations",
        "payment_adjustments",
        "payment_adjustment_status_histories",
        "payment_adjustment_point_impacts",
        "payment_adjustment_point_operations",
        "point_lot_reservations",
        "paid_point_amount = amount",
        "currency = 'JPY'",
        "source_provider_event_id",
        "v2_reject_payment_immutable_mutation",
        "v2_protect_published_plan",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 Payment migration missing {required}")
    for prohibited in (
        "tenant_id",
        "financial_state",
        "payment_adjustment_prize_actions",
        "float",
        "decimal",
    ):
        if prohibited in migration:
            raise PolicyFailure(
                f"V2 Payment migration contains prohibited {prohibited}"
            )

    service = (
        repository
        / "apps/api/app/Domain/Payment/V2/Services/V2PaymentService.php"
    ).read_text(encoding="utf-8")
    provider_lock = service.find("payment_provider_events")
    payment_lock = service.find("payments", provider_lock)
    wallet_lock = service.find("lockWallet(", payment_lock)
    lot_lock = service.find("point_lots", wallet_lock)
    if min(provider_lock, payment_lock, wallet_lock, lot_lock) < 0 or not (
        provider_lock < payment_lock < wallet_lock < lot_lock
    ):
        raise PolicyFailure("V2 Payment success lock order is invalid")
    for required in (
        "recordVerifiedProviderEvent",
        "confirmSucceeded",
        "reserveFullRefund",
        "resolveRefund",
        "processChargeback",
        "recordChargebackReversal",
        "payment_point_grants",
        "POINT_WALLET_NEGATIVE",
        "V2CoinExpiryPolicy",
        "expiresAt($grantedAt)",
        "orderByRaw('expire_at ASC NULLS LAST')",
        "manual_review",
        "'payment.succeeded'",
        "'payment.refund.requested'",
    ):
        if required not in service:
            raise PolicyFailure(f"V2 Payment service missing {required}")
    for prohibited in (
        "skipLocked",
        "SKIP LOCKED",
        "Math.random",
        "refundPaymentAutomatically",
    ):
        if prohibited in service:
            raise PolicyFailure(f"V2 Payment service contains prohibited {prohibited}")

    fincode_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_09_21_000065_add_fincode_payment_backend_core.php"
    ).read_text(encoding="utf-8")
    for required in (
        "fincode_customers",
        "fincode_card_registration_intents",
        "fincode_cards",
        "fincode_payment_attempts",
        "payment_method",
        "provider_confirmed_at",
    ):
        if required not in fincode_migration:
            raise PolicyFailure(f"fincode Payment migration missing {required}")
    fincode_webhook = (
        repository
        / "apps/api/app/Domain/Payment/V2/Services/V2FincodeWebhookService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "webhook_signature",
        "hash_equals",
        "retrievePayment",
        "CAPTURED",
        "recordVerifiedProviderEvent",
        "applyVerifiedStatus",
    ):
        if required not in fincode_webhook:
            raise PolicyFailure(f"fincode Webhook service missing {required}")
    fincode_payment = (
        repository
        / "apps/api/app/Domain/Payment/V2/Services/V2FincodePaymentService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "createRedirectSession",
        "executeSavedCard",
        "fincode_card_component",
        "KONBINI_UNPAID_LIMIT_REACHED",
        "UNPAID_PAYMENT_NOT_RESUMABLE",
    ):
        if required not in fincode_payment:
            raise PolicyFailure(f"fincode Payment service missing {required}")

    limited_bonus_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_09_10_000055_add_v2_limited_bonus_domain_core.php"
    ).read_text(encoding="utf-8")
    for required in (
        "point_purchase_plan_limited_bonus_campaigns",
        "payment_limited_bonus_snapshots",
        "limited_bonus_point_amount",
        "pg_advisory_xact_lock",
        "tstzrange",
        "'[)'",
        "payment_limited_bonus_snapshots_reject_mutation",
    ):
        if required not in limited_bonus_migration:
            raise PolicyFailure(f"V2 Limited Bonus migration missing {required}")

    limited_bonus_service = (
        repository
        / "apps/api/app/Domain/Payment/V2/Services/"
        "V2LimitedBonusCampaignService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "lockForUpdate()",
        "LIMITED_BONUS_CAMPAIGN_INVALID",
        "LIMITED_BONUS_CAMPAIGN_OVERLAP",
    ):
        if required not in limited_bonus_service:
            raise PolicyFailure(f"V2 Limited Bonus service missing {required}")

    for required in (
        "PROVIDER_OCCURRED_AT_REQUIRED",
        "$event->provider_occurred_at",
        "payment_limited_bonus_snapshots",
        "paymentFreeGrantAmount($payment)",
        "limited_bonus_point_amount",
    ):
        if required not in service:
            raise PolicyFailure(f"V2 Limited Bonus payment integration missing {required}")

    limited_bonus_tests = (
        repository / "apps/api/tests/V2/LimitedBonusDomainCoreTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_campaign_validation_overlap_adjacent_and_off_state",
        "test_concurrent_overlapping_campaign_mutations_are_serialized",
        "test_payment_snapshots_campaign_and_grants_regular_plus_limited_once",
        "test_success_boundaries_use_provider_time_and_disabled_snapshot_is_off",
        "test_missing_canonical_success_time_fails_closed",
        "test_legacy_payment_without_snapshot_never_receives_limited_bonus",
        "test_refund_chargeback_totals_include_granted_bonus_and_reversal_is_manual",
    ):
        if required not in limited_bonus_tests:
            raise PolicyFailure(f"V2 Limited Bonus test missing {required}")

    config = (repository / "apps/api/config/v2_payment.php").read_text(
        encoding="utf-8"
    )
    for required in (
        "'currency' => 'JPY'",
        "'paid_point_per_jpy' => 1",
        "'purchase_bonus_expiry_days'",
        "'provider_call_in_transaction' => false",
        "'refund_mode' => 'single_full_unused'",
        "'chargeback_reversal' => 'manual_review'",
    ):
        if required not in config:
            raise PolicyFailure(f"V2 Payment configuration missing {required}")

    readme = (
        repository / "docs/operations/payment-model/README.md"
    ).read_text(encoding="utf-8")
    for required in (
        "payment_adjustment_prize_actions",
        "user_prizes",
        "Provider結果不明時は予約を維持",
        "Chargeback Reversal",
        "Productionへ推測値を持ち込まない",
    ):
        if required not in readme:
            raise PolicyFailure(f"V2 Payment operational boundary missing {required}")

    tests = (
        repository / "apps/api/tests/V2/PaymentModelFoundationTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_plan_constraints_and_published_financial_values_are_immutable",
        "test_verified_payment_success_grants_paid_and_free_once",
        "test_provider_event_replay_is_deduplicated",
        "test_full_unused_refund_reserves_and_consumes_lots",
        "test_refund_rejects_any_consumed_payment_point",
        "test_failed_refund_releases_and_uncertain_refund_keeps_reservation",
        "test_provider_operation_runs_outside_transaction_and_tracks_unknown_result",
        "test_chargeback_uses_paid_then_free_and_records_shortfall_without_negative_balance",
        "test_chargeback_reversal_never_restores_points_automatically",
        "test_mock_payment_is_fail_closed_in_production",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 Payment test missing {required}")


def validate_v2_catalog_boundary(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(V2_CATALOG_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required V2 Catalog files missing: " + ", ".join(missing)
        )

    rank_scope_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_09_12_000057_scope_v2_gacha_rank_codes.php"
    ).read_text(encoding="utf-8")
    for required in (
        "catalog_ranks_gacha_id_code_unique",
        "UNIQUE (gacha_id, code)",
        "catalog_ranks_unowned_code_unique",
        "catalog_ranks_code_unique",
        "v2_catalog_guard_rank_gacha_scope",
        "Cross-Gacha Rank association is not allowed",
        "Cannot restore global Catalog Rank code uniqueness while duplicate codes exist",
    ):
        if required not in rank_scope_migration:
            raise PolicyFailure(f"V2 Gacha Rank scope migration missing {required}")
    for prohibited in (
        "DELETE FROM catalog_ranks",
        "DISABLE TRIGGER",
        "UPDATE catalog_ranks SET code",
    ):
        if prohibited in rank_scope_migration:
            raise PolicyFailure(
                f"V2 Gacha Rank scope migration contains prohibited {prohibited}"
            )

    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_07_28_000008_create_v2_catalog_probability_foundation.php"
    ).read_text(encoding="utf-8")
    for required in (
        "catalog_categories",
        "catalog_tags",
        "catalog_ranks",
        "catalog_rank_assets",
        "catalog_prizes",
        "catalog_presentation_assets",
        "catalog_gachas",
        "catalog_gacha_versions",
        "catalog_gacha_version_prizes",
        "catalog_probability_versions",
        "catalog_probability_stages",
        "catalog_probability_entries",
        "catalog_minimum_guarantees",
        "catalog_import_runs",
        "1000000",
        "ARRAY['prize'::text,'point_back'::text]",
        "v2_catalog_protect_published",
        "v2_catalog_validate_probability_publish",
        "v2_catalog_validate_gacha_publish",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 Catalog migration missing {required}")
    for prohibited in (
        "tenant_id",
        "no_prize",
        "float",
        "decimal",
    ):
        if prohibited in migration:
            raise PolicyFailure(
                f"V2 Catalog migration contains prohibited {prohibited}"
            )

    mutation_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_05_000016_add_v2_catalog_master_mutation_foundation.php"
    ).read_text(encoding="utf-8")
    for required in (
        "revision",
        "archived_at",
        "Catalog master records cannot be deleted",
        "Catalog master code is immutable",
        "Published Catalog references protect this master record",
    ):
        if required not in mutation_migration:
            raise PolicyFailure(f"V2 Catalog mutation migration missing {required}")
    if "tenant_id" in mutation_migration:
        raise PolicyFailure("V2 Catalog mutation migration contains prohibited tenant_id")

    prize_asset_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_06_000017_add_v2_catalog_prize_asset_mutation_foundation.php"
    ).read_text(encoding="utf-8")
    for required in (
        "catalog_prizes",
        "catalog_presentation_assets",
        "revision",
        "archived_at",
        "cannot be deleted",
        "Presentation Asset object identity is immutable",
        "Published Catalog references protect this master record",
    ):
        if required not in prize_asset_migration:
            raise PolicyFailure(
                f"V2 Catalog Prize/Asset mutation migration missing {required}"
            )
    if "tenant_id" in prize_asset_migration:
        raise PolicyFailure("V2 Catalog Prize/Asset migration contains prohibited tenant_id")

    gacha_draft_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_08_000021_add_v2_gacha_draft_management.php"
    ).read_text(encoding="utf-8")
    for required in (
        "catalog_gachas",
        "catalog_gacha_versions",
        "revision",
        "archived_at",
        "cloned_from_version_id",
        "Catalog Gacha records cannot be deleted",
        "Published Gacha Version is immutable",
        "Published or drawn Gacha references protect this record",
        "gacha_draw_states",
    ):
        if required not in gacha_draft_migration:
            raise PolicyFailure(
                f"V2 Gacha Draft management migration missing {required}"
            )
    if "tenant_id" in gacha_draft_migration:
        raise PolicyFailure("V2 Gacha Draft migration contains prohibited tenant_id")

    probability_draft_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_09_000022_add_v2_probability_draft_management.php"
    ).read_text(encoding="utf-8")
    for required in (
        "catalog_probability_versions",
        "revision",
        "archived_at",
        "cloned_from_probability_version_id",
        "Probability Version records cannot be deleted",
        "Published Probability Version is immutable",
        "Only active Draft Probability Version children are mutable",
    ):
        if required not in probability_draft_migration:
            raise PolicyFailure(
                f"V2 Probability Draft management migration missing {required}"
            )
    if "tenant_id" in probability_draft_migration:
        raise PolicyFailure(
            "V2 Probability Draft migration contains prohibited tenant_id"
        )

    schedule_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_13_000026_create_v2_gacha_publish_schedules.php"
    ).read_text(encoding="utf-8")
    for required in (
        "catalog_gacha_publish_schedules",
        "scheduled",
        "processing",
        "completed",
        "cancelled",
        "failed",
        "scheduled_for",
        "next_attempt_at",
        "lease_expires_at",
        "active_gacha_unique",
        "active_version_unique",
        "Gacha Publish Schedule history cannot be deleted",
        "Invalid Gacha Publish Schedule transition",
        "Gacha Publish Schedule requires its Draft and Published Probability",
        "Scheduled Gacha Master is immutable",
        "Scheduled Gacha Version is immutable",
    ):
        if required not in schedule_migration:
            raise PolicyFailure(
                f"V2 Gacha scheduled publish migration missing {required}"
            )
    if "tenant_id" in schedule_migration:
        raise PolicyFailure(
            "V2 Gacha scheduled publish migration contains prohibited tenant_id"
        )

    sales_pause_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_14_000027_add_v2_gacha_sales_pause.php"
    ).read_text(encoding="utf-8")
    for required in (
        "sales_paused",
        "sales_paused_at",
        "sales_paused_by_admin_public_id",
        "sales_pause_reason_code",
        "sales_resumed_at",
        "sales_last_mutation_request_id",
        "Gacha Sales operation history cannot be deleted",
        "Gacha Sales state requires one Revision transition",
        "Gacha Sales state requires matching active Draw references",
        "Gacha Sales Resume preflight failed",
        "Paused Gacha cannot accept a new Draw Request",
    ):
        if required not in sales_pause_migration:
            raise PolicyFailure(
                f"V2 Gacha sales pause migration missing {required}"
            )
    if "tenant_id" in sales_pause_migration:
        raise PolicyFailure(
            "V2 Gacha sales pause migration contains prohibited tenant_id"
        )

    public_deactivation_migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_15_000028_add_v2_gacha_public_deactivation.php"
    ).read_text(encoding="utf-8")
    for required in (
        "public_deactivated_at",
        "public_deactivated_by_admin_public_id",
        "public_deactivation_request_id",
        "Gacha Public deactivation history cannot be deleted",
        "Gacha Public deactivation requires one paused Revision transition",
        "Gacha Public deactivation conflicts with an active Publish Schedule",
        "Gacha Public deactivation requires matching active references",
    ):
        if required not in public_deactivation_migration:
            raise PolicyFailure(
                f"V2 Gacha Public deactivation migration missing {required}"
            )
    if "tenant_id" in public_deactivation_migration:
        raise PolicyFailure(
            "V2 Gacha Public deactivation migration contains prohibited tenant_id"
        )

    mutation_service = (
        repository
        / "apps/api/app/Domain/Catalog/Services/V2CatalogMasterMutationService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "V2Permission::ManageCatalog",
        "catalog_master_mutation",
        "expected_revision",
        "IDEMPOTENCY_KEY_REUSED",
        "CATALOG_PUBLISHED_REFERENCE_CONFLICT",
        "catalog.change",
    ):
        if required not in mutation_service:
            raise PolicyFailure(f"V2 Catalog mutation service missing {required}")
    for prohibited in ("forceDelete(", "tenant_id"):
        if prohibited in mutation_service:
            raise PolicyFailure(
                f"V2 Catalog mutation service contains prohibited {prohibited}"
            )
    for table in (
        "catalog_categories",
        "catalog_tags",
        "catalog_ranks",
        "catalog_prizes",
        "catalog_presentation_assets",
        "catalog_gachas",
        "catalog_gacha_versions",
    ):
        if re.search(
            rf"DB::table\(['\"]{table}['\"]\)(?:(?!;).)*?->delete\(",
            mutation_service,
            re.DOTALL,
        ):
            raise PolicyFailure(
                f"V2 Catalog mutation service physically deletes {table}"
            )

    schedule_worker = (
        repository
        / "apps/api/app/Domain/Catalog/Services/V2ScheduledGachaPublishWorker.php"
    ).read_text(encoding="utf-8")
    for required in (
        "final class V2ScheduledGachaPublishWorker",
        "activateClaimedGachaPublishSchedule",
        "FOR UPDATE SKIP LOCKED",
        "clock_timestamp()",
        "worker_lease_expired",
    ):
        if required not in schedule_worker:
            raise PolicyFailure(
                f"V2 Gacha scheduled publish worker missing {required}"
            )
    for prohibited in (
        "->delete(",
        "forceDelete(",
        "tenant_id",
        "DISABLE TRIGGER",
        "return 0;",
    ):
        if prohibited in schedule_worker:
            raise PolicyFailure(
                f"V2 Gacha scheduled publish worker contains prohibited {prohibited}"
            )

    service = (
        repository
        / "apps/api/app/Domain/Catalog/Services/V2CatalogReadService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "where('gv.status', 'published')",
        "where('pv.status', 'published')",
        "publish_start_at",
        "publish_end_at",
        "rank_probabilities",
        "minimum_guarantee",
        "next_cursor",
    ):
        if required not in service:
            raise PolicyFailure(f"V2 Catalog read service missing {required}")
    for prohibited in (
        "individual_ppm",
        "unit_cost",
        "secret",
        "credential",
        "lockForUpdate",
        "->insert(",
        "->update(",
        "Math.random",
    ):
        if prohibited in service:
            raise PolicyFailure(
                f"V2 Catalog read service contains prohibited {prohibited}"
            )

    admin_service = (
        repository
        / "apps/api/app/Domain/Catalog/Services/V2AdminCatalogReadService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "V2Permission::ReadCatalog",
        "authorizePermission",
        "Crypt::encryptString",
        "catalog_categories",
        "catalog_tags",
        "catalog_ranks",
        "catalog_prizes",
        "catalog_presentation_assets",
        "'public_path' => $row->is_public ? $row->public_path : null",
    ):
        if required not in admin_service:
            raise PolicyFailure(f"V2 Admin Catalog read service missing {required}")
    for prohibited in (
        "storage_identifier' =>",
        "->insert(",
        "->update(",
        "->delete(",
    ):
        if prohibited in admin_service:
            raise PolicyFailure(
                f"V2 Admin Catalog read service contains prohibited {prohibited}"
            )
    if admin_service.count("'cost_price' => (int) $row->cost_price") != 1:
        raise PolicyFailure(
            "V2 Admin Catalog cost price must remain Draft Gacha scoped"
        )

    admin_bundle = load_json(repository, "openapi/bundled/admin.openapi.json")
    admin_operation_ids = {
        operation.get("operationId")
        for path_item in admin_bundle.get("paths", {}).values()
        if isinstance(path_item, dict)
        for operation in path_item.values()
        if isinstance(operation, dict)
    }
    required_admin_operations = {
        "listAdminCatalogCategories",
        "getAdminCatalogCategory",
        "listAdminCatalogTags",
        "getAdminCatalogTag",
        "listAdminCatalogRanks",
        "getAdminCatalogRank",
        "listAdminCatalogPrizes",
        "getAdminCatalogPrize",
        "listAdminCatalogPresentationAssets",
        "getAdminCatalogPresentationAsset",
        "listAdminRankEffects",
        "getAdminRankEffect",
        "createAdminCatalogCategory",
        "updateAdminCatalogCategory",
        "archiveAdminCatalogCategory",
        "createAdminCatalogTag",
        "updateAdminCatalogTag",
        "archiveAdminCatalogTag",
        "createAdminCatalogRank",
        "updateAdminCatalogRank",
        "archiveAdminCatalogRank",
        "createAdminCatalogPrize",
        "updateAdminCatalogPrize",
        "archiveAdminCatalogPrize",
        "createAdminCatalogPresentationAsset",
        "updateAdminCatalogPresentationAsset",
        "archiveAdminCatalogPresentationAsset",
        "createAdminRankEffect",
        "updateAdminRankEffect",
        "listAdminCatalogGachas",
        "getAdminCatalogGacha",
        "createAdminCatalogGacha",
        "createAdminCatalogGachaCore",
        "updateAdminCatalogGacha",
        "archiveAdminCatalogGacha",
        "listAdminCatalogGachaVersions",
        "getAdminCatalogGachaVersion",
        "createAdminCatalogGachaDraft",
        "cloneAdminCatalogGachaDraft",
        "updateAdminCatalogGachaDraft",
        "archiveAdminCatalogGachaDraft",
        "listAdminGachaPublishedProbabilityCandidates",
        "getAdminGachaProbabilitySelection",
        "selectAdminGachaPublishedProbability",
        "preflightAdminGachaVersionPublish",
        "getAdminGachaPublishState",
        "publishAdminGachaVersionImmediately",
        "getAdminGachaPublishSchedule",
        "preflightAdminGachaVersionPublishSchedule",
        "scheduleAdminGachaVersionPublish",
        "cancelAdminGachaVersionPublishSchedule",
        "getAdminGachaSalesState",
        "preflightAdminGachaSalesPause",
        "pauseAdminGachaSales",
        "preflightAdminGachaSalesResume",
        "resumeAdminGachaSales",
        "getAdminGachaUnpublishState",
        "preflightAdminGachaUnpublish",
        "unpublishAdminGacha",
        "listAdminCatalogProbabilityVersions",
        "getAdminCatalogProbabilityVersion",
        "createAdminCatalogProbabilityDraft",
        "cloneAdminCatalogProbabilityDraft",
        "replaceAdminCatalogProbabilityDraftEntries",
        "validateAdminCatalogProbabilityDraft",
        "preflightAdminCatalogProbabilityPublish",
        "publishAdminCatalogProbabilityDraft",
        "archiveAdminCatalogProbabilityDraft",
        "listAdminGachaVersionRanks",
        "createAdminGachaVersionRank",
        "updateAdminGachaVersionRank",
        "listAdminGachaVersionPrizes",
        "createAdminGachaVersionPrize",
        "updateAdminGachaVersionPrize",
        "listAdminGachaUsageHistory",
        "getAdminGachaUsageHistory",
    }
    if not required_admin_operations.issubset(admin_operation_ids):
        raise PolicyFailure("V2 Admin Catalog operation set is incomplete")
    allowed_admin_catalog_methods = {
        "/catalog/categories": {"get", "post"},
        "/catalog/categories/{catalog_resource_id}": {"get", "put"},
        "/catalog/categories/{catalog_resource_id}/archive": {"post"},
        "/catalog/tags": {"get", "post"},
        "/catalog/tags/{catalog_resource_id}": {"get", "put"},
        "/catalog/tags/{catalog_resource_id}/archive": {"post"},
        "/catalog/ranks": {"get", "post"},
        "/catalog/ranks/{catalog_resource_id}": {"get", "put"},
        "/catalog/ranks/{catalog_resource_id}/archive": {"post"},
        "/catalog/prizes": {"get", "post"},
        "/catalog/prizes/{catalog_resource_id}": {"get", "put"},
        "/catalog/prizes/{catalog_resource_id}/archive": {"post"},
        "/catalog/presentation-assets": {"get", "post"},
        "/catalog/presentation-assets/{catalog_resource_id}": {"get", "put"},
        "/catalog/presentation-assets/{catalog_resource_id}/archive": {"post"},
        "/catalog/presentation-assets/{catalog_resource_id}/content": {"get"},
        "/catalog/rank-effects": {"get", "post"},
        "/catalog/rank-effects/{catalog_resource_id}": {"get", "put"},
        "/catalog/gacha-thumbnails": {"post"},
        "/catalog/gachas": {"get", "post"},
        "/catalog/gachas/core": {"post"},
        "/catalog/gachas/{gacha_id}": {"get", "put"},
        "/catalog/gachas/{gacha_id}/archive": {"post"},
        "/catalog/gachas/{gacha_id}/history": {"get"},
        "/catalog/gachas/{gacha_id}/history/{draw_request_id}": {"get"},
        "/catalog/gachas/{gacha_id}/versions": {"get", "post"},
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}": {"get", "put"},
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/clone": {"post"},
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/archive": {"post"},
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/ranks": {
            "get",
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/ranks/{rank_id}": {
            "put",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/prizes": {
            "get",
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/prizes/{prize_id}": {
            "put",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/published-probability-candidates": {
            "get",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-selection": {
            "get",
            "put",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/publish-preflight": {
            "post",
        },
        "/catalog/gachas/{gacha_id}/publish-state": {"get"},
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/publish": {
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/publish-schedule": {
            "get",
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/publish-schedule/preflight": {
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/publish-schedule/{schedule_id}/cancel": {
            "post",
        },
        "/catalog/gachas/{gacha_id}/sales-state": {"get"},
        "/catalog/gachas/{gacha_id}/sales-pause/preflight": {"post"},
        "/catalog/gachas/{gacha_id}/sales-pause": {"post"},
        "/catalog/gachas/{gacha_id}/sales-resume/preflight": {"post"},
        "/catalog/gachas/{gacha_id}/sales-resume": {"post"},
        "/catalog/gachas/{gacha_id}/qa-guarantees": {"get", "put"},
        "/catalog/gachas/{gacha_id}/qa-guarantees/{user_id}/disable": {"post"},
        "/catalog/gachas/{gacha_id}/unpublish-state": {"get"},
        "/catalog/gachas/{gacha_id}/unpublish/preflight": {"post"},
        "/catalog/gachas/{gacha_id}/unpublish": {"post"},
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-versions": {
            "get",
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-versions/{probability_version_id}": {
            "get",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-versions/{probability_version_id}/clone": {
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-versions/{probability_version_id}/entries": {
            "put",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-versions/{probability_version_id}/validate": {
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-versions/{probability_version_id}/publish-preflight": {
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-versions/{probability_version_id}/publish": {
            "post",
        },
        "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/probability-versions/{probability_version_id}/archive": {
            "post",
        },
    }
    actual_admin_catalog_methods = {
        path: {
            method
            for method, operation in path_item.items()
            if isinstance(operation, dict)
        }
        for path, path_item in admin_bundle.get("paths", {}).items()
        if path.startswith("/catalog/") and isinstance(path_item, dict)
    }
    if actual_admin_catalog_methods != allowed_admin_catalog_methods:
        raise PolicyFailure("V2 Admin Catalog contract contains prohibited mutation")
    admin_catalog_contract = json.dumps(
        {
            "paths": {
                path: value
                for path, value in admin_bundle.get("paths", {}).items()
                if path.startswith("/catalog/")
            },
            "schemas": {
                name: value
                for name, value in admin_bundle.get("components", {})
                .get("schemas", {})
                .items()
                if name.startswith(("AdminCatalog", "AdminGacha"))
                and not name.endswith(("Create", "Update"))
            },
        },
        sort_keys=True,
    ).lower()
    for prohibited in ("storage_identifier", '"patch"', '"delete"'):
        if prohibited in admin_catalog_contract:
            raise PolicyFailure(
                f"V2 Admin Catalog contract contains prohibited {prohibited}"
            )
    admin_catalog_schemas = admin_bundle.get("components", {}).get("schemas", {})
    if "cost_price" in admin_catalog_schemas.get("AdminCatalogPrize", {}).get(
        "properties", {}
    ):
        raise PolicyFailure("V2 Admin Catalog Prize exposes prohibited cost_price")
    draft_prize_schema = admin_catalog_schemas.get("AdminGachaVersionPrize", {})
    if "cost_price" not in draft_prize_schema.get("required", []) or "cost_price" not in (
        draft_prize_schema.get("properties", {})
    ):
        raise PolicyFailure("V2 Draft Gacha Prize contract missing cost_price")

    bundle = load_json(repository, "openapi/bundled/public.openapi.json")
    operation_ids = sorted(
        operation.get("operationId")
        for path_item in bundle.get("paths", {}).values()
        if isinstance(path_item, dict)
        for operation in path_item.values()
        if isinstance(operation, dict) and operation.get("operationId")
    )
    for required in (
        "getGacha",
        "getGachaBySlug",
        "getGachaPresentation",
        "listGachaCategories",
        "listGachaTags",
        "listGachas",
    ):
        if required not in operation_ids:
            raise PolicyFailure(f"Public Catalog contract missing {required}")
    catalog_paths = {
        path: path_item
        for path, path_item in bundle.get("paths", {}).items()
        if path.startswith("/api/v2/gacha")
    }
    catalog_schemas = {
        name: schema
        for name, schema in bundle.get("components", {}).get("schemas", {}).items()
        if name.startswith(
            (
                "Catalog",
                "Cursor",
                "Gacha",
                "MinimumGuarantee",
                "PresentationAsset",
                "Prize",
                "ProbabilityStage",
                "Rank",
            )
        )
    }
    public_contract = json.dumps(
        {"paths": catalog_paths, "schemas": catalog_schemas},
        ensure_ascii=False,
    ).lower()
    for prohibited in (
        "individual_ppm",
        "unit_cost",
        "storage_identifier",
        "secret",
        "credential",
        "internal_id",
    ):
        if prohibited in public_contract:
            raise PolicyFailure(
                f"Public Catalog contract exposes prohibited {prohibited}"
            )

    generated = (
        repository / "packages/storefront-client/src/generated/public.ts"
    ).read_text(encoding="utf-8")
    facade = (
        repository / "packages/storefront-client/src/catalog.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "listGachaCategories",
        "listGachaTags",
        "listGachas",
        "getGacha",
        "getGachaBySlug",
        "getGachaPresentation",
    ):
        if required not in generated or required not in facade:
            raise PolicyFailure(f"Storefront Catalog Client missing {required}")

    tests = (
        repository / "apps/api/tests/V2/CatalogProbabilityFoundationTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_fixture_import_order_checksum_and_replay_are_deterministic",
        "test_probability_stage_below_one_million_ppm_is_rejected",
        "test_probability_stage_above_one_million_ppm_is_rejected",
        "test_published_gacha_probability_and_children_are_immutable",
        "test_public_api_exposes_only_published_period_and_aggregate_probability",
        "test_draft_future_and_expired_versions_are_not_public",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 Catalog test missing {required}")

    publish_tests = (
        repository / "apps/api/tests/V2/AdminProbabilityDraftManagementTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_publish_preflight_and_publish_create_an_immutable_snapshot_only",
        "test_publish_rejects_invalid_archived_and_non_fresh_requests",
        "test_publish_revalidates_totals_and_revision_on_the_server",
        "test_publish_rate_limit_is_fail_closed",
        "test_publish_limiter_failure_is_fail_closed",
        "test_publish_outbox_failure_rolls_back_snapshot_and_idempotency",
    ):
        if required not in publish_tests:
            raise PolicyFailure(f"V2 Probability Publish test missing {required}")
    concurrency_tests = (
        repository / "apps/api/tests/V2/ZAdminProbabilityConcurrencyTest.php"
    ).read_text(encoding="utf-8")
    if "test_concurrent_publish_has_one_immutable_winner" not in concurrency_tests:
        raise PolicyFailure("V2 Probability Publish concurrency test is missing")
    initial_publish_tests = (
        repository / "apps/api/tests/V2/GachaLifecyclePresentationTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_initial_publish_uses_one_draw_state_and_current_overlay",
        "test_initial_publish_rolls_back_activation_on_outbox_failure",
    ):
        if required not in initial_publish_tests:
            raise PolicyFailure(f"V2 Gacha Initial Publish test missing {required}")
    immediate_publish_tests = (
        repository / "apps/api/tests/V2/AdminGachaPublishPreflightTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_activation_database_guards_reject_partial_or_destructive_sql",
        "test_immediate_publish_requires_admin_permission_fresh_mfa_and_csrf",
    ):
        if required not in immediate_publish_tests:
            raise PolicyFailure(f"V2 Gacha Immediate Publish test missing {required}")
    gacha_concurrency_tests = (
        repository
        / "apps/api/tests/V2/ZAdminGachaProbabilitySelectionConcurrencyTest.php"
    ).read_text(encoding="utf-8")
    if (
        "test_concurrent_immediate_publish_has_one_atomic_winner"
        not in gacha_concurrency_tests
    ):
        raise PolicyFailure("V2 Gacha Immediate Publish concurrency test is missing")

    workflow = (
        repository / ".github/workflows/platform-ci.yml"
    ).read_text(encoding="utf-8")
    if (
        "mig050-v2-" not in workflow
        and "mig051-v2-" not in workflow
        and "mig052-v2-" not in workflow
        and "mig053-v2-" not in workflow
        and "mig054-v2-" not in workflow
    ):
        raise PolicyFailure("platform-ci V2 Catalog project boundary is missing")


def validate_v2_draw_boundary(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(V2_DRAW_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure("required V2 Draw files missing: " + ", ".join(missing))

    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_07_29_000009_create_v2_draw_vertical_slice.php"
    ).read_text(encoding="utf-8")
    for required in (
        "gacha_draw_states",
        "prize_inventories",
        "draw_requests",
        "draw_results",
        "user_prizes",
        "payment_adjustment_prize_actions",
        "draw_results_gacha_sequence_unique",
        "v2_reject_draw_history_mutation",
        "ARRAY[1,5,10,100,1000]",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 Draw migration missing {required}")
    for prohibited in ("tenant_id", "no_prize", "float", "decimal"):
        if prohibited in migration:
            raise PolicyFailure(f"V2 Draw migration contains prohibited {prohibited}")

    service = (
        repository / "apps/api/app/Domain/Draw/Services/V2DrawService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "$this->transactions->run",
        "$this->idempotency->claim",
        "$this->idempotency->complete",
        "random->integer(1, $totalWeight)",
        "lockForUpdate()",
        "lockInventories",
        "remainingInventory",
        "$remainingCount = $this->remainingInventory($inventories)",
        "min($drawCount, $remainingCount)",
        "availableInventory",
        "totalInventoryWeight",
        "pickInventory",
        "prizeOutcomeRow",
        "persistInventory",
        "available_quantity",
        "awarded_count",
        "sold_count",
        "response_data",
        "array_chunk",
        "consumeForDraw",
        "draw.idempotent_replay",
        "draw.completed",
        "draw.events",
    ):
        if required not in service:
            raise PolicyFailure(f"V2 Draw service missing {required}")
    for prohibited in (
        "Math.random",
        "mt_rand(",
        "rand(",
        "random->integer(0, 999_999)",
        "SKIP LOCKED",
        "tenant_id",
        "individual_ppm",
        "no_prize",
        "catalog_probability_entries",
        "catalog_minimum_guarantees",
        "grantDrawPointBackBatch(",
    ):
        if prohibited in service:
            raise PolicyFailure(
                f"V2 Draw service contains prohibited legacy selection {prohibited}"
            )

    eligibility = (
        repository / "apps/api/app/Domain/Draw/Services/V2DrawEligibilityService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "first_time_eligible_days",
        "created_at",
        "addDays($eligibleDays)",
        "greaterThanOrEqualTo($registeredAt)",
        "lessThanOrEqualTo($registeredAt->addDays($eligibleDays))",
    ):
        if required not in eligibility:
            raise PolicyFailure(f"V2 Draw eligibility service missing {required}")
    if "DB::table('draw_requests')" in eligibility:
        raise PolicyFailure(
            "V2 Draw eligibility service derives first-time status from Draw history"
        )

    bundle = load_json(repository, "openapi/bundled/public.openapi.json")
    operations = {
        operation.get("operationId")
        for path_item in bundle.get("paths", {}).values()
        if isinstance(path_item, dict)
        for operation in path_item.values()
        if isinstance(operation, dict)
    }
    for required in ("createDraw", "getDrawRequest"):
        if required not in operations:
            raise PolicyFailure(f"Public Draw contract missing {required}")
    public_contract = json.dumps(bundle, ensure_ascii=False).lower()
    for prohibited in (
        "individual_ppm",
        "random_value",
        "internal_id",
        "cost_price",
    ):
        if prohibited in public_contract:
            raise PolicyFailure(f"Public Draw contract exposes prohibited {prohibited}")

    client = (
        repository / "packages/storefront-client/src/draw.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "createDraw",
        "getDrawRequest",
        "idempotency_key",
        'csrf: "required"',
        "1 | 5 | 10 | 100 | 1000",
    ):
        if required not in client:
            raise PolicyFailure(f"Storefront Draw Client missing {required}")

    tests = (
        repository / "apps/api/tests/V2/DrawVerticalSliceTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_all_allowed_counts_persist_ordered_results_and_compact_bulk_response",
        "test_locked_remaining_inventory_is_the_dynamic_integer_weight",
        "test_probability_stage_guarantee_and_point_back_do_not_select_results",
        "test_idempotent_replay_returns_canonical_result_and_conflict_is_rejected",
        "test_legacy_point_back_success_replays_saved_canonical_response_without_mutation",
        "test_chunk_failure_rolls_back_point_inventory_history_audit_and_outbox",
        "test_response_audit_and_outbox_do_not_expose_internal_or_sensitive_fields",
        "test_single_bulk_performance_meets_merge_thresholds",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 Draw test missing {required}")

    workflow = (
        repository / ".github/workflows/platform-ci.yml"
    ).read_text(encoding="utf-8")
    if "mig051-v2-" not in workflow:
        if "mig052-v2-" not in workflow:
            if "mig053-v2-" not in workflow and "mig054-v2-" not in workflow:
                raise PolicyFailure("platform-ci V2 Draw project boundary is missing")


def validate_v2_prize_shipping_boundary(
    repository: Path, paths: Iterable[str]
) -> None:
    path_set = set(paths)
    missing = sorted(V2_PRIZE_SHIPPING_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required V2 Prize Shipping files missing: " + ", ".join(missing)
        )

    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_07_30_000010_create_v2_prize_shipping_vertical_slice.php"
    ).read_text(encoding="utf-8")
    for required in (
        "exchange_point_snapshot",
        "storage_expires_at",
        "user_prize_status_histories",
        "prize_exchange_requests",
        "shipping_addresses",
        "shipping_requests",
        "shipping_request_items",
        "shipping_request_status_histories",
        "recipient_name_ciphertext",
        "tracking_number_ciphertext",
        "v2_protect_user_prize_ownership",
        "v2_reject_prize_shipping_history_mutation",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 Prize Shipping migration missing {required}")
    for prohibited in (
        "tenant_id",
        "$table->string('recipient_name'",
        "$table->string('phone_number'",
        "$table->string('tracking_number'",
    ):
        if prohibited in migration:
            raise PolicyFailure(
                f"V2 Prize Shipping migration contains prohibited {prohibited}"
            )

    service = (
        repository
        / "apps/api/app/Domain/PrizeShipping/Services/V2PrizeShippingService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "grantPrizeExchange",
        "lockForUpdate()",
        "assertNoActiveHold",
        "Crypt::encryptString",
        "Crypt::decryptString",
        "prize.exchanged",
        "shipping.request_created",
        "shipping.status_changed",
        "shipping.admin_address_read",
        "IDEMPOTENCY_KEY_REUSED",
        "CONCURRENT_OPERATION_RETRY_EXHAUSTED",
    ):
        if required not in service:
            raise PolicyFailure(f"V2 Prize Shipping service missing {required}")
    for prohibited in (
        "SKIP LOCKED",
        "tenant_id",
        "Math.random",
    ):
        if prohibited in service:
            raise PolicyFailure(
                f"V2 Prize Shipping service contains prohibited {prohibited}"
            )

    public = load_json(repository, "openapi/bundled/public.openapi.json")
    admin = load_json(repository, "openapi/bundled/admin.openapi.json")
    public_operations = {
        operation.get("operationId")
        for item in public.get("paths", {}).values()
        if isinstance(item, dict)
        for operation in item.values()
        if isinstance(operation, dict)
    }
    for required in (
        "listUserPrizes",
        "getUserPrize",
        "exchangeUserPrizes",
        "listShippingAddresses",
        "createShippingAddress",
        "updateShippingAddress",
        "deleteShippingAddress",
        "listShippingRequests",
        "createShippingRequest",
        "getShippingRequest",
    ):
        if required not in public_operations:
            raise PolicyFailure(f"Public Prize Shipping contract missing {required}")
    admin_operations = {
        operation.get("operationId")
        for item in admin.get("paths", {}).values()
        if isinstance(item, dict)
        for operation in item.values()
        if isinstance(operation, dict)
    }
    for required in (
        "listAdminShippingRequests",
        "getAdminShippingRequest",
        "updateAdminShippingRequest",
    ):
        if required not in admin_operations:
            raise PolicyFailure(f"Admin Shipping contract missing {required}")
    public_contract = json.dumps(public, ensure_ascii=False).lower()
    for prohibited in (
        "recipient_name_ciphertext",
        "phone_number_ciphertext",
        "tracking_number_ciphertext",
        "internal_id",
        "cost_price",
        "individual_ppm",
    ):
        if prohibited in public_contract:
            raise PolicyFailure(
                f"Public Prize Shipping contract exposes prohibited {prohibited}"
            )

    client = (
        repository / "packages/storefront-client/src/prize-shipping.ts"
    ).read_text(encoding="utf-8")
    for required in (
        "exchangePrizes",
        "listPrizes",
        "getPrize",
        "createShippingAddress",
        "createShippingRequest",
        "idempotency_key",
        'csrf: "required"',
    ):
        if required not in client:
            raise PolicyFailure(f"Storefront Prize Shipping Client missing {required}")

    tests = (
        repository / "apps/api/tests/V2/PrizeShippingVerticalSliceTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_schema_uses_snapshots_encrypted_pii_and_immutable_histories",
        "test_prize_list_is_owner_scoped_cursor_paginated_and_public_safe",
        "test_prize_presentation_actions_are_backend_authoritative_and_private",
        "test_bulk_exchange_grants_snapshot_free_points_and_replays_once",
        "test_exchange_rolls_back_all_items_when_one_prize_is_invalid",
        "test_address_is_encrypted_owner_scoped_masked_and_snapshot_is_stable",
        "test_shipping_request_is_atomic_replay_safe_and_tracks_admin_transitions",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 Prize Shipping test missing {required}")

    workflow = (
        repository / ".github/workflows/platform-ci.yml"
    ).read_text(encoding="utf-8")
    if "mig052-v2-" not in workflow:
        if "mig053-v2-" not in workflow and "mig054-v2-" not in workflow:
            raise PolicyFailure("platform-ci V2 Prize Shipping project boundary is missing")


def validate_v2_qa_draw_boundary(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(V2_QA_DRAW_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required V2 QA Draw files missing: " + ", ".join(missing)
        )

    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_07_31_000011_create_v2_qa_draw_vertical_slice.php"
    ).read_text(encoding="utf-8")
    for required in (
        "qa_test_user_modes",
        "qa_draw_plans",
        "qa_draw_plan_items",
        "qa_draw_executions",
        "qa_draw_plans_active_user_gacha_unique",
        "is_qa_draw",
        "qa_test_user_mode_id",
        "qa_draw_plan_id",
        "qa_draw_plan_item_id",
        "restrictOnDelete",
        "v2_reject_qa_draw_deletion",
        "v2_reject_qa_execution_update",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 QA Draw migration missing {required}")
    for prohibited in (
        "tenant_id",
        "cascadeOnDelete",
        "Schema::table('user_prizes'",
    ):
        if prohibited in migration:
            raise PolicyFailure(
                f"V2 QA Draw migration contains prohibited {prohibited}"
            )

    permission = (
        repository
        / "apps/api/app/Domain/Identity/Services/V2PermissionAuthorizer.php"
    ).read_text(encoding="utf-8")
    owner = permission.split("'owner' => [", 1)[1].split("],", 1)[0]
    admin = permission.split("'admin' => [", 1)[1].split("],", 1)[0]
    operator = permission.split("'operator' => [", 1)[1].split("],", 1)[0]
    if "'qa.draw.manage'" not in owner:
        raise PolicyFailure("V2 QA Draw Owner permission is missing")
    if "'qa.draw.manage'" in admin or "'qa.draw.manage'" in operator:
        raise PolicyFailure("V2 QA Draw permission must be Owner-only")

    fresh_authorizer = (
        repository
        / "apps/api/app/Domain/Identity/Services/V2AdminFreshMfaAuthorizer.php"
    ).read_text(encoding="utf-8")
    for required in (
        "FRESH_AUTHENTICATION_REQUIRED",
        "admin.fresh_mfa.required",
        "lessThan",
        "requires_mfa_enrollment",
        "critical_admin_mutation",
        "session_correlation_hash",
    ):
        if required not in fresh_authorizer:
            raise PolicyFailure(f"Admin Fresh MFA Authorizer missing {required}")

    reauthentication = (
        repository
        / "apps/api/app/Domain/Identity/Services/V2AdminReauthenticationService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "verifyReauthenticationAssertion",
        "rotateLockedAdminSession",
        "admin.reauthentication.succeeded",
        "admin.reauthentication.failed",
        "admin.reauthentication.rate_limited",
        "'totp'",
        "'webauthn'",
    ):
        if required not in reauthentication:
            raise PolicyFailure(f"Admin reauthentication missing {required}")
    if "'recovery_code' =>" in reauthentication:
        raise PolicyFailure("Admin Fresh MFA permits prohibited recovery code method")
    for required in (
        "V2AdminAuthenticationPolicyService",
        "'password' => ! $this->authenticationPolicy->mfaRequired()",
        "$this->passwords->verify($password, $admin->password_hash)",
    ):
        if required not in reauthentication:
            raise PolicyFailure(
                "Admin password reauthentication must be limited to MFA Policy OFF"
            )

    identity_config = (
        repository / "apps/api/config/v2_identity.php"
    ).read_text(encoding="utf-8")
    for required in (
        "'fresh_mfa'",
        "'minutes' => 5",
        "'critical_admin_mutation' => [10, 600]",
    ):
        if required not in identity_config:
            raise PolicyFailure(f"Admin Fresh MFA configuration missing {required}")

    admin_service = (
        repository
        / "apps/api/app/Domain/QaDraw/Services/V2QaDrawAdminService.php"
    ).read_text(encoding="utf-8")
    if "V2AdminAuthorizationContext" not in admin_service:
        raise PolicyFailure("V2 QA Domain Service lacks Admin Authorization Context")
    if "public function mode(Admin " in admin_service:
        raise PolicyFailure("V2 QA Domain Service permits direct Admin bypass")
    if admin_service.count("authorizeQa(") < 9:
        raise PolicyFailure("V2 QA Domain Service does not enforce Fresh MFA")

    resolver = (
        repository
        / "apps/api/app/Domain/QaDraw/Services/V2QaDrawResolver.php"
    ).read_text(encoding="utf-8")
    for required in (
        "lockForUpdate",
        "QA_CONFIGURATION_INVALID",
        "expectedItemIds",
        "consumed_count",
        "QaDrawExecution",
        "fixed_image",
        "fixed_video",
    ):
        if required not in resolver:
            raise PolicyFailure(f"V2 QA Draw Resolver missing {required}")

    draw = (
        repository / "apps/api/app/Domain/Draw/Services/V2DrawService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "selectQaOutcomes",
        "qa_draw_plan_item_id",
        "qa.draw.completed",
        "qa.draw.failed",
        "validateInventory",
        "V2QaDrawResolver",
    ):
        if required not in draw:
            raise PolicyFailure(f"V2 Draw QA integration missing {required}")
    if draw.index("$this->qaDraw->resolve") > draw.index("$this->points->consumeForDraw"):
        raise PolicyFailure("V2 QA configuration must be resolved before Point consumption")
    if not (
        draw.index("$this->lockInventories")
        < draw.index("$this->points->lockAndValidateForDraw")
        < draw.index("$this->points->consumeForDraw")
    ):
        raise PolicyFailure(
            "V2 QA Draw lock order must be locked Inventory before Point validation and consumption"
        )

    admin_bundle = load_json(repository, "openapi/bundled/admin.openapi.json")
    public_bundle = load_json(repository, "openapi/bundled/public.openapi.json")
    admin_operations = {
        operation.get("operationId")
        for item in admin_bundle.get("paths", {}).values()
        if isinstance(item, dict)
        for operation in item.values()
        if isinstance(operation, dict)
    }
    required_operations = {
        "createAdminReauthenticationWebauthnOptions",
        "reauthenticateAdmin",
        "getQaTestUserMode",
        "saveQaTestUserMode",
        "disableQaTestUserMode",
        "getQaGachaGuarantees",
        "saveQaGachaGuarantee",
        "disableQaGachaGuarantee",
        "listQaDrawPlans",
        "createQaDrawPlan",
        "getQaDrawPlan",
        "updateQaDrawPlan",
        "pauseQaDrawPlan",
        "activateQaDrawPlan",
        "disableQaDrawPlan",
        "listQaDrawExecutions",
        "getQaDrawExecution",
    }
    if not required_operations.issubset(admin_operations):
        raise PolicyFailure("V2 Admin QA Draw operation set is incomplete")
    qa_fresh_count = sum(
        1
        for path, item in admin_bundle.get("paths", {}).items()
        if "/qa-" in path or path.endswith("/qa-mode")
        for operation in item.values()
        if isinstance(operation, dict)
        and operation.get("x-fresh-mfa") == "5-minutes"
    )
    if qa_fresh_count != 15:
        raise PolicyFailure("Every V2 Admin QA operation must require Fresh MFA")
    public_text = json.dumps(public_bundle, sort_keys=True)
    for prohibited in ("QaMode", "QaPlan", "QaExecution", "/qa-draw"):
        if prohibited in public_text:
            raise PolicyFailure(
                f"V2 Public Contract exposes QA management surface {prohibited}"
            )

    tests = (
        repository / "apps/api/tests/V2/QaDrawVerticalSliceTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_owner_only_mode_is_indefinite_and_logically_disabled",
        "test_active_qa_draw_uses_ordered_items_real_domain_updates_and_compact_response",
        "test_qa_draw_supports_all_counts_and_replay_never_consumes_twice",
        "test_active_mode_without_gacha_assignment_uses_normal_draw",
        "test_expired_active_plan_fails_closed_and_is_completed_after_draw_rollback",
        "test_inventory_failure_rolls_back_plan_point_draw_and_execution",
        "test_qa_user_prize_remains_exchangeable_and_qa_execution_is_owner_readable",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 QA Draw test missing {required}")
    guarantee_tests = (
        repository / "apps/api/tests/V2/QaTestUserGuaranteeIntegrationTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_one_guaranteed_result_and_normal_remainder_are_canonical",
        "test_mode_is_indefinite_and_no_assignment_or_disabled_mode_draws_normally",
        "test_inventory_failure_and_cross_gacha_assignment_fail_closed",
        "test_partial_remaining_draw_keeps_one_guarantee_and_canonical_executed_count",
    ):
        if required not in guarantee_tests:
            raise PolicyFailure(f"V2 QA guarantee test missing {required}")
    fresh_tests = (
        repository / "apps/api/tests/V2/AdminFreshMfaQaTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "expires_at_exactly_five_minutes",
        "rotates_session_without_extending_absolute_expiry",
        "password_recovery_and_invalid_totp",
        "rate_limit_is_session_scoped_and_audited",
    ):
        if required not in fresh_tests:
            raise PolicyFailure(f"Admin Fresh MFA test missing {required}")

    runner = (repository / "scripts/db/v2_database.py").read_text(encoding="utf-8")
    workflow = (
        repository / ".github/workflows/platform-ci.yml"
    ).read_text(encoding="utf-8")
    for required in (
        "run_qa_draw_load_tests",
        "V2_QA_DRAW_LOAD_TEST",
        "ZQaDrawConcurrencyLoadTest",
    ):
        if required not in runner:
            raise PolicyFailure(f"V2 QA Draw load verification missing {required}")
    if "mig054-v2-" not in workflow:
        raise PolicyFailure("platform-ci V2 QA Draw project boundary is missing")


def validate_v2_reporting_boundary(repository: Path, paths: Iterable[str]) -> None:
    missing = sorted(V2_REPORTING_REQUIRED_FILES - set(paths))
    if missing:
        raise PolicyFailure(
            "required V2 Reporting files missing: " + ", ".join(missing)
        )
    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_01_000012_create_v2_reporting_export_foundation.php"
    ).read_text(encoding="utf-8")
    for required in (
        "export_jobs",
        "canonical_filter_hash",
        "data_cutoff_at",
        "query_version",
        "private_object_key",
        "lease_expires_at",
        "v2_export_job_transition_guard",
        "v2/private/exports/",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 Reporting migration missing {required}")
    for prohibited in ("tenant_id", "cascadeOnDelete", "public_object_key"):
        if prohibited in migration:
            raise PolicyFailure(
                f"V2 Reporting migration contains prohibited {prohibited}"
            )

    authorizer = (
        repository
        / "apps/api/app/Domain/Identity/Services/V2AdminFreshMfaAuthorizer.php"
    ).read_text(encoding="utf-8")
    for required in (
        "authorizeReporting",
        "ExportFinancialReporting",
        "FRESH_AUTHENTICATION_REQUIRED",
        "'financial_export'",
    ):
        if required not in authorizer:
            raise PolicyFailure(f"V2 Reporting authorization missing {required}")

    reporting = (
        repository
        / "apps/api/app/Domain/Reporting/Services/V2ReportingService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "payments.succeeded_at",
        "payment_adjustments.succeeded_at",
        "point_ledger_entries",
        "operational_event_aggregation_not_accounting_recognition",
        "Asia/Tokyo",
        "is_qa_draw",
        "authorizeReporting",
    ):
        if required not in reporting:
            raise PolicyFailure(f"V2 Reporting service missing {required}")
    if "email_display" in reporting or "email_normalized" in reporting:
        raise PolicyFailure("V2 Reporting service exposes Full Email")
    snapshot_command = (
        repository
        / "apps/api/app/Console/Commands/V2/CreatePreviousDayPointSnapshot.php"
    ).read_text(encoding="utf-8")
    for required in ("subDay()", "Asia/Tokyo", "V2PointSnapshotService"):
        if required not in snapshot_command:
            raise PolicyFailure(
                f"V2 previous-day Snapshot Command missing {required}"
            )
    if "{--date" in snapshot_command:
        raise PolicyFailure("V2 Snapshot Command permits arbitrary past dates")
    schedule = (repository / "apps/api/routes/console.php").read_text(encoding="utf-8")
    for required in (
        "v2:points:snapshot-previous-day",
        "v2:reporting:work-exports",
        "timezone('Asia/Tokyo')",
        "withoutOverlapping",
    ):
        if required not in schedule:
            raise PolicyFailure(f"V2 Reporting schedule missing {required}")

    export = (
        repository
        / "apps/api/app/Domain/Reporting/Services/V2ExportService.php"
    ).read_text(encoding="utf-8")
    worker = (
        repository
        / "apps/api/app/Domain/Reporting/Services/V2ExportWorker.php"
    ).read_text(encoding="utf-8")
    for required in (
        "streamDownload",
        "temporarySignedRoute",
        "reporting.export.requested",
        "authorizeReporting($context, true)",
        "Idempotency",
    ):
        if required not in export:
            raise PolicyFailure(f"V2 Export service missing {required}")
    for required in (
        "FOR UPDATE SKIP LOCKED",
        "tmpfile",
        "hash_update_stream",
        "worker_max_attempts",
        "Storage::disk",
    ):
        if required not in worker:
            raise PolicyFailure(f"V2 Export Worker missing {required}")
    if "DB::transaction(function ()" in worker.split("public function process", 1)[1].split(
        "private function complete", 1
    )[0]:
        raise PolicyFailure("CSV generation must not run in a long DB transaction")

    admin_bundle = load_json(repository, "openapi/bundled/admin.openapi.json")
    operations = {
        operation.get("operationId")
        for item in admin_bundle.get("paths", {}).values()
        if isinstance(item, dict)
        for operation in item.values()
        if isinstance(operation, dict)
    }
    required_operations = {
        "getAdminMonthlySalesReport",
        "listAdminDailySales",
        "listAdminPaymentAdjustmentsReport",
        "getAdminMonthlyPointReport",
        "getAdminMonthlyGachaReport",
        "listAdminDrawReportingHistory",
        "listAdminDrawResultReportingHistory",
        "listAdminPointBalanceSnapshots",
        "getAdminPointBalanceSnapshot",
        "streamAdminReportingCsv",
        "createAdminReportingExportJob",
        "listAdminReportingExportJobs",
        "getAdminReportingExportJob",
        "createAdminReportingExportDownload",
        "downloadAdminReportingExportFile",
    }
    if not required_operations.issubset(operations):
        raise PolicyFailure("V2 Admin Reporting operation set is incomplete")
    public_bundle = load_json(repository, "openapi/bundled/public.openapi.json")
    public_text = json.dumps(public_bundle, sort_keys=True)
    for prohibited in ("ExportJob", "/reports/", "ReportingCollection"):
        if prohibited in public_text:
            raise PolicyFailure(
                f"V2 Public Contract exposes Admin Reporting surface {prohibited}"
            )

    tests = (
        repository / "apps/api/tests/V2/ReportingExportVerticalSliceTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_sales_use_succeeded_event_dates_and_jst_boundary",
        "test_point_report_uses_immutable_ledger_not_wallet_balance",
        "test_csv_has_stable_header_utf8_bom_and_formula_protection",
        "test_export_job_is_idempotent_and_worker_persists_private_checksum",
        "test_reporting_permissions_and_fresh_mfa_fail_closed",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 Reporting test missing {required}")
    runner = (repository / "scripts/db/v2_database.py").read_text(encoding="utf-8")
    for required in (
        "run_reporting_performance_tests",
        "V2_REPORTING_PERFORMANCE_TEST",
        "ZReportingExportPerformanceTest",
    ):
        if required not in runner:
            raise PolicyFailure(
                f"V2 Reporting performance verification missing {required}"
            )
    workflow = (
        repository / ".github/workflows/platform-ci.yml"
    ).read_text(encoding="utf-8")
    if "mig054-v2-" not in workflow:
        raise PolicyFailure("platform-ci V2 Reporting project boundary is missing")


def validate_v2_content_contact_boundary(
    repository: Path, paths: Iterable[str]
) -> None:
    missing = sorted(V2_CONTENT_CONTACT_REQUIRED_FILES - set(paths))
    if missing:
        raise PolicyFailure(
            "required V2 Content／Contact files missing: " + ", ".join(missing)
        )
    migration = (
        repository
        / "apps/api/database/migrations-v2/"
        "2026_08_02_000013_create_v2_content_contact_vertical_slice.php"
    ).read_text(encoding="utf-8")
    for required in (
        "content_banners",
        "content_notices",
        "content_static_pages",
        "content_versions",
        "content_version_assets",
        "contact_inquiries",
        "contact_status_histories",
        "contact_internal_notes",
        "contact_reply_requests",
        "v2_content_protect_published_version",
        "v2_contact_reject_history_mutation",
        "v2_contact_reject_delete",
    ):
        if required not in migration:
            raise PolicyFailure(f"V2 Content／Contact migration missing {required}")
    for prohibited in ("tenant_id", "cascadeOnDelete"):
        if prohibited in migration:
            raise PolicyFailure(
                f"V2 Content／Contact migration contains prohibited {prohibited}"
            )

    sanitizer = (
        repository
        / "apps/api/app/Domain/ContentContact/Services/V2ContentHtmlSanitizer.php"
    ).read_text(encoding="utf-8")
    for required in (
        "DOMDocument",
        "LIBXML_NONET",
        "DROP_WITH_CONTENT",
        "safeHref",
        "['http', 'https', 'mailto']",
        "noopener noreferrer",
    ):
        if required not in sanitizer:
            raise PolicyFailure(f"V2 Content sanitizer missing {required}")
    contact = (
        repository
        / "apps/api/app/Domain/ContentContact/Services/V2ContactService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "Crypt::encryptString",
        "contact_hmac_key",
        "assertGlobal('contact_ip'",
        "assertSubject('contact_email'",
        "contact.receipt.requested",
        "contact.admin_notification.requested",
        "contact.rate_limited",
        "DB::transaction",
    ):
        if required not in contact:
            raise PolicyFailure(f"V2 Contact boundary missing {required}")
    if "email_ciphertext' => $email" in contact or "body_ciphertext' => $body" in contact:
        raise PolicyFailure("V2 Contact stores plaintext PII")

    admin_service = (
        repository
        / "apps/api/app/Domain/ContentContact/Services/"
        "V2ContentContactAdminService.php"
    ).read_text(encoding="utf-8")
    for required in (
        "ReadContent",
        "ManageContent",
        "PublishContent",
        "ReadContact",
        "ManageContact",
        "content.legal.publish",
        "content.legal.archive",
        "contact.reply.requested",
    ):
        if required not in admin_service:
            raise PolicyFailure(f"V2 Content／Contact Admin boundary missing {required}")

    public_bundle = load_json(repository, "openapi/bundled/public.openapi.json")
    admin_bundle = load_json(repository, "openapi/bundled/admin.openapi.json")
    public_operations = {
        operation.get("operationId")
        for item in public_bundle.get("paths", {}).values()
        if isinstance(item, dict)
        for operation in item.values()
        if isinstance(operation, dict)
    }
    required_public = {
        "listContentBanners",
        "listContentFooterPages",
        "listContentNotices",
        "getContentNotice",
        "getContentStaticPage",
        "createContactInquiry",
    }
    if not required_public.issubset(public_operations):
        raise PolicyFailure("V2 Public Content／Contact operation set is incomplete")
    public_text = json.dumps(public_bundle, sort_keys=True)
    for prohibited in (
        "AdminContent",
        "ContactInternalNote",
        "ContactReplyRequest",
        "/admin/",
    ):
        if prohibited in public_text:
            raise PolicyFailure(
                f"V2 Public Contract exposes Admin Content surface {prohibited}"
            )
    admin_operations = {
        operation.get("operationId")
        for item in admin_bundle.get("paths", {}).values()
        if isinstance(item, dict)
        for operation in item.values()
        if isinstance(operation, dict)
    }
    required_admin = {
        "createAdminContentBanner",
        "publishAdminContentBannerVersion",
        "createAdminContentNotice",
        "publishAdminContentNoticeVersion",
        "createAdminContentStaticPage",
        "publishAdminContentStaticPageVersion",
        "listAdminContactInquiries",
        "getAdminContactInquiry",
        "updateAdminContactInquiryStatus",
        "addAdminContactInternalNote",
        "createAdminContactReplyRequest",
    }
    if not required_admin.issubset(admin_operations):
        raise PolicyFailure("V2 Admin Content／Contact operation set is incomplete")

    tests = (
        repository / "apps/api/tests/V2/ContentContactVerticalSliceTest.php"
    ).read_text(encoding="utf-8")
    for required in (
        "test_html_sanitizer_keeps_document_markup_and_removes_active_content",
        "test_published_content_respects_period_order_cursor_and_public_asset",
        "test_published_version_is_immutable_and_legal_publish_requires_fresh_mfa",
        "test_contact_is_encrypted_audited_and_enqueues_notifications_atomically",
        "test_contact_admin_workflow_separates_notes_and_keeps_history_append_only",
        "test_rate_limit_and_permission_matrix_fail_closed_without_pii",
    ):
        if required not in tests:
            raise PolicyFailure(f"V2 Content／Contact test missing {required}")
    runner = (repository / "scripts/db/v2_database.py").read_text(encoding="utf-8")
    for required in (
        "run_content_contact_performance_tests",
        "V2_CONTENT_CONTACT_PERFORMANCE_TEST",
        "ZContentContactPerformanceTest",
    ):
        if required not in runner:
            raise PolicyFailure(
                f"V2 Content／Contact performance verification missing {required}"
            )


def validate_boundary_readmes(repository: Path) -> None:
    for relative in sorted(BOUNDARY_READMES):
        text = (repository / relative).read_text(encoding="utf-8")
        headings = markdown_headings(text)
        missing = sorted(BOUNDARY_HEADINGS - headings)
        if missing:
            raise PolicyFailure(
                f"{relative}: responsibility headings missing: {', '.join(missing)}"
            )
        status_statement = (
            "Alpha"
            if relative
            in {
                "packages/storefront-client/README.md",
                "packages/site-schema/README.md",
                "packages/storefront-testkit/README.md",
            }
            else ("MIG-060A" if relative == "apps/admin/README.md" else "Skeleton")
        )
        for statement in ("AGENTS.md", status_statement, "Production", "V1"):
            if statement not in text:
                raise PolicyFailure(
                    f"{relative}: required boundary statement missing: {statement}"
                )
        if len(text.strip()) < 300:
            raise PolicyFailure(f"{relative}: skeleton boundary is not substantive")


def validate_manifest_schema(
    repository: Path,
    relative: str,
    expected_required: set[str],
) -> dict:
    schema = load_json(repository, relative)
    if schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
        raise PolicyFailure(f"{relative}: JSON Schema Draft 2020-12 is required")
    if schema.get("type") != "object" or schema.get("additionalProperties") is not False:
        raise PolicyFailure(f"{relative}: strict object schema is required")
    required = schema.get("required")
    properties = schema.get("properties")
    if not isinstance(required, list) or not expected_required.issubset(required):
        raise PolicyFailure(f"{relative}: required manifest fields are missing")
    if not isinstance(properties, dict) or not expected_required.issubset(properties):
        raise PolicyFailure(f"{relative}: manifest properties are missing")
    semantic_version = schema.get("$defs", {}).get("semantic_version", {})
    if semantic_version.get("pattern") != SEMANTIC_VERSION.pattern:
        raise PolicyFailure(f"{relative}: semantic version policy is invalid")
    return schema


def validate_schema_value(
    value: object,
    schema: dict,
    root_schema: dict,
    location: str,
) -> None:
    reference = schema.get("$ref")
    if reference:
        if not isinstance(reference, str) or not reference.startswith("#/$defs/"):
            raise PolicyFailure(f"{location}: unsupported JSON Schema reference")
        definition = reference.removeprefix("#/$defs/")
        target = root_schema.get("$defs", {}).get(definition)
        if not isinstance(target, dict):
            raise PolicyFailure(f"{location}: unresolved JSON Schema reference")
        validate_schema_value(value, target, root_schema, location)
        return

    if "const" in schema and value != schema["const"]:
        raise PolicyFailure(f"{location}: value does not match const")
    if "enum" in schema and value not in schema["enum"]:
        raise PolicyFailure(f"{location}: value is outside enum")

    expected_type = schema.get("type")
    type_matches = {
        "object": isinstance(value, dict),
        "array": isinstance(value, list),
        "string": isinstance(value, str),
        "boolean": isinstance(value, bool),
        "integer": isinstance(value, int) and not isinstance(value, bool),
    }
    if expected_type and not type_matches.get(expected_type, False):
        raise PolicyFailure(f"{location}: value is not {expected_type}")

    if isinstance(value, str):
        if len(value) < int(schema.get("minLength", 0)):
            raise PolicyFailure(f"{location}: string is too short")
        pattern = schema.get("pattern")
        if pattern and not re.fullmatch(pattern, value):
            raise PolicyFailure(f"{location}: string does not match pattern")
        if schema.get("format") == "date-time" and not re.fullmatch(
            r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", value
        ):
            raise PolicyFailure(f"{location}: string is not UTC date-time")

    if isinstance(value, dict):
        required = schema.get("required", [])
        missing = sorted(set(required) - set(value))
        if missing:
            raise PolicyFailure(
                f"{location}: required fields missing: {', '.join(missing)}"
            )
        if len(value) < int(schema.get("minProperties", 0)):
            raise PolicyFailure(f"{location}: object has too few properties")
        properties = schema.get("properties", {})
        additional = schema.get("additionalProperties", True)
        for key, item in value.items():
            child_schema = properties.get(key)
            if child_schema is None:
                if additional is False:
                    raise PolicyFailure(f"{location}: unexpected field: {key}")
                if isinstance(additional, dict):
                    child_schema = additional
            if isinstance(child_schema, dict):
                validate_schema_value(
                    item, child_schema, root_schema, f"{location}.{key}"
                )

    if isinstance(value, list):
        if schema.get("uniqueItems"):
            serialized = [json.dumps(item, sort_keys=True) for item in value]
            if len(serialized) != len(set(serialized)):
                raise PolicyFailure(f"{location}: array items must be unique")
        item_schema = schema.get("items")
        if isinstance(item_schema, dict):
            for index, item in enumerate(value):
                validate_schema_value(
                    item, item_schema, root_schema, f"{location}[{index}]"
                )


def validate_manifest_example(
    repository: Path,
    relative: str,
    schema: dict,
) -> None:
    value = load_json(repository, relative)
    validate_schema_value(value, schema, schema, relative)


def validate_release_artifact_foundation(
    repository: Path,
    paths: Iterable[str],
) -> None:
    path_set = set(paths)
    missing = sorted(RELEASE_ARTIFACT_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required release artifact files missing: " + ", ".join(missing)
        )

    expected_labels = {
        "org.opencontainers.image.version",
        "org.opencontainers.image.revision",
        "org.opencontainers.image.source",
        "org.opencontainers.image.created",
        "org.opencontainers.image.title",
    }
    for relative in ("apps/admin/Dockerfile", "infra/docker/backend/Dockerfile"):
        text = (repository / relative).read_text(encoding="utf-8")
        from_lines = [
            line.strip()
            for line in text.splitlines()
            if line.strip().startswith("FROM ")
        ]
        if not from_lines or any(
            "@sha256:" not in line or re.search(r"\b(?:latest|edge)\b", line)
            for line in from_lines
        ):
            raise PolicyFailure(f"{relative}: release base image must use a digest")
        missing_labels = sorted(label for label in expected_labels if label not in text)
        if missing_labels:
            raise PolicyFailure(
                f"{relative}: OCI labels missing: {', '.join(missing_labels)}"
            )
        if "legacy/v1-frontend" in text:
            raise PolicyFailure(f"{relative}: legacy source is prohibited")

    admin_config = (repository / "apps/admin/next.config.ts").read_text(
        encoding="utf-8"
    )
    admin_dockerfile = (repository / "apps/admin/Dockerfile").read_text(
        encoding="utf-8"
    )
    if 'output: "standalone"' not in admin_config:
        raise PolicyFailure("apps/admin: standalone release output is required")
    if "COPY --from=build" not in admin_dockerfile or 'CMD ["node", "server.js"]' not in admin_dockerfile:
        raise PolicyFailure("apps/admin/Dockerfile: standalone runtime is required")
    for build_tool in (
        "/usr/local/lib/node_modules/corepack",
        "/usr/local/lib/node_modules/npm",
    ):
        if build_tool not in admin_dockerfile:
            raise PolicyFailure(
                "apps/admin/Dockerfile: runtime package tool removal is required"
            )

    api_dockerfile = (repository / "infra/docker/backend/Dockerfile").read_text(
        encoding="utf-8"
    )
    if " AS build" not in api_dockerfile or "linux-libc-dev=6.1.180-1" not in api_dockerfile:
        raise PolicyFailure(
            "infra/docker/backend/Dockerfile: patched multi-stage runtime is required"
        )

    package = load_json(repository, "package.json")
    scripts = package.get("scripts", {})
    if scripts.get("release:test") != (
        "python3 -m unittest discover -s tests/release -p 'test_*.py'"
    ):
        raise PolicyFailure("package.json: release test command is not fixed")
    if scripts.get("release:validate") != (
        "python3 scripts/release/platform_artifact.py validate-source --repository ."
    ):
        raise PolicyFailure("package.json: release validation command is not fixed")

    builder = (repository / "scripts/release/platform_artifact.py").read_text(
        encoding="utf-8"
    )
    required_statements = {
        'PLATFORM_VERSION = "2.0.0-alpha.23"',
        'CHANNEL = "alpha"',
        'RELEASE_TAG = "platform-v2.0.0-alpha.23"',
        "PRODUCTION_ALLOWED = False",
        "DATA_RETENTION_GUARANTEED = False",
        "pnpm",
        "--filter",
        "pack",
        "SHA256SUMS",
        "provenance.intoto.json",
        "cyclonedx",
    }
    absent = sorted(statement for statement in required_statements if statement not in builder)
    if absent:
        raise PolicyFailure(
            "release builder required controls missing: " + ", ".join(absent)
        )
    if re.search(r"(?:version|image|tag)\s*[:=]\s*[\"']latest[\"']", builder):
        raise PolicyFailure("release builder must not use latest")

    storefront_builder = (
        repository / "scripts/release/storefront_contract_artifact.py"
    ).read_text(encoding="utf-8")
    for statement in (
        "immutable existing version reissue prohibited",
        "package-only release set is not approved",
        "published package version must equal bundle version",
        "immutable package reference mismatch",
        "artifact file inventory mismatch",
        "verify_checksums",
    ):
        if statement not in storefront_builder:
            raise PolicyFailure(
                f"Storefront artifact validator control missing: {statement}"
            )

    operations = (
        repository / "docs/operations/releases/platform-alpha-artifact.md"
    ).read_text(encoding="utf-8")
    for statement in (
        "Production／Commercial利用: 禁止",
        "Data保持保証",
        "NOT_STARTED",
        "Assetは移動、削除、差替え",
    ):
        if statement not in operations:
            raise PolicyFailure(
                f"release operations document missing boundary: {statement}"
            )


def validate_no_v1_copy(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    source_paths = [
        path
        for path in path_set
        if path.startswith(("backend/", "frontend/", "legacy/v1-frontend/"))
        and not path.endswith((".md", ".lock"))
        and (repository / path).is_file()
    ]
    target_paths = [
        path
        for path in path_set
        if path.startswith(("apps/api/", "apps/admin/", "packages/"))
        and not path.endswith((".md", ".lock"))
        and (repository / path).is_file()
    ]
    source_hashes = {}
    for relative in source_paths:
        content = (repository / relative).read_bytes()
        if len(content) >= 64:
            source_hashes.setdefault(hashlib.sha256(content).digest(), relative)
    copied = []
    for relative in target_paths:
        content = (repository / relative).read_bytes()
        source = source_hashes.get(hashlib.sha256(content).digest())
        if len(content) >= 64 and source:
            copied.append(f"{source} -> {relative}")
    if copied:
        raise PolicyFailure("V1 content copied into V2 workspace: " + ", ".join(copied))


def validate_api_application_layout(paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(API_APPLICATION_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required API application files missing: " + ", ".join(missing)
        )
    legacy_paths = sorted(path for path in path_set if path.startswith("backend/"))
    if legacy_paths:
        raise PolicyFailure("legacy backend path remains tracked")


def validate_legacy_frontend_layout(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(LEGACY_FRONTEND_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure(
            "required legacy frontend files missing: " + ", ".join(missing)
        )
    old_paths = sorted(path for path in path_set if path.startswith("frontend/"))
    if old_paths:
        raise PolicyFailure("legacy frontend source path remains tracked")
    nested = sorted(
        path
        for path in path_set
        if path.startswith("legacy/v1-frontend/frontend/")
    )
    if nested:
        raise PolicyFailure("legacy frontend was moved into a nested frontend directory")

    for relative in paths:
        if not relative.endswith(("Dockerfile", ".dockerfile")):
            continue
        if relative == "infra/docker/frontend/Dockerfile":
            continue
        text = (repository / relative).read_text(encoding="utf-8", errors="replace")
        if "legacy/v1-frontend" in text:
            raise PolicyFailure(
                f"{relative}: V2 Production image must not copy legacy frontend"
            )


def validate_workspace_skeleton(repository: Path, paths: Iterable[str]) -> None:
    path_set = set(paths)
    missing = sorted(WORKSPACE_REQUIRED_FILES - path_set)
    if missing:
        raise PolicyFailure("required workspace files missing: " + ", ".join(missing))
    validate_workspace_configuration(repository)
    validate_admin_skeleton(repository, paths)
    validate_package_skeletons(repository)
    validate_storefront_client(repository, paths)
    validate_site_schema(repository, paths)
    validate_storefront_testkit(repository, paths)
    validate_compose_skeletons(repository)
    validate_boundary_readmes(repository)
    release_schema = validate_manifest_schema(
        repository,
        "manifests/schemas/release-manifest.schema.json",
        RELEASE_MANIFEST_REQUIRED,
    )
    deployment_schema = validate_manifest_schema(
        repository,
        "manifests/schemas/deployment-manifest.schema.json",
        DEPLOYMENT_MANIFEST_REQUIRED,
    )
    validate_manifest_example(
        repository,
        "manifests/examples/release-manifest.example.json",
        release_schema,
    )
    validate_manifest_example(
        repository,
        "manifests/examples/deployment-manifest.example.json",
        deployment_schema,
    )
    validate_no_v1_copy(repository, paths)


def validate_architecture_index(repository: Path) -> None:
    index_path = repository / "docs/architecture/README.md"
    text = index_path.read_text(encoding="utf-8")
    for link in re.findall(r"\[[^\]]+\]\(([^)]+)\)", text):
        if "://" in link or link.startswith("#"):
            continue
        target = (index_path.parent / link.split("#", 1)[0]).resolve()
        if not target.is_file():
            raise PolicyFailure(f"architecture index link does not exist: {link}")
    for current in (CURRENT_SECURITY, CURRENT_GOVERNANCE, CURRENT_RELEASE_GATES):
        if current not in text or not (index_path.parent / current).is_file():
            raise PolicyFailure(f"architecture authority missing: {current}")
    if OBSOLETE_SECURITY in text:
        raise PolicyFailure("obsolete non-revision Security baseline is referenced")
    if "sole current security baseline" not in text:
        raise PolicyFailure("Security REV1 is not identified as the sole baseline")
    if "behavioral references only" not in text:
        raise PolicyFailure("V1 is not identified as behavioral reference only")


def validate_governance_statements(repository: Path, paths: Iterable[str]) -> None:
    prohibited = re.compile(
        r"(?:direct\s+main\s+push|force\s+push)\s*[:=]\s*"
        r"(?:allowed|enabled|on|yes)",
        re.IGNORECASE,
    )
    for relative in paths:
        if not relative.endswith((".md", ".yml", ".yaml", ".py", ".toml")):
            continue
        text = (repository / relative).read_text(encoding="utf-8", errors="replace")
        if prohibited.search(text):
            raise PolicyFailure(
                f"{relative}: governance statement permits a protected operation"
            )


def validate_dependency_review_allowlist(repository: Path) -> None:
    baseline = load_json(repository, ".ci/baselines/dependency-advisories.json")
    expected = {
        item.get("advisory_id")
        for item in baseline.get("pnpm", [])
        if item.get("severity") in {"high", "critical"}
    }
    if None in expected:
        raise PolicyFailure("dependency advisory baseline has an invalid advisory ID")
    workflow = (
        repository / ".github/workflows/dependency-review.yml"
    ).read_text(encoding="utf-8")
    actual = set(re.findall(r"GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}", workflow))
    if actual != expected:
        raise PolicyFailure(
            "dependency-review allow-ghsas must exactly match the expiring "
            "high-severity pnpm baseline"
        )


def validate_repository(repository: Path) -> list[str]:
    paths = tracked_paths(repository)
    missing = sorted(REQUIRED_REPOSITORY_FILES - set(paths))
    if missing:
        raise PolicyFailure("required governance files missing: " + ", ".join(missing))
    validate_dangerous_paths(paths)
    validate_basic_structures(repository, paths)
    validate_workspace_skeleton(repository, paths)
    validate_release_artifact_foundation(repository, paths)
    validate_preview_image_pipeline(repository, paths)
    validate_api_application_layout(paths)
    validate_legacy_frontend_layout(repository, paths)
    validate_v2_database_boundary(repository, paths)
    validate_v2_identity_boundary(repository, paths)
    validate_v2_audit_outbox_boundary(repository, paths)
    validate_v2_point_boundary(repository, paths)
    validate_v2_payment_boundary(repository, paths)
    validate_v2_catalog_boundary(repository, paths)
    validate_v2_draw_boundary(repository, paths)
    validate_v2_prize_shipping_boundary(repository, paths)
    validate_v2_qa_draw_boundary(repository, paths)
    validate_v2_reporting_boundary(repository, paths)
    validate_v2_content_contact_boundary(repository, paths)
    validate_architecture_index(repository)
    validate_governance_statements(repository, paths)
    validate_dependency_review_allowlist(repository)
    for relative in paths:
        if relative.startswith(".github/workflows/") and relative.endswith(
            (".yml", ".yaml")
        ):
            validate_workflow_text(
                relative, (repository / relative).read_text(encoding="utf-8")
            )
    return paths


def validate_event(repository: Path, event_name: str, event_path: Path) -> None:
    if event_name != "pull_request":
        return
    event = json.loads(event_path.read_text(encoding="utf-8"))
    pull_request = event.get("pull_request")
    if not isinstance(pull_request, dict):
        raise PolicyFailure("pull_request event payload is missing")
    base_sha = pull_request.get("base", {}).get("sha")
    head_sha = pull_request.get("head", {}).get("sha")
    body = pull_request.get("body") or ""
    title = pull_request.get("title") or ""
    paths = changed_paths(repository, str(base_sha), str(head_sha))
    validate_pr_body(body, title, paths, str(base_sha))


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, required=True)
    return parser.parse_args()


def main() -> int:
    arguments = parse_arguments()
    repository = arguments.repository.resolve()
    try:
        paths = validate_repository(repository)
        event_name = os.environ.get("POLICY_EVENT_NAME", "")
        event_value = os.environ.get("POLICY_EVENT_PATH", "")
        if event_name:
            if not event_value:
                raise PolicyFailure("POLICY_EVENT_PATH is required")
            validate_event(repository, event_name, Path(event_value))
    except (OSError, ValueError, PolicyFailure) as error:
        print(f"policy-gate: FAIL: {error}", file=sys.stderr)
        return 1
    print(
        json.dumps(
            {
                "gate": "policy-gate",
                "status": "PASS",
                "tracked_files": len(paths),
                "event": os.environ.get("POLICY_EVENT_NAME") or "local",
            },
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
