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

    def test_public_additions_are_optional_for_standard_and_login(self):
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
            self.assertIn(reason, schemas["LoginGachaEligibility"]["properties"]["reason"]["enum"])
        self.assertIn("conditions", schemas["LoginGachaSummary"]["properties"])
        self.assertNotIn("conditions", schemas["LoginGachaSummary"]["required"])
        self.assertIn("purchase_progress", schemas["LoginGachaEligibility"]["properties"])

    def test_login_allows_conditions_but_signup_preserves_every_fixed_field(self):
        schemas = json.loads((ROOT / "openapi/bundled/admin.openapi.json").read_text())["components"]["schemas"]
        schemas["AdminGachaComposition"]["required"] = []
        schema = copy.deepcopy(schemas["AdminGachaCompositionSave"])
        schema["components"] = {"schemas": schemas}
        cases = []
        for kind in ["standard", "login_daily", "signup_once"]:
            for fields in [
                {"audience_code": "line_users"},
                {"audience_code": "first_time_users", "first_time_eligible_days": 365},
                {"first_time_eligible_days": 1},
                {"member_rank_mode": "only", "member_rank_level": 2},
                {"purchase_count_mode": "eq", "purchase_count_value": 0},
                {"purchase_amount_mode": "gte", "purchase_amount_value": 1},
            ]:
                cases.append(({"gacha_type": kind, **fields}, kind != "signup_once"))
        for field in ["price_points", "daily_draw_limit", "minimum_exchange_points"]:
            cases.append(({"gacha_type": "signup_once", field: 1}, False))
        for value in [0, 366]:
            cases.append(({"gacha_type": "login_daily", "first_time_eligible_days": value}, False))
        cases.append(({"gacha_type": "signup_once"}, True))
        validate_cases(schema, cases)


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
