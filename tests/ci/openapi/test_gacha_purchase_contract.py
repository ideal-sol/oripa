import copy
import json
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[3]


class GachaPurchaseContractTest(unittest.TestCase):
    def test_admin_purchase_boundaries_and_contradictions(self):
        schemas = json.loads((ROOT / "openapi/bundled/admin.openapi.json").read_text())["components"]["schemas"]
        schema = copy.deepcopy(schemas["AdminGachaComposition"])
        schema["required"] = []
        schema["components"] = {"schemas": schemas}
        cases = [({}, True)]
        for field, maximum in [("purchase_count", 1000000), ("purchase_amount", 9007199254740991)]:
            for mode in ["eq", "gte", "lt"]:
                for value in [1, maximum]:
                    cases.append(({field + "_mode": mode, field + "_value": value}, True))
                cases.append(({field + "_mode": mode}, False))
                for value in [-1, maximum + 1, 1.5, "1", None]:
                    cases.append(({field + "_mode": mode, field + "_value": value}, False))
            cases.append(({field + "_mode": "eq", field + "_value": 0}, True))
            for mode in ["gte", "lt", "none"]:
                cases.append(({field + "_mode": mode, field + "_value": 0}, False))
            cases.append(({field + "_value": 1}, False))
        for zero, positive in [("purchase_count", "purchase_amount"), ("purchase_amount", "purchase_count")]:
            for mode, value in [("eq", 0), ("lt", 1)]:
                for positive_mode in ["eq", "gte"]:
                    cases.append(({
                        zero + "_mode": mode, zero + "_value": value,
                        positive + "_mode": positive_mode, positive + "_value": 1,
                    }, False))
        validate_cases(schema, cases)

    def test_public_additions_are_optional_and_login_behavior_is_unchanged(self):
        schemas = json.loads((ROOT / "openapi/bundled/public.openapi.json").read_text())["components"]["schemas"]
        presentation = schemas["GachaPresentationState"]
        for field in ["conditions", "purchase_progress"]:
            self.assertIn(field, presentation["properties"])
            self.assertNotIn(field, presentation["required"])
        self.assertEqual(set(schemas["GachaConditions"]["properties"]), {
            "audience", "member_rank", "purchase_count", "purchase_amount",
        })
        for reason in ["purchase_count_not_eligible", "purchase_amount_not_eligible"]:
            self.assertIn(reason, schemas["GachaPresentationReason"]["enum"])
            self.assertNotIn(reason, schemas["LoginGachaEligibility"]["properties"]["reason"]["enum"])


def validate_cases(schema, cases):
    subprocess.run(["node", "--input-type=module", "-e", """
            import { createRequire } from 'node:module';
            import { readFileSync } from 'node:fs';
            import assert from 'node:assert/strict';
            const require = createRequire(process.cwd() + '/packages/site-schema/package.json');
            const Ajv = require('ajv/dist/2020');
            const { schema, cases } = JSON.parse(readFileSync(0, 'utf8'));
            const validate = new Ajv({ strict: false, validateFormats: false }).compile(schema);
            for (const [value, expected] of cases) {
                assert.equal(validate(value), expected, JSON.stringify({ value, errors: validate.errors }));
            }
        """], input=json.dumps({"schema": schema, "cases": cases}), text=True, cwd=ROOT, check=True)
