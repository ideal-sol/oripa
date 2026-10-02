import copy
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

from scripts.release.readiness import evaluator as gate
from scripts.release.readiness import records
from scripts.release.readiness.adapters import platform_artifact_receipt


STAMP = "2026-10-02T00:00:00Z"
SHA = "a" * 40
DIGEST = "sha256:" + "b" * 64


def fixture():
    snapshot = records.seal({
        "schema_version": "1.0", "generated_at": STAMP, "environment": "production",
        "services": [{"service": "api", "status": "ACTIVE", "observed_at": STAMP,
                      "runtime_source": SHA, "artifact_or_image_digest": DIGEST,
                      "digest_kind": "docker_image_id", "observation_method": "synthetic fixture",
                      "authority_record": "fixture:previous-runtime"}],
        "contract": {"status": "CONFIRMED", "artifact_id": "fixture-contract",
                     "manifest_digest": DIGEST, "client_pin": "fixture.1", "testkit_pin": "fixture.1",
                     "openapi_pin": "fixture.1", "evidence_reference": "fixture:contract"},
        "migration": {"status": "CONFIRMED", "revision": "fixture-0", "observed_at": STAMP,
                      "evidence_reference": "fixture:migration"},
        "external_runtime_definitions": {"status": "CONFIRMED", **{
            key: "fixture:unchanged" for key in ("docker", "workers", "systemd", "proxy", "storage_binding")}},
    }, "snapshot_digest")
    continuity = records.seal({
        "schema_version": "1.0", "base_snapshot_digest": snapshot["snapshot_digest"], "checked_at": STAMP,
        "continuity_status": "CONFIRMED_NO_AUTHORITY_CHANGE", "checked_scope": sorted(records.CONTINUITY_SCOPE),
        "authority_events": [], "evidence_references": ["fixture:human-continuity"],
        "unresolved_gaps": [], "confirmed_by_role": "human_operator",
    }, "record_digest")
    candidate = {
        "schema_version": "1.0", "candidate_id": "synthetic-only", "repository": "ideal-sol/oripa",
        "base_sha": SHA, "head_sha": SHA, "tree_sha": SHA, "workflow_authority": SHA,
        "authority_snapshot_digest": snapshot["snapshot_digest"], "source": {"platform": SHA, "storefront": None},
        "service_inventory": ["api"], "build_scope": ["api"], "activation_scope": ["api"],
        "acceptance_scope": ["api"], "rollback_scope": ["api"], "change_classes": ["platform_runtime"],
        "artifacts": {"api": {"service": "api", "artifact_id": "fixture-api", "digest": DIGEST}},
        "facts": {}, "target_contract": snapshot["contract"],
        "rollback_target": {"api": {"source_sha": SHA, "digest": DIGEST, "release_id": "fixture-previous",
                                   "acceptance": "KNOWN_GOOD", "evidence_reference": "fixture:accepted"}},
        "required_check_evidence": [{"repository": "ideal-sol/oripa", "head_sha": SHA,
                                     "complete": True, "evidence_reference": "fixture:checks", "check_runs": [
            {"id": number + 1, "name": name, "head_sha": SHA, "status": "completed", "conclusion": "success",
             "started_at": STAMP, "app": {"id": 15368, "slug": "github-actions", "owner": {"login": "github"}}}
            for number, name in enumerate(sorted(gate.REQUIRED_CHECKS))]}],
    }
    def add(name, value):
        candidate["facts"][name] = {"identity": gate.identity(candidate), "status": "PASS",
                                     "evidence_reference": "fixture:" + name, "value": value}
    candidate["rollback_authority"] = {
        "operator_role": "fixture_approved_operator", "go_actor_role": "human_operator",
        "go_evidence_reference": "fixture:rollback-go", "procedure_reference": "fixture:procedure",
        "procedure_status": "CURRENT", "target": candidate["rollback_target"],
    }
    for name in (*gate.R1_FACTS, *gate.R6_FACTS, "all_runtime_deltas_approved", "complete_diff", "no_known_holds"):
        add(name, True)
    for name in gate.RB_FACTS.values():
        add("rollback." + name, True)
    add("source_identity", candidate["source"])
    add("service_scope", {key: candidate[key] for key in ("service_inventory", "build_scope", "activation_scope", "acceptance_scope", "rollback_scope")})
    add("contract_provenance", candidate["target_contract"])
    add("runtime_delta_inventory", {"current": {"api": snapshot["services"][0]}, "surfaces": list(gate.RUNTIME_SURFACES)})
    add("stage.api", candidate["artifacts"]["api"])
    add("artifact.api", {"status": "VERIFIED", "artifact": candidate["artifacts"]["api"], "source_sha": SHA,
                         "architecture": "arm64", "validator": "fixture:canonical-validator"})
    return {"candidate": candidate, "snapshot": snapshot, "continuity": continuity}


class ReadinessTests(unittest.TestCase):
    def setUp(self):
        self.data = fixture()
        self.candidate = self.data["candidate"]

    def evaluate(self):
        return gate.evaluate(self.candidate, self.data["snapshot"], self.data["continuity"], generated_at=STAMP)

    def add(self, name, value):
        self.candidate["facts"][name] = {"identity": gate.identity(self.candidate), "status": "PASS",
                                         "evidence_reference": "fixture:" + name, "value": value}

    def test_ready_formula_and_no_operations(self):
        result = self.evaluate()
        self.assertEqual(result["shadow_final_status"], "SHADOW_READY")
        self.assertEqual(result["production_impact"], "NONE")
        self.assertFalse(result["blocking_authority"])
        self.assertEqual(result["required_browser_acceptance"]["status"], "HUMAN_BROWSER_ACCEPTANCE_PENDING")
        self.assertTrue(all(not row["operation_executed"] for row in result["production_step_matrix"].values()))

    def test_every_mandatory_fact_missing_blocks_ready(self):
        for name in tuple(self.candidate["facts"]):
            with self.subTest(name=name):
                original = self.candidate["facts"].pop(name)
                self.assertEqual(self.evaluate()["shadow_final_status"], "SHADOW_UNKNOWN")
                self.candidate["facts"][name] = original

    def test_r1_states_and_na_requires_evidence(self):
        for status in ("PASS", "HOLD", "UNKNOWN", "N/A"):
            self.candidate["facts"]["exact_source"]["status"] = status
            expected = "UNKNOWN" if status == "N/A" else status
            self.assertEqual(gate.fact(self.candidate, "exact_source")["status"], expected)
        self.candidate["facts"]["exact_source"]["non_applicability_reason"] = "fixture only"
        self.assertEqual(gate.fact(self.candidate, "exact_source", allow_na=True)["status"], "N/A")
        self.candidate["facts"]["exact_source"]["evidence_reference"] = ""
        self.assertEqual(gate.fact(self.candidate, "exact_source", allow_na=True)["status"], "UNKNOWN")

    def test_explicit_mismatches_hold(self):
        for name in ("exact_source", "all_runtime_deltas_approved", "compatibility", "no_known_holds"):
            with self.subTest(name=name):
                self.candidate["facts"][name]["value"] = False
                self.assertEqual(self.evaluate()["shadow_final_status"], "SHADOW_HOLD")
                self.candidate["facts"][name]["value"] = True

    def test_contract_mismatch(self):
        self.candidate["facts"]["contract_provenance"]["value"] = {"manifest_digest": "sha256:" + "c" * 64}
        self.assertEqual(self.evaluate()["requirements"]["R3"]["status"], "HOLD")

    def test_check_missing_pending_wrong_source_failed_and_workflow_separation(self):
        evidence = self.candidate["required_check_evidence"][0]
        original = copy.deepcopy(evidence)
        for field, value, expected in (("status", "in_progress", "UNKNOWN"), ("conclusion", "failure", "HOLD"), ("head_sha", "c" * 40, "HOLD"), ("app", {}, "HOLD")):
            evidence["check_runs"][0][field] = value
            self.assertEqual(self.evaluate()["requirements"]["R4"]["status"], expected)
            evidence["check_runs"] = copy.deepcopy(original["check_runs"])
        evidence["check_runs"] = []
        self.assertEqual(self.evaluate()["requirements"]["R4"]["status"], "UNKNOWN")
        self.candidate["workflow_authority"] = "c" * 40
        self.assertEqual(self.evaluate()["requirements"]["R4"]["status"], "UNKNOWN")

    def test_service_coverage_and_separate_scopes(self):
        self.candidate["build_scope"].append("storefront")
        with self.assertRaises(records.RecordError):
            self.evaluate()
        self.candidate["build_scope"] = []
        self.add("service_scope", {key: self.candidate[key] for key in ("service_inventory", "build_scope", "activation_scope", "acceptance_scope", "rollback_scope")})
        self.assertEqual(self.evaluate()["shadow_final_status"], "SHADOW_READY")

    def test_artifact_failure_categories(self):
        receipt = self.candidate["facts"]["artifact.api"]["value"]
        for status, final in (("PROVENANCE_INVALID", "SHADOW_HOLD"), ("VERIFICATION_INFRA_FAILURE", "SHADOW_UNKNOWN"), ("VERIFICATION_INCOMPLETE", "SHADOW_UNKNOWN"), ("unrecognized", "SHADOW_UNKNOWN")):
            receipt["status"] = status
            self.assertEqual(self.evaluate()["shadow_final_status"], final)

    def test_provenance_adapter_reuses_existing_receipt(self):
        verified = {"status": "verified", "artifact_kind": "production-candidate", "architecture": "arm64",
                    "platform": "linux/arm64", "manifest_sha256": "b" * 64, "source_commit": SHA,
                    "images": [{"name": "api", "image_id": DIGEST}]}
        self.assertEqual(platform_artifact_receipt(verified, self.candidate["artifacts"]["api"], "api")["status"], "VERIFIED")
        verified["images"][0]["image_id"] = "sha256:" + "c" * 64
        with self.assertRaises(records.RecordError):
            platform_artifact_receipt(verified, self.candidate["artifacts"]["api"], "api")

    def test_exact_evidence_binding(self):
        self.candidate["head_sha"] = "c" * 40
        self.assertNotEqual(self.evaluate()["shadow_final_status"], "SHADOW_READY")

    def test_snapshot_canonicalization_and_digest(self):
        snapshot = self.data["snapshot"]
        reverse = dict(reversed(list(snapshot.items())))
        self.assertEqual(records.digest(reverse, "snapshot_digest"), snapshot["snapshot_digest"])
        self.assertTrue(records.canonical(snapshot).endswith(b"\n"))
        self.assertNotIn(b": ", records.canonical(snapshot))
        snapshot["services"][0]["runtime_source"] = "c" * 40
        with self.assertRaises(records.RecordError):
            records.snapshot(snapshot)

    def test_snapshot_malformed_and_na(self):
        for key in self.data["snapshot"]:
            invalid = copy.deepcopy(self.data["snapshot"])
            del invalid[key]
            with self.subTest(key=key), self.assertRaises(records.RecordError):
                records.snapshot(invalid)
        invalid = copy.deepcopy(self.data["snapshot"])
        invalid["contract"]["status"] = "N/A"
        invalid["contract"]["evidence_reference"] = None
        with self.assertRaises(records.RecordError):
            records.snapshot(records.seal(invalid, "snapshot_digest"))

    def test_continuity_states_scope_digest_and_no_ttl(self):
        record = self.data["continuity"]
        record["checked_at"] = "2030-01-01T00:00:00Z"
        self.data["continuity"] = records.seal(record, "record_digest")
        self.assertEqual(self.evaluate()["shadow_final_status"], "SHADOW_READY")
        cases = [("continuity_status", "AUTHORITY_CHANGE_DETECTED"), ("continuity_status", "CONTINUITY_UNKNOWN"),
                 ("checked_scope", []), ("base_snapshot_digest", "sha256:" + "c" * 64),
                 ("unresolved_gaps", ["missing operation log"]), ("evidence_references", []),
                 ("confirmed_by_role", "approved_producer")]
        for key, value in cases:
            modified = copy.deepcopy(record)
            modified[key] = value
            self.data["continuity"] = records.seal(modified, "record_digest")
            self.assertEqual(self.evaluate()["shadow_final_status"], "AUTHORITY_SYNC_REQUIRED")

    def test_rollback_each_requirement_and_previous_not_known_good(self):
        for name in gate.RB_FACTS.values():
            key = "rollback." + name
            original = self.candidate["facts"].pop(key)
            self.assertEqual(gate.rollback(self.candidate, gate.FULL)["status"], "ROLLBACK_UNKNOWN")
            self.candidate["facts"][key] = original
        self.candidate["rollback_target"]["api"]["acceptance"] = "PREVIOUS_ONLY"
        self.assertEqual(gate.rollback(self.candidate, gate.FULL)["status"], "ROLLBACK_UNKNOWN")

    def test_minor_rollback_requires_all_invariants_and_exact_target(self):
        for key, value in gate.MINOR_INVARIANTS.items():
            self.add("minor_rollback." + key, value)
        self.add("minor_rollback.exact_previous_release", self.candidate["rollback_target"])
        self.assertEqual(gate.rollback(self.candidate, gate.MINOR)["status"], "ROLLBACK_READY")
        for key in ("contract", "migration", "routing"):
            self.add("minor_rollback." + key, "CHANGED")
            self.assertEqual(gate.rollback(self.candidate, gate.MINOR)["status"], "ROLLBACK_HOLD")
            self.add("minor_rollback." + key, gate.MINOR_INVARIANTS[key])

    def prepare_authority_candidate(self):
        self.add("platform_impact", "NONE")
        self.add("classification_baseline", {key: self.candidate[key] for key in ("base_sha", "head_sha", "tree_sha")})
        self.candidate["change_classes"] = ["authority_snapshot"]
        self.candidate["build_scope"] = self.candidate["activation_scope"] = []
        for surface in gate.RUNTIME_SURFACES:
            self.add("runtime_delta." + surface, "NONE")
        self.add("authority_metadata_not_in_runtime", True)
        self.add("service_scope", {key: self.candidate[key] for key in (
            "service_inventory", "build_scope", "activation_scope", "acceptance_scope", "rollback_scope"
        )})

    def assert_strict_authority_fallback(self):
        classification = gate.classify(self.candidate)
        self.assertEqual(classification["candidate_lane"], "NORMAL_STRICT_CI")
        self.assertEqual(classification["fallback_lane"], "NORMAL_STRICT_CI")
        self.assertEqual(classification["classification_reason"], "AUTHORITY_ONLY_FAST_LANE_INDETERMINATE")
        record = self.evaluate()
        self.assertEqual(record["classification"], classification)
        self.assertEqual(record["lane"], "NORMAL_STRICT_CI")
        self.assertEqual(record["fallback_lane"], record["lane"])
        matrix = record["production_step_matrix"]
        self.assertEqual(matrix, gate.step_matrix(self.candidate, gate.FULL))
        for step in ("platform_build", "platform_stage", "platform_activation", "storefront_build", "storefront_activation"):
            self.assertEqual(matrix[step]["policy_modes"], ["CONDITIONAL"])
            self.assertEqual(matrix[step]["disposition"], "CONDITIONAL")
        for step in ("required_security_policy", "arm64_proof", "db_migration_assessment", "human_go", "rollback_readiness"):
            self.assertEqual(matrix[step]["disposition"], "REQUIRED")
        self.assertTrue(all(not row["operation_executed"] for row in matrix.values()))
        self.assertEqual(record["production_impact"], "NONE")
        self.assertFalse(record["blocking_authority"])
        self.assertNotEqual(record["shadow_final_status"], "SHADOW_HOLD")

    def test_authority_only_eligible_and_indeterminate(self):
        self.prepare_authority_candidate()
        self.assertEqual(gate.classify(self.candidate)["candidate_lane"], gate.AUTHORITY)
        record = self.evaluate()
        self.assertEqual(record["lane"], gate.AUTHORITY)
        self.assertEqual(record["production_step_matrix"], gate.step_matrix(self.candidate, gate.AUTHORITY))
        self.assertEqual(record["production_step_matrix"]["platform_build"]["policy_modes"], ["N/A", "REUSE"])
        self.assertEqual(record["shadow_final_status"], "SHADOW_READY")
        for surface in gate.RUNTIME_SURFACES:
            key = "runtime_delta." + surface
            original = self.candidate["facts"].pop(key)
            self.assert_strict_authority_fallback()
            self.candidate["facts"][key] = original

    def test_authority_runtime_unknown_uses_strict_matrix(self):
        self.prepare_authority_candidate()
        for surface in gate.RUNTIME_SURFACES:
            with self.subTest(surface=surface):
                self.candidate["facts"]["runtime_delta." + surface]["status"] = "UNKNOWN"
                self.assert_strict_authority_fallback()
                self.candidate["facts"]["runtime_delta." + surface]["status"] = "PASS"

    def test_authority_missing_mandatory_proofs_uses_strict_matrix(self):
        self.prepare_authority_candidate()
        for name in ("authority_metadata_not_in_runtime", "complete_diff", "classification_baseline", "platform_impact"):
            with self.subTest(name=name):
                original = self.candidate["facts"].pop(name)
                self.assert_strict_authority_fallback()
                self.candidate["facts"][name] = original

    def test_authority_runtime_none_not_proven_uses_strict_matrix(self):
        self.prepare_authority_candidate()
        for surface in gate.RUNTIME_SURFACES:
            for value in ("CHANGED", None):
                with self.subTest(surface=surface, value=value):
                    self.add("runtime_delta." + surface, value)
                    self.assert_strict_authority_fallback()
            self.add("runtime_delta." + surface, "NONE")

    def test_authority_requested_operations_use_strict_matrix(self):
        self.prepare_authority_candidate()
        for scope in ("build_scope", "activation_scope"):
            with self.subTest(scope=scope):
                self.candidate[scope] = ["api"]
                self.add("service_scope", {key: self.candidate[key] for key in (
                    "service_inventory", "build_scope", "activation_scope", "acceptance_scope", "rollback_scope"
                )})
                self.assert_strict_authority_fallback()
                self.candidate[scope] = []

    def test_non_authority_lane_classification_is_unchanged(self):
        self.assertEqual(gate.classify(self.candidate)["candidate_lane"], gate.FULL)
        self.add("platform_impact", "NONE")
        self.add("classification_baseline", {key: self.candidate[key] for key in ("base_sha", "head_sha", "tree_sha")})
        self.candidate["change_classes"] = ["storefront_presentation"]
        self.assertEqual(gate.classify(self.candidate)["candidate_lane"], gate.NORMAL)
        self.assertEqual(gate.step_matrix(self.candidate, gate.NORMAL)["storefront_build"]["disposition"], "REQUIRED")
        classification = records.seal({
            **{key: self.candidate[key] for key in ("repository", "base_sha", "head_sha", "tree_sha")},
            "candidate_lane": gate.MINOR, "policy_approval": "HUMAN_APPROVED",
            "production_impact": "NONE", "unknown_reasons": [],
        }, "record_digest")
        self.add("storefront_minor_classification", classification)
        self.assertEqual(gate.classify(self.candidate)["candidate_lane"], gate.MINOR)
        self.assertEqual(gate.step_matrix(self.candidate, gate.MINOR)["storefront_build"]["disposition"], "REQUIRED")

    def test_matrix_reuse_and_na_require_exact_evidence(self):
        self.assertEqual(gate.step_matrix(self.candidate, gate.AUTHORITY)["platform_build"]["disposition"], "CONDITIONAL")
        self.add("step.platform_build", "REUSE")
        self.assertEqual(gate.step_matrix(self.candidate, gate.AUTHORITY)["platform_build"]["disposition"], "REUSE")

    def test_human_go_validator_does_not_generate_approval(self):
        record = records.seal({"schema_version": "1.0", "candidate_id": self.candidate["candidate_id"],
            "approved_at": STAMP, "service_scope": ["api"], "source": self.candidate["source"],
            "artifacts": [self.candidate["artifacts"]["api"]], "authority_snapshot_digest": self.candidate["authority_snapshot_digest"],
            "approval_actor_role": "human_operator", "approval_evidence_reference": "fixture:human-only",}, "record_digest")
        self.assertEqual(records.human_go(record, self.candidate), "HUMAN_GO_RECORD_VALID_NOT_AUTHENTICATED")
        for nullable in ("artifact_id", "digest"):
            partial = copy.deepcopy(record)
            partial["artifacts"][0][nullable] = None
            self.assertEqual(records.human_go(records.seal(partial, "record_digest"), self.candidate), "HUMAN_GO_RECORD_VALID_NOT_AUTHENTICATED")
        missing = copy.deepcopy(record)
        missing["artifacts"][0].update({"artifact_id": None, "digest": None})
        with self.assertRaises(records.RecordError):
            records.human_go(records.seal(missing, "record_digest"), self.candidate)
        record["approval_actor_role"] = "codex"
        with self.assertRaises(records.RecordError):
            records.human_go(records.seal(record, "record_digest"), self.candidate)

    def test_cli_unknown_is_observation_only_and_does_not_overwrite(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "input.json"
            output = Path(directory) / "output.json"
            source.write_text("{invalid", encoding="utf-8")
            command = ["python3", "-m", "scripts.release.readiness", "--input", str(source), "--output", str(output)]
            completed = subprocess.run(command, capture_output=True, check=False)
            self.assertEqual(completed.returncode, 0)
            self.assertEqual(json.loads(output.read_text())["shadow_final_status"], "SHADOW_UNKNOWN")
            self.assertNotEqual(subprocess.run(command, capture_output=True, check=False).returncode, 0)

    def test_duplicate_keys_and_nonfinite_json_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "input.json"
            for content in ('{"value":1,"value":2}', '{"value":NaN}'):
                path.write_text(content, encoding="utf-8")
                with self.assertRaises(records.RecordError):
                    records.load(path)

    def test_browser_acceptance_is_exact_and_scope_complete(self):
        self.candidate["source"]["storefront"] = SHA
        self.candidate["artifacts"]["storefront"] = {"service": "storefront", "artifact_id": "fixture-storefront", "digest": DIGEST}
        self.candidate["storefront_build_id"] = "fixture-build"
        plan = records.seal({"affected_routes": ["/contact"], "affected_states": ["error"],
                             "required_viewport_classes": ["mobile", "desktop"]}, "record_digest")
        record = records.seal({"schema_version": "1.0", "candidate_id": self.candidate["candidate_id"],
            "source_sha": SHA, "artifact_id": "fixture-storefront", "build_id": "fixture-build",
            "status": "HUMAN_BROWSER_ACCEPTANCE_PASS", "checked_at": STAMP, "actor_role": "human_operator",
            "change_class": "literal_className", "checked_routes": ["/contact"], "checked_states": ["error"],
            "checked_viewports": ["mobile", "desktop"], "acceptance_plan_reference": plan["record_digest"],
            "result_notes": "synthetic test record only"}, "record_digest")
        self.assertEqual(records.browser_acceptance(record, self.candidate, plan), "HUMAN_BROWSER_ACCEPTANCE_PASS")
        for key, value in (("checked_routes", []), ("checked_states", []), ("checked_viewports", ["desktop"]),
                           ("actor_role", None), ("source_sha", "c" * 40), ("build_id", "different")):
            modified = records.seal({**record, key: value}, "record_digest")
            with self.subTest(key=key), self.assertRaises(records.RecordError):
                records.browser_acceptance(modified, self.candidate, plan)

    def test_fast_lane_incomplete_diff_and_wrong_baseline(self):
        self.add("platform_impact", "NONE")
        self.add("classification_baseline", {key: self.candidate[key] for key in ("base_sha", "head_sha", "tree_sha")})
        self.add("complete_diff", False)
        self.assertEqual(gate.classify(self.candidate)["candidate_lane"], gate.FULL)
        self.add("complete_diff", True)
        self.add("classification_baseline", {"base_sha": "c" * 40})
        self.assertEqual(gate.classify(self.candidate)["candidate_lane"], gate.FULL)

    def test_no_artifacts_requires_evidence_backed_non_applicability(self):
        self.candidate["artifacts"] = {}
        self.candidate["build_scope"] = self.candidate["activation_scope"] = []
        self.assertEqual(gate.artifact_verification(self.candidate)["status"], "VERIFICATION_INCOMPLETE")
        self.add("artifacts_non_applicable", True)
        self.assertEqual(gate.artifact_verification(self.candidate)["status"], "N/A")

    def test_undefined_operator_and_historical_procedure_are_not_ready(self):
        self.candidate["rollback_authority"]["operator_role"] = None
        self.assertEqual(gate.rollback(self.candidate, gate.FULL)["requirements"]["RB6"]["status"], "UNKNOWN")
        self.candidate["rollback_authority"]["procedure_status"] = "HISTORICAL"
        self.assertEqual(gate.rollback(self.candidate, gate.FULL)["requirements"]["RB5"]["status"], "UNKNOWN")

    def test_built_service_requires_exact_source(self):
        self.candidate["source"]["platform"] = None
        with self.assertRaises(records.RecordError):
            self.evaluate()

    def test_malformed_nested_facts_produce_unknown_observation(self):
        self.add("platform_impact", "NONE")
        self.add("classification_baseline", {key: self.candidate[key] for key in ("base_sha", "head_sha", "tree_sha")})
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "input.json"
            for number, invalid in enumerate((None, False, [], 42)):
                self.candidate["facts"]["storefront_minor_classification"] = invalid
                source.write_text(json.dumps(self.data), encoding="utf-8")
                output = Path(directory) / ("output-" + str(number) + ".json")
                completed = subprocess.run([
                    "python3", "-m", "scripts.release.readiness", "--input", str(source), "--output", str(output)
                ], capture_output=True, check=False)
                self.assertEqual(completed.returncode, 0)
                self.assertEqual(json.loads(output.read_text())["shadow_final_status"], "SHADOW_UNKNOWN")

    def test_every_source_repository_requires_checks_and_exact_tree_reuse(self):
        self.candidate["source"]["storefront"] = "c" * 40
        self.assertEqual(gate.aggregate(gate.required_checks(self.candidate)), "UNKNOWN")
        storefront = copy.deepcopy(self.candidate["required_check_evidence"][0])
        storefront.update(repository="ideal-sol/luxe-pack-storefront", head_sha="c" * 40)
        for check in storefront["check_runs"]:
            check["head_sha"] = "c" * 40
        self.candidate["required_check_evidence"].append(storefront)
        self.assertEqual(gate.aggregate(gate.required_checks(self.candidate)), "PASS")
        storefront["check_runs"][0]["conclusion"] = "failure"
        self.assertEqual(gate.aggregate(gate.required_checks(self.candidate)), "HOLD")
        storefront["check_runs"][0]["conclusion"] = "success"
        storefront["head_sha"] = "d" * 40
        for check in storefront["check_runs"]:
            check["head_sha"] = "d" * 40
        self.assertEqual(gate.aggregate(gate.required_checks(self.candidate)), "UNKNOWN")
        storefront.update(source_sha="c" * 40, source_tree_sha="e" * 40, checked_tree_sha="e" * 40,
                          tree_evidence_reference="fixture:canonical-reviewed-tree-authority")
        self.assertEqual(gate.aggregate(gate.required_checks(self.candidate)), "PASS")
        storefront["checked_tree_sha"] = "f" * 40
        self.assertEqual(gate.aggregate(gate.required_checks(self.candidate)), "UNKNOWN")
