from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
MIGRATION = ROOT / "apps/api/database/migrations-v2/2026_10_22_000095_add_v2_gacha_purchase_conditions.php"


class GachaPurchaseMigrationTest(unittest.TestCase):
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
