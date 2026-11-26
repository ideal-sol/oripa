import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts/ops"))
import old_test_admin_measure as admin
import old_test_simple as simple
import old_test_simple_build as build
import old_test_worker_host as workers


class HostBindingsTest(unittest.TestCase):
    def test_enrolled_static_timer_pins_detect_drift_only_for_active_consumers(self):
        import old_test_bindings as bindings
        name = "prize-exchange"
        settings = {"ordinary_timers": {"reader": {}, "pins": {name: "static"}},
                    "worker_specs": {name: {}}, "containers": {"api": {"name": "test-api"}}}
        reader = Mock(return_value="static")
        with patch.object(bindings.host, "pinned", return_value={"exec_timer_pin": reader}):
            self.assertEqual(bindings.static_timer_pins(settings, {name: "active"}), {name: "static"})
            reader.return_value = "different"
            self.assertEqual(bindings.static_timer_pins(settings, {name: "inactive"}), {})
            with self.assertRaisesRegex(simple.Hold, "timer_definition_changed"):
                bindings.static_timer_pins(settings, {name: "active"})

    def test_independent_authority_reads_policy_and_bound_request_deadline(self):
        import old_test_host as host
        from tests.ops.test_old_test_simple import Fixture, OLD
        plan = Fixture(["admin"]).plan
        plan["ordinary_policy"] = OLD
        journal = dict(plan=simple.digest(plan), request_deadline=105)
        def read(path, private):
            self.assertIn(path.name, ("plan.json", "journal.json"))
            return json.dumps(plan if path.name == "plan.json" else journal).encode()
        with patch.object(host, "checked_file", side_effect=read), patch.object(host, "config"), \
                patch.object(simple, "ordinary_policy") as policy, patch.object(host.time, "time", return_value=100):
            _, window = host.approved(plan, lambda: None)
            self.assertEqual(window["expires"], 105)
            policy.assert_called_once_with(["admin"], OLD)
            journal["terminal"] = "Technical PASS"
            with self.assertRaisesRegex(simple.Hold, "ordinary_session_closed"):
                host.approved(plan, lambda: None)

    def test_cached_security_checks_nested_files(self):
        import hashlib
        import tempfile
        import old_test_php_authority as php

        with tempfile.TemporaryDirectory() as folder, patch.object(simple, "trusted"):
            root = Path(folder)
            file = root / "evidence"
            file.write_bytes(b"original")
            expected = hashlib.sha256(file.read_bytes()).hexdigest()
            ref = {"path": str(file), "sha256": expected}
            recipe = root / "recipe"
            recipe.write_text(json.dumps({"files": [ref]}))
            material = dict(artifact=dict(id=1, digest="verified", authority={"source_sha": "merged"},
                                          directory=folder, files={"evidence": expected}),
                            recipe=php.ref(recipe), gate=ref, policy=ref, evidence=[ref])
            item = dict(artifact_id=1, artifact_digest="verified", source="merged")
            php.verify_cached_material(material, item, lambda: None)
            file.write_bytes(b"different")
            with self.assertRaisesRegex(simple.Hold, "cached_security_file_changed"):
                php.verify_cached_material(material, item, lambda: None)
            file.unlink()
            with self.assertRaisesRegex(simple.Hold, "restore_missing_pinned"):
                php.verify_cached_material(material, item, lambda: None)

    def test_utc_sender_preserves_container_path_and_php_metadata(self):
        import tempfile
        import old_test_worker_lifecycle as lifecycle
        original = {"PATH": "/container/bin", "PHP_VERSION": "retained", "DB_TIMEZONE": "UTC"}
        old = {"Config": {"Env": [k + "=" + v for k, v in original.items()]}}
        image = {"Config": {"Env": ["PATH=/image/bin", "PHP_VERSION=from-image"]}}
        helper = {"PHP_METADATA": {"PHP_VERSION"}, "prepare_compose": Mock(return_value=["docker", "compose"])}
        settings = {"line_helper": {}, "database": {"source_repository": "/fixture"}}
        plan = {"sources": {"ideal-sol/oripa": {"sha": "a"*40}}}
        def decoded(cmd, environment=None):
            if cmd[1:3] == ["image", "inspect"]:
                return [image]
            overlay = json.loads(Path(cmd[cmd.index("-f")+1]).read_text())
            env = {"DB_TIMEZONE": "UTC"}
            for key, expression in overlay["services"]["line-delivery-worker"]["environment"].items():
                env[key] = environment[expression[2:].split("?")[0]]
            self.assertNotEqual(environment["PATH"], original["PATH"])
            return {"services": {"line-delivery-worker": {"environment": env}}}
        with tempfile.TemporaryDirectory() as directory, (
            patch.object(lifecycle.host, "pinned", return_value=helper)
        ), patch.object(lifecycle.host.canonical, "source_bytes", return_value=b"fixture"), (
            patch.object(lifecycle.host.canonical, "decoded", side_effect=decoded)
        ):
            command, _, _ = lifecycle.line_config(plan, settings, old, "pinned", Path(directory))
            self.assertEqual(Path(command[-1]).name, "line-launch.json")

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

    def test_only_confirmed_dispatch_rejection_can_resume_once(self):
        rejected = {"rejected": "github_http_403", "attempts": 1}
        with patch.object(build, "context", return_value=({}, {}, {}, None, rejected, None)):
            self.assertEqual(build.status({}, "admin", lambda: None), "resumable")
            rejected["attempts"] = 2
            with self.assertRaisesRegex(simple.Hold, "still_rejected"):
                build.status({}, "admin", lambda: None)

    def test_rejected_dispatch_resumes_with_canonical_wire_inputs(self):
        plan = {
            "session": "TEST-COLD",
            "targets": ["admin"],
            "sources": {build.PLATFORM: {"pr": 21, "sha": "a" * 40}},
            "recipes": {"admin": "approved-recipe"},
        }
        with patch.object(simple, "shape", return_value=(["admin"], ["admin"])):
            payload = build.build_inputs(plan, "admin")
        record = {"payload": payload, "rejected": "github_http_422", "attempts": 1}
        post = Mock(return_value=(204, None))
        with (
            patch.object(build, "context", return_value=({}, {"dispatcher": {}}, payload, "marker", record, None)),
            patch.object(build.host, "cached", return_value=None),
            patch.object(build, "merged_source"),
            patch.object(build, "current_recipes", return_value=plan["recipes"]),
            patch.object(build.host, "pinned", return_value={"request": post}),
            patch.object(build.host, "approved"),
            patch.object(simple, "persist") as persist,
        ):
            build.ensure(plan, "admin", dict(payload, workflow=simple.WORKFLOW), lambda: None)
        persist.assert_called_once_with("marker", {"payload": payload, "attempted": True, "attempts": 2})
        self.assertIs(type(payload["inputs"]["pr_number"]), int)
        wire = dict(payload, inputs=dict(payload["inputs"], pr_number="21"))
        post.assert_called_once_with("POST", build.FLOW + "/dispatches", wire)

    def test_scan_and_sbom(self):
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
