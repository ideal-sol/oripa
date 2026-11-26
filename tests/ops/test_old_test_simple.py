import fcntl
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

from scripts.ops import old_test_deploy as canonical
from scripts.ops import old_test_simple as deploy
from scripts.ops import old_test_simple_source as source


OLD = "sha256:" + "0" * 64
NEW = "sha256:" + "1" * 64
SHA, HEAD, TREE = ("a" * 40, "b" * 40, "c" * 40)
FILE = "2026_11_24_000119_example.php"
ACTOR = {"type": "Bot", "id": 17}
STAMP = "2026-11-24T00:00:00Z"


class Github:
    main = SHA
    merged = True
    tree = TREE
    author = ACTOR
    conclusion = "success"

    def get(self, path):
        repo = "/".join(path.split("/")[2:4])
        if path.endswith("/branches/main"):
            return {"protected": True, "commit": {"sha": self.main}}
        if "/compare/" in path:
            return {"status": "ahead", "merge_base_commit": {"sha": SHA}}
        if "/pulls/" in path:
            return {"state": "closed", "merged": self.merged, "merge_commit_sha": SHA, "merged_at": STAMP,
                    "merged_by": ACTOR, "head": {"sha": HEAD, "repo": {"full_name": repo}},
                    "base": {"ref": "main", "repo": {"full_name": repo}}}
        if "/git/commits/" in path:
            sha = path.split("/")[-1]
            return {"sha": sha, "tree": {"sha": TREE if sha == SHA else self.tree}}
        if "/check-runs?" in path:
            runs = [{"head_sha": HEAD, "id": n, "name": check, "status": "completed",
                     "conclusion": self.conclusion, "started_at": STAMP,
                     "app": {"id": 15368, "slug": "github-actions", "owner": {"login": "github"}}}
                    for n, check in enumerate(sorted(source.REQUIRED), 1)]
            return {"total_count": len(runs), "check_runs": runs}
        if "/comments?" in path:
            platform = repo == source.PLATFORM
            marker = "oripa-self-review:v2" if platform else "luxe-pack-storefront-self-review:v1"
            proof = {"schema_version": "2.0" if platform else "1.0", "lane": "Strict Change", "head_sha": HEAD,
                     "merge_recommendation": "MERGE", "scope": "PASS", "secret_pii": "PASS",
                     "github_checks": {"passed": True}, "local_validation": [{"status": "PASS"}],
                     "findings": {"SEV-0": 0, "SEV-1": 0}, "created_at": STAMP}
            return [{"id": 9, "updated_at": STAMP, "user": self.author,
                     "body": f"<!-- {marker} -->\n\n```json\n{json.dumps(proof)}\n```\n"}]
        raise AssertionError(path)


def image(role):
    common = {"target": "old-test", "source": SHA, "role": role, "identity": NEW, "recipe": OLD,
              "repository": source.PLATFORM, "verified_local_identity": NEW, "contract": OLD}
    if role == "storefront":
        return {**common, "repository": source.SITE, "runtime": "next start",
                "dependencies_verified": True, "vendored_contract_verified": True}
    if role == "sms":
        return common
    return {**common, "workflow": deploy.WORKFLOW, "revision": SHA, "arch": "linux/amd64",
            "artifact_id": 5, "artifact_digest": NEW, "run_id": 3}


class Fixture:
    def __init__(self, targets, *, active=(), migrations=()):
        self.words = targets
        self.targets = deploy.selection(targets)
        workers = deploy.WORKERS if "api" in self.targets else set(self.targets) & deploy.WORKERS.keys()
        roles = {deploy.asset_role(t) for t in self.targets}
        repos = {source.SITE if r == "storefront" else source.PLATFORM for r in roles}
        self.plan = {"environment": "old-test", "session": "TEST-SESSION", "targets": self.targets,
                     "sources": {repo: {"pr": 1, "sha": SHA} for repo in repos}, "configuration": OLD,
                     "site_policy": "sha256:" + hashlib.sha256(b'{"operations":{}}').hexdigest(),
                     "contract": OLD, "recipes": dict.fromkeys(roles, OLD), "recovery": "reconcile-only",
                     "workers": {w: "active" if w in active else "inactive" for w in workers},
                     "migration": {"files": list(migrations), "writers": [], "backup": False},
                     "previous": dict.fromkeys(self.targets, OLD)}
        if "api" in self.targets:
            self.plan["previous"].update({w: OLD for w in active if deploy.WORKERS[w] in ("pin", "image")})
        self.live = self.plan["previous"].copy()
        self.approval = {"session": self.plan["session"], "decision": "GO", "plan": deploy.digest(self.plan),
                         "issued": 100, "expires": 1000}
        self.github = Github()
        self.calls, self.journal, self.sleeps = [], {}, []
        self.items = {role: image(role) for role in roles}
        self.unhealthy = None
        self.ledger = []
        self.timeout = False
        self.clock = 101

    def run(self):
        return deploy.deploy(self.plan, self.targets, self.approval, self.journal, lambda j: None,
                             self, lambda: None, lambda: self.clock, self.sleeps.append)

    def call(self, op, target="", data=None):
        self.calls.append((op, target))
        if op == "github-read":
            return self.github.get(data)
        if op == "preflight":
            return {"environment": "old-test", "configuration": OLD, "contract": OLD, "workers": self.plan["workers"],
                    "identities": self.live.copy(), "space": True, "inodes": True, "provider_fences": True,
                    "compatible": True, "canonical_idle": True}
        if op == "worker-states":
            return self.plan["workers"].copy()
        if op in ("find-asset", "verify-retained"):
            return self.items.get(target)
        if op == "security":
            return {"result": "PASS", "binding": data, "measured": 101, "expires": 999}
        if op == "migration-inventory":
            return {"ledger": self.ledger.copy(), "inventory": [FILE], "source": SHA,
                    "destructive": False, "side_effects_known": True}
        if op == "observe":
            effect = data["operation"]
            if effect == "switch":
                return "done" if self.live[target] == NEW else "ready"
            if effect == "migrate":
                return "done" if target in self.ledger else "ready"
            if effect == "prepare-build":
                return "done" if all(role in self.items for role in data["input"]) else "ready"
        if op == "migrate":
            if self.timeout:
                raise subprocess.CalledProcessError(17, "private-command")
            self.ledger.append(target)
        elif op == "prepare-build":
            self.items.update({role: image(role) for role in data})
        elif op == "switch":
            self.live[target] = data
        elif op == "health":
            if target == self.unhealthy:
                return {"starting": True}
            if target in deploy.WORKERS:
                return {"state": "active", "process_ok": True, "business_result": "expired"}
            return {"internal": 200, "external": 401, "tls": True, "challenge": 'Basic realm="Restricted"',
                    "proxy_route": True, "checks": dict.fromkeys(("app", "db", "redis", "storage"), "ok")}
        else:
            raise AssertionError(op)


class SimpleDeploymentTest(unittest.TestCase):
    def setUp(self):
        block = mock.patch("subprocess.run", side_effect=AssertionError("unexpected live command"))
        block.start()
        self.addCleanup(block.stop)

    def test_scope(self):
        for args in (["new-production"], ["db"], ["docker", "ps"], ["api;id"], ["worker", "nginx.service"],
                     ["all", "sms"], ["admin", "admin"], ["worker", "../sms"]):
            with self.subTest(args=args), self.assertRaises(deploy.Hold):
                deploy.selection(args)
        for args in (["api"], ["admin"], ["storefront"], ["api", "storefront"], ["all"], ["worker", "sms"]):
            f = Fixture(args, active=("sms", "scheduled-publish", "d4"))
            f.run()
            selected = {t for op, t in f.calls if op == "switch"}
            expected = set(f.targets)
            if "api" in expected:
                expected.add("d4")
            self.assertEqual(selected, expected)
            self.assertNotIn(("migration-inventory", "api"), f.calls)
        with self.assertRaisesRegex(deploy.Hold, "worker_stopped"):
            Fixture(["worker", "line-sender"]).run()

    def test_preflight_and_approval(self):
        for field, value in (("environment", "production"), ("configuration", NEW), ("unrestricted_sudo", True)):
            f = Fixture(["api"])
            f.plan[field] = value
            with self.assertRaises(deploy.Hold):
                f.run()
            self.assertFalse(f.calls)
        f = Fixture(["api"])
        f.clock = 1000
        with self.assertRaisesRegex(deploy.Hold, "expired"):
            f.run()
        self.assertEqual(f.calls, [])

    def test_source_proof(self):
        g = Github()
        proof = source.merged_source(source.PLATFORM, 1, SHA, g.get)
        g.main = "d" * 40
        self.assertEqual(proof, source.merged_source(source.PLATFORM, 1, SHA, g.get))
        for key, val in (("author", {"id": 2}), ("merged", False), ("tree", HEAD), ("conclusion", "skipped")):
            g = Github()
            setattr(g, key, val)
            with self.subTest(key=key), self.assertRaises(deploy.Hold):
                source.merged_source(source.PLATFORM, 1, SHA, g.get)

    def test_bad_artifact(self):
        for key, value in (("target", "production"), ("arch", "linux/arm64"), ("source", HEAD),
                           ("workflow", "preview"), ("verified_local_identity", OLD), ("recipe", NEW)):
            f = Fixture(["api"])
            f.items["api"][key] = value
            with self.assertRaises(deploy.Hold):
                f.run()
            self.assertFalse(any(op == "switch" for op, t in f.calls))

    def test_resume_build_and_only_failed_target(self):
        f = Fixture(["api", "storefront"])
        f.items.clear()
        f.unhealthy = "storefront"
        with self.assertRaisesRegex(deploy.Hold, "timeout"):
            f.run()
        self.assertEqual(f.journal["results"], {"api": "PASS", "storefront": "FAIL"})
        self.assertEqual(len(f.sleeps), 11)
        f.unhealthy = None
        f.calls.clear()
        f.run()
        self.assertEqual(f.journal["results"]["storefront"], "PASS")
        self.assertFalse(any(op in ("switch", "prepare-build", "github-read") for op, t in f.calls))
        self.assertNotIn(("health", "api"), f.calls)

    def test_combined_build_and_admin_order(self):
        f = Fixture(["all"])
        f.items.clear()
        f.run()
        builds = [set(t.split("+")) for op, t in f.calls if op == "prepare-build"]
        self.assertEqual(builds, [{"api", "admin"}, {"storefront"}])
        self.assertLess(f.calls.index(("health", "admin")), f.calls.index(("switch", "api")))

    def test_acquisition_outside_runtime_lock_and_recheck_before_switch(self):
        for drift in (False, True):
            with self.subTest(drift=drift):
                fixture = Fixture(["admin"])
                fixture.items.clear()
                locked = False
                operations = []
                original = fixture.call

                def acquire():
                    nonlocal locked
                    locked = True
                    if drift:
                        fixture.live["admin"] = "sha256:" + "c" * 64
                    return lambda: None

                def observe(operation, target="", data=None):
                    operations.append((operation, locked))
                    return original(operation, target, data)

                fixture.call = observe
                def run():
                    deploy.deploy(fixture.plan, fixture.targets, fixture.approval, fixture.journal,
                                  lambda data: None, fixture, lambda: None, lambda: fixture.clock,
                                  fixture.sleeps.append, enter_runtime=acquire)

                if drift:
                    with self.assertRaisesRegex(deploy.Hold, "runtime_identity_changed"):
                        run()
                    self.assertNotIn(("switch", True), operations)
                else:
                    run()
                    self.assertIn(("verify-retained", True), operations)
                    self.assertIn(("switch", True), operations)
                self.assertIn(("preflight", False), operations)
                self.assertIn(("preflight", True), operations)
                self.assertIn(("prepare-build", False), operations)
                self.assertNotIn(("github-read", True), operations)

    def test_ledger_and_ambiguous_failure(self):
        f = Fixture(["api"], migrations=[FILE])
        f.timeout = True
        with self.assertRaises(subprocess.CalledProcessError):
            f.run()
        error = f.journal["error"]
        self.assertEqual((error["step"], error["exit"], error["class"]), ("migrate", 17, "CalledProcessError"))
        self.assertGreater(error["line"], 0)
        f.timeout = False
        with self.assertRaisesRegex(deploy.Hold, "reconciliation"):
            f.run()
        self.assertEqual(f.calls.count(("migrate", FILE)), 1)
        f.ledger.append(FILE)
        f.run()
        self.assertEqual(f.calls.count(("migrate", FILE)), 1)
        self.assertIn(("switch", "api"), f.calls)
        with self.assertRaisesRegex(deploy.Hold, "ledger_unknown"):
            deploy.migration_suffix([FILE], ["other"], [FILE])

    def test_worker_timer_and_basic(self):
        self.assertFalse(deploy.health("scheduled-publish", {"state": "active", "process_ok": True,
                         "next": "infinity", "oneshot_running": True}))
        with self.assertRaisesRegex(deploy.Hold, "unscheduled"):
            deploy.health("scheduled-publish", {"state": "active", "process_ok": True, "next": "infinity"})
        f = Fixture(["api"])
        good = f.call("health", "api")
        self.assertTrue(deploy.health("api", good))
        for k, v in (("tls", False), ("internal", 503), ("external", 200), ("challenge", "Bearer"),
                     ("proxy_route", False), ("checks", {})):
            with self.assertRaises(deploy.Hold):
                deploy.health("api", {**good, k: v})

    def test_canonical_lock(self):
        self.assertIn(str(deploy.LOCK), Path(canonical.__file__).read_text())
        with tempfile.TemporaryDirectory() as d, mock.patch.object(deploy, "trusted"):
            lock = Path(d) / "common"
            lock.touch()
            with lock.open("rb") as old:
                fcntl.flock(old, fcntl.LOCK_EX | fcntl.LOCK_NB)
                with self.assertRaisesRegex(deploy.Hold, "lock_busy"), deploy.runtime_lock(lock):
                    self.fail("overlapping deployments")
            with deploy.runtime_lock(lock) as check:
                check()

    def test_http_probes_local_route_no_credentials(self):
        headers = b'HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Basic realm="Restricted"\r\n\r\n\n401 0'
        body = json.dumps(dict.fromkeys(("app", "db", "redis", "storage"), "ok")).encode()
        run = mock.Mock(side_effect=[subprocess.CompletedProcess([], 0, body + b"\n200 0"),
                                     subprocess.CompletedProcess([], 0, headers)])
        route = {"listener_owned": True, "proxy_route": True}
        self.assertTrue(deploy.health("api", deploy.http_health("api", route, run)))
        cmd = run.call_args.args[0]
        self.assertIn("test.luxe-pack.biz:443:127.0.0.1", cmd)
        self.assertNotIn("--user", cmd)
        self.assertNotIn("--insecure", cmd)

    def test_switch_staged_systemd(self):
        spec = {"plan": OLD, "environment": "old-test", "target": "storefront", "identity": NEW,
                "old_unit": "luxe-pack-storefront-old-test-pr1.service",
                "new_unit": "luxe-pack-storefront-old-test-pr2.service", "staged_health": True,
                "release_directory": "/test/release", "build_id": "new", "fallback_start_verified": True}
        staged = {"Id": spec["new_unit"], "ActiveState": "inactive", "WorkingDirectory": spec["release_directory"],
                  "User": "luxe-pack-storefront-preview"}
        with mock.patch.object(canonical, "unit", side_effect=[staged, {"ActiveState": "active"}]), \
                mock.patch.object(deploy, "storefront_layout") as verify, \
                mock.patch.object(canonical, "run") as run:
            deploy.switch_service("storefront", NEW, "TEST-SESSION", OLD, spec, lambda: None)
        verify.assert_called_once_with(spec["release_directory"], NEW, "new")
        self.assertEqual(run.call_args_list, [mock.call(["systemctl", "stop", spec["old_unit"]]),
                                              mock.call(["systemctl", "start", spec["new_unit"]])])
        with self.assertRaisesRegex(deploy.Hold, "unit_mismatch"):
            deploy.switch_service("storefront", NEW, "TEST-SESSION", OLD, {**spec, "new_unit": "postgres.service"}, lambda: None)

    def test_staged_dependency_links_and_build_binding(self):
        with tempfile.TemporaryDirectory() as d, mock.patch.object(deploy, "trusted"):
            release = Path(d)
            (release / ".next").mkdir()
            (release / ".next/BUILD_ID").write_text("one")
            (release / "artifact-manifest.json").write_bytes(b"fixture")
            identity = "sha256:" + hashlib.sha256(b"fixture").hexdigest()
            pkg = release / "node_modules/.pnpm/pkg/next"
            (pkg / "dist/bin").mkdir(parents=True)
            (pkg / "dist/bin/next").touch()
            link = release / "node_modules/next"
            link.symlink_to(pkg)
            deploy.storefront_layout(release, identity, "one")
            with self.assertRaisesRegex(deploy.Hold, "release_identity"):
                deploy.storefront_layout(release, identity, "two")
            link.unlink()
            pkg.rename(release / "outside")
            link.symlink_to(release / "outside")
            with self.assertRaisesRegex(deploy.Hold, "dependency_layout"):
                deploy.storefront_layout(release, identity, "one")

    def test_api_rebinds_only_active_pins_and_records_effect_scope(self):
        running = ("d4", "f2b-purge", "univapay-monitor", "member-rank", "sms", "prize-exchange")
        f = Fixture(["api"], active=running)
        f.run()
        targets = [t for op, t in f.calls if op == "switch"]
        self.assertEqual(set(targets), {"api", "d4", "f2b-purge", "univapay-monitor", "member-rank"})
        self.assertEqual(set(f.journal["security"]), {"switch/" + t for t in targets})
        self.assertTrue(deploy.health("member-rank", {"state":"active", "process_ok":True, "warning":"known"}))
        with self.assertRaisesRegex(deploy.Hold, "worker_unhealthy"):
            deploy.health("univapay-monitor", {"state":"active", "process_ok":False, "warning":"known"})

    def test_registered_app_read_scope_and_source_proof(self):
        site = deploy.Site(Fixture(["api"]).plan)
        site.guard = lambda: None
        prefix = "/repos/" + source.PLATFORM + "/"
        def read(op, repo, path):
            self.assertEqual((op, repo), ("github-read", source.PLATFORM))
            return Github().get(path)
        with mock.patch.object(site, "private_operation", side_effect=read) as transport:
            proof = source.merged_source(source.PLATFORM, 1, SHA, lambda path: site.call("github-read", data=path))
            self.assertEqual(proof["source_sha"], SHA)
            transport.reset_mock()
            for path in (prefix + "actions/workflows", prefix + "contents/.env?ref=" + SHA,
                         prefix + "contents/../other?ref=" + SHA, "/repos/" + source.SITE + "/branches/main"):
                with self.assertRaises(deploy.Hold):
                    site.call("github-read", data=path)
            transport.assert_not_called()

    def test_approval_bound_dispatch_and_artifact_admission(self):
        f = Fixture(["api", "admin"])
        site = deploy.Site(f.plan)
        site.guard = lambda: None
        with mock.patch.object(site, "private_operation") as enrolled:
            site.call("prepare-build", "api+admin", ["api", "admin"])
            enrolled.assert_called_once_with("ensure-formal-build", "api+admin", {
                "ref": "main", "workflow": deploy.WORKFLOW,
                "inputs": {"task_id": f.plan["session"], "source_sha": SHA, "pr_number": 1,
                           "simple_mode": "normal", "simple_plan": deploy.digest(f.plan)}})
            with self.assertRaisesRegex(deploy.Hold, "build_scope_mismatch"):
                site.call("prepare-build", "admin", ["api"])
        env = {"SIMPLE_ENABLED": "true", "SIMPLE_ISSUER_ID": "27", "GITHUB_ACTOR_ID": "27",
               "GITHUB_RUN_ATTEMPT": "1", "SIMPLE_PLAN": NEW}
        self.assertEqual(source.dispatch_plan(env), NEW)
        for key, value in (("SIMPLE_ENABLED", ""), ("GITHUB_ACTOR_ID", "28"), ("GITHUB_RUN_ATTEMPT", "2"),
                           ("SIMPLE_PLAN", "")):
            with self.assertRaisesRegex(deploy.Hold, "approved_dispatch_required"):
                source.dispatch_plan({**env, key: value})
        a = source.build_authority("TEST-SESSION", "1", SHA, HEAD, "api-only", Github().get)
        a.update(run_id=3, run_attempt=1, plan=NEW)
        record = {"expired": False, "digest": NEW, "id": 1, "workflow_run": {"id": 3},
                  "name": f"oripa-old-test-simple-images-TEST-SESSION-{SHA}"}
        run = {"id": 3, "run_attempt": 1, "head_sha": HEAD, "repository": {"full_name": source.PLATFORM},
               "head_branch": "main", "event": "workflow_dispatch", "path": deploy.WORKFLOW,
               "status": "completed", "conclusion": "success"}
        verified = {"status": "verified", "task_id": "TEST-SESSION", "source_commit": SHA, "pull_request": 1,
                    "artifact_kind": "old-test", "platform": "linux/amd64", "images": [{"name": "api", "image_id": NEW}]}
        def admit(r=record, w=run, authority=a):
            return source.validate_download(r, w, authority, verified, NEW, "api", b"recipe", [TREE] * 4, OLD)
        self.assertEqual(admit()["identity"], NEW)
        self.assertEqual(admit()["recipe"], admit(w={**run, "head_sha": TREE}, authority={**a, "control_sha": TREE})["recipe"])
        self.assertNotEqual(admit()["recipe"], source.recipe_identity("api", b"changed", [TREE] * 4))
        for field, value in (("name", "pr-ci"), ("expired", True), ("digest", OLD)):
            with self.assertRaises(deploy.Hold):
                admit(r={**record, field: value})
        for field, value in (("head_branch", "draft"), ("head_sha", TREE), ("conclusion", "failure"),
                             ("event", "pull_request")):
            with self.assertRaises(deploy.Hold):
                admit(w={**run, field: value})

    def test_security_result_bound_to_role_and_session(self):
        for changes in ({"binding": {}}, {"result": "FAIL"}, {"expires": 101}, {"measured": 200}):
            f = Fixture(["admin"])
            real = f.call
            def call(op, target="", data=None):
                value = real(op, target, data)
                return {**value, **changes} if op == "security" else value
            f.call = call
            with self.assertRaisesRegex(deploy.Hold, "security_binding_failed"):
                f.run()
            self.assertNotIn(("switch", "admin"), f.calls)

    def test_partial_migration_resume(self):
        f = Fixture(["api"], migrations=[FILE])
        f.ledger = [FILE]
        f.run()
        self.assertNotIn(("migrate", FILE), f.calls)
        self.assertEqual(f.journal["completed"]["migrate/" + FILE], "PASS")
        f = Fixture(["api"], migrations=[FILE])
        f.run()
        self.assertLess(f.calls.index(("migrate", FILE)), f.calls.index(("switch", "api")))
        self.assertNotIn(("backup", "api"), f.calls)

    def test_image_switch_targets_only_existing_compose_service(self):
        proj = "mig061a-v2-preview"
        net = proj + "_v2_private"
        config = {"services": {"admin": {"image": OLD, "networks": {"private": {}}, "environment": {}}},
                  "networks": {"private": {"name": net}}}
        config_bytes = json.dumps(config).encode()
        new_config = json.loads(config_bytes)
        new_config["services"]["admin"]["image"] = NEW
        old = {"Id": HEAD, "Image": OLD, "State": {"Running": True}, "Config": {"Env": [],
               "Labels": {"com.docker.compose.project": proj, "com.docker.compose.service": "admin"}},
               "NetworkSettings": {"Networks": {net: {}}}}
        spec = {"target": "admin", "environment": "old-test", "identity": NEW, "plan": OLD, "container": HEAD,
                "previous": OLD, "compose_digest": "sha256:" + hashlib.sha256(config_bytes).hexdigest()}
        with tempfile.TemporaryDirectory() as d, mock.patch.object(deploy, "SESSIONS", Path(d)), \
                mock.patch.object(canonical, "inspect", return_value=old), \
                mock.patch.object(canonical, "compose", return_value=(["docker", "compose"], {})), \
                mock.patch.object(canonical, "run", return_value=config_bytes), \
                mock.patch.object(canonical, "decoded", side_effect=[new_config, [{"Config": {"Env": []}}]]), \
                mock.patch.object(canonical.Deployment, "readiness", return_value=True), \
                mock.patch("subprocess.run") as run:
            (Path(d) / "TEST-SESSION").mkdir()
            deploy.switch_service("admin", NEW, "TEST-SESSION", OLD, spec, lambda: None)
        run.assert_called_once()
        argv = run.call_args.args[0]
        self.assertEqual(argv[-7:], ["up", "-d", "--no-build", "--no-deps", "--pull", "never", "admin"])

    def test_current_target_remeasured_after_expiry(self):
        f = Fixture(["admin"])
        real = f.call
        def call(op, target="", data=None):
            value = real(op, target, data)
            if op == "security":
                return {**value, "measured": f.clock, "expires": f.clock + 2}
            if op == "observe":
                f.clock += 3
            return value
        f.call = call
        f.run()
        self.assertGreaterEqual(f.calls.count(("security", "admin")), 2)
        self.assertEqual(f.calls.count(("switch", "admin")), 1)

    def test_server_source_entry_is_inactive(self):
        with self.assertRaisesRegex(deploy.Hold, "installed_old_test_entry_required"):
            deploy.main(["admin", "--session", "TEST-SESSION"])

    def test_enrolled_site_operation_and_tamper_rejection(self):
        with tempfile.TemporaryDirectory() as d, mock.patch.object(deploy, "trusted"), \
                mock.patch.object(deploy, "PROCEDURES", Path(d)), \
                mock.patch.object(deploy, "BINDINGS", Path(d) / "bindings.json"):
            path = Path(d) / "sensors.py"
            path.write_text("def observe(plan, target, data, check):\n    check()\n    return plan['workers']\n")
            policy = {"environment": "old-test", "operations": {"worker-states": {"module": "sensors.py",
                      "function": "observe", "sha256": "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()}}}
            deploy.BINDINGS.write_text(json.dumps(policy))
            f = Fixture(["api"])
            f.plan["site_policy"] = "sha256:" + hashlib.sha256(deploy.BINDINGS.read_bytes()).hexdigest()
            site = deploy.Site(f.plan)
            with self.assertRaisesRegex(deploy.Hold, "site_session_required"):
                site.call("worker-states")
            site.guard = mock.Mock()
            self.assertEqual(site.call("worker-states"), f.plan["workers"])
            with self.assertRaisesRegex(deploy.Hold, "unknown_site_operation"):
                site.call("sh")
            path.write_text("raise AssertionError('unreviewed')\n")
            with self.assertRaisesRegex(deploy.Hold, "site_procedure_changed"):
                site.call("worker-states")
            deploy.BINDINGS.write_text("{}")
            with self.assertRaisesRegex(deploy.Hold, "site_policy_changed"):
                site.call("worker-states")

    def test_cli_preparation_go_and_reentry(self):
        f = Fixture(["admin"])
        def call(operation, target="", data=None):
            return f.plan if operation == "propose" else f.call(operation, target, data)
        with tempfile.TemporaryDirectory() as d, mock.patch.object(deploy, "SESSIONS", Path(d)), \
                mock.patch.object(deploy, "runtime_lock", return_value=mock.MagicMock()), \
                mock.patch.object(deploy, "trusted"), mock.patch.object(deploy, "installed_entry"), \
                mock.patch.object(deploy, "BINDINGS", Path(d) / "bindings.json"), \
                mock.patch.object(deploy, "canonical_idle"), mock.patch.object(deploy.Site, "call") as site_call, \
                mock.patch("builtins.print"):
            site_call.side_effect = call
            deploy.BINDINGS.write_bytes(b'{"operations":{}}')
            args = ["admin", "--session", "TEST-SESSION"]
            deploy.main(args + ["--prepare", "--platform-source", "1@" + SHA, "--contract", OLD])
            folder = Path(d) / "TEST-SESSION"
            self.assertEqual(json.loads((folder / "plan.json").read_bytes()), f.plan)
            with self.assertRaisesRegex(deploy.Hold, "human_go_missing"):
                deploy.main(args)
            now = int(deploy.time.time())
            approval = {**f.approval, "issued": now - 1, "expires": now + 100}
            deploy.persist(folder / "go.json", approval)
            def measured(operation, target="", data=None):
                value = call(operation, target, data)
                if operation == "security":
                    value.update(measured=now, expires=now + 100)
                return value
            site_call.side_effect = measured
            deploy.main(args)
            deploy.main(args)
            self.assertEqual(f.calls.count(("switch", "admin")), 1)
            self.assertEqual(json.loads((folder / "journal.json").read_bytes())["results"], {"admin": "PASS"})
