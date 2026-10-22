import json
from pathlib import Path
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[3]


class MemberRankPrelaunchContractTest(unittest.TestCase):
    def test_activation_is_admin_only_and_cannot_provision_site_authority(self):
        admin = yaml.safe_load((ROOT / "openapi/admin/openapi.yaml").read_text())
        path = "/settings/member-ranks/prelaunch-activation"
        operation = admin["paths"][path]["post"]
        self.assertEqual(operation["security"], [{"adminSession": []}])
        self.assertEqual(operation["x-idempotency"], "required")
        self.assertIn("Fresh MFA", operation["description"])
        schema = admin["components"]["schemas"]["AdminMemberRankPrelaunchActivationRequest"]
        self.assertFalse(schema["additionalProperties"])
        self.assertEqual(set(schema["properties"]), {"authorization_id", "expected_revision"})
        public = json.loads((ROOT / "openapi/bundled/public.openapi.json").read_text())
        self.assertNotIn(path, public["paths"])


if __name__ == "__main__":
    unittest.main()
