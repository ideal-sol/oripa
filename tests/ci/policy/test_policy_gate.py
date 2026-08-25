import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "scripts/ci/policy_gate.py"
FIXTURES = Path(__file__).parent / "fixtures"
SPEC = importlib.util.spec_from_file_location("policy_gate", SCRIPT)
policy_gate = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(policy_gate)


def fixture(name):
    return json.loads((FIXTURES / name).read_text(encoding="utf-8"))


class PolicyGateTest(unittest.TestCase):
    def test_mig_073_closed_email_reregistration_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_17_000063_allow_v2_closed_user_email_reregistration.php",
        }
        self.assertEqual(policy_gate.MIG_073_V2_IDENTITY_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_IDENTITY_REQUIRED_FILES))
        migration = (ROOT / next(iter(expected))).read_text(encoding="utf-8")
        self.assertIn("state <> 'closed'", migration)
        self.assertIn("HAVING COUNT(*) > 1", migration)
        self.assertIn("Cannot restore verified email uniqueness", migration)
        self.assertNotIn("DELETE FROM users", migration)
        self.assertNotIn("UPDATE users", migration)
        self.assertNotIn("DISABLE TRIGGER", migration)

    def test_mig_062n_admin_user_prize_paths_are_registered_exactly(self):
        expected_backend = {
            "apps/api/app/Domain/PrizeShipping/Services/V2AdminUserPrizeReadService.php",
            "apps/api/app/Http/Controllers/V2/V2AdminUserPrizeController.php",
            "apps/api/tests/V2/AdminUserPrizeReadTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-user-prize-management.spec.ts",
            "apps/admin/src/app/user-prizes/[userPrizeId]/page.tsx",
            "apps/admin/src/app/user-prizes/page.tsx",
            "apps/admin/src/components/user-prizes/admin-user-prize-detail.tsx",
            "apps/admin/src/components/user-prizes/admin-user-prize-list.tsx",
            "apps/admin/test/admin-user-prize-management.test.tsx",
        }
        self.assertEqual(policy_gate.MIG_062N_V2_PRIZE_SHIPPING_FILES, expected_backend)
        self.assertTrue(expected_backend.issubset(policy_gate.V2_PRIZE_SHIPPING_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_062N_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_backend | expected_admin))

    def test_mig_062m_qa_integration_paths_are_registered_exactly(self):
        expected_backend = {
            "apps/api/app/Models/V2/QaGachaGuaranteeAssignment.php",
            "apps/api/database/migrations-v2/2026_09_04_000049_integrate_v2_qa_test_user_guarantees.php",
            "apps/api/tests/V2/QaTestUserGuaranteeIntegrationTest.php",
            "apps/api/tests/V2/ZQaTestUserGuaranteeConcurrencyTest.php",
        }
        expected_admin = {
            "apps/admin/src/components/catalog/catalog-gacha-qa-guarantee-manager.tsx",
            "apps/admin/src/components/users/admin-user-qa-test-mode.tsx",
            "apps/admin/test/admin-user-qa-test-mode.test.tsx",
            "apps/admin/test/catalog-gacha-qa-guarantee.test.tsx",
        }
        self.assertEqual(policy_gate.MIG_062M_V2_QA_DRAW_FILES, expected_backend)
        self.assertTrue(expected_backend.issubset(policy_gate.V2_QA_DRAW_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_062M_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_backend | expected_admin))

        migration = (ROOT / "apps/api/database/migrations-v2/2026_09_04_000049_integrate_v2_qa_test_user_guarantees.php").read_text(encoding="utf-8")
        self.assertIn("qa_gacha_guarantee_assignments", migration)
        self.assertIn("Cross-Gacha QA Prize assignment is not allowed", migration)
        self.assertIn("ALTER COLUMN ends_at DROP NOT NULL", migration)

    def test_mig_062l_gacha_prize_ownership_paths_are_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_03_000048_add_v2_gacha_prize_ownership_snapshots.php",
            "apps/api/tests/V2/AdminGachaPrizeOwnershipTest.php",
        }
        self.assertEqual(policy_gate.MIG_062L_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

        migration = (ROOT / next(
            path for path in expected if path.endswith(".php") and "migrations-v2" in path
        )).read_text(encoding="utf-8")
        self.assertIn("gacha_id", migration)
        self.assertIn("Cross-Gacha Prize association is not allowed", migration)
        self.assertIn("display_name", migration)
        self.assertIn("rank_display_name", migration)

        policy_source = (ROOT / "scripts/ci/policy_gate.py").read_text(
            encoding="utf-8"
        )
        self.assertIn(
            '"2026_09_03_000048_add_v2_gacha_prize_ownership_snapshots.php"',
            policy_source,
        )

    def test_mig_062k_user_state_paths_are_registered_exactly(self):
        expected_identity = {
            "apps/api/app/Domain/Identity/Exceptions/V2AdminUserStateException.php",
            "apps/api/app/Domain/Identity/Services/V2AdminUserStateService.php",
            "apps/api/app/Http/Controllers/V2/V2AdminUserStateController.php",
            "apps/api/database/migrations-v2/2026_09_02_000047_add_v2_user_state_revision.php",
            "apps/api/tests/V2/AdminUserStateManagementTest.php",
        }
        expected_admin = {
            "apps/admin/src/components/users/admin-user-state-management.tsx",
            "apps/admin/test/admin-user-state-management.test.tsx",
        }
        self.assertEqual(policy_gate.MIG_062K_V2_IDENTITY_FILES, expected_identity)
        self.assertTrue(expected_identity.issubset(policy_gate.V2_IDENTITY_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_062K_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_identity | expected_admin))

        migration = (ROOT / "apps/api/database/migrations-v2/2026_09_02_000047_add_v2_user_state_revision.php").read_text(encoding="utf-8")
        self.assertIn("users_state_revision_check", migration)
        self.assertIn("state_revision >= 1", migration)

    def test_mig_062j_partial_draw_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_01_000046_allow_v2_partial_remaining_draw_execution.php",
        }
        self.assertEqual(policy_gate.MIG_062J_V2_DRAW_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_DRAW_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

        migration = (ROOT / next(iter(expected))).read_text(encoding="utf-8")
        self.assertIn("executed_count > 0 AND executed_count <= requested_count", migration)
        self.assertIn("executed_count = requested_count", migration)

        policy_source = (ROOT / "scripts/ci/policy_gate.py").read_text(encoding="utf-8")
        self.assertIn(
            '"2026_09_01_000046_allow_v2_partial_remaining_draw_execution.php"',
            policy_source,
        )

    def test_mig_062i_gacha_draw_count_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_08_31_000045_add_v2_gacha_allowed_draw_counts.php",
        }
        self.assertEqual(policy_gate.MIG_062I_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

        migration = (ROOT / next(iter(expected))).read_text(encoding="utf-8")
        self.assertIn("DEFAULT '[1,5,10]'::jsonb", migration)
        self.assertIn("catalog_gacha_versions_allowed_draw_counts_check", migration)
        self.assertIn("'[1,5,10,100,1000]'::jsonb", migration)

    def test_mig_062h_gacha_eligibility_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_08_30_000044_add_v2_gacha_registration_eligibility_and_management_state.php",
        }
        self.assertEqual(policy_gate.MIG_062H_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def test_mig_062d_point_purchase_target_tag_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_08_29_000043_add_v2_point_purchase_plan_target_tag.php",
        }
        self.assertEqual(policy_gate.MIG_062D_V2_PAYMENT_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_PAYMENT_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def test_mig_063a_limited_bonus_paths_are_registered_exactly(self):
        expected = {
            "apps/api/app/Domain/Payment/V2/Services/V2LimitedBonusCampaignService.php",
            "apps/api/database/migrations-v2/2026_09_10_000055_add_v2_limited_bonus_domain_core.php",
            "apps/api/tests/V2/LimitedBonusDomainCoreTest.php",
        }
        self.assertEqual(policy_gate.MIG_063A_V2_PAYMENT_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_PAYMENT_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def test_mig_063d_category_tag_presentation_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_11_000056_allow_v2_published_category_tag_presentation_edits.php",
        }
        self.assertEqual(policy_gate.MIG_063D_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def test_mig_063e_gacha_rank_scope_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_12_000057_scope_v2_gacha_rank_codes.php",
        }
        self.assertEqual(policy_gate.MIG_063E_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

        migration = (ROOT / next(iter(expected))).read_text(encoding="utf-8")
        self.assertIn("UNIQUE (gacha_id, code)", migration)
        self.assertIn("catalog_ranks_gacha_id_code_unique", migration)
        self.assertIn("catalog_ranks_unowned_code_unique", migration)
        self.assertIn("Cannot restore global Catalog Rank code uniqueness", migration)
        self.assertNotIn("DISABLE TRIGGER", migration)
        self.assertNotIn("DELETE FROM catalog_ranks", migration)

    def test_mig_067_gacha_lifecycle_inventory_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_13_000058_canonicalize_v2_gacha_lifecycle_inventory_capacity.php",
        }
        self.assertEqual(policy_gate.MIG_067_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

        migration = (ROOT / next(iter(expected))).read_text(encoding="utf-8")
        self.assertIn("Scheduled Gacha must remain unpublished before start", migration)
        self.assertIn("Aggregate Gacha Prize inventory cannot exceed total count", migration)
        self.assertIn("DEFERRABLE INITIALLY DEFERRED", migration)
        self.assertNotIn("DISABLE TRIGGER", migration)

    def test_ops_019_preview_capacity_reconciliation_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_12_000060_reconcile_preview_gacha_capacity.php",
            "apps/api/tests/V2/GachaCapacityForwardReconciliationTest.php",
        }
        self.assertEqual(policy_gate.OPS_019_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))

        migration_path = next(
            path for path in expected if "migrations-v2" in path
        )
        migration = (ROOT / migration_path).read_text(encoding="utf-8")
        for required in (
            "(id, gacha_id) IN ((8, 10), (9, 10), (10, 10), (11, 11), (12, 12))",
            "OLD.total_count = 9",
            "NEW.total_count = 18",
            "to_jsonb(NEW) - 'total_count'",
            "pg_get_functiondef",
            "Catalog guards were not restored exactly",
        ):
            self.assertIn(required, migration)
        for prohibited in (
            "DISABLE TRIGGER",
            "ENABLE TRIGGER",
            "SET session_replication_role",
            "DELETE FROM",
        ):
            self.assertNotIn(prohibited, migration)

    def test_mig_068_canonical_probability_publish_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_14_000059_internalize_v2_canonical_probability_publish.php",
        }
        self.assertEqual(policy_gate.MIG_068_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

        migration = (ROOT / next(iter(expected))).read_text(encoding="utf-8")
        self.assertIn("__canonical_inventory_v1", migration)
        self.assertIn("status::text NOT IN ('draft'::text, 'published'::text)", migration)
        self.assertIn("active_schedule.probability_version_id", migration)
        self.assertIn(
            "version.published_probability_version_id ",
            migration,
        )
        self.assertIn(
            "IS DISTINCT FROM schedule.probability_version_id",
            migration,
        )
        self.assertIn("Cannot roll back MIG-068", migration)
        self.assertNotIn("DISABLE TRIGGER", migration)
        self.assertNotRegex(migration, r"\b(?:UPDATE|DELETE FROM)\s+catalog_")

    def test_mig_072_unpublished_draft_restore_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_09_15_000061_allow_v2_gacha_unpublished_draft_restore.php",
            "apps/api/database/migrations-v2/2026_09_16_000062_allow_v2_direct_terminal_gacha_deactivation.php",
        }
        self.assertEqual(policy_gate.MIG_072_V2_CATALOG_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        restore_migration = (
            ROOT
            / "apps/api/database/migrations-v2/2026_09_15_000061_allow_v2_gacha_unpublished_draft_restore.php"
        ).read_text(encoding="utf-8")
        self.assertIn("NOT IN ('unpublished', 'draft')", restore_migration)
        self.assertIn("First publication history is immutable", restore_migration)
        self.assertIn("Cannot roll back MIG-072", restore_migration)
        direct_deactivation = (
            ROOT
            / "apps/api/database/migrations-v2/2026_09_16_000062_allow_v2_direct_terminal_gacha_deactivation.php"
        ).read_text(encoding="utf-8")
        self.assertIn("NEW.sales_paused IS DISTINCT FROM OLD.sales_paused", direct_deactivation)
        self.assertIn("NEW.sold_count IS DISTINCT FROM OLD.sold_count", direct_deactivation)
        self.assertNotIn("UPDATE catalog_gachas", direct_deactivation)
        self.assertNotIn("DISABLE TRIGGER", restore_migration + direct_deactivation)

    def test_mig_062b_user_tag_paths_are_registered_exactly(self):
        expected_identity = {
            "apps/api/app/Domain/Identity/Exceptions/V2UserTagException.php",
            "apps/api/app/Domain/Identity/Services/V2UserTagService.php",
            "apps/api/app/Http/Controllers/V2/V2AdminUserTagController.php",
            "apps/api/database/migrations-v2/2026_08_28_000041_create_v2_user_tag_management.php",
            "apps/api/database/migrations-v2/2026_08_28_000042_normalize_v2_user_tag_check_constraint.php",
            "apps/api/tests/V2/AdminUserTagManagementTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-user-tag-management.spec.ts",
            "apps/admin/src/app/users/tags/page.tsx",
            "apps/admin/src/components/users/admin-user-tag-management.tsx",
            "apps/admin/test/admin-user-tag-management.test.tsx",
        }
        self.assertEqual(policy_gate.MIG_062B_V2_IDENTITY_FILES, expected_identity)
        self.assertTrue(expected_identity.issubset(policy_gate.V2_IDENTITY_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_062B_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_identity | expected_admin))

    def test_mig_061x_session_timeout_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_08_27_000040_update_v2_session_timeout_constraints.php",
        }
        self.assertEqual(policy_gate.MIG_061X_V2_IDENTITY_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_IDENTITY_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def test_mig_061w_line_settings_migration_is_registered_exactly(self):
        expected = {
            "apps/api/database/migrations-v2/2026_08_26_000039_add_v2_line_settings_management.php",
        }
        self.assertEqual(policy_gate.MIG_061W_V2_IDENTITY_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.V2_IDENTITY_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def test_mig_061v_point_purchase_paths_are_registered_exactly(self):
        expected_payment = {
            "apps/api/app/Domain/Payment/V2/Exceptions/V2PointPurchasePlanException.php",
            "apps/api/app/Domain/Payment/V2/Services/V2PointPurchaseEligibilityService.php",
            "apps/api/app/Domain/Payment/V2/Services/V2PointPurchasePlanService.php",
            "apps/api/app/Http/Controllers/V2/V2AdminPointPurchasePlanController.php",
            "apps/api/database/migrations-v2/2026_08_25_000038_add_v2_point_purchase_management.php",
            "apps/api/tests/V2/AdminPointPurchasePlanManagementTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-point-purchase-management.spec.ts",
            "apps/admin/src/app/purchase-plans/[planPublicId]/page.tsx",
            "apps/admin/src/components/point-purchases/point-purchase-management-workspace.tsx",
            "apps/admin/test/admin-point-purchase-management.test.tsx",
        }
        self.assertEqual(policy_gate.MIG_061V_V2_PAYMENT_FILES, expected_payment)
        self.assertTrue(expected_payment.issubset(policy_gate.V2_PAYMENT_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061V_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_payment | expected_admin))

    def test_mig_061t_referral_point_paths_are_registered_exactly(self):
        expected_point = {
            "apps/api/app/Domain/Referral/Exceptions/V2ReferralException.php",
            "apps/api/app/Domain/Referral/Services/V2ReferralPointSettingService.php",
            "apps/api/app/Domain/Referral/Services/V2ReferralRewardService.php",
            "apps/api/app/Http/Controllers/V2/V2AdminReferralPointSettingController.php",
            "apps/api/app/Models/V2/ReferralPointSetting.php",
            "apps/api/app/Models/V2/UserReferral.php",
            "apps/api/database/migrations-v2/2026_08_24_000037_create_v2_referral_point_settings.php",
            "apps/api/tests/V2/ReferralPointSettingsTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-referral-point-settings.spec.ts",
            "apps/admin/src/components/settings/referral-point-settings.tsx",
            "apps/admin/test/referral-point-settings.test.tsx",
        }
        self.assertEqual(policy_gate.MIG_061T_V2_POINT_FILES, expected_point)
        self.assertTrue(expected_point.issubset(policy_gate.V2_POINT_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061T_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_point | expected_admin))

    def test_mig_061s_rank_effect_paths_are_registered_exactly(self):
        expected_catalog = {
            "apps/api/tests/V2/AdminRankEffectSettingsTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-rank-effect-settings.spec.ts",
            "apps/admin/src/components/catalog/rank-effect-settings-workspace.tsx",
            "apps/admin/test/rank-effect-settings.test.tsx",
        }
        self.assertEqual(policy_gate.MIG_061S_V2_CATALOG_FILES, expected_catalog)
        self.assertTrue(expected_catalog.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061S_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_catalog | expected_admin))

    def test_mig_061r_gacha_master_edit_path_registration_is_exact(self):
        expected_catalog = {
            "apps/api/app/Domain/Catalog/Services/V2GachaPublicCodeGenerator.php",
            "apps/api/database/migrations-v2/2026_08_23_000036_add_v2_gacha_external_public_code.php",
            "apps/api/tests/V2/AdminGachaMasterEditTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-gacha-master-edit.spec.ts",
            "apps/admin/src/app/gachas/[gachaPublicCode]/edit/page.tsx",
        }
        self.assertEqual(policy_gate.MIG_061R_V2_CATALOG_FILES, expected_catalog)
        self.assertTrue(expected_catalog.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061R_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_catalog | expected_admin))

    def test_mig_061q_page_path_registration_is_exact(self):
        expected_content = {
            "apps/api/database/migrations-v2/2026_08_22_000035_add_v2_page_management.php",
            "apps/api/tests/V2/AdminPageManagementTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-page-management.spec.ts",
            "apps/admin/src/app/settings/pages/[pagePublicId]/page.tsx",
            "apps/admin/src/app/settings/pages/new/page.tsx",
            "apps/admin/src/components/pages/page-management-workspace.tsx",
            "apps/admin/test/admin-page-management.test.tsx",
        }
        self.assertEqual(policy_gate.MIG_061Q_V2_CONTENT_FILES, expected_content)
        self.assertTrue(expected_content.issubset(policy_gate.V2_CONTENT_CONTACT_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061Q_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_content | expected_admin))

    def test_mig_061p_banner_path_registration_is_exact(self):
        expected_content = {
            "apps/api/database/migrations-v2/2026_08_21_000034_add_v2_banner_management.php",
            "apps/api/tests/V2/AdminBannerManagementTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-banner-management.spec.ts",
            "apps/admin/src/components/banners/banner-management-workspace.tsx",
            "apps/admin/test/admin-banner-management.test.tsx",
        }

        self.assertEqual(policy_gate.MIG_061P_V2_CONTENT_FILES, expected_content)
        self.assertTrue(expected_content.issubset(policy_gate.V2_CONTENT_CONTACT_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061P_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_content | expected_admin))

    def test_mig_061o_contact_path_registration_is_exact(self):
        expected_content = {
            "apps/api/tests/V2/AdminContactManagementTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-contact-management.spec.ts",
            "apps/admin/src/app/contacts/[contactPublicId]/page.tsx",
            "apps/admin/src/components/contacts/contact-management-workspace.tsx",
            "apps/admin/test/admin-contact-management.test.tsx",
        }

        self.assertTrue(
            expected_content.issubset(policy_gate.V2_CONTENT_CONTACT_REQUIRED_FILES)
        )
        self.assertEqual(policy_gate.MIG_061O_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_content | expected_admin))
        api_dockerfile = (ROOT / "infra/docker/backend/Dockerfile").read_text(
            encoding="utf-8"
        )
        self.assertIn("linux-libc-dev=6.1.180-1", api_dockerfile)
        self.assertNotIn("linux-libc-dev=6.1.177-1", api_dockerfile)

    def test_mig_061n_announcement_path_registration_is_exact(self):
        expected_content = {
            "apps/api/tests/V2/AdminAnnouncementManagementTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-announcement-management.spec.ts",
            "apps/admin/src/app/announcements/[announcementPublicId]/page.tsx",
            "apps/admin/src/components/announcements/announcement-management-workspace.tsx",
            "apps/admin/test/admin-announcement-management.test.tsx",
        }

        self.assertTrue(
            expected_content.issubset(policy_gate.V2_CONTENT_CONTACT_REQUIRED_FILES)
        )
        self.assertEqual(policy_gate.MIG_061N_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_content | expected_admin))

    def test_mig_061m_profit_simulation_path_registration_is_exact(self):
        expected_admin = {
            "apps/admin/e2e/admin-gacha-profit-simulation.spec.ts",
            "apps/admin/src/components/catalog/catalog-gacha-profit-simulation.tsx",
            "apps/admin/src/lib/catalog/gacha-profit-simulation.ts",
            "apps/admin/test/catalog-gacha-profit-simulation.test.tsx",
        }

        self.assertEqual(policy_gate.MIG_061M_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_admin))
        policy_gate.validate_admin_skeleton(ROOT, policy_gate.ADMIN_SKELETON_FILES)
        source = (
            ROOT / "apps/admin/src/components/catalog/catalog-gacha-profit-simulation.tsx"
        ).read_text(encoding="utf-8")
        self.assertEqual(source.count("cost_price"), 1)
        self.assertIn("入力内容と結果は保存されません", source)

    def test_mig_061l_usage_history_path_registration_is_exact(self):
        expected_catalog = {
            "apps/api/tests/V2/AdminGachaUsageHistoryTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-gacha-usage-history.spec.ts",
            "apps/admin/src/components/catalog/catalog-gacha-usage-history.tsx",
            "apps/admin/test/catalog-gacha-usage-history.test.tsx",
        }

        self.assertEqual(policy_gate.MIG_061L_V2_CATALOG_FILES, expected_catalog)
        self.assertTrue(expected_catalog.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061L_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_catalog | expected_admin))
        source = (ROOT / "scripts/ci/policy_gate.py").read_text(encoding="utf-8")
        self.assertIn('"/catalog/gachas/{gacha_id}/history": {"get"}', source)
        self.assertIn(
            '"/catalog/gachas/{gacha_id}/history/{draw_request_id}": {"get"}',
            source,
        )
        self.assertNotIn('"/catalog/gachas/{gacha_id}/history": {"post"}', source)

    def test_mig_061k_rank_prize_path_registration_is_exact(self):
        expected_catalog = {
            "apps/api/database/migrations-v2/2026_08_20_000033_add_v2_gacha_rank_prize_management.php",
            "apps/api/tests/V2/AdminGachaRankPrizeManagementTest.php",
        }
        expected_admin = {
            "apps/admin/src/components/catalog/catalog-gacha-rank-prize-manager.tsx",
            "apps/admin/test/catalog-gacha-rank-prize.test.tsx",
        }

        self.assertEqual(policy_gate.MIG_061K_V2_CATALOG_FILES, expected_catalog)
        self.assertTrue(expected_catalog.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061K_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_catalog | expected_admin))

    def test_mig_061k_cost_price_is_limited_to_draft_gacha_prizes(self):
        policy_gate.validate_admin_skeleton(ROOT, policy_gate.ADMIN_SKELETON_FILES)
        policy_gate.validate_v2_catalog_boundary(
            ROOT, policy_gate.V2_CATALOG_REQUIRED_FILES
        )

        generic_prize = json.loads(
            (ROOT / "openapi/bundled/admin.openapi.json").read_text(
                encoding="utf-8"
            )
        )["components"]["schemas"]["AdminCatalogPrize"]
        self.assertNotIn("cost_price", generic_prize["properties"])

    def test_mig_061i_catalog_path_registration_is_exact(self):
        expected_catalog = {
            "apps/api/database/migrations-v2/2026_08_19_000032_add_v2_gacha_core_management_fields.php",
        }

        self.assertEqual(policy_gate.MIG_061I_V2_CATALOG_FILES, expected_catalog)
        self.assertTrue(expected_catalog.issubset(policy_gate.V2_CATALOG_REQUIRED_FILES))
        self.assertFalse(any("*" in path for path in expected_catalog))

    def test_mig_061h_path_registration_is_exact(self):
        expected_point = {
            "apps/api/app/Domain/Point/Exceptions/V2AdminPointAdjustmentException.php",
            "apps/api/app/Domain/Point/Services/V2AdminPointAdjustmentService.php",
            "apps/api/app/Http/Controllers/V2/V2AdminUserPointAdjustmentController.php",
            "apps/api/tests/Unit/V2AdminPointAdjustmentServiceTest.php",
            "apps/api/tests/V2/AdminUserPointAdjustmentApiTest.php",
            "apps/api/tests/V2/ZAdminUserPointAdjustmentConcurrencyTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-user-point-adjustment.spec.ts",
            "apps/admin/src/components/users/admin-user-point-adjustment-modal.tsx",
            "apps/admin/test/admin-user-point-adjustment.test.tsx",
        }

        self.assertEqual(policy_gate.MIG_061H_V2_POINT_FILES, expected_point)
        self.assertTrue(expected_point.issubset(policy_gate.V2_POINT_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061H_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_point | expected_admin))

    def test_mig_061g_path_registration_is_exact(self):
        expected_identity = {
            "apps/api/app/Domain/Identity/Exceptions/V2AdminUserReadException.php",
            "apps/api/app/Domain/Identity/Services/V2AdminUserReadService.php",
            "apps/api/app/Http/Controllers/V2/V2AdminUserController.php",
            "apps/api/database/migrations-v2/2026_08_18_000031_add_display_name_to_v2_users.php",
            "apps/api/tests/Unit/V2AdminUserReadServiceTest.php",
            "apps/api/tests/V2/AdminUserReadModelApiTest.php",
            "apps/api/tests/V2/ZAdminUserReadModelPerformanceTest.php",
        }
        expected_admin = {
            "apps/admin/e2e/admin-user-read-model.spec.ts",
            "apps/admin/src/app/users/[userPublicId]/gacha-history/page.tsx",
            "apps/admin/src/app/users/[userPublicId]/page.tsx",
            "apps/admin/src/components/users/admin-user-read-workspace.tsx",
            "apps/admin/src/components/users/use-admin-user-read-model.ts",
            "apps/admin/test/admin-user-read-model.test.tsx",
        }

        self.assertEqual(policy_gate.MIG_061G_V2_IDENTITY_FILES, expected_identity)
        self.assertTrue(expected_identity.issubset(policy_gate.V2_IDENTITY_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061G_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_identity | expected_admin))

    def test_mig_061f_path_registration_is_exact(self):
        expected_reporting = {
            "apps/api/tests/Unit/V2ReportingServiceDashboardAggregationTest.php",
            "apps/api/tests/V2/DashboardSalesAggregationApiTest.php",
            "apps/api/tests/V2/ZDashboardSalesAggregationPerformanceTest.php",
        }
        expected_admin = {
            "apps/admin/src/components/shell/use-dashboard-sales-data.ts",
        }

        self.assertTrue(expected_reporting.issubset(policy_gate.V2_REPORTING_REQUIRED_FILES))
        self.assertEqual(policy_gate.MIG_061F_ADMIN_SKELETON_FILES, expected_admin)
        self.assertTrue(expected_admin.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected_reporting | expected_admin))

    def test_mig_061e_admin_skeleton_registration_is_exact(self):
        expected = {
            "apps/admin/e2e/dashboard-sales-layout.spec.ts",
            "apps/admin/src/components/shell/dashboard-sales-layout.tsx",
            "apps/admin/test/dashboard-sales-layout.test.tsx",
        }

        self.assertEqual(policy_gate.MIG_061E_ADMIN_SKELETON_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def test_mig_084_admin_skeleton_registration_is_exact(self):
        expected = {
            "apps/admin/e2e/admin-payment-history.spec.ts",
            "apps/admin/src/app/payments/page.tsx",
            "apps/admin/src/components/payments/admin-payment-history.tsx",
            "apps/admin/test/admin-payment-history.test.tsx",
        }

        self.assertEqual(policy_gate.MIG_084_ADMIN_SKELETON_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def test_mig_061d_admin_skeleton_registration_is_exact(self):
        expected = {
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

        self.assertEqual(policy_gate.MIG_061D_ADMIN_SKELETON_FILES, expected)
        self.assertTrue(expected.issubset(policy_gate.ADMIN_SKELETON_FILES))
        self.assertFalse(any("*" in path for path in expected))

    def make_release_foundation(self, root):
        required = set(policy_gate.RELEASE_ARTIFACT_REQUIRED_FILES)
        required.add("package.json")
        for relative in required:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return required

    def test_release_artifact_foundation_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_release_foundation(root)
            policy_gate.validate_release_artifact_foundation(root, paths)

    def test_storefront_release_governance_rejects_latest_manifest_tamper(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / "manifests/storefront-contract-releases.json"
            target.parent.mkdir(parents=True)
            value = json.loads(
                (ROOT / "manifests/storefront-contract-releases.json").read_text()
            )
            value["latest_immutable"]["manifest_sha256"] = "0" * 64
            target.write_text(json.dumps(value), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "release governance is invalid"
            ):
                policy_gate.storefront_release_governance(root)

    def test_storefront_release_governance_rejects_latest_package_tamper(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / "manifests/storefront-contract-releases.json"
            target.parent.mkdir(parents=True)
            value = json.loads(
                (ROOT / "manifests/storefront-contract-releases.json").read_text()
            )
            value["latest_immutable"]["packages"]["@oripa/site-schema"]["sha256"] = "0" * 64
            target.write_text(json.dumps(value), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "release governance is invalid"
            ):
                policy_gate.storefront_release_governance(root)

    def test_storefront_release_governance_accepts_released_additive_alpha_27_bundle(self):
        value = policy_gate.storefront_release_governance(ROOT)
        self.assertEqual(value["latest_immutable"]["bundle_version"], "2.0.0-alpha.27")
        self.assertEqual(value["latest_immutable"]["release_mode"], "contract-additive")
        self.assertIsNone(value["candidate"])
        self.assertEqual(value["latest_immutable"]["public_openapi"]["operation_count"], 64)
        self.assertEqual(
            value["latest_immutable"]["contract_versions"],
            {
                "public": "2.0.0-alpha.26",
                "admin": "2.0.0-alpha.26",
                "webhook": "2.0.0-alpha.26",
            },
        )

    def test_storefront_release_governance_rejects_released_public_digest_tamper(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            target = root / "manifests/storefront-contract-releases.json"
            target.parent.mkdir(parents=True)
            value = json.loads(
                (ROOT / "manifests/storefront-contract-releases.json").read_text()
            )
            value["latest_immutable"]["public_openapi"]["sha256"] = "0"
            target.write_text(json.dumps(value), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "release governance is invalid"
            ):
                policy_gate.storefront_release_governance(root)

    def test_release_artifact_floating_base_image_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_release_foundation(root)
            dockerfile = root / "apps/admin/Dockerfile"
            dockerfile.write_text(
                dockerfile.read_text(encoding="utf-8").replace(
                    "node:22.22.3-alpine@sha256:"
                    "e58326d0d441090181ac150dc2078d3e2cf6a0d42e809aebba3ef5880935ffdd",
                    "node:latest",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "digest"):
                policy_gate.validate_release_artifact_foundation(root, paths)

    def test_release_artifact_non_standalone_admin_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_release_foundation(root)
            config = root / "apps/admin/next.config.ts"
            config.write_text(
                config.read_text(encoding="utf-8").replace(
                    '  output: "standalone",\n',
                    "",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "standalone"):
                policy_gate.validate_release_artifact_foundation(root, paths)

    def test_positive_pull_request_fixture_passes(self):
        data = fixture("positive.json")
        policy_gate.validate_pr_body(
            data["pr_body"],
            data["title"],
            data["changed_paths"],
            data["base_sha"],
        )
        policy_gate.validate_workflow_text("fixture.yml", data["workflow"])
        policy_gate.validate_dangerous_paths(data["tracked_paths"])

    def test_contract_lane_task_id_with_multiple_segments_passes(self):
        data = fixture("positive.json")
        body = data["pr_body"].replace("GOV-008", "STORE-SITE-034")
        policy_gate.validate_pr_body(
            body,
            "[STORE-SITE-034] Browser-safe Contact Client Contract",
            data["changed_paths"],
            data["base_sha"],
        )

    def test_existing_task_id_pattern_remains_accepted(self):
        self.assertIsNotNone(policy_gate.TASK_ID.fullmatch("GOV-008"))
        self.assertIsNotNone(policy_gate.TASK_ID.fullmatch("MIG-063B"))

    def test_malformed_store_site_task_ids_are_rejected(self):
        for task_id in (
            "STORE-SITE",
            "STORE-SITE-",
            "STORE-SITE-ABC",
            "STORE-SITE-034A",
            "STORE-SITE--034",
        ):
            with self.subTest(task_id=task_id):
                self.assertIsNone(policy_gate.TASK_ID.fullmatch(task_id))

    def test_unrelated_store_task_ids_are_rejected(self):
        for task_id in ("STORE-CONTACT-034", "STORE-OTHER-034", "STORE-034-EXTRA"):
            with self.subTest(task_id=task_id):
                self.assertIsNone(policy_gate.TASK_ID.fullmatch(task_id))

    def test_pull_request_scope_sections_stop_at_the_next_heading(self):
        data = fixture("positive.json")
        body = (
            data["pr_body"]
            + "\n## Verification performed\n"
            + "\n- `php artisan test` PASS\n"
        )
        policy_gate.validate_pr_body(
            body,
            data["title"],
            data["changed_paths"],
            data["base_sha"],
        )

    def test_missing_metadata_fixture_fails(self):
        data = fixture("negative_missing_metadata.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "Risk"):
            policy_gate.validate_pr_body(
                data["pr_body"],
                data["title"],
                data["changed_paths"],
                data["base_sha"],
            )

    def test_floating_action_fixture_fails(self):
        data = fixture("negative_floating_action.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "full SHA"):
            policy_gate.validate_workflow_text("fixture.yml", data["workflow"])

    def test_secret_path_fixture_fails(self):
        data = fixture("negative_secret_path.json")
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "dangerous tracked"):
            policy_gate.validate_dangerous_paths(data["tracked_paths"])

    def test_declared_directory_prefix_allows_nested_path(self):
        self.assertTrue(
            policy_gate.declared_path_allowed(
                "apps/api/app/Models/User.php",
                ["apps/api/**"],
            )
        )
        self.assertFalse(
            policy_gate.declared_path_allowed(
                "legacy/v1-frontend/src/app/page.tsx",
                ["apps/api/**"],
            )
        )

    def test_codeql_job_can_upload_security_events(self):
        workflow = """\
permissions:
  contents: read
concurrency:
  group: codeql
jobs:
  analyze:
    timeout-minutes: 30
    permissions:
      contents: read
      security-events: write
    steps:
      - uses: github/codeql-action/analyze@e4fba868fa4b1b91e1fdab776edc8cfbe6e9fb81 # v4
"""
        policy_gate.validate_workflow_text(".github/workflows/codeql.yml", workflow)

    def test_non_codeql_security_events_write_fails(self):
        workflow = """\
permissions:
  contents: read
concurrency:
  group: unsafe
jobs:
  analyze:
    timeout-minutes: 30
    permissions:
      contents: read
      security-events: write
    steps:
      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2
"""
        with self.assertRaisesRegex(policy_gate.PolicyFailure, "write workflow permission"):
            policy_gate.validate_workflow_text(".github/workflows/unsafe.yml", workflow)

    def test_preview_image_pipeline_uses_pinned_amd64_artifacts_and_host_guards(self):
        policy_gate.validate_preview_image_pipeline(
            ROOT,
            set(policy_gate.tracked_paths(ROOT))
            | policy_gate.PREVIEW_IMAGE_PIPELINE_REQUIRED_FILES,
        )

    def test_preview_image_pipeline_rejects_production_host_build_helper(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for relative in policy_gate.PREVIEW_IMAGE_PIPELINE_REQUIRED_FILES:
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(
                    (ROOT / relative).read_text(encoding="utf-8"), encoding="utf-8"
                )
            helper = root / "scripts/ops/preview_image_artifact.py"
            helper.write_text(helper.read_text() + "\n# docker build\n")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "may only load verified images"
            ):
                policy_gate.validate_preview_image_pipeline(
                    root, policy_gate.PREVIEW_IMAGE_PIPELINE_REQUIRED_FILES
                )

    def test_preview_image_pipeline_rejects_arm64_runner_regression(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for relative in policy_gate.PREVIEW_IMAGE_PIPELINE_REQUIRED_FILES:
                target = root / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(
                    (ROOT / relative).read_text(encoding="utf-8"), encoding="utf-8"
                )
            workflow = root / ".github/workflows/preview-image-build.yml"
            workflow.write_text(
                workflow.read_text().replace(
                    "runs-on: ubuntu-24.04", "runs-on: ubuntu-24.04-arm"
                )
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "GitHub-hosted x64 runner"
            ):
                policy_gate.validate_preview_image_pipeline(
                    root, policy_gate.PREVIEW_IMAGE_PIPELINE_REQUIRED_FILES
                )

    def test_dependency_review_allowlist_matches_exact_security_baseline(self):
        policy_gate.validate_dependency_review_allowlist(ROOT)

    def make_v2_database_boundary(self, root):
        migration = root / "apps/api/database/migrations/2026_01_01_000000_v1.php"
        migration.parent.mkdir(parents=True, exist_ok=True)
        migration.write_text("<?php return 'v1';\n", encoding="utf-8")
        v2_root = root / "apps/api/database/migrations-v2"
        v2_root.mkdir(parents=True)
        (v2_root / "README.md").write_text(
            "scripts/db/v2_database.py uses apps/api/database/migrations-v2 "
            "instead of apps/api/database/migrations in non-Production.\n",
            encoding="utf-8",
        )
        count, checksum = policy_gate.migration_content_set(
            root, "apps/api/database/migrations"
        )
        baseline = root / ".ci/baselines/v1-migrations.json"
        baseline.parent.mkdir(parents=True)
        baseline.write_text(
            json.dumps(
                {
                    "schema_version": "1.0",
                    "path": "apps/api/database/migrations",
                    "file_count": count,
                    "content_sha256_set": checksum,
                }
            ),
            encoding="utf-8",
        )
        compose = root / "docker-compose.v2.yml"
        compose.write_text(
            """# This is never a Production deployment.
services:
  api:
    environment:
      DB_DATABASE: ${V2_DB_DATABASE:?required}
      DB_USERNAME: ${V2_DB_USERNAME:?required}
      DB_PASSWORD: ${V2_DB_PASSWORD:?required}
      REDIS_PASSWORD: ${V2_REDIS_PASSWORD:?required}
      V2_AUDIT_HMAC_KEY: ${V2_AUDIT_HMAC_KEY:?required}
      V2_PII_CORRELATION_KEY: ${V2_PII_CORRELATION_KEY:?required}
    networks:
      - v2_private
  admin:
    image: admin
    networks:
      - v2_private
  postgres:
    image: postgres:17-alpine
    volumes:
      - v2_postgres:/var/lib/postgresql/data
    networks:
      - v2_private
  redis:
    image: redis:7-alpine
    volumes:
      - v2_redis:/data
    networks:
      - v2_private
networks:
  v2_private:
    internal: true
  v2_api_egress:
    driver: bridge
    ipam:
      config:
        - subnet: ${V2_API_EGRESS_SUBNET:-192.168.62.0/28}
volumes:
  v2_postgres:
  v2_redis:
""",
            encoding="utf-8",
        )
        runner = root / "scripts/db/v2_database.py"
        runner.parent.mkdir(parents=True)
        runner.write_text(
            """
MIGRATION_PATH = "apps/api/database/migrations-v2"
V1_MIGRATION_PATH = "apps/api/database/migrations"
# Production or unexpected environment is prohibited
# V1 Compose Project is prohibited
# V1 Migration Path is prohibited
# Unexpected Database or Redis Host
# "V2_AUDIT_HMAC_KEY"
# V2 Audit HMAC key
# "V2_PII_CORRELATION_KEY"
# V2 PII correlation key
# Database and Redis Host Ports are prohibited
# Refusing to remove an unscoped Volume
""",
            encoding="utf-8",
        )
        for relative in (
            "docs/operations/database/README.md",
            "scripts/db/README.md",
            "tests/db/test_v2_database.py",
        ):
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("fixture\n", encoding="utf-8")
        workflow = root / ".github/workflows/platform-ci.yml"
        workflow.parent.mkdir(parents=True)
        workflow.write_text(
            """
php apps/api/artisan migrate --path=database/migrations
python3 -m unittest discover -s tests/db -p 'test_*.py'
python3 scripts/db/v2_database.py smoke \\
  --migration-path apps/api/database/migrations-v2
""",
            encoding="utf-8",
        )
        paths = set(policy_gate.V2_DATABASE_REQUIRED_FILES)
        paths.update(
            {
                "apps/api/database/migrations/2026_01_01_000000_v1.php",
                ".github/workflows/platform-ci.yml",
            }
        )
        return paths

    def test_v2_database_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            policy_gate.validate_v2_database_boundary(root, paths)

    def test_v1_migration_change_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            migration = next((root / "apps/api/database/migrations").glob("*.php"))
            migration.write_text("<?php return 'changed';\n", encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "checksum"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_host_port_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "    volumes:\n      - v2_postgres",
                    "    ports:\n      - 5432:5432\n    volumes:\n      - v2_postgres",
                    1,
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "Host Port"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_api_egress_is_required(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "  v2_api_egress:\n"
                    "    driver: bridge\n"
                    "    ipam:\n"
                    "      config:\n"
                    "        - subnet: ${V2_API_EGRESS_SUBNET:-192.168.62.0/28}\n",
                    "",
                    1,
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "missing v2_api_egress"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_api_create_phase_egress_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "  admin:\n",
                    "      - v2_api_egress\n  admin:\n",
                    1,
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "api.*prohibited"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_postgres_api_egress_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "  redis:\n",
                    "      - v2_api_egress\n  redis:\n",
                    1,
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "postgres.*prohibited"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_admin_api_egress_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "  postgres:\n",
                    "      - v2_api_egress\n  postgres:\n",
                    1,
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "admin.*prohibited"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_private_network_must_remain_internal(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "    internal: true", "    internal: false", 1
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "missing internal: true"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_api_egress_must_not_be_internal(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "  v2_api_egress:\n    driver: bridge",
                    "  v2_api_egress:\n    driver: bridge\n    internal: true",
                    1,
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "egress.*invalid"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_shared_volume_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "v2_postgres", "oripa_postgres_data"
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "missing|prohibited"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def test_v2_database_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_v2_database_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8") + "\n# tenant_id\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_database_boundary(root, paths)

    def copy_v2_identity_boundary(self, root):
        paths = set(policy_gate.V2_IDENTITY_REQUIRED_FILES)
        supporting = {
            "apps/api/config/auth.php",
            "apps/api/composer.json",
            "apps/api/composer.lock",
            ".github/workflows/platform-ci.yml",
            "openapi/bundled/public.openapi.json",
            "openapi/bundled/admin.openapi.json",
            "apps/api/routes/admin.php",
            "scripts/db/v2_database.py",
            "apps/api/database/migrations-v2/2026_07_24_000005_create_v2_audit_outbox_foundation.php",
            "apps/api/database/migrations-v2/2026_07_24_000006_create_v2_point_model_foundation.php",
            "apps/api/database/migrations-v2/2026_07_25_000007_create_v2_payment_model_foundation.php",
            "apps/api/database/migrations-v2/2026_07_28_000008_create_v2_catalog_probability_foundation.php",
            "apps/api/database/migrations-v2/2026_07_29_000009_create_v2_draw_vertical_slice.php",
            "apps/api/database/migrations-v2/2026_07_30_000010_create_v2_prize_shipping_vertical_slice.php",
            "apps/api/database/migrations-v2/2026_07_31_000011_create_v2_qa_draw_vertical_slice.php",
            "apps/api/database/migrations-v2/2026_08_01_000012_create_v2_reporting_export_foundation.php",
            "apps/api/database/migrations-v2/2026_08_02_000013_create_v2_content_contact_vertical_slice.php",
            "apps/api/database/migrations-v2/2026_08_03_000014_create_v2_password_reset_sms_verification.php",
            "apps/api/database/migrations-v2/2026_08_04_000015_create_v2_external_identity_google_oidc.php",
            "apps/api/database/migrations-v2/2026_08_05_000016_add_v2_catalog_master_mutation_foundation.php",
            "apps/api/database/migrations-v2/2026_08_06_000017_add_v2_catalog_prize_asset_mutation_foundation.php",
            "apps/api/database/migrations-v2/2026_08_07_000018_add_line_external_identity_provider.php",
            "apps/api/database/migrations-v2/2026_08_07_000019_create_line_messaging_follow_foundation.php",
            "apps/api/database/migrations-v2/2026_08_07_000020_add_line_friend_reward_enabled.php",
            "apps/api/database/migrations-v2/2026_08_08_000021_add_v2_gacha_draft_management.php",
            "apps/api/database/migrations-v2/2026_08_09_000022_add_v2_probability_draft_management.php",
            "apps/api/database/migrations-v2/2026_08_10_000023_protect_v2_published_probability_relations.php",
            "apps/api/database/migrations-v2/2026_08_11_000024_guard_v2_gacha_probability_selection.php",
            "apps/api/database/migrations-v2/2026_08_12_000025_add_v2_gacha_immediate_publish_activation.php",
            "apps/api/database/migrations-v2/2026_08_13_000026_create_v2_gacha_publish_schedules.php",
            "apps/api/database/migrations-v2/2026_08_14_000027_add_v2_gacha_sales_pause.php",
            "apps/api/database/migrations-v2/2026_08_15_000028_add_v2_gacha_public_deactivation.php",
            "apps/api/database/migrations-v2/2026_08_16_000029_add_v2_qa_plan_management.php",
            "apps/api/database/migrations-v2/2026_08_18_000031_add_display_name_to_v2_users.php",
            "apps/api/database/migrations-v2/2026_08_19_000032_add_v2_gacha_core_management_fields.php",
            "apps/api/database/migrations-v2/2026_08_20_000033_add_v2_gacha_rank_prize_management.php",
            "apps/api/database/migrations-v2/2026_08_21_000034_add_v2_banner_management.php",
            "apps/api/database/migrations-v2/2026_08_22_000035_add_v2_page_management.php",
            "apps/api/database/migrations-v2/2026_08_23_000036_add_v2_gacha_external_public_code.php",
            "apps/api/database/migrations-v2/2026_08_24_000037_create_v2_referral_point_settings.php",
            "apps/api/database/migrations-v2/2026_08_25_000038_add_v2_point_purchase_management.php",
            "apps/api/database/migrations-v2/2026_08_26_000039_add_v2_line_settings_management.php",
            "apps/api/database/migrations-v2/2026_08_27_000040_update_v2_session_timeout_constraints.php",
            "apps/api/database/migrations-v2/2026_08_28_000041_create_v2_user_tag_management.php",
            "apps/api/database/migrations-v2/2026_08_28_000042_normalize_v2_user_tag_check_constraint.php",
            "apps/api/database/migrations-v2/2026_08_29_000043_add_v2_point_purchase_plan_target_tag.php",
            "apps/api/database/migrations-v2/2026_08_30_000044_add_v2_gacha_registration_eligibility_and_management_state.php",
            "apps/api/database/migrations-v2/2026_08_31_000045_add_v2_gacha_allowed_draw_counts.php",
            "apps/api/database/migrations-v2/2026_09_01_000046_allow_v2_partial_remaining_draw_execution.php",
            "apps/api/database/migrations-v2/2026_09_02_000047_add_v2_user_state_revision.php",
            "apps/api/database/migrations-v2/2026_09_03_000048_add_v2_gacha_prize_ownership_snapshots.php",
            "apps/api/database/migrations-v2/2026_09_04_000049_integrate_v2_qa_test_user_guarantees.php",
            "apps/api/database/migrations-v2/2026_09_05_000050_add_v2_static_page_footer_visibility.php",
            "apps/api/database/migrations-v2/2026_09_06_000051_add_v2_banner_top_presentation.php",
            "apps/api/database/migrations-v2/2026_09_07_000052_add_v2_gacha_lifecycle_presentation.php",
            "apps/api/database/migrations-v2/2026_09_08_000053_operational_gacha_inventory.php",
            "apps/api/database/migrations-v2/2026_09_09_000054_add_v2_coin_expiry_core.php",
            "apps/api/database/migrations-v2/2026_09_10_000055_add_v2_limited_bonus_domain_core.php",
            "apps/api/database/migrations-v2/2026_09_11_000056_allow_v2_published_category_tag_presentation_edits.php",
            "apps/api/database/migrations-v2/2026_09_12_000057_scope_v2_gacha_rank_codes.php",
            "apps/api/database/migrations-v2/2026_09_12_000060_reconcile_preview_gacha_capacity.php",
            "apps/api/database/migrations-v2/2026_09_13_000058_canonicalize_v2_gacha_lifecycle_inventory_capacity.php",
            "apps/api/database/migrations-v2/2026_09_14_000059_internalize_v2_canonical_probability_publish.php",
            "apps/api/database/migrations-v2/2026_09_15_000061_allow_v2_gacha_unpublished_draft_restore.php",
            "apps/api/database/migrations-v2/2026_09_16_000062_allow_v2_direct_terminal_gacha_deactivation.php",
            "apps/api/database/migrations-v2/2026_09_18_000064_add_v2_mail_templates.php",
            "apps/api/database/migrations-v2/2026_09_21_000065_add_fincode_payment_backend_core.php",
        }
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_identity_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_identity_boundary(root)
            policy_gate.validate_v2_identity_boundary(root, paths)

    def test_v2_identity_oidc_jwt_resolved_version_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_identity_boundary(root)
            lock_path = root / "apps/api/composer.lock"
            lock = json.loads(lock_path.read_text(encoding="utf-8"))
            for package in lock["packages"]:
                if package.get("name") == "firebase/php-jwt":
                    package["version"] = "7.1.1"
            lock_path.write_text(json.dumps(lock), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "exactly v7.1.0"):
                policy_gate.validate_v2_identity_boundary(root, paths)

    def test_v2_identity_missing_admin_guard_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_identity_boundary(root)
            auth = root / "apps/api/config/auth.php"
            auth.write_text(
                auth.read_text(encoding="utf-8").replace("'v2_admin'", "'removed'"),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "Auth separation"):
                policy_gate.validate_v2_identity_boundary(root, paths)

    def test_v2_identity_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_identity_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_24_000001_create_v2_identity_accounts.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8") + "\n// tenant_id\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_identity_boundary(root, paths)

    def copy_v2_audit_outbox_boundary(self, root):
        paths = set(policy_gate.V2_AUDIT_OUTBOX_REQUIRED_FILES)
        supporting = {
            "apps/api/app/Providers/V2AuthorizationServiceProvider.php",
            "docker-compose.v2.yml",
            "scripts/db/v2_database.py",
        }
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_audit_outbox_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_audit_outbox_boundary(root)
            policy_gate.validate_v2_audit_outbox_boundary(root, paths)

    def test_v2_audit_outbox_boundary_rejects_outbox_email_binding(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_audit_outbox_boundary(root)
            provider = root / "apps/api/app/Providers/V2AuthorizationServiceProvider.php"
            provider.write_text(
                provider.read_text(encoding="utf-8").replace(
                    "V2MailEmailVerificationNotifier::class",
                    "V2OutboxEmailVerificationNotifier::class",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "direct email"):
                policy_gate.validate_v2_audit_outbox_boundary(root, paths)

    def test_v2_audit_outbox_missing_hmac_boundary_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_audit_outbox_boundary(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "V2_AUDIT_HMAC_KEY", "REMOVED_AUDIT_KEY"
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "HMAC"):
                policy_gate.validate_v2_audit_outbox_boundary(root, paths)

    def test_v2_audit_outbox_mutation_guard_missing_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_audit_outbox_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_24_000005_create_v2_audit_outbox_foundation.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8").replace(
                    "BEFORE TRUNCATE", "REMOVED TRUNCATE GUARD"
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "BEFORE TRUNCATE"):
                policy_gate.validate_v2_audit_outbox_boundary(root, paths)

    def copy_v2_point_boundary(self, root):
        paths = set(policy_gate.V2_POINT_REQUIRED_FILES)
        supporting = {
            "apps/api/app/Domain/Identity/Services/V2PermissionAuthorizer.php",
        }
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_point_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_point_boundary(root)
            policy_gate.validate_v2_point_boundary(root, paths)

    def test_v2_point_skip_locked_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_point_boundary(root)
            service = (
                root / "apps/api/app/Domain/Point/Services/V2PointService.php"
            )
            service.write_text(
                service.read_text(encoding="utf-8") + "\n// SKIP LOCKED\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "SKIP LOCKED"):
                policy_gate.validate_v2_point_boundary(root, paths)

    def test_v2_point_paid_grant_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_point_boundary(root)
            service = (
                root / "apps/api/app/Domain/Point/Services/V2PointService.php"
            )
            service.write_text(
                service.read_text(encoding="utf-8") + "\n// grantPaid\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "paid Point grant"):
                policy_gate.validate_v2_point_boundary(root, paths)

    def test_v2_coin_expiry_null_grant_guard_is_required(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_point_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_09_09_000054_add_v2_coin_expiry_core.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8").replace(
                    "point_lots_new_expiry_guard",
                    "REMOVED_POINT_LOT_EXPIRY_GUARD",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "expiry_guard"):
                policy_gate.validate_v2_point_boundary(root, paths)

    def test_v2_coin_expiry_fefo_nulls_last_is_required(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_point_boundary(root)
            service = root / "apps/api/app/Domain/Point/Services/V2PointService.php"
            service.write_text(
                service.read_text(encoding="utf-8").replace(
                    "orderByRaw('expire_at ASC NULLS LAST')",
                    "orderBy('expire_at')",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "NULLS LAST"):
                policy_gate.validate_v2_point_boundary(root, paths)

    def copy_v2_payment_boundary(self, root):
        paths = set(policy_gate.V2_PAYMENT_REQUIRED_FILES)
        for relative in paths:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths

    def test_v2_payment_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_payment_boundary(root)
            policy_gate.validate_v2_payment_boundary(root, paths)

    def test_v2_payment_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_payment_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_25_000007_create_v2_payment_model_foundation.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8") + "\n// tenant_id\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_payment_boundary(root, paths)

    def test_v2_payment_common_expiry_policy_is_required(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_payment_boundary(root)
            service = (
                root
                / "apps/api/app/Domain/Payment/V2/Services/V2PaymentService.php"
            )
            service.write_text(
                service.read_text(encoding="utf-8").replace(
                    "expiresAt($grantedAt)",
                    "REMOVED_COMMON_EXPIRY_POLICY",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "expiresAt|missing"):
                policy_gate.validate_v2_payment_boundary(root, paths)

    def test_v2_payment_canonical_provider_time_is_required(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_payment_boundary(root)
            service = (
                root
                / "apps/api/app/Domain/Payment/V2/Services/V2PaymentService.php"
            )
            service.write_text(
                service.read_text(encoding="utf-8").replace(
                    "PROVIDER_OCCURRED_AT_REQUIRED",
                    "REMOVED_CANONICAL_PROVIDER_TIME",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "provider|missing"):
                policy_gate.validate_v2_payment_boundary(root, paths)

    def copy_v2_catalog_boundary(self, root):
        paths = set(policy_gate.V2_CATALOG_REQUIRED_FILES)
        for relative in paths:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        lifecycle_test = Path(
            "apps/api/tests/V2/GachaLifecyclePresentationTest.php"
        )
        lifecycle_destination = root / lifecycle_test
        lifecycle_destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / lifecycle_test, lifecycle_destination)
        workflow = root / ".github/workflows/platform-ci.yml"
        workflow.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / ".github/workflows/platform-ci.yml", workflow)
        paths.add(".github/workflows/platform-ci.yml")
        return paths

    def test_v2_catalog_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            policy_gate.validate_v2_catalog_boundary(root, paths)

    def test_v2_catalog_scheduled_worker_mutation_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            worker = (
                root
                / "apps/api/app/Domain/Catalog/Services/"
                "V2ScheduledGachaPublishWorker.php"
            )
            worker.write_text(
                worker.read_text(encoding="utf-8")
                + "\n<?php DB::table('catalog_gachas')->forceDelete();\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "prohibited forceDelete"):
                policy_gate.validate_v2_catalog_boundary(root, paths)

    def test_v2_catalog_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_28_000008_create_v2_catalog_probability_foundation.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8") + "\n// tenant_id\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_catalog_boundary(root, paths)

    def test_v2_catalog_no_prize_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_28_000008_create_v2_catalog_probability_foundation.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8") + "\n// no_prize\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "no_prize"):
                policy_gate.validate_v2_catalog_boundary(root, paths)

    def test_v2_catalog_public_probability_leak_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            bundle = root / "openapi/bundled/public.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document["components"]["schemas"]["GachaDetail"]["properties"][
                "individual_ppm"
            ] = {"type": "integer"}
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "individual_ppm"):
                policy_gate.validate_v2_catalog_boundary(root, paths)

    def test_v2_admin_catalog_physical_delete_contract_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            bundle = root / "openapi/bundled/admin.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document["paths"]["/catalog/prizes/{catalog_resource_id}"]["delete"] = {
                "operationId": "deleteAdminCatalogPrize"
            }
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "prohibited"):
                policy_gate.validate_v2_catalog_boundary(root, paths)

    def test_v2_catalog_physical_asset_delete_fails_without_cross_statement_false_positive(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            service = (
                root
                / "apps/api/app/Domain/Catalog/Services/"
                "V2CatalogMasterMutationService.php"
            )
            service.write_text(
                service.read_text(encoding="utf-8")
                + "\n<?php DB::table('catalog_presentation_assets')"
                + "->where('id', 1)->delete();\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure,
                "physically deletes catalog_presentation_assets",
            ):
                policy_gate.validate_v2_catalog_boundary(root, paths)

    def test_v2_admin_gacha_unpublish_contract_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            bundle = root / "openapi/bundled/admin.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document["paths"][
                "/catalog/gachas/{gacha_id}/versions/{gacha_version_id}/unpublish"
            ] = {
                "post": {
                    "operationId": "unpublishAdminGachaVersion",
                }
            }
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "prohibited"):
                policy_gate.validate_v2_catalog_boundary(root, paths)

    def test_v2_admin_catalog_storage_identifier_leak_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_catalog_boundary(root)
            bundle = root / "openapi/bundled/admin.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document["components"]["schemas"]["AdminCatalogPresentationAsset"][
                "properties"
            ]["storage_identifier"] = {"type": "string"}
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "storage_identifier"):
                policy_gate.validate_v2_catalog_boundary(root, paths)

    def copy_v2_draw_boundary(self, root):
        paths = set(policy_gate.V2_DRAW_REQUIRED_FILES)
        supporting = {
            ".github/workflows/platform-ci.yml",
        }
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_draw_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_draw_boundary(root)
            policy_gate.validate_v2_draw_boundary(root, paths)

    def test_v2_draw_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_draw_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_29_000009_create_v2_draw_vertical_slice.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8") + "\n// tenant_id\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_draw_boundary(root, paths)

    def test_v2_draw_public_internal_field_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_draw_boundary(root)
            bundle = root / "openapi/bundled/public.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document["components"]["schemas"]["DrawResponse"]["properties"][
                "random_value"
            ] = {"type": "integer"}
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "random_value"):
                policy_gate.validate_v2_draw_boundary(root, paths)

    def test_v2_draw_legacy_probability_selection_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_draw_boundary(root)
            service = root / "apps/api/app/Domain/Draw/Services/V2DrawService.php"
            service.write_text(
                service.read_text(encoding="utf-8")
                + "\n// catalog_probability_entries\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "legacy selection"):
                policy_gate.validate_v2_draw_boundary(root, paths)

    def test_v2_draw_direct_point_back_selection_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_draw_boundary(root)
            service = root / "apps/api/app/Domain/Draw/Services/V2DrawService.php"
            service.write_text(
                service.read_text(encoding="utf-8")
                + "\n// grantDrawPointBackBatch(\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "legacy selection"):
                policy_gate.validate_v2_draw_boundary(root, paths)

    def test_v2_draw_fixed_ppm_random_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_draw_boundary(root)
            service = root / "apps/api/app/Domain/Draw/Services/V2DrawService.php"
            service.write_text(
                service.read_text(encoding="utf-8").replace(
                    "random->integer(1, $totalWeight)",
                    "random->integer(0, 999_999)",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "integer"):
                policy_gate.validate_v2_draw_boundary(root, paths)

    def test_v2_draw_canonical_inventory_snapshot_fails_when_bypassed(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_draw_boundary(root)
            service = root / "apps/api/app/Domain/Draw/Services/V2DrawService.php"
            service.write_text(
                service.read_text(encoding="utf-8").replace(
                    "$remainingCount = $this->remainingInventory($inventories)",
                    "$remainingCount = 0",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "remainingCount"):
                policy_gate.validate_v2_draw_boundary(root, paths)

    def test_v2_draw_transaction_idempotency_boundary_fails_when_removed(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_draw_boundary(root)
            service = root / "apps/api/app/Domain/Draw/Services/V2DrawService.php"
            service.write_text(
                service.read_text(encoding="utf-8").replace(
                    "$this->idempotency->complete",
                    "$this->idempotency->discard",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "idempotency"):
                policy_gate.validate_v2_draw_boundary(root, paths)

    def copy_v2_prize_shipping_boundary(self, root):
        paths = set(policy_gate.V2_PRIZE_SHIPPING_REQUIRED_FILES)
        supporting = {
            ".github/workflows/platform-ci.yml",
        }
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_prize_shipping_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_prize_shipping_boundary(root)
            policy_gate.validate_v2_prize_shipping_boundary(root, paths)

    def test_v2_prize_shipping_plain_pii_column_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_prize_shipping_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_30_000010_create_v2_prize_shipping_vertical_slice.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8")
                + "\n// $table->string('recipient_name');\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "recipient_name"):
                policy_gate.validate_v2_prize_shipping_boundary(root, paths)

    def test_v2_prize_shipping_public_ciphertext_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_prize_shipping_boundary(root)
            bundle = root / "openapi/bundled/public.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document["components"]["schemas"]["ShippingAddress"]["properties"] = {
                "recipient_name_ciphertext": {"type": "string"}
            }
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "ciphertext"):
                policy_gate.validate_v2_prize_shipping_boundary(root, paths)

    def copy_v2_qa_draw_boundary(self, root):
        paths = set(policy_gate.V2_QA_DRAW_REQUIRED_FILES)
        supporting = {
            ".github/workflows/platform-ci.yml",
            "apps/api/app/Domain/Draw/Services/V2DrawService.php",
            "apps/api/app/Domain/Identity/Services/V2PermissionAuthorizer.php",
            "openapi/bundled/public.openapi.json",
            "scripts/db/v2_database.py",
        }
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_qa_draw_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_qa_draw_boundary(root)
            policy_gate.validate_v2_qa_draw_boundary(root, paths)

    def test_v2_qa_draw_inventory_first_lock_order_fails_when_reversed(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_qa_draw_boundary(root)
            draw = root / "apps/api/app/Domain/Draw/Services/V2DrawService.php"
            draw.write_text(
                draw.read_text(encoding="utf-8").replace(
                    "$inventories = $this->lockInventories($state);",
                    "$inventories = collect();",
                    1,
                )
                + "\n// $this->lockInventories($state);\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "lock order"):
                policy_gate.validate_v2_qa_draw_boundary(root, paths)

    def test_v2_qa_draw_fresh_mfa_boundary_fails_when_five_minute_check_is_removed(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_qa_draw_boundary(root)
            authorizer = (
                root
                / "apps/api/app/Domain/Identity/Services/"
                "V2AdminFreshMfaAuthorizer.php"
            )
            authorizer.write_text(
                authorizer.read_text(encoding="utf-8").replace(
                    "FRESH_AUTHENTICATION_REQUIRED",
                    "AUTHORIZATION_DENIED",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure,
                "Fresh MFA Authorizer",
            ):
                policy_gate.validate_v2_qa_draw_boundary(root, paths)

    def test_v2_qa_draw_password_reauthentication_requires_mfa_policy_off(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_qa_draw_boundary(root)
            service = (
                root
                / "apps/api/app/Domain/Identity/Services/"
                "V2AdminReauthenticationService.php"
            )
            service.write_text(
                service.read_text(encoding="utf-8").replace(
                    "! $this->authenticationPolicy->mfaRequired()",
                    "true",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure,
                "MFA Policy OFF",
            ):
                policy_gate.validate_v2_qa_draw_boundary(root, paths)

    def test_v2_qa_draw_user_prize_boolean_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_qa_draw_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_07_31_000011_create_v2_qa_draw_vertical_slice.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8")
                + "\n// Schema::table('user_prizes', fn () => null);\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "user_prizes"):
                policy_gate.validate_v2_qa_draw_boundary(root, paths)

    def test_v2_qa_draw_admin_permission_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_qa_draw_boundary(root)
            permission = (
                root
                / "apps/api/app/Domain/Identity/Services/"
                "V2PermissionAuthorizer.php"
            )
            text = permission.read_text(encoding="utf-8")
            text = text.replace(
                "'admin' => [",
                "'admin' => [\n            'qa.draw.manage',",
                1,
            )
            permission.write_text(text, encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "Owner-only"):
                policy_gate.validate_v2_qa_draw_boundary(root, paths)

    def test_v2_qa_draw_public_contract_exposure_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_qa_draw_boundary(root)
            bundle = root / "openapi/bundled/public.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document.setdefault("components", {}).setdefault("schemas", {})[
                "QaPlan"
            ] = {"type": "object"}
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "QA management"):
                policy_gate.validate_v2_qa_draw_boundary(root, paths)

    def copy_v2_reporting_boundary(self, root):
        paths = set(policy_gate.V2_REPORTING_REQUIRED_FILES)
        supporting = {
            ".github/workflows/platform-ci.yml",
            "apps/api/app/Domain/Identity/Services/V2AdminFreshMfaAuthorizer.php",
            "openapi/bundled/public.openapi.json",
            "scripts/db/v2_database.py",
        }
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_reporting_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_reporting_boundary(root)
            policy_gate.validate_v2_reporting_boundary(root, paths)

    def test_v2_reporting_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_reporting_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_08_01_000012_create_v2_reporting_export_foundation.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8")
                + "\n// $table->unsignedBigInteger('tenant_id');\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_reporting_boundary(root, paths)

    def test_v2_reporting_public_contract_exposure_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_reporting_boundary(root)
            bundle = root / "openapi/bundled/public.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document.setdefault("components", {}).setdefault("schemas", {})[
                "ExportJob"
            ] = {"type": "object"}
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "Admin Reporting"):
                policy_gate.validate_v2_reporting_boundary(root, paths)

    def copy_v2_content_contact_boundary(self, root):
        paths = set(policy_gate.V2_CONTENT_CONTACT_REQUIRED_FILES)
        supporting = {"scripts/db/v2_database.py"}
        for relative in paths | supporting:
            source = ROOT / relative
            destination = root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)
        return paths | supporting

    def test_v2_content_contact_boundary_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_content_contact_boundary(root)
            policy_gate.validate_v2_content_contact_boundary(root, paths)

    def test_v2_content_contact_tenant_id_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_content_contact_boundary(root)
            migration = (
                root
                / "apps/api/database/migrations-v2/"
                "2026_08_02_000013_create_v2_content_contact_vertical_slice.php"
            )
            migration.write_text(
                migration.read_text(encoding="utf-8")
                + "\n// $table->unsignedBigInteger('tenant_id');\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "tenant_id"):
                policy_gate.validate_v2_content_contact_boundary(root, paths)

    def test_v2_content_contact_public_admin_exposure_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.copy_v2_content_contact_boundary(root)
            bundle = root / "openapi/bundled/public.openapi.json"
            document = json.loads(bundle.read_text(encoding="utf-8"))
            document.setdefault("components", {}).setdefault("schemas", {})[
                "ContactInternalNote"
            ] = {"type": "object"}
            bundle.write_text(json.dumps(document), encoding="utf-8")
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "Admin Content"):
                policy_gate.validate_v2_content_contact_boundary(root, paths)

    def make_workspace(self, root):
        paths = set(policy_gate.WORKSPACE_REQUIRED_FILES)
        paths.add("manifests/storefront-contract-releases.json")
        for relative in paths:
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            if relative == "manifests/storefront-contract-releases.json":
                shutil.copy2(ROOT / relative, path)
            elif relative.endswith("README.md"):
                path.write_text(
                    """# Fixture

## Responsibility

This fixture defines one clear repository responsibility.

## Ownership

Platform Codex follows the nearest AGENTS.md.

## Planned Components

Only a future V2 component belongs here.

## Allowed Scope

Documentation and approved V2 implementation are allowed.

## Forbidden Scope

V1 Code copying and Production use are forbidden.

## Status

This is a non-Production Skeleton and contains no application implementation.
""",
                    encoding="utf-8",
                )
            elif relative.endswith("AGENTS.md"):
                path.write_text("# Fixture AGENTS\n", encoding="utf-8")
            else:
                path.write_text("fixture\n", encoding="utf-8")

        (root / "package.json").write_text(
            json.dumps(
                {
                    "name": "@oripa/platform-workspace",
                    "version": "2.0.0-alpha.23",
                    "private": True,
                    "packageManager": "pnpm@10.12.1",
                    "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
                    "pnpm": {
                        "overrides": {
                            "brace-expansion": "5.0.9",
                            "fast-uri": "3.1.5",
                            "js-yaml": "4.3.1",
                            "minimatch": "10.2.5",
                            "nanoid": "3.3.18",
                            "postcss": "8.5.23",
                            "sharp": "0.35.0",
                        }
                    },
                    "devDependencies": policy_gate.ROOT_DEV_DEPENDENCY_VERSIONS,
                }
            ),
            encoding="utf-8",
        )
        (root / "pnpm-workspace.yaml").write_text(
            "packages:\n  - apps/admin\n  - packages/*\n",
            encoding="utf-8",
        )
        (root / ".github/dependabot.yml").write_text(
            """version: 2
updates:
  - package-ecosystem: npm
    directory: /
  - package-ecosystem: npm
    directory: /legacy/v1-frontend
""",
            encoding="utf-8",
        )
        (root / "pnpm-lock.yaml").write_text(
            """lockfileVersion: '9.0'

importers:

  .: {}

  apps/admin: {}

  packages/platform: {}

  packages/site-schema: {}

  packages/storefront-client: {}

  packages/storefront-testkit: {}

packages:
""",
            encoding="utf-8",
        )
        for relative, name in policy_gate.PACKAGE_SKELETONS.items():
            (root / relative).write_text(
                json.dumps(
                    {
                        "name": name,
                        "version": "2.0.0-alpha.23",
                        "private": True,
                        "description": "Fixture Skeleton",
                        "license": "UNLICENSED",
                    }
                ),
                encoding="utf-8",
            )
        (root / "packages/site-schema/package.json").write_text(
            json.dumps(
                {
                    "name": "@oripa/site-schema",
                    "version": "2.0.0-alpha.23",
                    "private": True,
                    "description": "Fixture Alpha",
                    "license": "UNLICENSED",
                    "type": "module",
                    "sideEffects": False,
                    "packageManager": "pnpm@10.12.1",
                    "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
                    "files": ["dist", "schema"],
                    "exports": {
                        ".": {
                            "types": "./dist/index.d.ts",
                            "import": "./dist/index.js",
                        },
                        "./schema": "./schema/site-manifest.schema.json",
                    },
                    "scripts": {
                        "build": "tsc",
                        "generate": "node generate --write",
                        "generate:check": "node generate --check",
                        "lint": "eslint",
                        "test": "node --test",
                        "typecheck": "tsc --noEmit",
                    },
                    "dependencies": policy_gate.SITE_SCHEMA_DEPENDENCY_VERSIONS,
                    "devDependencies": policy_gate.SITE_SCHEMA_DEV_DEPENDENCY_VERSIONS,
                    "oripaCompatibility": {
                        "family": 2,
                        "currentSchemaVersion": "2.0.0-alpha.1",
                        "testedSchemaVersions": ["2.0.0-alpha.1"],
                        "nMinusOneStatus": "pending-first-minor",
                    },
                }
            ),
            encoding="utf-8",
        )
        site_schema = {
            "$schema": "https://json-schema.org/draft/2020-12/schema",
            "$id": "urn:oripa:site-manifest:2.0.0-alpha.1",
            "type": "object",
            "additionalProperties": False,
            "required": [
                "schema_version",
                "site_version",
                "compatibility",
                "public",
            ],
            "properties": {
                "schema_version": {
                    "type": "string",
                    "const": "2.0.0-alpha.1",
                },
                "site_version": {"$ref": "#/$defs/semantic_version"},
                "compatibility": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": [
                        "family",
                        "storefront_client_version",
                        "required_capabilities",
                    ],
                    "properties": {
                        "family": {"type": "integer", "const": 2},
                        "storefront_client_version": {
                            "$ref": "#/$defs/semantic_version"
                        },
                        "required_capabilities": {
                            "$ref": "#/$defs/capability_list"
                        },
                    },
                },
                "public": {
                    "type": "object",
                    "additionalProperties": False,
                    "required": ["locale", "timezone", "features"],
                    "properties": {
                        "locale": {"type": "string"},
                        "timezone": {"type": "string"},
                        "features": {
                            "type": "object",
                            "additionalProperties": False,
                            "required": ["enabled"],
                            "properties": {
                                "enabled": {
                                    "$ref": "#/$defs/capability_list",
                                    "default": [],
                                }
                            },
                        },
                    },
                },
            },
            "$defs": {
                "semantic_version": {
                    "type": "string",
                    "pattern": policy_gate.SEMANTIC_VERSION.pattern,
                },
                "capability_name": {
                    "type": "string",
                    "pattern": "^[a-z]+\\.[a-z-]+\\.v[1-9][0-9]*$",
                },
                "capability_list": {
                    "type": "array",
                    "items": {"$ref": "#/$defs/capability_name"},
                    "uniqueItems": True,
                    "default": [],
                },
            },
        }
        (
            root / "packages/site-schema/schema/site-manifest.schema.json"
        ).write_text(json.dumps(site_schema), encoding="utf-8")
        (root / "packages/site-schema/.gitignore").write_text(
            "/dist/\n",
            encoding="utf-8",
        )
        (root / "packages/site-schema/src/generated/site-manifest.ts").write_text(
            """/**
 * This file is generated from schema/site-manifest.schema.json.
 */
export type SiteManifest = {
  readonly schema_version: "2.0.0-alpha.1";
  readonly compatibility: {
    readonly family: 2;
    readonly required_capabilities: ReadonlyArray<string>;
  };
};
""",
            encoding="utf-8",
        )
        site_schema_readme = root / "packages/site-schema/README.md"
        site_schema_readme.write_text(
            site_schema_readme.read_text(encoding="utf-8")
            + "\nThis package is an Alpha boundary.\n",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/package.json").write_text(
            json.dumps(
                {
                    "name": "@oripa/storefront-client",
                    "version": "2.0.0-alpha.27",
                    "private": True,
                    "description": "Fixture Client",
                    "license": "UNLICENSED",
                    "type": "module",
                    "sideEffects": False,
                    "packageManager": "pnpm@10.12.1",
                    "engines": {"node": "22.22.3", "pnpm": "10.12.1"},
                    "files": ["dist"],
                    "exports": {
                        ".": {"types": "./dist/index.d.ts", "import": "./dist/index.js"},
                        "./browser": {
                            "types": "./dist/browser.d.ts",
                            "import": "./dist/browser.js",
                        },
                        "./server": {
                            "types": "./dist/server.d.ts",
                            "import": "./dist/server.js",
                        },
                        "./types": {
                            "types": "./dist/types.d.ts",
                            "import": "./dist/types.js",
                        },
                    },
                    "scripts": {
                        "build": "tsc -p tsconfig.build.json",
                        "generate": "openapi-typescript fixture",
                        "generate:check": "node scripts/check-generated.mjs",
                        "lint": "eslint src",
                        "test": "node --test",
                        "typecheck": "tsc --noEmit",
                    },
                    "devDependencies": (
                        policy_gate.STOREFRONT_CLIENT_DEV_DEPENDENCY_VERSIONS
                    ),
                    "oripaCompatibility": {
                        "family": 2,
                        "apiMajor": 2,
                        "minimumPublicApiContract": "2.0.0-alpha.26",
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
                    },
                }
            ),
            encoding="utf-8",
        )
        (root / "packages/storefront-client/.gitignore").write_text(
            "/dist/\n",
            encoding="utf-8",
        )
        storefront_readme = root / "packages/storefront-client/README.md"
        storefront_readme.write_text(
            storefront_readme.read_text(encoding="utf-8")
            + "\nThis package is an Alpha boundary.\n",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/generated/public.ts").write_text(
            """/**
 * This file was auto-generated by openapi-typescript.
 */
export interface paths { "/auth/register": unknown; }
export interface operations {
  registerUser: unknown;
  loginUser: unknown;
  logoutUser: unknown;
  resendUserEmailVerification: unknown;
  verifyUserEmail: unknown;
  getUserSession: unknown;
}
""",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/index.ts").write_text(
            'export * from "./browser.js";\n',
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/types.ts").write_text(
            'export type { paths as PublicPaths } from "./generated/public.js";\n',
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/browser.ts").write_text(
            'export const browser = { credentials: "include" };\n',
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/server.ts").write_text(
            "export const server = true;\n",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/constants.ts").write_text(
            """export const headers = [
  "X-Oripa-Client-Version",
  "X-Oripa-Site-Version",
  "Idempotency-Key",
];
""",
            encoding="utf-8",
        )
        (root / "packages/storefront-client/src/transport.ts").write_text(
            """const RETRYABLE_STATUS = new Set([502, 503, 504]);
const signal: AbortSignal | undefined = undefined;
const csrf_initializer = true;
const problem = "application/problem+json";
""",
            encoding="utf-8",
        )
        shutil.copytree(
            ROOT / "packages/storefront-testkit",
            root / "packages/storefront-testkit",
            dirs_exist_ok=True,
            ignore=shutil.ignore_patterns("dist", "node_modules"),
        )
        shutil.copytree(
            ROOT / "apps/admin",
            root / "apps/admin",
            dirs_exist_ok=True,
            ignore=shutil.ignore_patterns(
                ".next",
                "node_modules",
                "tsconfig.tsbuildinfo",
            ),
        )
        workflow = root / ".github/workflows/platform-ci.yml"
        workflow.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / ".github/workflows/platform-ci.yml", workflow)
        (root / ".dockerignore").write_text(
            "legacy/v1-frontend\n",
            encoding="utf-8",
        )
        (root / "docker-compose.yml").write_text(
            """# V1 reference for non-production characterization only.
services:
  api:
    volumes:
      - ./apps/api:/app
  frontend:
    build: ./legacy/v1-frontend
  postgres:
    image: postgres:17-alpine
  redis:
    image: redis:7-alpine
""",
            encoding="utf-8",
        )
        (root / "docker-compose.v2.yml").write_text(
            """# This is never a Production deployment.
services:
  api:
    environment:
      V2_PUBLIC_ORIGIN: ${V2_PUBLIC_ORIGIN:-http://localhost:3000}
    healthcheck:
      test: health
  admin:
    healthcheck:
      test: health
  postgres:
    image: postgres:17-alpine
  redis:
    image: redis:7-alpine
""",
            encoding="utf-8",
        )
        semantic_version = {
            "type": "string",
            "pattern": policy_gate.SEMANTIC_VERSION.pattern,
        }
        release_schema = root / "manifests/schemas/release-manifest.schema.json"
        release_schema.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(
            ROOT / "manifests/schemas/release-manifest.schema.json",
            release_schema,
        )
        deployment_properties = {
            field: {"type": "string"}
            for field in policy_gate.DEPLOYMENT_MANIFEST_REQUIRED
        }
        deployment_properties["schema_version"] = {"const": "1.0"}
        deployment_properties["platform_version"] = {
            "$ref": "#/$defs/semantic_version"
        }
        deployment_properties["package_versions"] = {
            "type": "object",
            "additionalProperties": {"$ref": "#/$defs/semantic_version"},
        }
        for relative, required, properties in (
            (
                "manifests/schemas/deployment-manifest.schema.json",
                policy_gate.DEPLOYMENT_MANIFEST_REQUIRED,
                deployment_properties,
            ),
        ):
            (root / relative).write_text(
                json.dumps(
                    {
                        "$schema": "https://json-schema.org/draft/2020-12/schema",
                        "type": "object",
                        "additionalProperties": False,
                        "required": sorted(required),
                        "properties": properties,
                        "$defs": {"semantic_version": semantic_version},
                    }
                ),
                encoding="utf-8",
            )
        release_example = root / "manifests/examples/release-manifest.example.json"
        release_example.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(
            ROOT / "manifests/examples/release-manifest.example.json",
            release_example,
        )
        (root / "manifests/examples/deployment-manifest.example.json").write_text(
            json.dumps(
                {
                    "schema_version": "1.0",
                    "site_id": "fixture-site",
                    "environment": "platform-staging",
                    "platform_version": "2.0.0-alpha.1",
                    "package_versions": {"@oripa/platform": "2.0.0-alpha.1"},
                    "image_digest": "sha256:" + "0" * 64,
                    "migration_revision": "fixture",
                    "deployed_at": "1970-01-01T00:00:00Z",
                    "approved_by": "fixture",
                    "source_release_manifest": "fixture",
                }
            ),
            encoding="utf-8",
        )
        return paths

    def test_workspace_skeleton_fixture_passes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_admin_type_export_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "packages/storefront-client/src/types.ts").write_text(
                "export type AdminSecret = string;\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "Admin or Webhook type"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_required_auth_operation_missing_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            generated = root / "packages/storefront-client/src/generated/public.ts"
            generated.write_text(
                generated.read_text(encoding="utf-8").replace(
                    "registerUser: unknown;",
                    "fakeDraw: unknown;",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "generated Public API types"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_browser_credentials_omit_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "packages/storefront-client/src/browser.ts").write_text(
                'export const browser = { credentials: "omit" };\n',
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "credentials must be include"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_site_schema_secret_field_definition_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            schema_path = (
                root / "packages/site-schema/schema/site-manifest.schema.json"
            )
            schema = json.loads(schema_path.read_text(encoding="utf-8"))
            schema["properties"]["api_token"] = {"type": "string"}
            schema_path.write_text(json.dumps(schema), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "prohibited field"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_site_schema_unknown_top_level_field_policy_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            schema_path = (
                root / "packages/site-schema/schema/site-manifest.schema.json"
            )
            schema = json.loads(schema_path.read_text(encoding="utf-8"))
            schema["additionalProperties"] = True
            schema_path.write_text(json.dumps(schema), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "reject unknown fields"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_dependency_range_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "packages/storefront-testkit/package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["dependencies"]["@oripa/storefront-client"] = "workspace:*"
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "exact runtime dependencies"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_forbidden_export_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "packages/storefront-testkit/package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["exports"]["./admin"] = "./dist/admin.js"
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "exports are invalid"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_fake_operation_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            generated = (
                root
                / "packages/storefront-testkit/src/generated/public-contract.ts"
            )
            generated.write_text(
                generated.read_text(encoding="utf-8").replace(
                    "operation_count: 64",
                    "operation_count: 60",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "Public Contract Fixture"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_real_network_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            source = root / "packages/storefront-testkit/src/mock.ts"
            source.write_text(
                source.read_text(encoding="utf-8")
                + "\nexport const unsafe = () => globalThis.fetch('/');\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "real network access"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_noop_test_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            test_path = root / "packages/storefront-testkit/test/testkit.test.mjs"
            test_path.write_text(
                'import test from "node:test";\ntest("noop", () => {});\n',
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "substantive assertions"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_storefront_testkit_missing_mock_boundary_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            source = root / "packages/storefront-testkit/src/mock.ts"
            source.write_text(
                source.read_text(encoding="utf-8").replace(
                    "queue.shift()",
                    "queue.at(0)",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "Mock Transport missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_missing_root_lockfile_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "pnpm-lock.yaml").unlink()
            paths.remove("pnpm-lock.yaml")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required workspace files missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_workspace_missing_readme_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            paths.remove("apps/admin/README.md")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required workspace files missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v1_frontend_workspace_member_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "pnpm-workspace.yaml").write_text(
                "packages:\n  - apps/admin\n  - packages/*\n  - legacy/v1-frontend\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "workspace members"):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_api_workspace_member_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            (root / "pnpm-workspace.yaml").write_text(
                "packages:\n  - apps/admin\n  - packages/*\n  - apps/api\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "workspace members"):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_dependency_range_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "apps/admin/package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["dependencies"]["next"] = "^16.2.9"
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "exact runtime dependencies"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_tiptap_dependency_removal_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "apps/admin/package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            del package["dependencies"]["@tiptap/react"]
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "exact runtime dependencies"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_unapproved_root_tool_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            package_path = root / "package.json"
            package = json.loads(package_path.read_text(encoding="utf-8"))
            package["devDependencies"]["unapproved-tool"] = "1.0.0"
            package_path.write_text(json.dumps(package), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure,
                "only the pinned OpenAPI validation tool",
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_health_endpoint_missing_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            relative = "apps/admin/src/app/api/health/route.ts"
            (root / relative).unlink()
            paths.remove(relative)
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required workspace files missing"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_business_logic_file_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            relative = "apps/admin/src/domain/point-ledger.ts"
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("export const calculatePoints = () => 1;\n", encoding="utf-8")
            paths.add(relative)
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "unapproved application files"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_browser_token_storage_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            client = root / "apps/admin/src/lib/admin-api/client.ts"
            client.write_text(
                client.read_text(encoding="utf-8")
                + '\nlocalStorage.setItem("admin_token", "forbidden");\n',
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "prohibited implementation"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_cookie_credentials_omission_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            client = root / "apps/admin/src/lib/admin-api/client.ts"
            client.write_text(
                client.read_text(encoding="utf-8").replace(
                    'credentials: "include"',
                    'credentials: "omit"',
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "Admin API boundary"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_security_header_removal_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            proxy = root / "apps/admin/src/proxy.ts"
            proxy.write_text(
                proxy.read_text(encoding="utf-8").replace(
                    "frame-ancestors 'none'",
                    "frame-ancestors *",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "security response boundary"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_permission_registry_unknown_code_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            registry = root / "apps/admin/src/lib/permissions/admin-navigation.ts"
            registry.write_text(
                registry.read_text(encoding="utf-8").replace(
                    '"catalog.read"',
                    '"unknown.permission"',
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "permission registry"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_admin_permission_provider_role_authorization_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            registry = root / "apps/admin/src/lib/permissions/admin-navigation.ts"
            registry.write_text(
                registry.read_text(encoding="utf-8")
                + '\nconst bypass = role === "owner";\n',
                encoding="utf-8",
            )
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "must not authorize by role"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v2_compose_with_legacy_frontend_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8")
                + "\n# COPY legacy/v1-frontend into V2\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "prohibited value"):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v2_compose_without_public_origin_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            compose = root / "docker-compose.v2.yml"
            compose.write_text(
                compose.read_text(encoding="utf-8").replace(
                    "      V2_PUBLIC_ORIGIN: ${V2_PUBLIC_ORIGIN:-http://localhost:3000}\n",
                    "",
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "V2_PUBLIC_ORIGIN"):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_api_application_layout_passes(self):
        policy_gate.validate_api_application_layout(
            policy_gate.API_APPLICATION_REQUIRED_FILES
        )

    def test_legacy_backend_path_fails(self):
        paths = set(policy_gate.API_APPLICATION_REQUIRED_FILES)
        paths.add("backend/artisan")
        with self.assertRaisesRegex(
            policy_gate.PolicyFailure, "legacy backend path remains tracked"
        ):
            policy_gate.validate_api_application_layout(paths)

    def test_missing_api_application_file_fails(self):
        paths = set(policy_gate.API_APPLICATION_REQUIRED_FILES)
        paths.remove("apps/api/composer.lock")
        with self.assertRaisesRegex(
            policy_gate.PolicyFailure, "required API application files missing"
        ):
            policy_gate.validate_api_application_layout(paths)

    def test_manifest_required_field_removal_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            schema_path = root / "manifests/schemas/release-manifest.schema.json"
            schema = json.loads(schema_path.read_text(encoding="utf-8"))
            schema["required"].remove("platform")
            schema_path.write_text(json.dumps(schema), encoding="utf-8")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure, "required manifest fields"
            ):
                policy_gate.validate_workspace_skeleton(root, paths)

    def test_v1_content_copy_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = self.make_workspace(root)
            content = "const copiedV1Implementation = true;\n" * 4
            for relative in (
                "legacy/v1-frontend/source.ts",
                "apps/admin/source.ts",
            ):
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content, encoding="utf-8")
                paths.add(relative)
            with self.assertRaisesRegex(policy_gate.PolicyFailure, "V1 content copied"):
                policy_gate.validate_no_v1_copy(root, paths)

    def test_legacy_frontend_layout_passes(self):
        policy_gate.validate_legacy_frontend_layout(
            ROOT,
            set(policy_gate.LEGACY_FRONTEND_REQUIRED_FILES),
        )

    def test_tracked_frontend_path_fails(self):
        paths = set(policy_gate.LEGACY_FRONTEND_REQUIRED_FILES)
        paths.add("frontend/src/app/page.tsx")
        with self.assertRaisesRegex(
            policy_gate.PolicyFailure,
            "legacy frontend source path remains tracked",
        ):
            policy_gate.validate_legacy_frontend_layout(ROOT, paths)

    def test_nested_legacy_frontend_path_fails(self):
        paths = set(policy_gate.LEGACY_FRONTEND_REQUIRED_FILES)
        paths.add("legacy/v1-frontend/frontend/src/app/page.tsx")
        with self.assertRaisesRegex(
            policy_gate.PolicyFailure,
            "nested frontend directory",
        ):
            policy_gate.validate_legacy_frontend_layout(ROOT, paths)

    def test_v2_dockerfile_copying_legacy_frontend_fails(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            paths = set(policy_gate.LEGACY_FRONTEND_REQUIRED_FILES)
            for relative in paths:
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text("fixture\n", encoding="utf-8")
            dockerfile = root / "apps/admin/Dockerfile"
            dockerfile.parent.mkdir(parents=True, exist_ok=True)
            dockerfile.write_text(
                "COPY legacy/v1-frontend /app\n",
                encoding="utf-8",
            )
            paths.add("apps/admin/Dockerfile")
            with self.assertRaisesRegex(
                policy_gate.PolicyFailure,
                "must not copy legacy frontend",
            ):
                policy_gate.validate_legacy_frontend_layout(root, paths)


if __name__ == "__main__":
    unittest.main()
