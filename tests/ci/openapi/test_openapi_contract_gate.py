import importlib.util
import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[3]
FIXTURES = Path(__file__).parent / "fixtures"
SPEC = importlib.util.spec_from_file_location(
    "openapi_contract_gate",
    ROOT / "scripts/ci/openapi_contract_gate.py",
)
openapi_contract_gate = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(openapi_contract_gate)


def fixture(group, name):
    return json.loads(
        (FIXTURES / group / f"{name}.json").read_text(encoding="utf-8")
    )


class OpenApiContractGateTest(unittest.TestCase):
    def test_additive_change_passes(self):
        self.assertEqual(
            [],
            openapi_contract_gate.breaking_changes(
                fixture("positive", "previous"),
                fixture("positive", "current"),
            ),
        )

    def test_breaking_change_fixture_fails(self):
        findings = openapi_contract_gate.breaking_changes(
            fixture("negative", "previous"),
            fixture("negative", "current"),
        )
        self.assertIn("GET /resources: operationId changed", findings)
        self.assertIn(
            "components.schemas.Resource: required field added: state",
            findings,
        )
        self.assertIn(
            "components.schemas.Resource: property removed: label",
            findings,
        )
        self.assertIn(
            "components.schemas.Resource.id: type changed",
            findings,
        )

    def test_public_internal_field_leak_fails(self):
        document = {
            "openapi": "3.1.1",
            "jsonSchemaDialect": "https://json-schema.org/draft/2020-12/schema",
            "info": {
                "title": "Oripa Public API",
                "version": "2.0.0-alpha.1",
                "x-status": "skeleton",
            },
            "x-oripa-surface": "public",
            "servers": [{"url": "/api/v2"}],
            "paths": {},
            "components": {
                "schemas": {
                    **{
                        name: {"type": "string"}
                        for name in openapi_contract_gate.REQUIRED_COMMON_SCHEMAS
                    },
                    "Leak": {
                        "type": "object",
                        "properties": {"provider_secret": {"type": "string"}},
                    },
                }
            },
        }
        document["components"]["schemas"]["ProblemDetails"] = {
            "type": "object",
            "required": [
                "type",
                "title",
                "status",
                "code",
                "request_id",
                "retryable",
            ],
        }
        with self.assertRaisesRegex(
            openapi_contract_gate.ContractFailure,
            "internal schema fields leaked",
        ):
            openapi_contract_gate.validate_document("public", document)

    def test_skeleton_business_endpoint_fails(self):
        document = {
            "openapi": "3.1.1",
            "jsonSchemaDialect": "https://json-schema.org/draft/2020-12/schema",
            "info": {
                "title": "Oripa Public API",
                "version": "2.0.0-alpha.1",
                "x-status": "skeleton",
            },
            "x-oripa-surface": "public",
            "servers": [{"url": "/api/v2"}],
            "paths": {"/guessed": {}},
            "components": {"schemas": {}},
        }
        with self.assertRaisesRegex(
            openapi_contract_gate.ContractFailure,
            "must not define business endpoints",
        ):
            openapi_contract_gate.validate_document("public", document)


if __name__ == "__main__":
    unittest.main()
