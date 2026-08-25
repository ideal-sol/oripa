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
    def test_numeric_enum_widened_to_covering_range_passes(self):
        previous = {"type": "integer", "enum": [1, 5, 10, 100, 1000]}
        current = {"type": "integer", "minimum": 1, "maximum": 1000}

        self.assertEqual(
            [],
            openapi_contract_gate.compare_schema(previous, current, "draw.executed_count"),
        )

    def test_numeric_enum_widened_to_non_covering_range_fails(self):
        previous = {"type": "integer", "enum": [1, 5, 10, 100, 1000]}
        current = {"type": "integer", "minimum": 2, "maximum": 999}

        findings = openapi_contract_gate.compare_schema(
            previous,
            current,
            "draw.executed_count",
        )

        self.assertIn("draw.executed_count: enum value removed: 1", findings)
        self.assertIn("draw.executed_count: enum value removed: 1000", findings)

    def test_string_enum_removal_still_fails(self):
        findings = openapi_contract_gate.compare_schema(
            {"type": "string", "enum": ["active", "ended"]},
            {"type": "string"},
            "gacha.state",
        )

        self.assertIn("gacha.state: enum value removed: active", findings)
        self.assertIn("gacha.state: enum value removed: ended", findings)

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

    def test_explicit_response_only_required_addition_is_additive(self):
        previous = {
            "paths": {
                "/resources": {
                    "get": {
                        "operationId": "listResources",
                        "responses": {
                            "200": {
                                "content": {
                                    "application/json": {
                                        "schema": {"$ref": "#/components/schemas/Resource"}
                                    }
                                }
                            }
                        },
                    }
                }
            },
            "components": {
                "schemas": {
                    "Resource": {
                        "type": "object",
                        "required": ["id"],
                        "properties": {"id": {"type": "string"}},
                    }
                }
            },
        }
        current = json.loads(json.dumps(previous))
        current["components"]["schemas"]["Resource"].update({
            "required": ["id", "total"],
            "x-oripa-response-required-additions": ["total"],
        })
        current["components"]["schemas"]["Resource"]["properties"]["total"] = {
            "type": "integer"
        }

        self.assertEqual([], openapi_contract_gate.breaking_changes(previous, current))

    def test_request_schema_cannot_waive_required_addition(self):
        previous = {
            "paths": {
                "/resources": {
                    "post": {
                        "operationId": "createResource",
                        "requestBody": {
                            "content": {
                                "application/json": {
                                    "schema": {"$ref": "#/components/schemas/Resource"}
                                }
                            }
                        },
                        "responses": {"201": {"description": "Created"}},
                    }
                }
            },
            "components": {
                "schemas": {
                    "Resource": {
                        "type": "object",
                        "required": ["id"],
                        "properties": {"id": {"type": "string"}},
                    }
                }
            },
        }
        current = json.loads(json.dumps(previous))
        current["components"]["schemas"]["Resource"].update({
            "required": ["id", "total"],
            "x-oripa-response-required-additions": ["total"],
        })
        current["components"]["schemas"]["Resource"]["properties"]["total"] = {
            "type": "integer"
        }

        self.assertIn(
            "components.schemas.Resource: required field added: total",
            openapi_contract_gate.breaking_changes(previous, current),
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
