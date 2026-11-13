"""Production Admin-only composition and fail-closed artifact/workflow boundary."""
import json
import os
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from tests.ops import test_preview_image_pipeline as pipeline

ROOT = Path(__file__).resolve().parents[2]
M = runpy.run_path(str(ROOT / "scripts/ops/production_source_authority.py"))
H = M["LIMITED"]


class ProductionAdminNodeTest(unittest.TestCase):
    def setUp(self):
        self.spec = H["admin_specification"](ROOT)
        self.baseline = H["specification"](ROOT)
        self.head = M["git"](ROOT, "rev-parse", "HEAD")
        self.bad = None

    def get(self, path):
        if path.endswith("/branches/main"):
            return {"protected": self.bad != "unprotected", "commit": {"sha": self.head}}
        if "/pulls/" in path:
            number = int(path.rsplit("/", 1)[1])
            sha = {659: self.baseline["base_sha"], 666: self.baseline["fix_sha"],
                   685: self.spec["node_sha"]}[number]
            return {"merged": self.bad != number, "state": "closed", "merge_commit_sha": sha,
                    "base": {"ref": "main", "repo": {"full_name": M["REPOSITORY"]}},
                    "head": {"sha": sha, "repo": {"full_name": M["REPOSITORY"]}}}
        if "/git/commits/" in path:
            sha = path.rsplit("/", 1)[1]
            return {"sha": sha, "tree": {"sha": "0" * 40 if self.bad == "tree" else
                    M["git"](ROOT, "rev-parse", sha + "^{tree}")}}
        if "/check-runs?" in path:
            sha = path.split("/commits/")[1].split("/")[0]
            runs = [{"name": n, "id": i + 1, "head_sha": sha, "status": "completed",
                     "conclusion": "failure" if self.bad == sha else "success",
                     "started_at": "2026-11-12T00:00:00Z",
                     "app": {"id": 15368, "slug": "github-actions", "owner": {"login": "github"}}}
                    for i, n in enumerate(sorted(M["REQUIRED_CHECKS"]))]
            return {"total_count": len(runs), "check_runs": runs}
        raise AssertionError(path)

    def authorize(self, **changes):
        values = dict(repository=ROOT, source_sha=H["ADMIN_SOURCE"], workflow_sha=self.head,
                      change_id=H["ADMIN_CHANGE"], pr_number=685, get=self.get, image_mode="admin-only")
        values.update(changes)
        return M["authorize"](**values)

    def test_source_authority_and_reviewed_inputs(self):
        evidence = self.authorize()
        self.assertEqual(set(evidence["reviewed_sources"]), {"base", "fix", "node"})
        self.assertEqual(evidence["source_tree"], self.spec["source_tree"])
        self.assertEqual(evidence["checkout_sha"], self.baseline["base_sha"])
        self.assertIs(evidence["activation_authorized"], False)
        line = json.loads((ROOT / M["AUTHORITY_PATH"]).read_text())
        self.assertEqual(line["source_sha"], "9235350523accdb2c3d41dca869d7d44f0863ba5")

    def test_no_unreviewed_wrong_source_or_mode(self):
        for bad in (659, 666, 685, "unprotected", "tree", self.head, self.spec["node_sha"],
                    self.baseline["base_sha"], self.baseline["fix_sha"]):
            self.bad = bad
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                self.authorize()
        self.bad = None
        for values in ({"source_sha": self.spec["node_sha"]}, {"source_sha": H["SOURCE"]},
                       {"pr_number": 686}, {"change_id": "OLDTEST-NODE"},
                       {"image_mode": "agency"}, {"image_mode": "api-only"}):
            with self.subTest(values=values), self.assertRaises(ValueError):
                self.authorize(**values)

    def test_tampered_spec_and_main_drift(self):
        with patch.dict(H["admin_specification"].__globals__, git=lambda *a: b"{}"):
            with self.assertRaisesRegex(ValueError, "specification mismatch"):
                self.authorize()
        calls = []
        def get(path):
            value = self.get(path)
            if path.endswith("/branches/main"):
                calls.append(path)
                if len(calls) > 1:
                    value["commit"]["sha"] = "0" * 40
            return value
        with self.assertRaisesRegex(ValueError, "main moved"):
            self.authorize(get=get)

    def test_exact_node_only_delta_all_other_inputs_unchanged(self):
        evidence = {"composition": self.spec, "source_sha": H["ADMIN_SOURCE"],
                    "source_tree": self.spec["source_tree"], "change_id": H["ADMIN_CHANGE"],
                    "pr_number": 685, "image_mode": "admin-only", "architecture": "arm64"}
        with tempfile.TemporaryDirectory() as temp:
            payload = Path(temp) / "payload"
            M["git"](ROOT, "clone", "-q", "--shared", "--no-checkout", str(ROOT), str(payload))
            M["git"](payload, "checkout", "-q", "--detach", self.baseline["base_sha"])
            H["materialize_admin"](ROOT, payload, evidence)
            self.assertEqual(M["git"](payload, "rev-parse", "HEAD"), H["ADMIN_SOURCE"])
            changed = M["git"](payload, "diff", "--name-only", H["SOURCE"], "HEAD").splitlines()
            self.assertEqual(set(changed), {"apps/admin/Dockerfile", "apps/admin/package.json", "package.json"})
            self.assertEqual(M["git"](payload, "diff", "--name-only", H["SOURCE"], "HEAD", "--",
                             "apps/admin/src", "pnpm-lock.yaml", "apps/api", "apps/agency", "packages"), "")
            for path in ("apps/admin/package.json", "package.json"):
                before = json.loads(M["git"](payload, "show", H["SOURCE"] + ":" + path))
                after = json.loads((payload / path).read_text())
                before["engines"]["node"] = "22.23.5"
                self.assertEqual(before, after)
            docker = (payload / "apps/admin/Dockerfile").read_text()
            self.assertEqual(docker.count("FROM node:22.23.5-alpine@sha256:1314fb30ec37592d5845e5a160d522c0e680972aa534549940b8d73bde88481a"), 2)

    def test_materialization_rejects_authority_and_dirty_checkout(self):
        evidence = {"composition": self.spec, "source_sha": H["ADMIN_SOURCE"],
                    "source_tree": self.spec["source_tree"], "change_id": H["ADMIN_CHANGE"],
                    "pr_number": 685, "image_mode": "admin-only", "architecture": "arm64"}
        with tempfile.TemporaryDirectory() as temp:
            payload = Path(temp) / "payload"
            M["git"](ROOT, "clone", "-q", "--shared", "--no-checkout", str(ROOT), str(payload))
            M["git"](payload, "checkout", "-q", "--detach", self.baseline["base_sha"])
            for field in evidence:
                with self.subTest(field=field), self.assertRaises(ValueError):
                    H["materialize_admin"](ROOT, payload, {**evidence, field: None})
            (payload / "extra").write_text("unapproved")
            with self.assertRaisesRegex(ValueError, "pristine"):
                H["materialize_admin"](ROOT, payload, evidence)

    def test_artifact_exact_production_identity_and_archive(self):
        with patch.multiple(pipeline, TASK=H["ADMIN_CHANGE"], PR=685, HEAD=H["ADMIN_SOURCE"]):
            with tempfile.TemporaryDirectory() as temp:
                directory = Path(temp)
                pipeline.create_artifact(directory, ("admin",), architecture="arm64",
                                         artifact_kind="production-candidate", revision=H["ADMIN_SOURCE"])
                result = pipeline.artifact.verify_artifact(directory, task_id=H["ADMIN_CHANGE"],
                         pr_number=685, source_sha=H["ADMIN_SOURCE"], architecture="arm64",
                         artifact_kind="production-candidate")
                self.assertEqual([i["name"] for i in result["images"]], ["admin"])
        for index, value in enumerate(("preview", "amd64", "OLDTEST-NODE", 686, self.spec["node_sha"])):
            args = ["production-candidate", "arm64", H["ADMIN_CHANGE"], 685, H["ADMIN_SOURCE"]]
            args[index] = value
            with self.subTest(args=args), self.assertRaises(pipeline.artifact.ArtifactError):
                pipeline.artifact.validate_admin_only(*args)

    def test_admin_readiness_isolated_no_api_agency_or_providers(self):
        with patch.object(pipeline.readiness.subprocess, "run") as run:
            pipeline.readiness.admin_image_checks("candidate-admin")
        run.assert_called_once()
        command = run.call_args.args[0]
        self.assertEqual(command[:7], ["docker", "run", "--rm", "--read-only", "--network", "none", "--env"])
        self.assertIn("v22.23.5", command[-1])
        self.assertIn("arm64", command[-1])
        self.assertIn(pipeline.readiness.FRONTEND_CHECK, command[-1])

    def test_workflow_admin_branch_builds_only_admin(self):
        workflow = (ROOT / ".github/workflows/platform-production-arm64-artifact.yml").read_text()
        steps = workflow.split("      - name: ")
        builds = [s for s in steps if "docker build" in s]
        self.assertEqual(len(builds), 2)
        admin, = [s for s in builds if "if: inputs.image_mode == 'admin-only'" in s]
        self.assertIn("if: inputs.image_mode == 'agency'", next(s for s in builds if s != admin))
        script = "\n".join(line[10:] for line in admin.split("        run: |\n", 1)[1].splitlines())
        self.assertEqual(script.count("docker build"), 1)
        self.assertNotIn("api_image", script)
        self.assertNotIn("agency_image", script)
        with tempfile.TemporaryDirectory() as temp:
            folder = Path(temp)
            # Exercise the actual workflow shell with command recorders; no Docker or network.
            for name in ("docker", "python3", "git"):
                file = folder / name
                file.write_text('#!/bin/sh\nprintf "%s\\n" "$0 $*" >> "$CALLS"\n'
                                + ('echo linux/arm64\n' if name == "python3" else 'echo fixture\n'))
                file.chmod(0o755)
            env = dict(os.environ, PATH=str(folder), CALLS=str(folder / "calls"),
                       GITHUB_WORKSPACE=temp, RUNNER_TEMP=temp, CHANGE_ID=H["ADMIN_CHANGE"],
                       PR_NUMBER="685", SOURCE_SHA=H["ADMIN_SOURCE"])
            subprocess.run(["/bin/bash", "-e", "-o", "pipefail", "-c", script], env=env, check=True,
                           stdout=subprocess.DEVNULL)
            calls = (folder / "calls").read_text().splitlines()
            docker, = [c for c in calls if "/docker build " in c]
            self.assertIn("--file apps/admin/Dockerfile", docker)
            self.assertIn("--platform linux/arm64", docker)
            self.assertEqual(sum("preview_image_artifact.py" in c for c in calls), 4)


if __name__ == "__main__":
    unittest.main()
