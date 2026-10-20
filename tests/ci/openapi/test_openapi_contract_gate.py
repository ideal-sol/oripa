import importlib.util
import copy
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
    def test_m9_provider_widening_is_limited_to_the_exact_approved_admin_delta(self):
        current = json.loads((ROOT / "openapi/bundled/admin.openapi.json").read_text())
        previous = copy.deepcopy(current)
        previous["info"]["version"] = "2.0.0-alpha.41"
        previous["components"]["schemas"]["AdminPayment"]["properties"]["provider"] = {"const": "fincode"}
        previous["components"]["schemas"]["AdminPayment"]["properties"].pop("adjustments")
        authorize = openapi_contract_gate.is_authorized_m9_payment_provider_break
        self.assertTrue(authorize("admin", previous, current))
        self.assertFalse(authorize("public", previous, current))
        for mutation in ("authority", "version", "provider", "security", "removed_path", "unrelated_constraint"):
            changed = copy.deepcopy(current)
            if mutation == "authority":
                changed["x-oripa-breaking-change"]["authority"] = "unapproved"
            elif mutation == "version":
                changed["info"]["version"] = "2.0.0-alpha.41"
            elif mutation == "provider":
                changed["components"]["schemas"]["AdminPayment"]["properties"]["provider"] = {"type": "number"}
            elif mutation == "security":
                changed["paths"]["/payments"]["get"]["security"] = []
            elif mutation == "unrelated_constraint":
                changed["components"]["schemas"]["AdminPayment"]["properties"]["provider_status"]["maxLength"] = 1
            else:
                del changed["paths"]["/payments"]
            with self.subTest(mutation=mutation):
                self.assertFalse(authorize("admin", previous, changed))

    def composed_object_documents(self):
        previous = {
            "paths": {},
            "components": {"schemas": {
                "Summary": {"type": "object", "additionalProperties": False, "required": ["id", "status"],
                            "properties": {"id": {"type": "string"}, "status": {"type": "string", "enum": ["active", "closed"]}}},
                "Detail": {"allOf": [
                    {"$ref": "#/components/schemas/Summary"},
                    {"type": "object", "additionalProperties": False, "required": ["email"],
                     "properties": {"email": {"type": "string"}}},
                ]},
            }},
        }
        current = copy.deepcopy(previous)
        current["components"]["schemas"]["Detail"] = {
            "type": "object", "additionalProperties": False, "required": ["id", "status", "email"],
            "properties": {"id": {"type": "string"}, "status": {"type": "string", "enum": ["active", "closed"]},
                           "email": {"type": "string"}, "rank": {"type": "string"}},
        }
        return previous, current

    def test_object_allof_flattening_preserves_inherited_required_fields_and_types(self):
        previous, current = self.composed_object_documents()
        self.assertEqual([], openapi_contract_gate.breaking_changes(previous, current))
        equivalent = copy.deepcopy(current)
        del equivalent["components"]["schemas"]["Detail"]["properties"]["rank"]
        self.assertEqual([], openapi_contract_gate.breaking_changes(equivalent, previous))
        self.assertIn("components.schemas.Detail: property removed: rank", openapi_contract_gate.breaking_changes(current, previous))
        shape = openapi_contract_gate.object_composition_shape(previous["components"]["schemas"]["Detail"], previous["components"]["schemas"])
        self.assertFalse(shape["additionalProperties"])
        self.assertEqual(["email", "id", "status"], shape["required"])

    def test_object_allof_flattening_still_rejects_real_breaks(self):
        previous, current = self.composed_object_documents()
        for mutation, finding in [
            ("required", "components.schemas.Detail: required field added: rank"),
            ("type", "components.schemas.Detail.id: type changed"),
            ("removed", "components.schemas.Detail: property removed: email"),
            ("enum", "components.schemas.Detail.status: enum value removed: closed"),
        ]:
            changed = copy.deepcopy(current)
            detail = changed["components"]["schemas"]["Detail"]
            if mutation == "required":
                detail["required"].append("rank")
            elif mutation == "type":
                detail["properties"]["id"]["type"] = "integer"
            elif mutation == "removed":
                del detail["properties"]["email"]
            else:
                detail["properties"]["status"]["enum"].remove("closed")
            with self.subTest(mutation=mutation):
                self.assertIn(finding, openapi_contract_gate.breaking_changes(previous, changed))

    def test_object_allof_projection_fails_closed_for_unresolved_or_conflicting_shapes(self):
        previous, current = self.composed_object_documents()
        for mutation in ["missing", "cycle", "external", "conflict", "constraint", "alternative"]:
            changed = copy.deepcopy(previous)
            schemas = changed["components"]["schemas"]
            detail = schemas["Detail"]
            if mutation == "missing":
                detail["allOf"][0]["$ref"] = "#/components/schemas/Missing"
            elif mutation == "cycle":
                detail["allOf"][0]["$ref"] = "#/components/schemas/Detail"
            elif mutation == "external":
                detail["allOf"][0]["$ref"] = "external.yaml#/Summary"
            elif mutation == "conflict":
                detail["allOf"][1]["properties"]["id"] = {"type": "integer"}
            elif mutation == "constraint":
                detail["allOf"][1]["minProperties"] = 4
            else:
                detail["allOf"][1] = {"oneOf": [{"type": "object"}, {"type": "null"}]}
            with self.subTest(mutation=mutation):
                self.assertIsNone(openapi_contract_gate.object_composition_shape(detail, schemas))
                self.assertIn("components.schemas.Detail: type changed", openapi_contract_gate.breaking_changes(changed, current))

    def test_object_flattening_cannot_hide_a_new_additional_property_restriction(self):
        previous, current = self.composed_object_documents()
        previous["components"]["schemas"]["Summary"]["additionalProperties"] = True
        previous["components"]["schemas"]["Detail"]["allOf"][1]["additionalProperties"] = True
        current["components"]["schemas"]["Summary"]["additionalProperties"] = True
        self.assertIn("components.schemas.Detail: additional properties restricted", openapi_contract_gate.breaking_changes(previous, current))

    def test_notion_cache_authority_allows_only_the_three_approved_placeholder_types(self):
        current = json.loads((ROOT / "openapi/bundled/admin.openapi.json").read_text())
        current["x-oripa-breaking-change"] = openapi_contract_gate.NOTION_CACHE_BREAK
        current["info"]["version"] = "2.0.0-alpha.40"
        previous = copy.deepcopy(current)
        previous["info"]["version"] = "2.0.0-alpha.40"
        properties = previous["components"]["schemas"]["AdminInventoryCard"]["properties"]
        for field in ["notion_on_hand", "notion_available", "notion_read_at"]:
            properties[field] = {"type": "null"}
        authorize = openapi_contract_gate.is_authorized_notion_cache_break
        self.assertTrue(authorize("admin", previous, current))
        self.assertFalse(authorize("public", previous, current))
        self.assertFalse(openapi_contract_gate.is_authorized_contact_phone_required_break("admin", previous, current))
        for mutation in ["authority", "version", "type", "nullable", "date", "auth", "idempotency", "required-removal", "unrelated"]:
            altered = copy.deepcopy(current)
            fields = altered["components"]["schemas"]["AdminInventoryCard"]["properties"]
            if mutation == "authority":
                altered.pop("x-oripa-breaking-change")
            elif mutation == "version":
                altered["info"]["version"] = "2.0.0-alpha.42"
            elif mutation == "type":
                fields["notion_on_hand"]["type"] = ["string", "null"]
            elif mutation == "nullable":
                fields["notion_available"]["type"] = "number"
            elif mutation == "date":
                fields["notion_read_at"].pop("format")
            elif mutation == "auth":
                altered["paths"]["/inventory/cards"]["get"]["security"] = [{}]
            elif mutation == "idempotency":
                altered["paths"]["/inventory/cards"]["get"]["x-idempotency"] = "not-supported"
            elif mutation == "required-removal":
                altered["components"]["schemas"]["AdminInventoryCard"]["required"].remove("allocation")
            else:
                fields["allocation"]["type"] = "string"
            with self.subTest(mutation=mutation):
                self.assertFalse(authorize("admin", previous, altered))

    def test_signup_sms_break_requires_exact_authority_and_rejects_unrelated_breaks(self):
        current = json.loads((ROOT / "openapi/bundled/public.openapi.json").read_text())
        current["info"]["version"] = "2.0.0-alpha.40"
        previous = copy.deepcopy(current)
        previous["info"]["version"] = "2.0.0-alpha.39"
        registration = previous["components"]["schemas"]["UserRegistrationRequest"]
        registration["required"] = ["email", "password"]
        del registration["properties"]["full_name"]
        del registration["properties"]["birth_date"]
        authorize = openapi_contract_gate.is_authorized_signup_sms_gate_break
        self.assertTrue(authorize("public", previous, current))
        for mutation in ("authority", "surface", "version", "contact", "password", "path"):
            altered = copy.deepcopy(current)
            if mutation == "authority":
                altered.pop("x-oripa-breaking-change")
            elif mutation == "version":
                altered["info"]["version"] = "2.0.0-alpha.41"
            elif mutation == "contact":
                altered["paths"]["/contact-inquiries"]["post"]["security"] = []
            elif mutation == "password":
                altered["components"]["schemas"]["UserRegistrationRequest"]["properties"]["password"]["minLength"] = 12
            elif mutation == "path":
                del altered["paths"]["/auth/session"]
            self.assertFalse(authorize("admin" if mutation == "surface" else "public", previous, altered))

    def test_fincode_webhook_alignment_is_exact_and_does_not_relax_other_contract_guards(self):
        current = json.loads((ROOT / "openapi/bundled/webhook.openapi.json").read_text())
        previous = copy.deepcopy(current)
        schema = previous["components"]["schemas"]["FincodeCardRegistrationWebhook"]
        schema["required"].append("transaction_id")
        schema["properties"]["transaction_id"] = {"type": "string", "minLength": 1, "maxLength": 128}
        schema["properties"]["status"] = {"enum": ["AUTHENTICATING", "CHALLENGE", "AUTHENTICATED"]}
        authorize = openapi_contract_gate.is_authorized_fincode_registration_webhook_alignment
        self.assertEqual(
            ["components.schemas.FincodeCardRegistrationWebhook.transaction_id: type changed"],
            openapi_contract_gate.breaking_changes(previous, current),
        )
        self.assertTrue(authorize("webhook", previous, current))
        for surface in ("public", "admin", "agency"):
            self.assertFalse(authorize(surface, previous, current))
        for mutation in ("signature", "event", "access", "customer", "status", "type", "pattern", "length", "version"):
            altered = copy.deepcopy(current)
            changed = altered["components"]["schemas"]["FincodeCardRegistrationWebhook"]
            if mutation == "signature":
                altered["components"]["parameters"]["FincodeSignature"]["required"] = False
            elif mutation == "event":
                changed["properties"]["event"]["const"] = "customers.other"
            elif mutation == "access":
                changed["required"].remove("access_id")
            elif mutation == "customer":
                del changed["properties"]["customer_id"]
            elif mutation == "status":
                changed["properties"]["status"]["enum"].append("UNKNOWN")
            elif mutation == "type":
                changed["properties"]["transaction_id"]["type"].append("integer")
            elif mutation == "pattern":
                del changed["properties"]["transaction_id"]["pattern"]
            elif mutation == "length":
                changed["properties"]["transaction_id"]["minLength"] = 0
            else:
                altered["info"]["version"] = "2.0.0-alpha.99"
            with self.subTest(mutation=mutation):
                self.assertFalse(authorize("webhook", previous, altered))
        self.assertFalse(authorize("webhook", {}, current))
        self.assertFalse(authorize("webhook", current, current))

    def test_contact_authority_is_limited_to_login_and_idempotency_cutover(self):
        current = json.loads((ROOT / "openapi/bundled/public.openapi.json").read_text())
        current["info"]["version"] = "2.0.0-alpha.33"
        current["x-oripa-breaking-change"] = openapi_contract_gate.CONTACT_AUTHENTICATED_BREAK
        previous = copy.deepcopy(current)
        previous["info"]["version"] = "2.0.0-alpha.32"
        previous["paths"]["/contact-inquiries"]["post"]["security"] = [{}, {"userSession": []}]
        previous["paths"]["/contact-inquiries"]["post"]["x-idempotency"] = "not-supported"
        authorize = openapi_contract_gate.is_authorized_contact_authenticated_break
        self.assertTrue(authorize("public", previous, current))
        self.assertFalse(authorize("admin", previous, current))
        for mutation in ("other-break", "anonymous", "wrong-version", "missing-header", "missing-authority"):
            altered = copy.deepcopy(current)
            if mutation == "other-break":
                altered["paths"]["/contact-inquiries"]["post"]["operationId"] = "differentOperation"
            elif mutation == "anonymous":
                altered["paths"]["/contact-inquiries"]["post"]["security"] = [{}]
            elif mutation == "wrong-version":
                altered["info"]["version"] = "2.0.0-alpha.34"
            elif mutation == "missing-header":
                altered["paths"]["/contact-inquiries"]["post"]["parameters"] = []
            else:
                altered.pop("x-oripa-breaking-change")
            with self.subTest(mutation=mutation):
                self.assertFalse(authorize("public", previous, altered))

    def test_contact_phone_authority_rejects_unrelated_changes(self):
        current = json.loads((ROOT / "openapi/bundled/public.openapi.json").read_text())
        current["info"]["version"] = "2.0.0-alpha.34"
        current["x-oripa-breaking-change"] = openapi_contract_gate.CONTACT_PHONE_REQUIRED_BREAK
        previous = copy.deepcopy(current)
        previous["info"]["version"] = "2.0.0-alpha.33"
        previous["x-oripa-breaking-change"] = openapi_contract_gate.CONTACT_AUTHENTICATED_BREAK
        for field in ("display_name", "email"):
            previous["components"]["schemas"]["PublicUser"]["properties"].pop(field)
        schema = previous["components"]["schemas"]["CreateContactInquiryRequest"]
        schema["required"].remove("phone")
        schema["properties"]["phone"] = {"type": ["string", "null"], "maxLength": 32}
        authorize = openapi_contract_gate.is_authorized_contact_phone_required_break
        self.assertTrue(authorize("public", previous, current))
        self.assertFalse(authorize("admin", previous, current))
        for mutation in ("auth", "idempotency", "constraint", "version", "authority"):
            altered = copy.deepcopy(current)
            if mutation == "auth":
                altered["paths"]["/contact-inquiries"]["post"]["security"] = [{}]
            elif mutation == "idempotency":
                altered["paths"]["/contact-inquiries"]["post"]["x-idempotency"] = "not-supported"
            elif mutation == "constraint":
                altered["components"]["schemas"]["CreateContactInquiryRequest"]["properties"]["phone"]["maxLength"] = 33
            elif mutation == "version":
                altered["info"]["version"] = "2.0.0-alpha.35"
            else:
                altered.pop("x-oripa-breaking-change")
            with self.subTest(mutation=mutation):
                self.assertFalse(authorize("public", previous, altered))

    def test_admin_phase2_retires_fresh_requirements_without_breaking_legacy_schema(self):
        contract = json.loads((ROOT / "openapi/bundled/admin.openapi.json").read_text(encoding="utf-8"))
        self.assertEqual("2.0.0-alpha.42", contract["info"]["version"])
        for item in contract["paths"].values():
            for operation in item.values():
                if isinstance(operation, dict):
                    self.assertNotIn("x-fresh-mfa", operation)
        schema = contract["components"]["schemas"]["AdminReauthenticationRequest"]
        self.assertEqual(["password", "totp", "webauthn"], schema["properties"]["method"]["enum"])
        self.assertTrue(schema["properties"]["password"]["deprecated"])
        self.assertTrue(schema["properties"]["password"]["writeOnly"])
        self.assertNotIn("password", schema["required"])
        for operation in ("/auth/reauthenticate", "/auth/reauthenticate/webauthn/options"):
            self.assertIn(operation, contract["paths"])
        for name in ("AdminPointAdjustmentRequest", "AdminAuthenticationPolicyUpdate"):
            legacy = contract["components"]["schemas"][name]
            self.assertNotIn("current_password", legacy["required"])
            self.assertTrue(legacy["properties"]["current_password"]["deprecated"])

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

    def test_mig_099_break_requires_exact_authority_and_alpha_transition(self):
        previous = {"info": {"version": "2.0.0-alpha.29"}}
        current = {
            "info": {"version": "2.0.0-alpha.30"},
            "x-oripa-breaking-change": openapi_contract_gate.MIG_099_CANONICAL_BREAK,
        }

        self.assertTrue(
            openapi_contract_gate.is_authorized_mig_099_canonical_break(
                "public",
                previous,
                current,
            )
        )
        self.assertFalse(
            openapi_contract_gate.is_authorized_mig_099_canonical_break(
                "webhook",
                previous,
                current,
            )
        )
        current["info"]["version"] = "2.0.0-alpha.31"
        self.assertFalse(
            openapi_contract_gate.is_authorized_mig_099_canonical_break(
                "public",
                previous,
                current,
            )
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
