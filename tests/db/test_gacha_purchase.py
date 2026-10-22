from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[2]
MIGRATION = ROOT / "apps/api/database/migrations-v2/2026_10_22_000095_add_v2_gacha_purchase_conditions.php"


class GachaPurchaseMigrationTest(unittest.TestCase):
    def test_login_migration_replaces_only_the_two_approved_guard_predicates(self):
        directory = MIGRATION.parent
        source = (directory / "2026_10_22_000096_enable_v2_login_gacha_conditions.php").read_text()
        def function(text, name):
            return re.search(r"CREATE OR REPLACE FUNCTION " + name + r"\(\).*?\$\$;", text, re.S)[0]
        typed = function((directory / "2026_10_04_000078_add_v2_login_gachas.php").read_text(), "v2_catalog_guard_typed_version")
        expected = typed.replace("OR NEW.audience_code <> 'all_users' OR NEW.first_time_eligible_days <> 7",
                                "OR (kind = 'signup_once' AND (NEW.audience_code <> 'all_users' OR NEW.first_time_eligible_days <> 7))")
        expected = expected.replace("OR (kind = 'login_daily' AND (NEW.daily_draw_limit <> 1 OR NEW.minimum_exchange_points IS NULL))",
                                    "OR (kind = 'login_daily' AND (NEW.daily_draw_limit <> 1 OR NEW.minimum_exchange_points IS NULL\n                           OR NEW.first_time_eligible_days NOT BETWEEN 1 AND 365))")
        self.assertEqual(expected, function(source, "v2_catalog_guard_typed_version"))
        purchase = function(MIGRATION.read_text(), "v2_catalog_guard_purchase_condition")
        expected = purchase.replace("gacha.gacha_type <> 'standard'", "gacha.gacha_type = 'signup_once'").replace(
            "Purchase conditions require a standard Gacha", "Signup Gacha cannot have purchase conditions")
        self.assertEqual(expected, function(source, "v2_catalog_guard_purchase_condition"))
        self.assertNotIn("UPDATE catalog_gacha_versions SET", source)
        self.assertIn("throw new LogicException('Login Gacha eligibility requires a forward-only correction.')", source)

    def test_purchase_mode_arrays_are_dump_restore_stable_text_expressions(self):
        source = MIGRATION.read_text()
        for field in ("purchase_count_mode", "purchase_amount_mode"):
            self.assertIn(field + "::text IN ('gte', 'lt')", source)
            self.assertIn(field + "::text IN ('eq', 'gte')", source)
            self.assertNotIn(field + " IN (", source)

    def test_guards_are_forward_only_and_do_not_rewrite_published_rows(self):
        source = MIGRATION.read_text()
        self.assertIn("throw new LogicException('Gacha purchase conditions and Draw snapshots are forward-only.')", source)
        self.assertIn("BEFORE UPDATE OR DELETE ON draw_requests", source)
        self.assertIn("BEFORE TRUNCATE ON draw_requests", source)
        self.assertIn("CREATE OR REPLACE FUNCTION v2_catalog_guard_purchase_condition", source)
        self.assertNotIn("UPDATE catalog_gacha_versions SET", source)
