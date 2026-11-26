"""Prepare an isolated next-start release and its recoverable systemd definition."""

import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import tarfile
import tempfile
import time

import old_test_host as host
import old_test_simple as simple
from old_test_simple_source import SITE, require

UNIT = re.compile(r"luxe-pack-storefront-old-test-pr[1-9][0-9]*\.service")


def release_path(plan, spec):
    src = plan["sources"][SITE]
    require(
        type(src["pr"]) is int and src["pr"] > 0 and simple.SHA.fullmatch(src["sha"]),
        "invalid_storefront_source",
    )
    name = spec["release_pattern"].replace("{pr}", str(src["pr"]))
    require(re.fullmatch("[a-zA-Z0-9_-]+", name), "invalid_release_name")
    return Path(spec["parent"]) / name


def ref(path):
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def built_files(release):
    result = hashlib.sha256()
    roots = [release / ".next/server", release / ".next/static"]
    files = [
        release / "pnpm-lock.yaml",
        release / ".next/BUILD_ID",
        release / ".next/required-server-files.json",
    ]
    for root in roots:
        require(root.is_dir(), "storefront_build_output_missing")
        files.extend(p for p in root.rglob("*") if p.is_file())
    for path in sorted(files):
        require(path.resolve(strict=True).is_relative_to(release), "storefront_build_symlink_outside_release")
        result.update(str(path.relative_to(release)).encode() + b"\0")
        result.update(hashlib.sha256(path.read_bytes()).digest())
    return result.hexdigest()


def seal_release(data_dir, staging, user, scratch):
    # The build user owns its working tree. Read it with that account's access,
    # then unpack one immutable stream into a root-private directory.
    with tempfile.TemporaryFile(dir=scratch) as stream:
        process = subprocess.run(
            [
                "/usr/sbin/runuser",
                "-u",
                user,
                "--",
                "/usr/bin/prlimit",
                "--fsize=3221225472",
                "--",
                "/usr/bin/tar",
                "--one-file-system",
                "--hard-dereference",
                "-C",
                str(data_dir),
                "-cf",
                "-",
                ".",
            ],
            stdout=stream,
            stderr=subprocess.DEVNULL,
            timeout=240,
        )
        process.check_returncode()
        stream.seek(0)
        with tarfile.open(fileobj=stream, mode="r|") as archive:
            for member in archive:
                require(member.isdir() or member.isfile() or member.issym(), "storefront_special_file")
                # Keep staging inaccessible until its final ownership and modes
                # are checked. The archive's root-directory mode is untrusted.
                if member.name == ".":
                    continue
                archive.extract(member, staging, filter="data")
    for child in staging.rglob("*"):
        if child.is_symlink():
            require(child.resolve(strict=True).is_relative_to(staging), "storefront_external_dependency_link")
        else:
            os.chmod(child, 0o755 if child.is_dir() else (child.stat().st_mode & 0o111) | 0o644)


def unit_fields(name):
    require(UNIT.fullmatch(name), "unexpected_storefront_unit")
    out = host.run(
        [
            "/usr/bin/systemctl",
            "show",
            name,
            "--property=Id,User,ActiveState,WorkingDirectory,FragmentPath,DropInPaths",
        ]
    )
    return dict(line.split("=", 1) for line in out.decode().splitlines())


def live(spec):
    pattern = "luxe-pack-storefront-old-test-pr*.service"
    rows = (
        host.run(
            [
                "/usr/bin/systemctl",
                "list-units",
                pattern,
                "--state=running",
                "--no-legend",
                "--plain",
                "--no-pager",
            ]
        )
        .decode()
        .splitlines()
    )
    names = [line.split()[0] for line in rows if line.split() and UNIT.fullmatch(line.split()[0])]
    require(len(names) == 1, "storefront_listener_unit_ambiguous")
    name = names[0]
    unit = unit_fields(name)
    release = Path(unit["WorkingDirectory"])
    require(
        release.is_absolute()
        and release.resolve(strict=True) == release
        and release.parent == Path(spec["parent"])
        and unit["User"] == spec["user"]
        and unit["ActiveState"] == "active"
        and not unit["DropInPaths"],
        "storefront_runtime_binding_changed",
    )
    raw = host.checked_file(Path(unit["FragmentPath"]))
    normalized = raw.replace(str(release).encode(), b"BOUND_RELEASE")
    require(
        hashlib.sha256(normalized).hexdigest() == spec["unit_template"],
        "storefront_service_definition_changed",
    )
    manifest = release / "artifact-manifest.json"
    info = json.loads(host.checked_file(manifest))
    simple.storefront_layout(str(release), ref(manifest), info["build_id"])
    return name, release, raw


def current(settings):
    spec = settings["storefront"]
    _, release, _ = live(spec)
    return ref(release / "artifact-manifest.json"), spec["unit_template"]


def recipe(plan, settings):
    spec = settings["storefront"]
    return simple.digest(
        [
            plan["sources"][SITE]["sha"],
            plan["contract"],
            spec["node_digest"],
            spec["pnpm_version"],
            spec["public_environment"],
            spec["unit_template"],
        ]
    )


def paths(plan):
    spec = host.config(plan)["storefront"]
    release = release_path(plan, spec)
    return spec, release, "luxe-pack-storefront-old-test-pr" + str(plan["sources"][SITE]["pr"]) + ".service"


def local_asset(plan):
    spec, release, _ = paths(plan)
    if not release.exists():
        return None
    stored = host.cached(plan, "storefront")
    require(stored, "storefront_release_requires_provenance")
    manifest = json.loads(host.checked_file(release / "artifact-manifest.json"))
    require(
        manifest["source_sha"] == plan["sources"][SITE]["sha"]
        and manifest["target"] == "old-test"
        and manifest["gtm_enabled"] is False
        and manifest["gtm_id"] is None,
        "storefront_manifest_mismatch",
    )
    simple.storefront_layout(str(release), stored["identity"], manifest["build_id"])
    if "compiled_sha256" in manifest:
        require(built_files(release) == manifest["compiled_sha256"], "storefront_compiled_files_changed")
    else:
        require(
            ref(release / "FILE_SHA256SUMS")[7:] == manifest["file_manifest_sha256"],
            "storefront_file_manifest_changed",
        )
    simple.asset(plan, "storefront", stored)
    return stored


def stage(plan, target, data, guard):
    folder, _ = host.approved(plan, guard)
    require(
        target == "storefront" and target in plan["targets"] and data == ["storefront"],
        "storefront_build_scope",
    )
    spec, release, _ = paths(plan)
    if local_asset(plan):
        return
    source = plan["sources"][SITE]["sha"]
    require(recipe(plan, host.config(plan)) == plan["recipes"]["storefront"], "storefront_recipe_changed")
    repo = Path(spec["repository"])
    node = Path(spec["node"])
    require(
        ref(node) == spec["node_digest"]
        and host.run([str(node), "--version"]).decode().strip() == spec["node_version"],
        "review_storefront_node_update",
    )
    root = Path(spec["build_root"])
    for path in (root, release.parent):
        simple.trusted(path, directory=True)
        require(shutil.disk_usage(path).free >= 3 * 1024**3, "storefront_build_needs_3gib")
    work = root / plan["session"]
    work.mkdir(mode=0o755, exist_ok=True)
    simple.trusted(work, directory=True)
    os.chmod(work, 0o755)
    tree = host.run(["/usr/bin/git", "-C", str(repo), "rev-parse", source + "^{tree}"]).decode().strip()
    require(simple.SHA.fullmatch(tree), "storefront_source_not_cached")
    user = pwd.getpwnam(spec["user"])
    data_dir = work / "source"
    private_store = work / "pnpm-store"
    private_store.mkdir(mode=0o700, exist_ok=True)
    os.chown(private_store, user.pw_uid, user.pw_gid)
    home = work / "home"
    home.mkdir(mode=0o700, exist_ok=True)
    os.chown(home, user.pw_uid, user.pw_gid)
    progress = folder / "storefront-build-progress.json"
    stages = (
        json.loads(host.checked_file(progress, True)) if progress.exists() else {"plan": simple.digest(plan)}
    )
    require(stages["plan"] == simple.digest(plan), "storefront_build_plan_changed")
    archive = work / "source.tar"
    if not stages.get("source"):
        require(not data_dir.exists(), "storefront_partial_source_requires_reconciliation")
        data_dir.mkdir(mode=0o755)
        os.chown(data_dir, user.pw_uid, user.pw_gid)
        with archive.open("xb") as out:
            subprocess.run(["/usr/bin/git", "-C", str(repo), "archive", source], stdout=out, check=True)
        os.chmod(archive, 0o644)
        host.run(
            [
                "/usr/sbin/runuser",
                "-u",
                user.pw_name,
                "--",
                "/usr/bin/tar",
                "--no-same-owner",
                "-xf",
                str(archive),
                "-C",
                str(data_dir),
            ]
        )
        stages["source"] = source
        simple.persist(progress, stages)
    require(stages["source"] == source, "storefront_stage_source_mismatch")
    require(not any(p.name != ".env.example" for p in data_dir.glob(".env*")), "unexpected_storefront_env_file")

    def sandbox(cmd, seconds=600):
        guard()
        args = [
            "/usr/bin/systemd-run",
            "--quiet",
            "--wait",
            "--pipe",
            "--collect",
            "--uid=" + user.pw_name,
            "--working-directory=" + str(data_dir),
            "--property=PrivateNetwork=yes",
            "--property=ProtectHome=yes",
            "--property=PrivateTmp=yes",
            "--property=NoNewPrivileges=yes",
            "--property=ProtectSystem=strict",
            "--property=ReadWritePaths=" + str(data_dir) + " " + str(private_store) + " " + str(home),
            "--property=RuntimeMaxSec=" + str(seconds),
            "--property=CPUQuota=100%",
            "--property=MemoryMax=2G",
            "--",
            "/usr/bin/env",
            "-i",
            "HOME=" + str(home),
            "NODE_ENV=production",
            "NEXT_TELEMETRY_DISABLED=1",
            "PATH=" + str(node.parent) + ":/usr/local/bin:/usr/bin:/bin",
        ]
        for k, v in spec["public_environment"].items():
            require(k.startswith("NEXT_PUBLIC_") and k != "NEXT_PUBLIC_GTM_ID", "unapproved_storefront_env")
            args.append(k + "=" + v)
        return host.run(args + cmd, timeout=seconds + 30)

    sandbox([str(node), "scripts/verify-vendored-artifacts.mjs"])
    require(
        sandbox([spec["pnpm"], "--version"]).decode().strip() == spec["pnpm_version"],
        "storefront_pnpm_changed",
    )
    # pnpm writes index metadata even offline. Give it an independent copy-on-write
    # store; the existing development/storefront cache remains read-only.
    if not stages.get("dependencies"):
        sandbox(
            [
                "/usr/bin/cp",
                "-a",
                "--reflink=auto",
                "--no-preserve=ownership",
                spec["dependency_store"] + "/.",
                str(private_store),
            ]
        )
        sandbox(
            [
                spec["pnpm"],
                "install",
                "--frozen-lockfile",
                "--offline",
                "--prod=false",
                "--store-dir",
                str(private_store),
            ]
        )
        stages["dependencies"] = True
        simple.persist(progress, stages)
    if not stages.get("built"):
        require(not stages.get("building"), "storefront_build_status_unknown_inspect_output")
        stages["building"] = True
        simple.persist(progress, stages)
        sandbox([spec["pnpm"], "build"])
        stages["built"] = True
        simple.persist(progress, stages)
    sandbox([str(node), "scripts/verify-gtm-build.mjs", "observe", ".", "disabled"])
    staging = Path(tempfile.mkdtemp(prefix=".oldtest-release-", dir=release.parent))
    try:
        seal_release(data_dir, staging, user.pw_name, folder)
        build_id = (staging / ".next/BUILD_ID").read_text().strip()
        observation = json.loads((staging / ".next/gtm-build-observation.json").read_bytes())
        require(
            build_id
            and observation["build_id"] == build_id
            and observation["gtm_enabled"] is False
            and observation["gtm_id"] is None,
            "storefront_build_observation_invalid",
        )
        manifest = dict(
            source_sha=source,
            git_tree=tree,
            build_id=build_id,
            contract=plan["contract"],
            target="old-test",
            gtm_enabled=False,
            gtm_id=None,
            recipe=plan["recipes"]["storefront"],
        )
        manifest["compiled_sha256"] = built_files(staging)
        m = staging / "artifact-manifest.json"
        require(not m.exists() and not m.is_symlink(), "unexpected_source_manifest")
        m.write_text(json.dumps(manifest, sort_keys=True))
        os.chmod(m, 0o644)
        identity = ref(m)
        simple.storefront_layout(str(staging), identity, build_id)
        guard()
        require(not release.exists(), "storefront_release_already_exists")
        os.chmod(staging, 0o755)
        os.rename(staging, release)
    finally:
        if staging.exists():
            shutil.rmtree(staging)
    item = dict(
        target="old-test",
        role="storefront",
        repository=SITE,
        source=source,
        recipe=plan["recipes"]["storefront"],
        contract=plan["contract"],
        identity=identity,
        verified_local_identity=identity,
        runtime="next start",
        dependencies_verified=True,
        vendored_contract_verified=True,
    )
    simple.persist(simple.SESSIONS / "assets/storefront" / (source + ".json"), item)
    require(
        work.parent == root
        and work.name == plan["session"]
        and work.resolve(strict=True) == work
        and local_asset(plan) == item,
        "storefront_build_cleanup_binding_changed",
    )
    for scratch in (private_store, home, data_dir):
        if scratch.is_dir() and not scratch.is_symlink():
            shutil.rmtree(scratch)
    archive.unlink(missing_ok=True)


def observe(plan, op, expected, guard):
    host.approved(plan, guard)
    if op == "prepare-build":
        if local_asset(plan):
            return "done"
        journal = simple.SESSIONS / plan["session"] / "storefront-build-progress.json"
        return "resumable" if journal.exists() else "ready"
    require(op == "switch", "storefront_observation_scope")
    identity, _ = current(host.config(plan))
    if identity == expected:
        return "done"
    require(identity == plan["previous"]["storefront"], "storefront_runtime_changed")
    return "ready"


def staged_listener(plan, release, spec, guard):
    import secrets

    unit = "oldtest-simple-stage-" + secrets.token_hex(8) + ".service"
    cli = release / "node_modules/next/dist/bin/next"
    argv = [
        "/usr/bin/systemd-run",
        "--quiet",
        "--collect",
        "--unit=" + unit,
        "--uid=" + spec["user"],
        "--working-directory=" + str(release),
        "--property=PrivateNetwork=yes",
        "--property=ProtectSystem=strict",
        "--property=PrivateTmp=yes",
        "--property=ProtectHome=yes",
        "--property=NoNewPrivileges=yes",
        "--property=CPUQuota=50%",
        "--property=MemoryMax=384M",
        "--property=RuntimeMaxSec=90",
        "--setenv=NODE_ENV=production",
        "--setenv=NEXT_TELEMETRY_DISABLED=1",
        spec["node"],
        str(cli),
        "start",
        "--hostname",
        "127.0.0.1",
        "--port",
        "3279",
    ]
    guard()
    host.run(argv)
    try:
        for attempt in range(30):
            guard()
            pid = int(host.run(["/usr/bin/systemctl", "show", unit, "-p", "MainPID", "--value"]).strip() or 0)
            if pid > 1:
                proc = Path("/proc") / str(pid)
                require(
                    unit in (proc / "cgroup").read_text()
                    and (proc / "ns/net").stat().st_ino != Path("/proc/self/ns/net").stat().st_ino,
                    "staged_listener_not_isolated",
                )
                try:
                    http = host.run(
                        [
                            "/usr/bin/nsenter",
                            "--target",
                            str(pid),
                            "--net",
                            "--",
                            "/usr/bin/curl",
                            "--disable",
                            "--noproxy",
                            "*",
                            "--max-time",
                            "2",
                            "--silent",
                            "--output",
                            "/dev/null",
                            "--write-out",
                            "%{http_code}",
                            "http://127.0.0.1:3279/",
                        ],
                        timeout=5,
                    )
                    if http == b"200":
                        return
                except subprocess.CalledProcessError:
                    pass
            time.sleep(0.5)
        raise simple.Hold("storefront_staged_listener_timeout")
    finally:
        if host.run(["/usr/bin/systemctl", "show", unit, "-p", "Transient", "--value"]).strip() == b"yes":
            host.run(["/usr/bin/systemctl", "stop", unit])


def verify(plan, target, data, guard):
    folder, go = host.approved(plan, guard)
    spec, release, _ = paths(plan)
    item = local_asset(plan)
    require(target in plan["targets"] and target == "storefront", "storefront_not_selected")
    expected = dict(
        source=item["source"],
        identity=item["identity"],
        configuration=plan["configuration"],
        contract=plan["contract"],
        plan=simple.digest(plan),
        operation="switch",
        target=target,
        deadline=go["expires"],
    )
    require(data == expected, "storefront_outside_approved_scope")
    require(ref(Path(spec["node"])) == spec["node_digest"], "storefront_node_changed")
    staged_listener(plan, release, spec, guard)
    now = time.time()
    receipt = dict(
        result="PASS",
        binding=expected,
        measured=now,
        expires=min(now + 300, go["expires"]),
        receipt_digest=simple.digest([expected, now]),
    )
    simple.persist(folder / "storefront-health.json", receipt)
    return receipt


def switch_binding(plan, target, identity, guard):
    folder, go = host.approved(plan, guard)
    spec, release, new = paths(plan)
    old, previous, raw = live(spec)
    item = local_asset(plan)
    receipt = json.loads(host.checked_file(folder / "storefront-health.json", True))
    require(
        target == "storefront"
        and target in plan["targets"]
        and old != new
        and item["identity"] == identity
        and receipt["binding"]["plan"] == simple.digest(plan)
        and receipt["binding"]["identity"] == identity
        and time.time() < receipt["expires"] <= go["expires"],
        "storefront_transition_changed",
    )
    manifest = json.loads(host.checked_file(release / "artifact-manifest.json"))
    definitions = Path("/run/systemd/system")
    fallback_manifest = previous / "artifact-manifest.json"
    fallback_identity = ref(fallback_manifest)
    require(fallback_identity == plan["previous"]["storefront"], "previous_storefront_release_changed")
    fallback_record = folder / "storefront-fallback.json"
    fallback = {
        "unit": old,
        "directory": str(previous),
        "identity": fallback_identity,
        "definition": hashlib.sha256(raw).hexdigest(),
    }
    if not fallback_record.exists():
        staged_listener(plan, previous, spec, guard)
        simple.persist(fallback_record, fallback)
    require(
        json.loads(host.checked_file(fallback_record, True)) == fallback,
        "storefront_recovery_definition_changed",
    )
    simple.trusted(definitions, directory=True)
    # A transient unit ceases to exist when stopped. Preserve the measured definition
    # as a lower-priority runtime unit before attempting the port handover.
    for name, body in ((old, raw), (new, raw.replace(str(previous).encode(), str(release).encode()))):
        require(UNIT.fullmatch(name), "storefront_unit_invalid")
        path = definitions / name
        guard()
        if path.exists():
            require(host.checked_file(path) == body, "storefront_unit_collision")
        else:
            with path.open("xb") as stream:
                os.fchmod(stream.fileno(), 0o644)
                stream.write(body)
                stream.flush()
                os.fsync(stream.fileno())
    guard()
    host.run(["/usr/bin/systemctl", "daemon-reload"])
    require(
        unit_fields(old)["WorkingDirectory"] == str(previous)
        and unit_fields(new)["WorkingDirectory"] == str(release),
        "storefront_staged_unit_invalid",
    )
    return dict(
        target=target,
        identity=identity,
        plan=simple.digest(plan),
        environment="old-test",
        old_unit=old,
        new_unit=new,
        release_directory=str(release),
        build_id=manifest["build_id"],
        staged_health=True,
        fallback_start_verified=True,
        fallback_directory=str(previous),
    )
