import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


class MemberRankSettingsContractTest(unittest.TestCase):
    def test_admin_boundary_and_mutation_guards(self):
        admin = json.loads((ROOT / "openapi/bundled/admin.openapi.json").read_text())
        public = json.loads((ROOT / "openapi/bundled/public.openapi.json").read_text())
        for path, method in [
            ("/settings/member-ranks", "get"),
            ("/settings/member-ranks", "put"),
            ("/settings/member-ranks/scheduled", "delete"),
            ("/settings/member-ranks/revisions", "get"),
        ]:
            operation = admin["paths"][path][method]
            self.assertNotIn(path, public["paths"])
            self.assertEqual(operation["security"], [{"adminSession": []}])
            self.assertEqual(operation["x-cache-policy"], "no-store")
            if method != "get":
                self.assertEqual(operation["x-idempotency"], "required")
                self.assertIn({"$ref": "#/components/parameters/IdempotencyKey"}, operation["parameters"])
        schemas = admin["components"]["schemas"]
        for name in ["AdminMemberRankSettingsUpdate", "AdminMemberRankSettingsCancel"]:
            self.assertIn("expected_revision", schemas[name]["required"])
            self.assertFalse(schemas[name]["additionalProperties"])
        rank = schemas["AdminMemberRankValue"]["properties"]
        self.assertEqual(rank["code"]["enum"], ["beginner", "bronze", "silver", "gold", "platinum", "diamond", "vip"])
        self.assertEqual(rank["purchase_bonus_rate_bp"]["maximum"], 10000)
        self.assertEqual(rank["purchase_bonus_rate_bp"]["type"], "integer")
        self.assertEqual(rank["promotion_bonus"]["maximum"], 1000000)
        for code in ["MEMBER_RANK_ACTIVATION_NOT_READY", "MEMBER_RANK_DEACTIVATION_FORBIDDEN"]:
            self.assertEqual(admin["x-oripa-error-codes"][code]["status"], 409)
