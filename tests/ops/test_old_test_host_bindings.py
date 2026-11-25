import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts/ops"))
import old_test_admin_measure as admin
import old_test_simple as simple
import old_test_simple_build as build
import old_test_worker_host as workers


class HostBindingsTest(unittest.TestCase):
    def test_scope_only_dispatch_input(self):
        plan = {
            "sources": {build.PLATFORM: {"pr": 21, "sha": "a" * 40}},
            "session": "TEST-COLD",
            "targets": ["admin"],
        }
        with patch.object(simple, "shape", return_value=(["admin"], ["admin"])):
            payload = build.build_inputs(plan, "admin")
            self.assertEqual(payload["ref"], "main")
            self.assertEqual(payload["inputs"]["simple_mode"], "admin-only")
            self.assertIn(simple.digest(plan), build.title(payload))
            with self.assertRaises(simple.Hold):
                build.build_inputs(plan, "api+admin")

    def test_run_and_artifact_reconciliation(self):
        payload = {
            "inputs": {
                "simple_plan": "sha256:" + "a" * 64,
                "simple_mode": "admin-only",
                "task_id": "TEST-COLD",
                "source_sha": "b" * 40,
            }
        }
        run = {
            "id": 44,
            "display_title": build.title(payload),
            "event": "workflow_dispatch",
            "path": simple.WORKFLOW,
            "head_branch": "main",
            "repository": {"full_name": build.PLATFORM},
            "run_attempt": 1,
            "actor": {"id": 7},
        }
        with patch.object(build, "read", return_value={"workflow_runs": [run]}):
            self.assertEqual(build.matching_run({}, 7, payload, lambda: None)["id"], 44)
        with patch.object(build, "read", side_effect=[{"workflow_runs": []}, {"artifacts": []}]):
            self.assertIsNone(build.matching_run({}, 7, payload, lambda: None))
        for key, value in [
            ("head_branch", "release"),
            ("event", "push"),
            ("run_attempt", 2),
            ("actor", {"id": 8}),
        ]:
            self.assertFalse(build.scoped_run(dict(run, **{key: value}), 7))
        with patch.object(build, "read", return_value={"workflow_runs": [run, run]}):
            with self.assertRaisesRegex(simple.Hold, "duplicate"):
                build.matching_run({}, 7, payload, lambda: None)

    def test_ambiguous_dispatch_is_never_repeated(self):
        payload = {"ref": "main", "inputs": {"simple_mode": "admin-only"}}
        data = dict(payload, workflow=simple.WORKFLOW)
        with (
            patch.object(build, "context", return_value=({}, {}, payload, None, {"attempted": True}, None)),
            patch.object(build.host, "pinned") as broker,
        ):
            build.ensure({}, "admin", data, lambda: None)
            broker.assert_not_called()
            self.assertEqual(build.status({}, "admin", lambda: None), "in-progress")

    def test_report_identity_and_findings(self):
        identity = "sha256:" + "a" * 64
        sha = "b" * 40
        scan = {
            "SchemaVersion": 2,
            "Metadata": {"ImageID": identity, "OS": {"Family": "alpine"}},
            "Results": [{}],
        }
        sbom = {
            "bomFormat": "CycloneDX",
            "components": [{}],
            "metadata": {
                "component": {
                    "properties": [
                        {"name": "aquasecurity:trivy:ImageID", "value": identity},
                        {"name": "aquasecurity:trivy:Labels:org.opencontainers.image.revision", "value": sha},
                    ]
                }
            },
        }
        admin.validate_reports(scan, sbom, identity, sha)
        with self.assertRaisesRegex(simple.Hold, "findings"):
            admin.validate_reports(
                dict(scan, Results=[{"Vulnerabilities": [{"Severity": "HIGH"}]}]), sbom, identity, sha
            )
        with self.assertRaisesRegex(simple.Hold, "identity"):
            admin.validate_reports(scan, sbom, identity, "d" * 40)

    def test_pins_retain_all_other_unit_bytes_and_monitor_settings(self):
        old = "a" * 64
        new = "b" * 64
        raw = ("ExecStart=example " + old + " read-only\n").encode()
        self.assertEqual(
            workers.projection("unit", raw)[0],
            workers.projection("unit", raw.replace(old.encode(), new.encode()))[0],
        )
        with self.assertRaisesRegex(simple.Hold, "ambiguous"):
            workers.projection("unit", raw + raw)
        image = "sha256:" + old
        data = dict(id=old, image=image, source="c" * 40, health="healthy", mount="registered")
        static, pin = workers.projection("identity", json.dumps(data).encode())
        self.assertEqual(pin, (old, image, data["source"]))
        self.assertEqual(json.loads(static), {"health": "healthy", "mount": "registered"})

    def test_prepare_inputs_only_for_selected_missing_images(self):
        import old_test_bindings as bindings

        with (
            patch.object(bindings.host, "config", return_value={"contracts": ["contract"]}),
            patch.object(build, "remote_recipes", return_value={}),
            patch.object(bindings.host, "cached", side_effect=lambda p, r: {} if r == "api" else None),
            patch.object(build, "current_recipes", return_value={"admin": "recipe"}) as recipes,
        ):
            plan = {"targets": ["api", "admin"], "contract": "contract"}
            guard = lambda: None
            self.assertEqual(bindings.prepare_inputs(plan, "", {}, guard), {"admin": "recipe"})
            recipes.assert_called_once_with(plan, ["admin"], guard)
