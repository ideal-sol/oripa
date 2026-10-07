import copy
import json
import unittest

from scripts.ci import security_gate
from scripts.release.readiness import change, records
from scripts.release.readiness.adapters import confirm_advisory_drift
from tests.ci.policy import test_change_evidence
from tests.release import test_readiness


class DeltaSecurityTests(unittest.TestCase):
    def setUp(self):
        self.source = test_change_evidence.ChangeEvidenceTests()
        self.source.setUp()
        self.addCleanup(self.source.doCleanups)
        self.repository = self.source.repository
        self.source.write("apps/api/composer.lock", json.dumps({
            "packages": [{"name": "laravel/framework", "version": "v12.0.0"},
                         {"name": "league/flysystem", "version": "3.0.0"}], "packages-dev": [],
        }))
        for path in ("pnpm-lock.yaml", "legacy/v1-frontend/pnpm-lock.yaml"):
            self.source.write(path, "lockfileVersion: '9.0'\nimporters:\n  .: {}\npackages:\n  sharp@0.35.4:\n  undici@7.0.0:\n")
        self.source.base = self.source.commit()
        self.baseline = {"schema_version": "1.1", "management": {
            key: "synthetic replay fixture" for key in ("owner", "reason", "tracking_task", "removal_condition")
        }, "composer": [], "pnpm": []}
        self.audits = {"composer": {"advisories": []}}
        self.audits.update({name: {"advisories": {}, "metadata": {"vulnerabilities": {
            "info": 0, "low": 0, "moderate": 0, "high": 0, "critical": 0,
        }}} for name in ("workspace-pnpm", "legacy-pnpm", "workspace-prod", "legacy-prod")})
        self.statuses = {name: 0 for name in self.audits}

    def finding(self, package="sharp", version="0.35.4", advisory="GHSA-wq5f-xc86-pv6w"):
        self.audits["workspace-pnpm"]["advisories"] = {"1": {
            "url": "https://github.com/advisories/" + advisory,
            "module_name": package, "severity": "high",
            "findings": [{"version": version, "paths": [".>" + package]}],
        }}
        self.audits["workspace-pnpm"]["metadata"]["vulnerabilities"]["high"] = 1
        self.audits["workspace-prod"] = copy.deepcopy(self.audits["workspace-pnpm"])
        self.statuses.update({"workspace-pnpm": 1, "workspace-prod": 1})

    def evaluate(self, classification):
        return security_gate.validate_dependency_audits(
            self.repository, self.audits, self.statuses, self.baseline, classification,
        )

    def assert_separated(self, classification):
        result = self.evaluate(classification)
        self.assertEqual(result["development_security_result"], "PASS_NO_PR_INTRODUCED_DEPENDENCY_REGRESSION")
        self.assertEqual(result["current_security_posture"], "HOLD_UNAPPROVED_FINDING")
        self.assertEqual(result["finding_classification"], "UNCHANGED_DEPENDENCY_SECURITY_POSTURE")
        self.assertTrue(result["security_maintenance_required"])
        self.assertGreater(result["current_findings"], 0)
        self.assertEqual(change.security_posture(result, {
            key: classification[key] for key in ("repository", "base_sha", "head_sha", "tree_sha")
        }), "HOLD")
        return result

    def test_replay_515_feature_undici_normalized_scenario(self):
        self.source.write("apps/api/app/Example.php", "<?php echo 'card delete feature';\n")
        self.finding("undici", "7.0.0", "GHSA-1234-5678-9abc")
        classification = self.source.classify()
        self.assertIn("APPLICATION", classification["change_classes"])
        self.assert_separated(classification)

    def test_replay_519_authority_laravel_flysystem_normalized_scenario(self):
        self.source.authority()
        self.audits["composer"]["advisories"] = {
            name: [{"advisoryId": "PKSA-synthetic-replay", "severity": "high", "cve": None}]
            for name in ("laravel/framework", "league/flysystem")
        }
        self.statuses["composer"] = 1
        classification = self.source.classify()
        self.assertEqual(classification["change_classes"], ["AUTHORITY_ONLY"])
        self.assert_separated(classification)

    def test_replay_526_large_application_separate_advisory_normalized_scenario(self):
        for path in ("apps/api/app/Example.php", "apps/admin/src/example.tsx", "apps/agency/src/example.tsx"):
            self.source.write(path, "synthetic application feature\n")
        self.finding()
        classification = self.source.classify()
        self.assertEqual(classification["validation_path"], "NORMAL_STRICT_CI")
        self.assert_separated(classification)

    def test_replay_547_sharp_no_unauthenticated_historical_pass_claim(self):
        self.source.authority()
        self.finding()
        classification = self.source.classify()
        self.assertEqual(classification["change_classes"], ["AUTHORITY_ONLY"])
        result = self.assert_separated(classification)
        self.assertEqual(result["advisory_db_drift"], "NOT_PROVEN")
        self.assertEqual(result["current_security_findings"][0]["advisory_id"], "GHSA-wq5f-xc86-pv6w")

    def drift_fixture(self):
        self.source.authority()
        self.finding()
        classification = self.source.classify()
        report = self.evaluate(classification)
        check = {"id": 1, "name": "security-gate", "head_sha": self.source.base,
                 "status": "completed", "conclusion": "success", "started_at": "2026-01-01T00:00:00Z",
                 "completed_at": "2026-01-01T00:01:00Z",
                 "details_url": "https://github.com/ideal-sol/oripa/actions/runs/2/job/3",
                 "app": {"id": 15368, "slug": "github-actions", "owner": {"login": "github"}}}
        job = {"run_id": 2, "check_run_url": "https://api.github.com/repos/ideal-sol/oripa/check-runs/1",
               "head_sha": self.source.base, "conclusion": "success"}
        def get(path):
            if "/check-runs?" in path:
                return {"total_count": 1, "check_runs": [check]}
            self.assertEqual(path, "/repos/ideal-sol/oripa/actions/jobs/3")
            return job
        def logs(path):
            self.assertEqual(path, "/repos/ideal-sol/oripa/actions/jobs/3/logs")
            return b'2026-01-01T00:00:30Z {"gate":"security-gate","status":"PASS","unapproved_findings":0}\n'
        return classification, report, check, job, get, logs

    def test_replay_547_verified_prior_same_bytes_confirms_drift(self):
        classification, report, _, _, get, logs = self.drift_fixture()
        result = confirm_advisory_drift(self.repository, classification, report, self.source.base, get=get, get_bytes=logs)
        self.assertEqual(result["finding_classification"], "ADVISORY_DB_DRIFT_CONFIRMED")
        self.assertEqual(result["development_security_result"], "PASS_NO_PR_INTRODUCED_DEPENDENCY_REGRESSION")
        self.assertEqual(result["current_security_posture"], "HOLD_UNAPPROVED_FINDING")
        self.assertTrue(result["security_maintenance_required"])

    def test_drift_missing_malformed_or_wrong_producer_evidence_is_not_accepted(self):
        classification, report, check, job, get, logs = self.drift_fixture()
        for target, field, value in ((check, "conclusion", "failure"), (check, "head_sha", "f" * 40),
                                     (check, "app", {}), (job, "head_sha", "f" * 40),
                                     (job, "run_id", 9), (check, "completed_at", "2099-01-01T00:00:00Z")):
            original = target[field]
            target[field] = value
            with self.subTest(field=field), self.assertRaises(records.RecordError):
                confirm_advisory_drift(self.repository, classification, report, self.source.base, get=get, get_bytes=logs)
            target[field] = original
        with self.assertRaises(records.RecordError):
            confirm_advisory_drift(self.repository, classification, report, self.source.base, get=get, get_bytes=lambda path: b'{}')

    def test_dependency_lock_or_manifest_delta_with_finding_blocks(self):
        self.finding()
        for path in ("pnpm-lock.yaml", "package.json"):
            self.source.write(path, (self.repository / path).read_text() + "\n")
            classification = self.source.classify()
            result = self.evaluate(classification)
            self.assertEqual(result["development_security_result"], "BLOCK_PR_INTRODUCED_SECURITY_FINDING")
            self.assertEqual(result["finding_classification"], "PR_INTRODUCED_SECURITY_FINDING")

    def test_missing_malformed_unavailable_and_contradictory_audits_fail_closed(self):
        self.source.authority()
        classification = self.source.classify()
        for name in self.audits:
            original = self.audits[name]
            with self.subTest(name=name):
                self.audits[name] = {"error": "unavailable"}
                with self.assertRaises(security_gate.SecurityFailure):
                    self.evaluate(classification)
                self.audits[name] = original
                self.statuses[name] = 2
                with self.assertRaises(security_gate.SecurityFailure):
                    self.evaluate(classification)
                self.statuses[name] = 0

    def test_malformed_dependency_fingerprint_never_relaxes_audit(self):
        self.source.authority()
        classification = self.source.classify()
        classification["head_components"]["dependency"] = "malformed"
        self.finding()
        with self.assertRaises(records.RecordError):
            self.evaluate(records.seal(classification, "record_digest"))

    def test_production_report_cannot_hide_findings_or_accept_wrong_identity(self):
        self.source.authority()
        classification = self.source.classify()
        self.finding()
        result = self.evaluate(classification)
        with self.assertRaises(records.RecordError):
            change.security_posture(result, {"head_sha": "f" * 40})
        result["current_security_posture"] = "PASS_APPROVED_POLICY"
        with self.assertRaises(records.RecordError):
            change.security_posture(records.seal(result, "record_digest"), {})

    def test_security_policy_change_remains_full_strict(self):
        self.source.authority()
        self.source.write(".ci/baselines/dependency-advisories.json", json.dumps(self.baseline))
        classification = self.source.classify()
        self.assertEqual(classification["deltas"]["security_policy_delta"], "PRESENT")
        self.assertEqual(classification["validation_path"], "NORMAL_STRICT_CI")

    def test_same_canonical_ci_evidence_is_consumed_by_production_shadow(self):
        self.source.write("apps/api/app/Example.php", "<?php echo 'feature';\n")
        classification = self.source.classify()
        data = test_readiness.fixture()
        candidate = data["candidate"]
        candidate.update({key: classification[key] for key in ("base_sha", "head_sha", "tree_sha")})
        candidate["workflow_authority"] = candidate["head_sha"]
        candidate["source"]["platform"] = candidate["head_sha"]
        candidate["facts"]["artifact.api"]["value"]["source_sha"] = candidate["head_sha"]
        for entry in candidate["facts"].values():
            entry["identity"] = test_readiness.gate.identity(candidate)
        for row in candidate["required_check_evidence"]:
            row["head_sha"] = candidate["head_sha"]
            for check in row["check_runs"]:
                check["head_sha"] = candidate["head_sha"]
        def add(name, value):
            candidate["facts"][name] = {"status": "PASS", "value": value,
                "identity": test_readiness.gate.identity(candidate), "evidence_reference": "fixture:trusted-handoff"}
        add("canonical_change", classification)
        add("current_security_posture", self.evaluate(classification))
        self.assertEqual(test_readiness.gate.evaluate(candidate, data["snapshot"], data["continuity"])["shadow_final_status"], "SHADOW_READY")
        self.finding()
        add("current_security_posture", self.evaluate(classification))
        result = test_readiness.gate.evaluate(candidate, data["snapshot"], data["continuity"])
        self.assertEqual(result["shadow_final_status"], "SHADOW_HOLD")
        self.assertFalse(result["blocking_authority"])
        candidate["facts"]["canonical_change"]["value"] = {"invalid": True}
        self.assertEqual(test_readiness.gate.current_security(candidate)["status"], "UNKNOWN")
