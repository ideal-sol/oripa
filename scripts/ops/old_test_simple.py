"""Targeted OLD Test deployment. The CLI accepts target names, never host commands."""
from __future__ import annotations

import argparse
import base64
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import runpy
import stat
import subprocess
import sys
import tempfile
import time
import traceback

if __package__:
    from .old_test_simple_source import Hold, PLATFORM, SHA, SITE, ROOT, merged_source, require, validate_download
else:
    from old_test_simple_source import Hold, PLATFORM, SHA, SITE, ROOT, merged_source, require, validate_download


SERVICES = ("api", "admin", "storefront")
# An API exec oneshot picks up the new API automatically. Only a running
# separate image consumer or a container-ID pin needs explicit rebinding.
WORKERS = {"sms": "independent", "line-sender": "image", "d4": "pin",
           "member-rank": "image", "univapay-receipts": "pin", "univapay-monitor": "pin", "paidy": "exec",
           "f2b-worker": "pin", "f2b-purge": "pin", "prize-exchange": "exec", "scheduled-publish": "exec",
           "contact-reply": "image", "identity-mail": "image"}
SESSION = re.compile(r"[A-Z][A-Z0-9-]{2,31}")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}")
MIGRATION = re.compile(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+(?:\.php)?")
LOCK = Path("/run/lock/oripa-v2-preview-deployment.lock")
SESSIONS = Path("/var/lib/oripa-old-test-simple")
BINDINGS = Path("/etc/oripa-old-test-simple/bindings.json")
PROCEDURES = Path("/usr/local/lib/oripa-old-test-simple/private")
WORKFLOW = ".github/workflows/old-test-main-artifact.yml"


def selection(words):
    if words == ["all"]:
        return list(SERVICES)
    if len(words) == 2 and words[0] == "worker" and words[1] in WORKERS:
        return [words[1]]
    require(words and len(set(words)) == len(words) and set(words) <= set(SERVICES), "unknown_target")
    return [role for role in SERVICES if role in words]


def digest(value):
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()
    return "sha256:" + hashlib.sha256(raw).hexdigest()


def shape(plan, targets):
    require(set(plan) == {"environment", "session", "targets", "sources", "contract", "recipes",
                          "previous", "configuration", "site_policy", "workers", "migration", "recovery"}
            and plan["environment"] == "old-test" and plan["targets"] == targets
            and SESSION.fullmatch(plan["session"]), "plan_scope_mismatch")
    workers = plan["workers"]
    expected_workers = set(WORKERS) if "api" in targets else set(targets) & WORKERS.keys()
    require(set(workers) == expected_workers and all(state in ("active", "inactive")
            for state in workers.values()), "worker_inventory_mismatch")
    affected = list(targets)
    if "api" in targets:
        affected = [s for s in ("admin", "api") if s in targets]
        affected.extend(w for w in WORKERS if workers[w] == "active" and WORKERS[w] in ("image", "pin"))
        if "storefront" in targets:
            affected.append("storefront")
    require(all(workers[w] == "active" for w in affected if w in WORKERS), "worker_stopped")
    roles = list(dict.fromkeys(asset_role(t) for t in affected))
    require(set(plan["previous"]) == set(affected) and set(plan["recipes"]) == set(roles)
            and all(DIGEST.fullmatch(x) for x in [*plan["recipes"].values(), *plan["previous"].values(),
                                                  plan["configuration"], plan["site_policy"], plan["contract"]]), "invalid_binding")
    repos = {SITE if r == "storefront" else PLATFORM for r in roles}
    require(set(plan["sources"]) == repos, "source_scope_mismatch")
    for src in plan["sources"].values():
        require(set(src) == {"pr", "sha"} and type(src["pr"]) is int and src["pr"] > 0
                and SHA.fullmatch(src["sha"]), "invalid_source")
    migration = plan["migration"]
    require(set(migration) == {"files", "writers", "backup"}
            and type(migration["backup"]) is bool and isinstance(migration["files"], list)
            and all(MIGRATION.fullmatch(f) and f.endswith(".php") for f in migration["files"])
            and migration["files"] == sorted(set(migration["files"]))
            and isinstance(migration["writers"], list)
            and len(set(migration["writers"])) == len(migration["writers"])
            and all(w in expected_workers and workers[w] == "active" for w in migration["writers"]),
            "migration_scope_mismatch")
    require(("api" in targets or not migration["files"]) and
            (migration["files"] or not (migration["backup"] or migration["writers"])), "migration_scope_mismatch")
    require(plan["recovery"] == "reconcile-only", "recovery_scope_mismatch")
    return affected, roles


def asset_role(target):
    # Independently packaged workers retain their own approved build. API exec
    # and pin consumers refer to the accepted API, including worker-only plans.
    if target in WORKERS and WORKERS[target] != "independent":
        return "api"
    return target


def asset(plan, role, item):
    repo = SITE if role == "storefront" else PLATFORM
    fields = {"target", "role", "repository", "source", "recipe", "contract", "identity", "verified_local_identity"}
    if role in ("api", "admin"):
        fields.update(("workflow", "arch", "revision", "artifact_id", "run_id", "artifact_digest"))
    if role == "storefront":
        fields.update(("runtime", "dependencies_verified", "vendored_contract_verified"))
    require(isinstance(item, dict) and set(item) == fields, "invalid_asset_record")
    require(item["target"] == "old-test" and item["role"] == role and item["repository"] == repo
            and item["source"] == plan["sources"][repo]["sha"] and item["recipe"] == plan["recipes"][role]
            and item["contract"] == plan["contract"] and DIGEST.fullmatch(item["identity"])
            and item["verified_local_identity"] == item["identity"], "asset_identity_mismatch")
    if role in ("api", "admin"):
        require(item["workflow"] == WORKFLOW and item["arch"] == "linux/amd64"
                and item["revision"] == item["source"] and type(item["artifact_id"]) is int
                and item["artifact_id"] > 0 and type(item["run_id"]) is int and item["run_id"] > 0
                and DIGEST.fullmatch(item["artifact_digest"]), "formal_image_required")
    elif role == "storefront":
        require(item["runtime"] == "next start" and item["dependencies_verified"] is True
                and item["vendored_contract_verified"] is True, "storefront_bundle_invalid")
    return item["identity"]


def migration_suffix(source_inventory, ledger, approved):
    require(isinstance(source_inventory, list) and isinstance(ledger, list)
            and source_inventory == sorted(set(source_inventory))
            and all(MIGRATION.fullmatch(f) and f.endswith(".php") for f in source_inventory)
            and source_inventory[:len(ledger)] == ledger, "ledger_unknown")
    pending = source_inventory[len(ledger):]
    require(len(pending) <= len(approved) and (not pending or pending == approved[-len(pending):])
            and (not approved or source_inventory[-len(approved):] == approved), "unexpected_migration")
    return pending


def health(target, reading):
    if target in WORKERS:
        require(reading["state"] == "active" and reading["process_ok"] is True, "worker_unhealthy")
        if reading.get("next") == "infinity":
            require(reading.get("oneshot_running") is True, "timer_unscheduled")
            return False
        return True
    if reading.get("starting") is True:
        return False
    require(reading["internal"] == 200 and reading["external"] == 401 and reading["tls"] is True
            and reading["proxy_route"] is True
            and reading["challenge"] == 'Basic realm="Restricted"', "http_health_failed")
    if target == "api":
        require(all(reading["checks"].get(k) == "ok" for k in ("app", "db", "redis", "storage")),
                "api_dependency_unhealthy")
    return True


def trusted(path, *, private=False, directory=False):
    info = path.lstat()
    require((stat.S_ISDIR if directory else stat.S_ISREG)(info.st_mode) and info.st_uid == 0
            and not info.st_mode & (0o077 if private else 0o022), "untrusted_path")


@contextlib.contextmanager
def runtime_lock(path=LOCK, *, session_directory=False):
    trusted(path, directory=session_directory, private=session_directory)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | (os.O_DIRECTORY if session_directory else 0))
    try:
        for attempt in range(3):
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if session_directory or attempt == 2:
                    raise Hold("session_lock_busy" if session_directory else "deployment_lock_busy") from None
                time.sleep(0.25)

        def check():
            trusted(path, directory=session_directory, private=session_directory)
            require(os.path.samestat(os.fstat(fd), path.lstat()), "deployment_lock_replaced")
        check()
        yield check
    finally:
        os.close(fd)


def installed_entry():
    require(sys.flags.isolated and sys.flags.no_site and sys.flags.dont_write_bytecode
            and ROOT == Path("/usr/local/lib/oripa-old-test-simple")
            and os.environ.get("DOCKER_HOST") == "unix:///var/run/docker.sock"
            and not os.environ.get("DOCKER_CONTEXT"), "installed_old_test_entry_required")
    for directory in (ROOT, *ROOT.parents):
        trusted(directory, directory=True)
    for directory in (BINDINGS.parent, *BINDINGS.parent.parents, PROCEDURES):
        trusted(directory, directory=True)
    trusted(BINDINGS, private=True)


def canonical_idle():
    entry = Path("/etc/oripa-old-safe-deploy/entry.json")
    trusted(entry)
    require(json.loads(entry.read_bytes())["execution"] == "disabled", "canonical_entry_active")


def persist(path, data):
    # Caller holds the common lock in a private session directory. An intent
    # must reach disk before its effect can start, even after a host crash.
    fd, name = tempfile.mkstemp(dir=path.parent, prefix=".journal-")
    try:
        with os.fdopen(fd, "w") as stream:
            json.dump(data, stream, sort_keys=True, allow_nan=False)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(name, path)
        fd = os.open(path.parent, os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)
    finally:
        Path(name).unlink(missing_ok=True)


def php_measurement(plan, scope, material, directory, guard, authorize_request):
    """Project one authorized effect into the native verification-only Gate ABI."""
    def pinned(ref):
        path = Path(ref["path"])
        for parent in path.parents:
            trusted(parent, directory=True)
        trusted(path)
        raw = path.read_bytes()
        require(hashlib.sha256(raw).hexdigest() == ref["sha256"], "gate_input_changed")
        return raw

    artifact = material["artifact"]
    require(scope["plan"] == digest(plan) and scope["source"] == plan["sources"][PLATFORM]["sha"]
            and (artifact["id"], artifact["digest"]) == (scope["artifact_id"], scope["artifact_digest"])
            and artifact["authority"]["source_sha"] == scope["source"] and artifact["files"]
            and material["evidence"], "gate_artifact_mismatch")
    recipe = json.loads(pinned(material["recipe"]))
    require(recipe["source_sha"] == scope["source"] and recipe["mode"] == "canonical", "gate_recipe_mismatch")
    policy = json.loads(pinned(material["policy"]))
    gate_ref = material["gate"]
    require(policy["gate_sha256"] == gate_ref["sha256"], "gate_policy_mismatch")
    gate_module = {"__file__": gate_ref["path"], "__name__": "old_test_php_measurement"}
    exec(compile(pinned(gate_ref), gate_ref["path"], "exec"), gate_module)
    role = ("migration" if scope["operation"] == "migrate" else
            "worker" if scope["target"] in WORKERS else "api")
    native_op = "worker" if role == "worker" else "apply"
    with tempfile.TemporaryDirectory(dir=directory) as temporary:
        folder = Path(temporary)
        def record(name, value):
            raw = json.dumps(value, sort_keys=True).encode()
            path = folder / name
            fd = os.open(path, os.O_WRONLY | os.O_EXCL | os.O_CREAT, 0o600)
            with os.fdopen(fd, "wb") as stream:
                stream.write(raw)
            return {"path": str(path), "sha256": hashlib.sha256(raw).hexdigest()}
        projection = {"target": "old-test", "task_id": plan["session"], "scope": scope,
                      "artifact": artifact, "images": {"api": {"image_id": scope["identity"]}},
                      "state": {"phase": "prepared"}}
        request = {"schema": 1, "target": "old-test", "mode": "canonical", "operation": native_op,
                   "observed_at": time.time(), "expires_at": scope["deadline"], "runtime_authorized": False,
                   "candidate": record("selection.json", projection), "candidate_phase": "prepared",
                   "candidate_identity": gate_module["identity"](projection),
                   "artifact": {k: artifact[k] for k in ("id", "digest", "authority")},
                   "artifact_files": artifact["files"], "control_sha": artifact["authority"]["control_sha"],
                   "recipe": material["recipe"], "recipe_authority": recipe["authority_id"],
                   "evidence": material["evidence"], "images": [{"role": role, "source_sha": scope["source"],
                   "image_id": scope["identity"], "platform": "linux/amd64", "recipe_authority": recipe["authority_id"]}]}
        req = record("request.json", request)
        # Only the separately enrolled OLD Test authority can admit a request.
        # The caller checks its exact grant and cannot relax the policy template.
        guard()
        grant = authorize_request({"scope": scope, "request": req, "template": material["policy"]})
        granted = json.loads(pinned(grant))
        require(granted == {**policy, "verification_request_sha256": [req["sha256"]]},
                "gate_request_not_authorized")
        gate = gate_module["Gate"](grant["path"])
        guard()
        receipt = gate.verify(req["path"])
        gate.validate_receipt(req["path"], receipt)
        require(receipt["runtime"][role]["image_id"] == scope["identity"] and
                receipt["runtime_authorized"] is False and receipt["operation"] == native_op,
                "gate_receipt_mismatch")
        return {"result": "PASS", "binding": scope, "measured": receipt["observed_at"],
                "expires": receipt["expires_at"], "receipt_digest": digest(receipt)}


class Site:
    """Only the installed operator-owned routines can supply private site operations."""
    def __init__(self, plan):
        self.session = plan["session"]
        self.binding = digest(plan)
        self.plan = plan
        self.guard = None
        self.procedures = {}
        self.contract_verified = False

    def private_operation(self, operation, target, data):
        # There is no separate privileged CLI for these operations. Enrollment
        # is installation policy, outside every session and deployment input.
        require(self.guard is not None or operation == "propose", "site_session_required")
        trusted(BINDINGS, private=True)
        raw_registry = BINDINGS.read_bytes()
        require("sha256:" + hashlib.sha256(raw_registry).hexdigest() == self.plan["site_policy"],
                "site_policy_changed")
        registry = json.loads(raw_registry)
        require(registry["environment"] == "old-test" and operation in registry["operations"],
                "unregistered_site_operation")
        ref = registry["operations"][operation]
        require(set(ref) == {"module", "sha256", "function"}
                and re.fullmatch(r"[a-z][a-z0-9_]*\.py", ref["module"])
                and re.fullmatch(r"[a-z][a-z0-9_]*", ref["function"])
                and DIGEST.fullmatch(ref["sha256"]), "invalid_site_binding")
        trusted(PROCEDURES, directory=True)
        path = PROCEDURES / ref["module"]
        trusted(path)
        raw = path.read_bytes()
        require("sha256:" + hashlib.sha256(raw).hexdigest() == ref["sha256"], "site_procedure_changed")
        if ref["sha256"] not in self.procedures:
            namespace = {"__name__": "old_test_private", "__file__": str(path)}
            exec(compile(raw, str(path), "exec"), namespace)
            self.procedures[ref["sha256"]] = namespace
        handler = self.procedures[ref["sha256"]][ref["function"]]
        require(callable(handler) and getattr(handler, "__module__", "") == "old_test_private",
                "site_function_unavailable")
        plan_copy, input_copy = json.loads(json.dumps([self.plan, data]))
        return handler(plan_copy, target, input_copy, self.guard)

    def call(self, operation, target="", data=None):
        if self.guard:
            self.guard()
        if operation == "github-read":
            match = re.fullmatch(r"/repos/(ideal-sol/(?:oripa|luxe-pack-storefront))/(.+)", data)
            require(match and match[1] in self.plan["sources"], "invalid_repository")
            sha = r"[0-9a-f]{40}"
            page = r"(?:[1-9]|10)"
            content = (r"(?:package\.json|vendor/oripa/[A-Za-z0-9_-]+/artifact-manifest\.json)" if match[1] == SITE
                       else r"(?:\.github/workflows/old-test-main-artifact\.yml|infra/docker/backend/Dockerfile|"
                            r"apps/admin/Dockerfile|scripts/ops/(?:preview_image_artifact|old_test_simple_source|old_test_source_authority)\.py)")
            allowed = (r"branches/main", rf"compare/{sha}\.\.\.{sha}", rf"git/commits/{sha}",
                       r"pulls/[1-9][0-9]*", rf"commits/{sha}/check-runs\?filter=all&per_page=100&page={page}",
                       rf"issues/[1-9][0-9]*/comments\?per_page=100&page={page}",
                       r"actions/(?:artifacts|runs)/[1-9][0-9]*",
                       rf"contents/{content}\?ref={sha}")
            require(any(re.fullmatch(pattern, match[2]) for pattern in allowed), "github_read_scope")
            return self.private_operation("github-read", match[1], data)
        if operation == "switch" and target in SERVICES:
            # Private Gate remeasures compose/ENV, egress and unit ownership
            # against this GO immediately before the fixed, selected operation.
            spec = self.call("switch-binding", target, data)
            return switch_service(target, data, self.session, self.binding, spec, self.guard)
        if operation == "security" and target == "api":
            material = self.private_operation("php-gate-inputs", target, data)
            return php_measurement(self.plan, data, material, SESSIONS / self.session, self.guard,
                                   lambda req: self.private_operation("authorize-php-request", target, req))
        if operation in ("migrate", "backup"):
            return self.database_step(operation, target, data)
        if operation == "verify-retained" and target in ("api", "admin"):
            asset(self.plan, target, data)
            require(self.local_image(target, data) == data["identity"], "verified_asset_lost")
            return data
        if operation in ("drain", "resume") or (operation == "switch" and target in WORKERS):
            require(target in WORKERS and self.plan["workers"].get(target) == "active", "worker_stopped")
            if operation in ("drain", "resume"):
                require(target in self.plan["migration"]["writers"], "writer_outside_scope")
            if operation == "resume" and target == "line-sender":
                require(self.call("worker-lock-released", target) is True, "worker_lock_held")
            if operation == "switch" and WORKERS[target] == "exec":
                reading = self.call("exec-binding", target)
                require(reading == {"active_api_image": data, "worker": target, "next_run_uses_api": True},
                        "worker_needs_approved_api_switch")
                return
        if operation == "prepare-build" and target in ("api", "admin", "api+admin"):
            modes = {"api": "api-only", "admin": "admin-only", "api+admin": "normal"}
            require(data == target.split("+"), "build_scope_mismatch")
            src = self.plan["sources"][PLATFORM]
            inputs = {"task_id": self.session, "source_sha": src["sha"], "pr_number": src["pr"],
                      "simple_mode": modes[target], "simple_plan": self.binding}
            # The installed, separately scoped App transport adopts a matching
            # run first. Never repeat a dispatch with an ambiguous response.
            return self.call("ensure-formal-build", target, {"ref": "main", "workflow": WORKFLOW, "inputs": inputs})
        supported = {
            "propose", "preflight", "observe", "find-asset", "verify-retained", "cache-admission", "ensure-formal-build",
            "prepare-build", "database-binding", "migration-inventory", "switch-binding",
            "switch", "health", "security", "drain", "resume", "exec-binding", "worker-lock-released", "worker-states",
        }
        require(operation in supported, "unknown_site_operation")
        value = self.private_operation(operation, target, data)
        if self.guard:
            self.guard()
        if operation == "preflight" and not self.contract_verified:
            self.verify_contract()
            self.contract_verified = True
        if operation == "find-asset" and target in ("api", "admin") and value is not None:
            if "download_id" in value:
                value = self.admit_download(target, value["download_id"])
                self.call("cache-admission", target, value)
            checked = self.local_image(target, value)
            return {**value, "verified_local_identity": checked}
        if operation == "health" and target in SERVICES:
            # The site proves the effective Nginx mapping/listener belongs to
            # this deployment. Independently measure the HTTP responses here.
            return http_health(target, value)
        return value

    def verify_contract(self):
        registry = json.loads((ROOT / "manifests/storefront-contract-releases.json").read_bytes())
        published = next((r for r in registry["immutable_history"]
                          if "sha256:" + r["manifest_sha256"] == self.plan["contract"]
                          and r.get("handoff_status") == "released" and r.get("publication")), None)
        require(published and published["publication"]["artifact_id"] > 0, "unpublished_contract")
        if SITE not in self.plan["sources"]:
            return
        sha = self.plan["sources"][SITE]["sha"]
        def contents(path):
            response = self.call("github-read", data=f"/repos/{SITE}/contents/{path}?ref={sha}")
            require(response["encoding"] == "base64", "vendored_contract_unavailable")
            return base64.b64decode(response["content"])
        package = json.loads(contents("package.json"))
        pins = {**package.get("dependencies", {}), **package.get("devDependencies", {})}
        directories = set()
        for name in ("@oripa/storefront-client", "@oripa/storefront-testkit"):
            info = published["packages"][name]
            filename = "oripa-" + name.split("/")[1] + "-" + info["version"] + ".tgz"
            pin = re.fullmatch(r"file:(vendor/oripa/[A-Za-z0-9_-]+)/" + re.escape(filename), pins[name])
            require(pin, "vendored_contract_version_changed")
            directories.add(pin[1])
        for directory in directories:
            require("sha256:" + hashlib.sha256(contents(directory + "/artifact-manifest.json")).hexdigest()
                    == self.plan["contract"], "vendored_contract_digest_changed")

    def database_step(self, operation, target, image):
        if __package__:
            from . import old_test_deploy as canonical
        else:
            import old_test_deploy as canonical
        require(DIGEST.fullmatch(image) and "api" in self.plan["targets"], "migration_scope_mismatch")
        migration = self.plan["migration"]
        require((operation == "backup" and target == "api" and migration["backup"]) or
                (operation == "migrate" and target in migration["files"] and MIGRATION.fullmatch(target)),
                "migration_scope_mismatch")
        spec = self.call("database-binding", target, {"image": image, "operation": operation})
        api = canonical.inspect(canonical.API)
        require(spec["plan"] == self.binding and spec["image"] == image and spec["container"] == api["Id"]
                and spec["old_test_database_verified"] is True and spec["writers_drained"] is True
                and spec["side_effects_known"] is True and spec["non_destructive"] is True
                and api["Config"]["Labels"]["com.docker.compose.project"] == "agency-004b-test"
                and api["Config"]["Labels"]["com.docker.compose.service"] == "test-api", "database_binding_invalid")
        path = SESSIONS / self.session / "database"
        if operation == "backup":
            job = canonical.Deployment({"state": {}, "session": self.session}, path)
            job.runtime["api"] = api
            self.guard()
            job.backup()
            return
        require(spec["next_file"] == target, "migration_order_changed")
        command, env = canonical.compose(api)
        require("sha256:" + canonical.digest(canonical.run(command + ["config", "--format", "json"],
                                                          environment=env)) == spec["compose_digest"], "compose_changed")
        config = path.with_suffix(".json")
        persist(config, {"services": {"test-api": {"image": image}}})
        self.guard()
        subprocess.run(command + ["-f", str(config), "run", "--rm", "--no-deps", "--pull", "never",
                       "-e", "SHELL_VERBOSITY=0", "-T", "test-api", "php", "artisan", "migrate",
                       "--path=database/migrations-v2/" + target, "--no-interaction"],
                       env=env, capture_output=True, check=True, timeout=300)

    def local_image(self, role, item):
        from_helper = runpy.run_path(str(ROOT / "scripts/ops/preview_image_artifact.py"))
        require(DIGEST.fullmatch(item["identity"]), "image_id_invalid")
        observed = from_helper["docker_inspect"](item["identity"])
        metadata = from_helper["image_metadata"](observed, self.plan["sources"][PLATFORM]["sha"], "amd64")
        require(metadata["labels"]["org.opencontainers.image.title"] ==
                {"api": "Oripa V2 API", "admin": "Oripa V2 Admin"}[role], "image_role_mismatch")
        return metadata["image_id"]

    def admit_download(self, role, identifier):
        """Inspect a formally fetched ZIP, then load only the selected image."""
        require(type(identifier) is int and identifier > 0, "artifact_id_invalid")
        get = lambda path: self.call("github-read", data=path)
        remote = get(f"/repos/{PLATFORM}/actions/artifacts/{identifier}")
        run_id = remote["workflow_run"]["id"]
        require(remote["id"] == identifier and type(run_id) is int and run_id > 0, "artifact_id_invalid")
        run = get(f"/repos/{PLATFORM}/actions/runs/{run_id}")
        wrapper = runpy.run_path(str(ROOT / "infrastructure/github-app/oripa-github-app-api"))
        helper = runpy.run_path(str(ROOT / "scripts/ops/preview_image_artifact.py"))
        folder = SESSIONS / self.session
        archive = folder / f"artifact-{identifier}.zip"
        trusted(archive, private=True)
        checksum = hashlib.sha256()
        with archive.open("rb") as stream:
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                checksum.update(chunk)
        wrapper["require_outer_digest"](remote["digest"], checksum.hexdigest())
        with tempfile.TemporaryDirectory(dir=folder) as temp:
            payload = Path(temp)
            wrapper["safe_extract"](archive, payload, old_test=True)
            provenance = json.loads((payload / "source-authority.json").read_bytes())
            src = self.plan["sources"][PLATFORM]
            require(provenance["source_sha"] == src["sha"] and provenance["source_pr"] == src["pr"],
                    "artifact_source_mismatch")
            verified = helper["verify_artifact"](payload, task_id=provenance["task_id"], pr_number=src["pr"],
                                                source_sha=src["sha"], artifact_kind="old-test", architecture="amd64")
            # Only the approved bytes actually used by this run are acceptable.
            # For historical overrides the formal provenance supplies their hash.
            recipe = folder / (role + ".Dockerfile")
            trusted(recipe, private=True)
            raw = recipe.read_bytes()
            override = provenance.get("build_recipe") if role == "api" else None
            if override:
                require(hashlib.sha256(raw).hexdigest() == override["recipe_sha256"], "build_recipe_mismatch")
            else:
                path = "infra/docker/backend/Dockerfile" if role == "api" else "apps/admin/Dockerfile"
                content = get(f"/repos/{PLATFORM}/contents/{path}?ref={src['sha']}")
                require(content["encoding"] == "base64" and base64.b64decode(content["content"]) == raw,
                        "build_recipe_mismatch")
            control = provenance["control_sha"]
            require(SHA.fullmatch(control) and run["head_sha"] == control, "control_mismatch")
            paths = [WORKFLOW, "scripts/ops/preview_image_artifact.py", "scripts/ops/old_test_source_authority.py"]
            if provenance.get("authority_mode") == "simple":
                paths.append("scripts/ops/old_test_simple_source.py")
            blobs = [get(f"/repos/{PLATFORM}/contents/{p}?ref={control}")["sha"] for p in paths]
            item = validate_download(remote, run, provenance, verified, "sha256:" + checksum.hexdigest(),
                                     role, raw, blobs, self.plan["contract"])
            require(item["recipe"] == self.plan["recipes"][role], "unapproved_recipe")
            manifest = json.loads((payload / "manifest.json").read_bytes())
            image = next(entry for entry in manifest["images"] if entry["name"] == role)
            tar = payload / "selected.tar"
            helper["decompress_archive"](payload / image["archive"], tar, image["archive_uncompressed_bytes"])
            with runtime_lock():
                canonical_idle()
                self.guard()
                subprocess.run(["/usr/bin/docker", "image", "load", "--input", str(tar)],
                               check=True, capture_output=True, timeout=300)
                require(self.local_image(role, item) == item["identity"], "loaded_image_mismatch")
            return item


def storefront_layout(directory, identity, build_id):
    release = Path(directory)
    require(release.is_absolute() and release.resolve(strict=True) == release, "storefront_release_path")
    manifest = release / "artifact-manifest.json"
    trusted(manifest)
    require("sha256:" + hashlib.sha256(manifest.read_bytes()).hexdigest() == identity
            and bool(build_id) and (release / ".next/BUILD_ID").read_text().strip() == build_id,
            "storefront_release_identity")
    modules = release / "node_modules"
    cli = (modules / "next/dist/bin/next").resolve(strict=True)
    require(modules.resolve(strict=True) == modules and modules in cli.parents and cli.is_file(),
            "storefront_dependency_layout")


def switch_service(target, identity, session, binding, spec, guard):
    if __package__:
        from . import old_test_deploy as canonical
    else:
        import old_test_deploy as canonical
    require(DIGEST.fullmatch(identity) and spec["identity"] == identity and spec["plan"] == binding
            and spec["environment"] == "old-test" and spec["target"] == target, "switch_binding_invalid")
    if target == "storefront":
        # Stage the new next-start service, release dependencies and unit
        # beforehand. The existing site unit and staged unit must both be bound.
        old, new = spec["old_unit"], spec["new_unit"]
        require(old != new and all(re.fullmatch(r"luxe-pack-storefront-old-test-pr[1-9][0-9]*\.service", u)
                                  for u in (old, new)) and spec["staged_health"] is True,
                "storefront_unit_mismatch")
        staged = canonical.unit(new)
        require(canonical.unit(old)["ActiveState"] == "active" and staged["ActiveState"] == "inactive"
                and staged["Id"] == new and staged["User"] == "luxe-pack-storefront-preview"
                and staged["WorkingDirectory"] == spec["release_directory"]
                and spec["fallback_start_verified"] is True,
                "storefront_state_changed")
        storefront_layout(staged["WorkingDirectory"], identity, spec["build_id"])
        for action, unit in (("stop", old), ("start", new)):
            guard()
            canonical.run(["systemctl", action, unit])
        return
    require(target in ("api", "admin"), "unknown_target")
    name = canonical.API if target == "api" else canonical.ADMIN
    current = canonical.inspect(name)
    project, service = (("agency-004b-test", "test-api") if target == "api" else ("mig061a-v2-preview", "admin"))
    labels = current["Config"]["Labels"]
    require(current["Id"] == spec["container"] and current["Image"] == spec["previous"]
            and current["State"]["Running"] and labels["com.docker.compose.project"] == project
            and labels["com.docker.compose.service"] == service, "container_changed")
    command, env = canonical.compose(current)
    config = canonical.run(command + ["config", "--format", "json"], environment=env)
    require("sha256:" + canonical.digest(config) == spec["compose_digest"], "compose_changed")
    effective = json.loads(config)
    nets = effective["services"][service]["networks"]
    require(len(nets) == 1 and effective["networks"][next(iter(nets))]["name"].endswith("_v2_private"),
            "private_first_configuration_required")
    networks = sorted(current["NetworkSettings"]["Networks"])
    egress = [name for name in networks if name.endswith("_v2_api_egress")]
    private = sorted(set(networks) - set(egress))
    require(len(egress) == int(target == "api") and private == [effective["networks"][next(iter(nets))]["name"]],
            "unexpected_network")
    switch = canonical.Deployment({}, SESSIONS / session / "switch")
    switch.runtime[target] = current
    override = switch.path.with_suffix("." + target + ".json")
    persist(override, {"services": {service: {"image": identity}}})
    command += ["-f", str(override)]
    new_config = canonical.decoded(command + ["config", "--format", "json"], environment=env)
    service_config = new_config["services"][service]
    expected = json.loads(config)
    expected["services"][service]["image"] = identity
    require(new_config == expected, "compose_scope_changed")
    metadata = canonical.decoded(["docker", "image", "inspect", identity])[0]
    effective_env = dict(entry.split("=", 1) for entry in metadata["Config"].get("Env", []))
    effective_env.update({k: str(v) for k, v in service_config.get("environment", {}).items()})
    switch.check_image_environment(target, identity, effective_env, current)
    # The common lock stays held across private readiness. Recheck the session
    # immediately before each effect, particularly the egress reconnection.
    def execute(argv, environment=None):
        guard()
        subprocess.run(argv, env=environment, capture_output=True, check=True, timeout=300)
    for net in egress:
        execute(["docker", "network", "disconnect", net, name])
    execute(command + ["up", "-d", "--no-build", "--no-deps", "--pull", "never", service], env)
    switch.wait(lambda: switch.readiness(target, identity, private, private=True))
    for net in egress:
        switch.runtime_identity(target, identity, private, private=True)
        execute(["docker", "network", "connect", net, name])
    switch.wait(lambda: switch.readiness(target, identity, networks))


def http_health(target, route, run=subprocess.run):
    import ipaddress
    require(route["proxy_route"] is True and route["listener_owned"] is True, "proxy_route_mismatch")
    host = "test.luxe-pack.biz"
    headers = []
    if target == "api":
        internal = "http://127.0.0.1:8621/api/health"
    elif target == "admin":
        # Returned by the trusted network/identity probe for the selected Admin.
        address = ipaddress.IPv4Address(route["container_address"])
        require(address.is_private and not address.is_link_local and not address.is_multicast,
                "admin_network_invalid")
        internal = f"http://{address}:3000/api/health"
        headers = ["--header", "Host: admin-test.luxe-pack.biz"]
    else:
        require(target == "storefront", "unknown_target")
        internal = "http://127.0.0.1:3241/"
    curl = ["/usr/bin/curl", "--disable", "--silent", "--noproxy", "*", "--connect-timeout", "2",
            "--max-time", "10", "--max-filesize", "4194304", "--write-out", "\n%{http_code} %{ssl_verify_result}"]
    # Drop page HTML at the transport boundary. Never persist headers or bodies.
    local = run(curl + (["--output", "/dev/null"] if target == "storefront" else []) + headers + [internal],
                capture_output=True, timeout=12)
    if local.returncode == 7:
        return {"starting": True}
    local.check_returncode()
    body, metrics = local.stdout.rsplit(b"\n", 1)
    path = "/" if target == "storefront" else "/api/v2/auth/session"
    public = run(curl + ["--resolve", host + ":443:127.0.0.1", "--output", "/dev/null",
                         "--dump-header", "-", "https://" + host + path], capture_output=True, timeout=12)
    public.check_returncode()
    raw_headers, public_metrics = public.stdout.rsplit(b"\n", 1)
    challenges = [line.partition(b":")[2].strip() for line in raw_headers.splitlines()
                  if line.lower().startswith(b"www-authenticate:")]
    require(len(challenges) == 1, "basic_challenge_missing")
    status, tls = public_metrics.split()
    result = {"internal": int(metrics.split()[0]), "external": int(status), "tls": tls == b"0",
              "proxy_route": True, "challenge": challenges[0].decode("ascii")}
    if target == "api":
        result["checks"] = json.loads(body)
    if target == "admin":
        require(json.loads(body) == {"checks": {"process": "ok"}, "component": "apps/admin",
                "readiness_scope": "process", "status": "ok"}, "admin_process_unhealthy")
    return result


def deploy(plan, targets, approval, journal, save, site, lock_check, now=time.time, sleep=time.sleep,
           *, enter_runtime=None):
    affected, roles = shape(plan, targets)
    binding = digest(plan)
    require(approval["plan"] == binding and approval["session"] == plan["session"]
            and approval["decision"] == "GO" and type(approval["issued"]) is int
            and type(approval["expires"]) is int and 0 < approval["expires"] - approval["issued"] <= 3600,
            "approval_required")
    require(journal.get("plan", binding) == binding, "journal_binding_changed")
    journal.setdefault("plan", binding)
    completed = journal.setdefault("completed", {})
    acquired = journal.setdefault("assets", {})
    results = journal.setdefault("results", {})
    step, target = "preflight", ""
    security_role, security_details, valid_until = "", {}, 0
    measuring = False
    runtime_check = None

    def guard():
        lock_check()
        if runtime_check:
            runtime_check()
        require(approval["issued"] <= now() < approval["expires"], "session_expired")
        if security_role and not measuring and now() >= valid_until:
            measure()

    def measure():
        nonlocal measuring, valid_until
        measuring = True
        try:
            reading = site.call("security", security_role, security_details)
            require(reading["result"] == "PASS" and reading["binding"] == security_details
                    and approval["issued"] <= reading["measured"] <= now() < reading["expires"] <= approval["expires"],
                    "security_binding_failed")
            valid_until = reading["expires"]
            key = security_details["operation"] + "/" + security_details["target"]
            journal.setdefault("security", {})[key] = {"measured": reading["measured"], "expires": valid_until,
                                                       "binding": digest(security_details)}
            if "receipt_digest" in reading:
                require(DIGEST.fullmatch(reading["receipt_digest"]), "security_receipt_digest")
                journal["security"][key]["receipt_digest"] = reading["receipt_digest"]
        finally:
            measuring = False

    if isinstance(site, Site):
        site.guard = guard

    def call(op, target="", data=None):
        guard()
        return site.call(op, target, data)

    def wait_health(target):
        for attempt in range(12):
            if health(target, call("health", target)):
                return
            if attempt < 11:
                sleep(2)
        raise Hold("readiness_timeout")

    def effect(op, target, data=None):
        # Every non-repeatable action has one durable intent. The site reports
        # done only after independently reading the desired host state.
        nonlocal step
        step = op
        key = op + "/" + target
        def observe():
            for attempt in range(121):
                state = call("observe", target, {"operation": op, "input": data})
                if op != "prepare-build" or state != "in-progress":
                    return state
                if attempt < 120:
                    sleep(5)
            raise Hold("build_still_running")
        observed = observe()
        if observed == "done":
            completed[key] = "PASS"
            save(journal)
            return
        require(key not in completed and observed == "ready", "effect_requires_reconciliation")
        completed[key] = "STARTED"
        save(journal)
        call(op, target, data)
        require(observe() == "done", "effect_unconfirmed")
        completed[key] = "PASS"
        save(journal)

    def inspect_host():
        preflight = call("preflight")
        expected_workers = dict(plan["workers"])
        for writer in plan["migration"]["writers"]:
            if completed.get("drain/" + writer) == "PASS" and "resume/" + writer not in completed:
                expected_workers[writer] = "inactive"
        require(preflight["environment"] == "old-test" and preflight["configuration"] == plan["configuration"]
                and preflight["contract"] == plan["contract"] and preflight["workers"] == expected_workers
                and all(preflight[k] is True for k in ("provider_fences", "space", "inodes", "compatible",
                                                       "canonical_idle")), "preflight_mismatch")
        for target in affected:
            live = preflight["identities"][target]
            expected = [plan["previous"][target]]
            if "switch/" + target in completed and asset_role(target) in acquired:
                expected.append(acquired[asset_role(target)]["identity"])
            require(live in expected, "runtime_identity_changed")
        return preflight

    try:
        guard()
        for repo, src in plan["sources"].items():
            proofs = journal.setdefault("sources", {})
            if repo not in proofs:
                proofs[repo] = merged_source(repo, src["pr"], src["sha"], lambda path: call("github-read", data=path))
        preflight = inspect_host()
        missing = []
        for role in roles:
            step, target = "find-asset", role
            item = call("verify-retained", role, acquired[role]) if role in acquired else call(step, role)
            if item is None:
                require(role not in acquired, "verified_asset_lost")
                missing.append(role)
                continue
            asset(plan, role, item)
            if role in acquired:
                require(acquired[role] == item, "verified_asset_changed")
            acquired[role] = item
        if missing:
            # Combine missing API/Admin into one formal AMD64 workflow dispatch.
            for group in ([r for r in ("api", "admin") if r in missing],
                          [r for r in missing if r == "storefront"], [r for r in missing if r == "sms"]):
                if group:
                    effect("prepare-build", "+".join(group), group)
                    for role in group:
                        item = call("find-asset", role)
                        require(item is not None, "verified_asset_missing")
                        asset(plan, role, item)
                        acquired[role] = item
        save(journal)

        if enter_runtime is not None:
            step, target = "runtime-lock", ""
            runtime_check = enter_runtime()
            guard()
            step = "preflight"
            preflight = inspect_host()
            for role in roles:
                require(call("verify-retained", role, acquired[role]) == acquired[role],
                        "verified_asset_changed_before_switch")

        def secure(role, operation, subject):
            nonlocal security_role, security_details, valid_until
            security_role, valid_until = role, 0
            security_details = {"source": acquired[role]["source"], "identity": acquired[role]["identity"],
                                "configuration": plan["configuration"], "contract": plan["contract"], "plan": binding,
                                "operation": operation, "target": subject, "deadline": approval["expires"]}
            if role in ("api", "admin"):
                security_details.update(artifact_id=acquired[role]["artifact_id"],
                                        artifact_digest=acquired[role]["artifact_digest"])
            guard()

        migration = plan["migration"]
        if migration["files"]:
            step, target = "migration-inventory", "api"
            def pending():
                reading = call("migration-inventory", "api")
                require(reading["destructive"] is False and reading["side_effects_known"] is True
                        and reading["source"] == acquired["api"]["source"],
                        "migration_requires_separate_go")
                remaining = migration_suffix(reading["inventory"], reading["ledger"], migration["files"])
                for applied in migration["files"]:
                    if applied not in remaining:
                        completed["migrate/" + applied] = "PASS"
                return remaining
            remaining = pending()
            if remaining:
                for writer in migration["writers"]:
                    secure("api", "drain", writer)
                    effect("drain", writer)
                if migration["backup"]:
                    secure("api", "backup", "api")
                    effect("backup", "api", acquired["api"]["identity"])
            for name in remaining:
                secure("api", "migrate", name)
                effect("migrate", name, acquired["api"]["identity"])
                require(name not in pending(), "migration_outcome_unknown")
            require(not pending(), "migration_incomplete")
        for target in affected:
            role = asset_role(target)
            if results.get(target) == "PASS":
                require(preflight["identities"][target] == acquired[role]["identity"], "accepted_target_changed")
                if WORKERS.get(target) == "pin":
                    require(call("observe", target, {"operation": "switch", "input": acquired[role]["identity"]})
                            == "done", "accepted_worker_pin_changed")
                continue
            step = "security"
            secure(role, "switch", target)
            effect("switch", target, acquired[role]["identity"])
            for writer in migration["writers"]:
                resume_after = writer if writer in affected else "api"
                if target == resume_after and "drain/" + writer in completed:
                    secure("api", "resume", writer)
                    effect("resume", writer)
            step = "health"
            wait_health(target)
            results[target] = "PASS"
            save(journal)
            security_role = ""
        guard()
        if "api" in targets:
            # Include exec-oneshots in acceptance without invoking the job.
            for worker, state in plan["workers"].items():
                if state == "active" and WORKERS[worker] == "exec":
                    step, target = "health", worker
                    wait_health(worker)
                    results[worker] = "PASS"
        if plan["workers"]:
            step, target = "worker-states", ""
            require(call("worker-states") == plan["workers"], "retained_worker_changed")
        guard()
        journal.pop("error", None)
        save(journal)
    except Exception as exc:
        if target in affected or target in plan["workers"]:
            results[target] = "FAIL"
        frames = traceback.extract_tb(exc.__traceback__)
        frame = next((f for f in reversed(frames) if f.name != "require"), frames[-1])
        journal["error"] = {"step": step, "target": target, "class": type(exc).__name__,
                            "file": Path(frame.filename).name, "line": frame.lineno,
                            "exit": getattr(exc, "returncode", 1),
                            "reason": str(exc) if isinstance(exc, Hold) else "operation_failed"}
        save(journal)
        raise


def main(argv=None):
    parser = argparse.ArgumentParser(prog="oldtest-deploy", allow_abbrev=False)
    parser.add_argument("targets", nargs="+")
    parser.add_argument("--session", required=True, help="approved OLD Test session ID")
    parser.add_argument("--prepare", action="store_true", help="record the scoped plan for Human GO")
    parser.add_argument("--platform-source", metavar="PR@SHA")
    parser.add_argument("--storefront-source", metavar="PR@SHA")
    parser.add_argument("--contract", metavar="sha256:DIGEST")
    parser.add_argument("--migration", action="append", default=[], metavar="FILE.php")
    args = parser.parse_args(argv)
    targets = selection(args.targets)
    require(SESSION.fullmatch(args.session), "invalid_session")
    installed_entry()
    folder = SESSIONS / args.session
    for directory in reversed(folder.parents):
        trusted(directory, private=directory == SESSIONS, directory=True)
    if args.prepare:
        folder.mkdir(mode=0o700, exist_ok=True)
    with runtime_lock(folder, session_directory=True) as check, contextlib.ExitStack() as runtime:
        def enter_runtime():
            locked = runtime.enter_context(runtime_lock())
            canonical_idle()
            return locked
        if args.prepare:
            sources = {}
            for repo, value in ((PLATFORM, args.platform_source), (SITE, args.storefront_source)):
                if value:
                    match = re.fullmatch(r"([1-9][0-9]{0,9})@([0-9a-f]{40})", value)
                    require(match, "invalid_source")
                    sources[repo] = {"pr": int(match[1]), "sha": match[2]}
            require(args.contract and DIGEST.fullmatch(args.contract)
                    and all(MIGRATION.fullmatch(m) and m.endswith(".php") for m in args.migration),
                    "invalid_plan_input")
            request = {"environment": "old-test", "session": args.session, "targets": targets, "sources": sources,
                       "contract": args.contract, "site_policy": "sha256:" + hashlib.sha256(BINDINGS.read_bytes()).hexdigest()}
            site = Site(request)
            site.guard = check
            proofs = {repo: merged_source(repo, src["pr"], src["sha"],
                                          lambda path: site.call("github-read", data=path))
                      for repo, src in sources.items()}
            site.guard = None
            enter_runtime()
            plan = site.call("propose", data={**request, "migrations": args.migration})
            require(all(plan[k] == v for k, v in request.items())
                    and plan["migration"]["files"] == args.migration, "proposed_scope_changed")
            shape(plan, targets)
            trusted(folder, private=True, directory=True)
            path = folder / "plan.json"
            if path.exists():
                trusted(path, private=True)
                require(json.loads(path.read_bytes()) == plan, "session_already_bound")
            else:
                persist(path, plan)
            journal = folder / "journal.json"
            if not journal.exists():
                persist(journal, {"plan": digest(plan), "sources": proofs})
            print(json.dumps({"plan": plan, "sources": proofs, "approval_digest": digest(plan)}, sort_keys=True))
            return
        require(not (args.platform_source or args.storefront_source or args.contract or args.migration),
                "approved_plan_is_immutable")
        trusted(folder, private=True, directory=True)
        def read(name):
            path = folder / name
            trusted(path, private=True)
            return json.loads(path.read_bytes())
        plan = read("plan.json")
        require(plan["session"] == args.session, "session_mismatch")
        path = folder / "journal.json"
        journal = read(path.name) if path.exists() else {}
        require((folder / "go.json").exists(), "human_go_missing_for_prepared_plan")
        canonical_idle()
        deploy(plan, targets, read("go.json"), journal, lambda data: persist(path, data),
               Site(plan), check, enter_runtime=enter_runtime)
        print(json.dumps(journal["results"], sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        raise SystemExit(str(error) if isinstance(error, Hold) else type(error).__name__) from None
