#!/usr/bin/env python3
"""Sequence existing OLD Test operations from a single, separately approved candidate."""

from __future__ import annotations

import argparse
import contextlib
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import re
import runpy
import shlex
import stat
import subprocess
import sys
import tempfile
import time


ROOT = Path(__file__).resolve().parents[2]
WRAPPER = Path("/usr/local/bin/oripa-github-app-api")
API = "agency-004b-test-api"
ADMIN = "mig061a-v2-preview-admin-1"
SMS = "oripa-old-sms-delivery-worker.service"
TIMERS = ("oripa-oldtest-f2b-worker.timer", "oripa-oldtest-f2b-purge.timer")
F2B_LOCK = Path("/run/lock/oripa-old-f2b-single.lock")
F2B_RUNNER = Path("/var/lib/oripa-v2-evidence/OLDTEST-20261015/f2b-auto-runner.py")
MIGRATIONS = "apps/api/database/migrations-v2"
FULL_SHA = re.compile(r"[0-9a-f]{40}")
IMAGE_ID = re.compile(r"sha256:[0-9a-f]{64}")


class Hold(RuntimeError):
    pass


def require(condition: object, reason: str) -> None:
    if not condition:
        raise Hold(reason)


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def file_digest(path: Path) -> str:
    checksum = hashlib.sha256()
    with path.open("rb") as source_file:
        for block in iter(lambda: source_file.read(1024 * 1024), b""):
            checksum.update(block)
    return checksum.hexdigest()


def identity(candidate: dict) -> str:
    return digest(json.dumps({key: value for key, value in candidate.items() if key != "state"},
                             sort_keys=True, separators=(",", ":")).encode())


def run(arguments: list[str], *, environment: dict | None = None, cwd: Path | None = None) -> bytes:
    try:
        result = subprocess.run(arguments, env=environment, cwd=cwd, capture_output=True, timeout=300)
    except (OSError, subprocess.SubprocessError):
        raise Hold("command_failed") from None
    require(result.returncode == 0, "command_failed")
    return result.stdout


def decoded(arguments: list[str], **kwargs) -> dict:
    return json.loads(run(arguments, **kwargs))


def private_file(path: Path) -> None:
    metadata = path.lstat()
    require(stat.S_ISREG(metadata.st_mode) and metadata.st_uid == 0
            and not metadata.st_mode & 0o077, "private_file_required")


def destination(path: Path) -> None:
    require(path.is_absolute() and path.resolve() == path and path.suffix == ".json", "candidate_path_invalid")
    require(not path.is_relative_to(ROOT), "candidate_outside_repository_required")
    parent = path.parent.stat()
    require(parent.st_uid == 0 and not parent.st_mode & 0o077, "private_parent_required")


def save(path: Path, candidate: dict, *, create: bool = False) -> None:
    destination(path)
    flags = os.O_WRONLY | os.O_NOFOLLOW | (os.O_CREAT | os.O_EXCL if create else os.O_TRUNC)
    descriptor = os.open(path, flags, 0o600)
    with os.fdopen(descriptor, "w") as output:
        json.dump(candidate, output, sort_keys=True, indent=2)
        output.flush()
        os.fsync(output.fileno())


def source(path: str, expected: str | None = None) -> tuple[Path, str]:
    repository = Path(path).resolve()
    revision = run(["git", "-c", "safe.directory=" + str(repository), "-C", str(repository), "rev-parse", "HEAD"]).decode().strip()
    require(FULL_SHA.fullmatch(revision), "source_sha_invalid")
    require(expected is None or revision == expected, "source_sha_mismatch")
    return repository, revision


def source_bytes(repository: Path, revision: str, relative: str) -> bytes:
    return run(["git", "-c", "safe.directory=" + str(repository), "-C", str(repository), "show", f"{revision}:{relative}"])


def migration_files(repository: Path, revision: str) -> list[str]:
    names = run(["git", "-c", "safe.directory=" + str(repository), "-C", str(repository),
                 "ls-tree", "-r", "--name-only", revision, MIGRATIONS])
    result = names.decode().splitlines()
    require(result and all(name.endswith(".php") for name in result), "migration_inventory_invalid")
    for relative in result + ["scripts/db/v2_database.py"]:
        require((repository / relative).read_bytes() == source_bytes(repository, revision, relative),
                "guard_source_modified")
    require(sorted(str(path.relative_to(repository)) for path in (repository / MIGRATIONS).glob("*.php"))
            == sorted(result), "migration_inventory_modified")
    return result


def inspect(name: str) -> dict:
    return decoded(["docker", "inspect", name])[0]


def compose(current: dict) -> tuple[list[str], dict]:
    labels = current["Config"]["Labels"]
    command = ["docker", "compose", "--project-name", labels["com.docker.compose.project"]]
    for filename in filter(None, labels.get("com.docker.compose.project.environment_file", "").split(",")):
        require(Path(filename).is_absolute(), "compose_path_invalid")
        command += ["--env-file", filename]
    for filename in labels["com.docker.compose.project.config_files"].split(","):
        require(Path(filename).is_absolute(), "compose_path_invalid")
        command += ["-f", filename]
    environment = dict(os.environ)
    environment.update(entry.split("=", 1) for entry in current["Config"]["Env"])
    return command, environment


def unit(name: str) -> dict:
    properties = "Id,ActiveState,SubState,User,WorkingDirectory,ExecStart,Environment,EnvironmentFiles"
    output = run(["systemctl", "show", name, "--property=" + properties]).decode()
    return dict(line.split("=", 1) for line in output.splitlines() if "=" in line)


def storefront(path: str, expected: str) -> dict:
    repository, revision = source(path, expected)
    git = ["git", "-c", "safe.directory=" + str(repository), "-C", str(repository)]
    remote = run(git + ["remote", "get-url", "origin"]).decode().strip()
    require(remote in {"git@github.com:ideal-sol/luxe-pack-storefront.git",
                       "https://github.com/ideal-sol/luxe-pack-storefront.git"},
            "storefront_repository_invalid")
    changed = run(git + ["diff", "HEAD", "--name-only"]).decode().splitlines()
    require(not run(git + ["ls-files", "--others", "--exclude-standard"]).strip(), "storefront_untracked_source")
    require(set(changed) <= {"next.config.ts", "next-env.d.ts"}, "storefront_source_modified")
    for relative in changed:
        original = source_bytes(repository, revision, relative)
        if relative == "next.config.ts":
            expected_bytes = original.replace(b"const nextConfig: NextConfig = {\n",
                b'const nextConfig: NextConfig = {\n  allowedDevOrigins: ["test.luxe-pack.biz"],\n', 1)
        else:
            expected_bytes = original.replace(b'"./.next/types/', b'"./.next/dev/types/')
        require((repository / relative).read_bytes() == expected_bytes, "storefront_overlay_invalid")
    require((repository / "node_modules/next/dist/bin/next").is_file(), "storefront_dependencies_missing")
    require((repository / "scripts/verify-vendored-artifacts.mjs").is_file(), "storefront_verifier_missing")
    return {"directory": str(repository), "sha": revision,
            "overlays": {name: digest((repository / name).read_bytes()) for name in changed}}


class Deployment:
    def __init__(self, candidate: dict, path: Path):
        self.candidate = candidate
        self.path = path
        self.phase = "preflight"
        self.runtime: dict = {}

    def artifact(self) -> dict:
        selected = self.candidate["artifact"]
        directory = Path(selected["directory"])
        metadata = directory.stat()
        require(directory.resolve() == directory and metadata.st_uid == 0
                and not metadata.st_mode & 0o077, "artifact_directory_invalid")
        download = json.loads((directory / "download-metadata.json").read_bytes())
        task, pull, revision = download["task_id"], str(download["pull_request"]), download["source_commit"]
        available = decoded([str(WRAPPER), "old-test-artifacts", task, pull, revision])
        require(any(item["id"] == selected["id"] and item["digest"] == selected["digest"]
                    and item["workflow_run_id"] == download["workflow_run_id"] for item in available),
                "artifact_rejected_or_expired")
        require(download["artifact_id"] == selected["id"] and download["github_digest"] == selected["digest"]
                and "sha256:" + download["outer_sha256"] == selected["digest"], "artifact_identity_mismatch")
        wrapper = runpy.run_path(str(WRAPPER))
        authority = wrapper["authorize_old_test_source"](task, pull, revision, download["control_sha"])
        provenance = json.loads((directory / "payload/source-authority.json").read_bytes())
        require(all(provenance.get(key) == value for key, value in authority.items())
                and provenance["run_id"] == download["workflow_run_id"], "artifact_provenance_mismatch")
        helper = directory / "verified-control-artifact-helper.py"
        require(helper.read_bytes() == source_bytes(ROOT, download["control_sha"],
                "scripts/ops/preview_image_artifact.py"), "artifact_helper_mismatch")
        files = [directory / "download-metadata.json", helper] + sorted((directory / "payload").iterdir())
        require(all(path.is_file() and not path.is_symlink() for path in files), "artifact_file_invalid")
        hashes = {str(path.relative_to(directory)): file_digest(path) for path in files}
        if "files" in selected:
            require(selected["files"] == hashes, "artifact_files_changed")
        else:
            selected.update(files=hashes, authority=authority)
        manifest = json.loads((directory / "payload/manifest.json").read_bytes())
        expected = ["api", "admin"] if authority["image_mode"] == "normal" else ["api"]
        require([image["name"] for image in manifest["images"]] == expected, "artifact_inventory_mismatch")
        return {image["name"]: image for image in manifest["images"]}

    def snapshot(self) -> dict:
        observed = {}
        for role, name in (("api", API), ("admin", ADMIN)):
            if role not in self.candidate["services"] and not self.candidate["migrations"]:
                continue
            current = inspect(name)
            labels = current["Config"]["Labels"]
            expected = ("agency-004b-test", "test-api") if role == "api" else ("mig061a-v2-preview", "admin")
            require((labels["com.docker.compose.project"], labels["com.docker.compose.service"]) == expected
                    and current["State"]["Running"], "runtime_target_invalid")
            if role == "api":
                require(current["HostConfig"]["PortBindings"] == {
                    "8000/tcp": [{"HostIp": "127.0.0.1", "HostPort": "8621"}]}, "api_port_invalid")
                require("FINCODE_PAYMENT_ENABLED=false" in current["Config"]["Env"], "payment_must_be_off")
                image = decoded(["docker", "image", "inspect", current["Image"]])[0]
                require(image["Config"]["Labels"]["org.opencontainers.image.revision"]
                        == self.candidate["current_platform_sha"], "baseline_runtime_source_mismatch")
            self.runtime[role] = current
            command, environment = compose(current)
            config = run(command + ["config", "--format", "json"], environment=environment)
            observed[role] = {"id": current["Id"], "image": current["Image"], "compose": command, "compose_sha256": digest(config),
                              "networks": sorted(current["NetworkSettings"]["Networks"])}
        units = ((SMS,) if self.candidate["migrations"] else ()) + (TIMERS if "api" in self.candidate["services"] else ())
        for name in units:
            observed[name] = unit(name)["ActiveState"]
        if "api" in self.candidate["services"] or self.candidate["migrations"]:
            require(not F2B_RUNNER.is_symlink(), "f2b_runner_invalid")
            content = F2B_RUNNER.read_bytes()
            require(content.count(('IMAGE = "' + observed["api"]["image"] + '"').encode()) == 1,
                    "f2b_image_pin_mismatch")
            observed["f2b_runner"] = digest(content)
        if "storefront" in self.candidate["services"]:
            current_unit = unit(self.candidate["storefront_unit"])
            require(current_unit["ActiveState"] == "active" and not current_unit["EnvironmentFiles"],
                    "storefront_unit_invalid")
            environment = shlex.split(current_unit["Environment"])
            require(all(entry.split("=", 1)[0] in {"NODE_ENV", "NEXT_TELEMETRY_DISABLED", "NEXT_PUBLIC_SITE_URL",
                    "NEXT_PUBLIC_PLATFORM_API_BASE_URL"} for entry in environment), "storefront_environment_unsupported")
            expected_command = (f'/usr/bin/node {current_unit["WorkingDirectory"]}/node_modules/next/dist/bin/next '
                                'dev --hostname 127.0.0.1 --port 3241')
            require("argv[]=" + expected_command + " ;" in current_unit["ExecStart"]
                    and current_unit["User"] == "luxe-pack-storefront-preview", "storefront_start_unsupported")
            old_repository, old_sha = source(current_unit["WorkingDirectory"])
            observed["storefront"] = storefront(str(old_repository), old_sha)
            observed["storefront"].update(unit=current_unit["Id"], environment=environment)
        return observed

    def guard(self, repository: str, revision: str) -> None:
        checkout, _ = source(repository, revision)
        migration_files(checkout, revision)
        guard = runpy.run_path(str(checkout / "scripts/db/v2_database.py"))
        command, environment = compose(self.runtime["admin"])
        api_environment = dict(entry.split("=", 1) for entry in self.runtime["api"]["Config"]["Env"])
        mapping = {"V2_APP_ENV": "APP_ENV", "V2_APP_KEY": "APP_KEY", "V2_DB_HOST": "DB_HOST",
                   "V2_DB_PORT": "DB_PORT", "V2_DB_DATABASE": "DB_DATABASE", "V2_DB_USERNAME": "DB_USERNAME",
                   "V2_DB_PASSWORD": "DB_PASSWORD", "V2_REDIS_HOST": "REDIS_HOST", "V2_REDIS_PORT": "REDIS_PORT",
                   "V2_REDIS_PASSWORD": "REDIS_PASSWORD", "V2_AUDIT_HMAC_KEY": "V2_AUDIT_HMAC_KEY",
                   "V2_PII_CORRELATION_KEY": "V2_PII_CORRELATION_KEY"}
        values = {key: api_environment[value] for key, value in mapping.items()}
        values["COMPOSE_PROJECT_NAME"] = "mig061a-v2-preview"
        previous = dict(os.environ)
        try:
            os.environ.update(environment)
            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                guard["validate_values"](values, "mig061a-v2-preview")
                guard["assert_database_target"](command, checkout, values, "MIG-061A", "v2-persistent", "repository")
                guard["validate_schema_inventory"](guard["schema_inventory"](command, checkout))
        except Exception:
            raise Hold("canonical_guard_failed") from None
        finally:
            os.environ.clear()
            os.environ.update(previous)

    def preflight(self, *, preparing: bool = False) -> None:
        candidate = self.candidate
        require(candidate["version"] == 1 and candidate["target"] == "old-test", "candidate_invalid")
        require(re.fullmatch(r"[A-Z][A-Z0-9-]{2,31}", candidate["task_id"]), "task_id_invalid")
        require(candidate["services"] and set(candidate["services"]) <= {"api", "admin", "storefront"}
                and len(set(candidate["services"])) == len(candidate["services"]), "services_invalid")
        images = self.artifact()
        candidate_sha = candidate["artifact"]["authority"]["source_sha"]
        target, _ = source(candidate["platform_source"], candidate_sha)
        baseline, baseline_sha = source(candidate["current_platform_source"], candidate["current_platform_sha"])
        before, after = migration_files(baseline, baseline_sha), migration_files(target, candidate_sha)
        approved = candidate["migrations"]
        require(len(set(approved)) == len(approved) and all(Path(name).name == name for name in approved),
                "migration_approval_invalid")
        require(set(after) == set(before) | {MIGRATIONS + "/" + name for name in approved}
                and not set(before) & {MIGRATIONS + "/" + name for name in approved}, "migration_scope_incompatible")
        require(all(source_bytes(baseline, baseline_sha, name) == source_bytes(target, candidate_sha, name)
                    for name in before), "historical_migration_changed")
        require(not approved or "api" in candidate["services"], "migration_requires_api")
        for role in set(candidate["services"]) & {"api", "admin"}:
            require(role in images and IMAGE_ID.fullmatch(images[role]["image_id"]), "candidate_image_missing")
        observed = self.snapshot()
        if preparing:
            candidate["previous"] = observed
            candidate["images"] = images
        else:
            require(observed == candidate["previous"] and images == candidate["images"], "runtime_or_candidate_changed")
        if "storefront" in candidate["services"]:
            require(storefront(candidate["storefront"]["directory"], candidate["storefront"]["sha"])
                    == candidate["storefront"], "storefront_candidate_changed")
            main = run(["git", "ls-remote", "git@github.com:ideal-sol/luxe-pack-storefront.git", "refs/heads/main"]).decode().split()[0]
            require(FULL_SHA.fullmatch(main), "storefront_main_invalid")
            repository = candidate["storefront"]["directory"]
            run(["git", "-c", "safe.directory=" + repository, "-C", repository, "merge-base", "--is-ancestor",
                 candidate["storefront"]["sha"], main])
        if approved:
            require(set(self.candidate["services"]) >= {"api", "admin"}, "migration_requires_api_admin")
            self.guard(str(baseline), baseline_sha)
        for role in set(candidate["services"]) & {"api", "admin"}:
            current = self.runtime[role]
            command, environment = compose(current)
            service = current["Config"]["Labels"]["com.docker.compose.service"]
            config = decoded(command + ["config", "--format", "json"], environment=environment)["services"][service]
            old_image = decoded(["docker", "image", "inspect", current["Image"]])[0]
            effective = dict(entry.split("=", 1) for entry in old_image["Config"].get("Env", []))
            effective.update({key: str(value) for key, value in config.get("environment", {}).items()})
            require(effective == dict(entry.split("=", 1) for entry in current["Config"]["Env"]),
                    "compose_environment_changed")

    def phase_start(self, name: str) -> None:
        self.phase = name
        self.candidate["state"]["phase"] = name
        save(self.path, self.candidate)

    def wait(self, predicate) -> None:
        for attempt in range(60):
            if predicate():
                return
            time.sleep(1)
        raise Hold("drain_or_health_timeout")

    def fence(self) -> None:
        for timer in TIMERS:
            if self.candidate["previous"][timer] == "active":
                run(["systemctl", "stop", timer])
        self.wait(lambda: all(unit(timer.replace(".timer", ".service"))["ActiveState"] == "inactive"
                              for timer in TIMERS))

    def backup(self) -> None:
        container = "mig061a-v2-preview-postgres-1"
        database = inspect(container)
        labels = database["Config"]["Labels"]
        database_environment = dict(entry.split("=", 1) for entry in database["Config"]["Env"])
        api_environment = dict(entry.split("=", 1) for entry in self.runtime["api"]["Config"]["Env"])
        require(labels["com.docker.compose.project"] == "mig061a-v2-preview"
                and labels["com.docker.compose.service"] == "postgres"
                and database_environment["POSTGRES_DB"] == api_environment["DB_DATABASE"]
                and database_environment["POSTGRES_USER"] == api_environment["DB_USERNAME"], "backup_target_mismatch")
        remote = "/tmp/oripa-old-test-" + identity(self.candidate) + ".dump"
        local = self.path.with_suffix(".dump")
        require(not local.exists(), "backup_already_exists")
        script = 'umask 077; test ! -e "$1"; pg_dump --format=custom --no-owner --no-privileges --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --file "$1"'
        run(["docker", "exec", container, "sh", "-ec", script, "sh", remote])
        run(["docker", "exec", container, "pg_restore", "--file=/dev/null", remote])
        checksum = run(["docker", "exec", container, "sha256sum", remote]).decode().split()[0]
        run(["docker", "cp", container + ":" + remote, str(local)])
        os.chmod(local, 0o600)
        require(local.stat().st_size > 0, "backup_empty")
        require(file_digest(local) == checksum, "backup_copy_mismatch")
        self.candidate["state"]["backup_sha256"] = checksum

    def load(self) -> None:
        directory = Path(self.candidate["artifact"]["directory"])
        authority = self.candidate["artifact"]["authority"]
        helper = runpy.run_path(str(directory / "verified-control-artifact-helper.py"))
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
            helper["verify_artifact"](directory / "payload", task_id=authority["task_id"],
                pr_number=authority["source_pr"], source_sha=authority["source_sha"],
                artifact_kind="old-test", architecture="amd64")
            helper["require_target_host"]("amd64")
            for role in self.candidate["services"]:
                if role == "storefront":
                    continue
                image = self.candidate["images"][role]
                try:
                    present = decoded(["docker", "image", "inspect", image["image_id"]])[0]
                except Hold:
                    with tempfile.TemporaryDirectory(prefix="oripa-old-test-load-") as temporary:
                        raw = Path(temporary) / "image.tar"
                        helper["decompress_archive"](directory / "payload" / image["archive"], raw,
                                                     image["archive_uncompressed_bytes"])
                        run(["docker", "image", "load", "--input", str(raw)])
                    present = decoded(["docker", "image", "inspect", image["image_id"]])[0]
                require(helper["image_metadata"](present, authority["source_sha"], "amd64")["image_id"]
                        == image["image_id"], "loaded_image_mismatch")
                current = self.runtime[role]
                command, environment = compose(current)
                service = current["Config"]["Labels"]["com.docker.compose.service"]
                config = decoded(command + ["config", "--format", "json"], environment=environment)
                effective = dict(entry.split("=", 1) for entry in present["Config"].get("Env", []))
                effective.update({key: str(value) for key, value in config["services"][service].get("environment", {}).items()})
                require(effective == dict(entry.split("=", 1) for entry in current["Config"]["Env"]),
                        "new_image_environment_changed")
                configured = config["services"][service].get("networks", {})
                require(len(configured) == 1 and all(config["networks"][name].get("name", "").endswith("_v2_private")
                            for name in configured), "private_first_configuration_required")

    def switch(self, role: str, image_id: str) -> None:
        require(IMAGE_ID.fullmatch(image_id), "image_id_invalid")
        current = self.runtime[role]
        command, environment = compose(current)
        command = self.candidate["previous"][role]["compose"]
        service = current["Config"]["Labels"]["com.docker.compose.service"]
        override = self.path.with_suffix("." + role + ".json")
        override.write_text(json.dumps({"services": {service: {"image": image_id}}}))
        config = decoded(command + ["-f", str(override), "config", "--format", "json"], environment=environment)
        image = decoded(["docker", "image", "inspect", image_id])[0]
        effective = dict(entry.split("=", 1) for entry in image["Config"].get("Env", []))
        effective.update({key: str(value) for key, value in config["services"][service].get("environment", {}).items()})
        require(effective == dict(entry.split("=", 1) for entry in current["Config"]["Env"]),
                "new_image_environment_changed")
        networks = current["NetworkSettings"]["Networks"]
        expected_networks = self.candidate["previous"][role]["networks"]
        egress = [name for name in expected_networks if name.endswith("_v2_api_egress")]
        require(len(egress) == (1 if role == "api" else 0), "network_boundary_invalid")
        for name in egress:
            if name in networks:
                run(["docker", "network", "disconnect", name, current["Name"]])
        run(command + ["-f", str(override), "up", "-d", "--no-build", "--no-deps", "--pull", "never", service],
            environment=environment)
        name = current["Name"]
        self.wait(lambda: inspect(name)["State"].get("Health", {}).get("Status") == "healthy")
        for network in egress:
            run(["docker", "network", "connect", network, name])
        require(inspect(name)["Image"] == image_id, "loaded_image_mismatch")
        require(sorted(inspect(name)["NetworkSettings"]["Networks"]) == expected_networks, "restored_network_mismatch")

    def start_storefront(self, selected: dict, name: str) -> None:
        command = ["systemd-run", "--unit", name, "--uid=luxe-pack-storefront-preview",
                   "--working-directory=" + selected["directory"], "--property=Restart=on-failure",
                   "--property=KillMode=control-group", "--property=TimeoutStopSec=90"]
        command += ["--setenv=" + entry for entry in self.candidate["previous"]["storefront"]["environment"]]
        run(command + ["/usr/bin/node", selected["directory"] + "/node_modules/next/dist/bin/next",
                       "dev", "--hostname", "127.0.0.1", "--port", "3241"])

    def pin(self, previous: str, new: str) -> None:
        original = F2B_RUNNER.read_bytes()
        before = ('IMAGE = "' + previous + '"').encode()
        require(original.count(before) == 1, "f2b_pin_changed")
        F2B_RUNNER.write_bytes(original.replace(before, ('IMAGE = "' + new + '"').encode(), 1))

    def resume(self) -> None:
        if self.candidate["migrations"] and self.candidate["previous"][SMS] == "active":
            run(["systemctl", "start", SMS])
            require(unit(SMS)["ActiveState"] == "active", "sms_resume_failed")
        if "api" in self.candidate["services"]:
            for timer in TIMERS:
                if self.candidate["previous"][timer] == "active":
                    run(["systemctl", "start", timer])
                    require(unit(timer)["ActiveState"] == "active", "timer_resume_failed")

    def health(self) -> None:
        paths = []
        if set(self.candidate["services"]) & {"api", "admin"}:
            paths += ["http://127.0.0.1:8621/api/health", "https://test.luxe-pack.biz/api/v2/auth/session"]
        if "admin" in self.candidate["services"]:
            paths.append("https://admin-test.luxe-pack.biz/")
        if "storefront" in self.candidate["services"]:
            paths.append("https://test.luxe-pack.biz/")
        for url in paths:
            require(run(["curl", "--silent", "--output", "/dev/null", "--write-out", "%{http_code}",
                         "--max-time", "120", url]) == b"200", "health_failed")

    def apply(self, go: str, *, dry_run: bool = False) -> None:
        require(go == identity(self.candidate), "exact_candidate_runtime_go_required")
        require(self.candidate["state"]["phase"] == "prepared", "candidate_already_attempted")
        self.preflight()
        if dry_run:
            return
        self.phase_start("load")
        authority = self.candidate["artifact"]["authority"]
        if set(self.candidate["services"]) & {"api", "admin"}:
            self.load()
        if "storefront" in self.candidate["services"]:
            repository = Path(self.candidate["storefront"]["directory"])
            run(["node", str(repository / "scripts/verify-vendored-artifacts.mjs")], cwd=repository)
        lock = None
        try:
            if "api" in self.candidate["services"]:
                self.phase_start("fence")
                self.fence()
                lock = F2B_LOCK.open("r+")
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.phase_start("stop")
            if self.candidate["migrations"] and self.candidate["previous"][SMS] == "active":
                run(["systemctl", "stop", SMS])
            for role in ("admin", "api"):
                if role in self.candidate["services"] or self.candidate["migrations"]:
                    run(["docker", "stop", self.runtime[role]["Name"]])
            if self.candidate["migrations"]:
                self.phase_start("guard_backup")
                self.guard(self.candidate["current_platform_source"], self.candidate["current_platform_sha"])
                self.backup()
                command, environment = compose(self.runtime["api"])
                override = self.path.with_suffix(".migration.json")
                override.write_text(json.dumps({"services": {"test-api": {"image": self.candidate["images"]["api"]["image_id"]}}}))
                for migration in self.candidate["migrations"]:
                    self.candidate["state"]["migration_attempted"] = True
                    self.phase_start("migration")
                    run(command + ["-f", str(override), "run", "--rm", "--no-deps", "--pull", "never", "-T", "test-api",
                        "php", "artisan", "migrate", "--path=database/migrations-v2/" + migration, "--no-interaction"], environment=environment)
                    self.candidate["state"]["applied"].append(migration)
                    save(self.path, self.candidate)
                self.guard(self.candidate["platform_source"], authority["source_sha"])
                self.candidate["state"]["schema_verified"] = True
            self.phase_start("switch")
            for role in ("admin", "api"):
                if role in self.candidate["services"]:
                    self.switch(role, self.candidate["images"][role]["image_id"])
                elif self.candidate["migrations"]:
                    run(["docker", "start", self.runtime[role]["Name"]])
            if "storefront" in self.candidate["services"]:
                run(["systemctl", "stop", self.candidate["previous"]["storefront"]["unit"]])
                self.start_storefront(self.candidate["storefront"], self.candidate["storefront_new_unit"])
            if "api" in self.candidate["services"]:
                self.pin(self.candidate["previous"]["api"]["image"], self.candidate["images"]["api"]["image_id"])
            self.phase_start("health")
            self.health()
            if lock is not None:
                lock.close()
                lock = None
            self.resume()
            self.phase_start("complete")
        finally:
            if lock is not None:
                lock.close()

    def rollback(self, go: str, *, dry_run: bool = False) -> None:
        require(go == identity(self.candidate), "exact_candidate_runtime_go_required")
        state = self.candidate["state"]
        require(state["phase"] not in {"prepared", "rolled_back"}, "rollback_not_applicable")
        if state["migration_attempted"]:
            require(state.get("schema_verified") and self.candidate["rollback_schema_compatible"],
                    "migration_rollback_compatibility_unknown")
        for role in set(self.candidate["services"]) & {"api", "admin"}:
            self.runtime[role] = inspect(API if role == "api" else ADMIN)
            require(self.runtime[role]["Image"] in {self.candidate["previous"][role]["image"],
                    self.candidate["images"].get(role, {}).get("image_id")}, "rollback_runtime_changed")
        if state["migration_attempted"]:
            self.guard(self.candidate["platform_source"], self.candidate["artifact"]["authority"]["source_sha"])
        if "storefront" in self.candidate["services"]:
            old = self.candidate["previous"]["storefront"]
            require(storefront(old["directory"], old["sha"]) == {key: old[key] for key in ("directory", "sha", "overlays")},
                    "rollback_storefront_changed")
        if dry_run:
            return
        if state["phase"] in {"load", "fence"}:
            if "api" in self.candidate["services"]:
                for timer in TIMERS:
                    if self.candidate["previous"][timer] == "active":
                        run(["systemctl", "start", timer])
            self.phase_start("rolled_back")
            return
        if "api" in self.candidate["services"]:
            self.fence()
        if "storefront" in self.candidate["services"]:
            repository = Path(self.candidate["previous"]["storefront"]["directory"])
            run(["node", str(repository / "scripts/verify-vendored-artifacts.mjs")], cwd=repository)
        with contextlib.ExitStack() as stack:
            if "api" in self.candidate["services"]:
                lock = stack.enter_context(F2B_LOCK.open("r+"))
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            if self.candidate["migrations"]:
                run(["systemctl", "stop", SMS])
            for role in ("admin", "api"):
                if role in self.candidate["services"]:
                    run(["docker", "stop", self.runtime[role]["Name"]])
                    self.switch(role, self.candidate["previous"][role]["image"])
            if "storefront" in self.candidate["services"]:
                run(["systemctl", "stop", self.candidate["storefront_new_unit"]])
                old = self.candidate["previous"]["storefront"]
                self.start_storefront(old, self.candidate["storefront_new_unit"])
            if "api" in self.candidate["services"]:
                current = self.candidate["images"]["api"]["image_id"]
                old = self.candidate["previous"]["api"]["image"]
                if current != old and ('IMAGE = "' + current + '"').encode() in F2B_RUNNER.read_bytes():
                    self.pin(current, old)
            self.health()
        self.resume()
        self.phase_start("rolled_back")


def parser() -> argparse.ArgumentParser:
    value = argparse.ArgumentParser(description=__doc__)
    commands = value.add_subparsers(dest="command", required=True)
    for name in ("prepare", "apply", "rollback"):
        command = commands.add_parser(name)
        command.add_argument("--candidate", type=Path, required=True)
        command.add_argument("--dry-run", action="store_true")
        if name != "prepare":
            command.add_argument("--go", required=True, help="SHA256 of the exact Human-approved candidate identity")
    prepare = commands.choices["prepare"]
    prepare.add_argument("--task-id", required=True)
    prepare.add_argument("--artifact-directory", required=True)
    prepare.add_argument("--artifact-id", type=int, required=True)
    prepare.add_argument("--artifact-digest", required=True)
    prepare.add_argument("--platform-source", required=True)
    prepare.add_argument("--current-platform-source", required=True)
    prepare.add_argument("--services", nargs="+", choices=("api", "admin", "storefront"), required=True)
    prepare.add_argument("--migration", action="append", default=[])
    prepare.add_argument("--storefront-source")
    prepare.add_argument("--storefront-sha")
    prepare.add_argument("--storefront-unit")
    prepare.add_argument("--rollback-schema-compatible", action="store_true")
    return value


def main() -> int:
    deployment = None
    try:
        arguments = parser().parse_args()
        destination(arguments.candidate)
        if arguments.command == "prepare":
            baseline, baseline_sha = source(arguments.current_platform_source)
            candidate = {"version": 1, "target": "old-test", "task_id": arguments.task_id,
                "artifact": {"directory": str(Path(arguments.artifact_directory).resolve()),
                             "id": arguments.artifact_id, "digest": arguments.artifact_digest},
                "platform_source": str(Path(arguments.platform_source).resolve()),
                "current_platform_source": str(baseline), "current_platform_sha": baseline_sha,
                "services": arguments.services, "migrations": arguments.migration,
                "rollback_schema_compatible": arguments.rollback_schema_compatible,
                "state": {"phase": "prepared", "migration_attempted": False, "applied": []}}
            if "storefront" in arguments.services:
                require(arguments.storefront_source and arguments.storefront_sha and arguments.storefront_unit,
                        "storefront_inputs_required")
                candidate["storefront"] = storefront(arguments.storefront_source, arguments.storefront_sha)
                candidate["storefront_unit"] = arguments.storefront_unit
                candidate["storefront_new_unit"] = "luxe-pack-storefront-old-test-" + arguments.task_id.lower() + ".service"
            deployment = Deployment(candidate, arguments.candidate)
            deployment.preflight(preparing=True)
            if not arguments.dry_run:
                save(arguments.candidate, candidate, create=True)
        else:
            private_file(arguments.candidate)
            candidate = json.loads(arguments.candidate.read_bytes())
            deployment = Deployment(candidate, arguments.candidate)
            if arguments.dry_run:
                getattr(deployment, arguments.command)(arguments.go, dry_run=True)
            else:
                require(os.geteuid() == 0, "human_managed_root_required")
                with Path("/run/lock/oripa-v2-preview-deployment.lock").open("r+") as lock:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    getattr(deployment, arguments.command)(arguments.go)
        print(json.dumps({"status": "DRY_RUN" if arguments.dry_run else "PASS",
                          "candidate_identity": identity(candidate)}))
        return 0
    except Exception as error:
        print(json.dumps({"status": "HOLD", "phase": deployment.phase if deployment else "input",
                          "reason": str(error) if isinstance(error, Hold) else "operation_failed",
                          "recovery": "no automatic DB restore or worker restart; inspect candidate state"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
