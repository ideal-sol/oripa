"""Installed OLD Test adapters. Private site data is bound by the plan's registry digest."""

import hashlib
import json
import re
import runpy
import subprocess
import time
from pathlib import Path

import old_test_deploy as canonical
import old_test_simple as simple
from old_test_simple_source import PLATFORM, SITE, require


def checked_file(path, private=False):
    for parent in path.parents:
        simple.trusted(parent, directory=True)
    simple.trusted(path, private=private)
    return path.read_bytes()


def config(plan):
    raw = checked_file(simple.BINDINGS, True)
    require(
        plan["environment"] == "old-test"
        and simple.SESSION.fullmatch(plan["session"])
        and "sha256:" + hashlib.sha256(raw).hexdigest() == plan["site_policy"],
        "old_site_policy_changed",
    )
    settings = json.loads(raw)["site"]
    require(
        hashlib.sha256(Path("/etc/machine-id").read_bytes()).hexdigest() == settings["machine"],
        "wrong_machine",
    )
    return settings


def approved(plan, guard):
    guard()
    config(plan)
    folder = simple.SESSIONS / plan["session"]
    bound = json.loads(checked_file(folder / "plan.json", True))
    go = json.loads(checked_file(folder / "go.json", True))
    require(
        bound == plan
        and go["plan"] == simple.digest(plan)
        and go["session"] == plan["session"]
        and go["decision"] == "GO"
        and type(go["issued"]) is int
        and type(go["expires"]) is int
        and 0 < go["expires"] - go["issued"] <= 3600
        and go["issued"] <= time.time() < go["expires"],
        "approved_session_required",
    )
    return folder, go


def pinned(ref):
    path = Path(ref["path"])
    require(hashlib.sha256(checked_file(path)).hexdigest() == ref["sha256"], "registered_tool_changed")
    return runpy.run_path(str(path))


def call(plan, guard, operation, target="", data=None):
    site = simple.Site(plan)
    site.guard = guard
    return site.call(operation, target, data)


def cached(plan, role):
    repo = SITE if role == "storefront" else PLATFORM
    sha = plan["sources"][repo]["sha"]
    require(simple.SHA.fullmatch(sha) and role in ("api", "admin", "sms", "storefront"), "asset_key_invalid")
    path = simple.SESSIONS / "assets" / role / (sha + ".json")
    if path.exists():
        return json.loads(checked_file(path, True))


def stable_container(current):
    # Docker/Compose changes generated labels, addresses and IDs on recreate.
    # Bind the actual authority-bearing settings independently of image selection.
    cfg, host = current["Config"], current["HostConfig"]
    return simple.digest(
        {
            "process": {k: cfg.get(k) for k in ("Entrypoint", "Cmd", "User", "WorkingDir")},
            "environment": sorted(cfg.get("Env") or []),
            "mounts": sorted((m["Type"], m["Source"], m["Destination"], m["RW"]) for m in current["Mounts"]),
            "networks": {k: v["NetworkID"] for k, v in current["NetworkSettings"]["Networks"].items()},
            "host": {
                k: host.get(k)
                for k in (
                    "Privileged",
                    "CapAdd",
                    "CapDrop",
                    "Devices",
                    "SecurityOpt",
                    "PidMode",
                    "IpcMode",
                    "PortBindings",
                    "ReadonlyRootfs",
                    "RestartPolicy",
                )
            },
        }
    )


def container(settings, role):
    spec = settings["containers"][role]
    current = canonical.inspect(spec["name"])
    require(
        current["Name"] == "/" + spec["name"] and stable_container(current) == spec["configuration"],
        "old_container_configuration_changed_" + role,
    )
    return current


def compose_identity(current):
    unsafe = {
        "DOCKER_HOST",
        "DOCKER_CONTEXT",
        "DOCKER_CONFIG",
        "LD_PRELOAD",
        "LD_LIBRARY_PATH",
        "COMPOSE_FILE",
        "COMPOSE_PROFILES",
        "BASH_ENV",
        "ENV",
        "PYTHONPATH",
        "PYTHONHOME",
        "HOME",
    }
    require(
        not any(v.partition("=")[0] in unsafe for v in current["Config"]["Env"]), "unsafe_compose_environment"
    )
    command, env = canonical.compose(current)
    for part in env.get("PATH", "").split(":"):
        path = Path(part)
        require(path.is_absolute(), "unsafe_compose_host_path")
        if path.exists():
            simple.trusted(path.resolve(strict=True), directory=True)
    # Only operator-provisioned Compose inputs may instruct the root coordinator.
    for index, arg in enumerate(command):
        if arg in ("-f", "--env-file"):
            path = Path(command[index + 1])
            checked_file(path)
    raw = canonical.run(command + ["config", "--format", "json"], environment=env)
    model = json.loads(raw)
    service = current["Config"]["Labels"]["com.docker.compose.service"]
    selected = model["services"][service]
    require(
        not selected.get("privileged") and not selected.get("network_mode"), "unsafe_service_configuration"
    )
    selected["image"] = "approved-runtime-image"
    return "sha256:" + hashlib.sha256(raw).hexdigest(), simple.digest(model)


def compose_binding(current, policy):
    exact, normalized = compose_identity(current)
    require(normalized == policy["compose"], "enrolled_compose_configuration_changed")
    return exact


def service_switch(plan, role, image, guard):
    approved(plan, guard)
    require(role in plan["targets"] and role in ("api", "admin"), "service_outside_plan")
    item = cached(plan, role)
    require(simple.asset(plan, role, item) == image, "unadmitted_switch_image")
    current = container(config(plan), role)
    require(
        current["State"]["Running"] and current["Image"] == plan["previous"][role], "service_identity_changed"
    )
    return dict(
        environment="old-test",
        target=role,
        plan=simple.digest(plan),
        identity=image,
        container=current["Id"],
        previous=current["Image"],
        compose_digest=compose_binding(current, config(plan)["containers"][role]),
    )


def observed_service(plan, role, desired):
    current = container(config(plan), role)
    require(current["State"]["Running"], "service_outcome_requires_reconciliation")
    if current["Image"] == desired:
        return "done"
    require(current["Image"] == plan["previous"][role], "unexpected_service_image")
    return "ready"


def run(argv, timeout=60):
    proc = subprocess.run(argv, capture_output=True, timeout=timeout)
    if proc.returncode:
        # Return a known diagnostic identifier rather than private stderr or argv.
        detail = re.search(
            rb"\b(?:ERR_PNPM_[A-Z_]{2,60}|ENOSPC|EACCES|EROFS|ECONNRESET)\b", proc.stderr + proc.stdout
        )
        reason = Path(argv[0]).name.replace("-", "_") + "_exit_" + str(proc.returncode)
        if detail:
            reason += "_" + detail[0].decode().lower()
        error = subprocess.CalledProcessError(proc.returncode, Path(argv[0]).name)
        error.diagnostic = reason
        raise error
    return proc.stdout
