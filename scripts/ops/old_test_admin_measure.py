"""Measure the selected OLD Test Node image. The resulting receipt has no runtime authority."""

import hashlib
import base64
import json
import os
import re
import shutil
import subprocess
import tempfile
import time
from pathlib import Path

import old_test_host as host
import old_test_simple as simple
from old_test_simple_source import PLATFORM, require

NATIVE = """const r=require('module').createRequire(require.resolve('next/package.json'));
const v=r('sharp').versions; console.log(JSON.stringify([process.version,require('next/package.json').version,
v.sharp,v.vips,process.getuid(),process.arch]));"""
HEALTH = """fetch('http://127.0.0.1:3000/api/health').then(async r=>{
if(r.status!==200)process.exit(2);console.log(JSON.stringify(await r.json()))}).catch(()=>process.exit(3))"""


def validate_reports(scan, sbom, identity, sha):
    require(
        scan.get("SchemaVersion") == 2
        and scan["Metadata"]["ImageID"] == identity
        and scan.get("Results")
        and scan["Metadata"]["OS"]["Family"],
        "admin_scan_identity_invalid",
    )
    require(
        not any(
            v.get("Severity") in ("HIGH", "CRITICAL")
            for r in scan["Results"]
            for v in (r.get("Vulnerabilities") or [])
        ),
        "admin_image_security_findings",
    )
    props = {p["name"]: p["value"] for p in sbom["metadata"]["component"]["properties"]}
    require(
        sbom["bomFormat"] == "CycloneDX"
        and sbom["components"]
        and props["aquasecurity:trivy:ImageID"] == identity
        and props["aquasecurity:trivy:Labels:org.opencontainers.image.revision"] == sha,
        "admin_sbom_identity_invalid",
    )


def selected(plan, guard):
    _, go = host.approved(plan, guard)
    require("admin" in plan["targets"], "admin_not_selected")
    item = host.cached(plan, "admin")
    identity = simple.asset(plan, "admin", item)
    require(simple.Site(plan).local_image("admin", item) == identity, "admin_local_identity_changed")
    policy = host.config(plan)["admin_gate"]
    previous_policy = json.loads(host.checked_file(Path(policy["denials"]), True))
    require(
        previous_policy["target"] == "old-test" and identity not in previous_policy["denied_image_ids"],
        "admin_image_denied",
    )
    return item, policy, go


def measure_image(item, policy, guard):
    identity, source = item["identity"], item["source"]
    data = json.loads(host.run(["/usr/bin/docker", "image", "inspect", identity]))[0]
    cfg = data["Config"]
    require(
        data["Id"] == identity
        and data["Os"] == "linux"
        and data["Architecture"] == "amd64"
        and cfg["User"] == "node"
        and not cfg.get("Volumes")
        and cfg["Labels"]["org.opencontainers.image.source"] == "https://github.com/" + PLATFORM
        and cfg["Labels"]["org.opencontainers.image.revision"] == source,
        "admin_image_or_user_invalid",
    )
    argv = [
        "/usr/bin/docker",
        "run",
        "--pull=never",
        "--network=none",
        "--read-only",
        "--cap-drop=ALL",
        "--security-opt=no-new-privileges",
        "--pids-limit=64",
        "--memory=512m",
        "--memory-swap=512m",
        "--cpus=0.75",
        "--tmpfs",
        "/tmp:rw,nosuid,nodev,size=32m",
        "--no-healthcheck",
        "--log-driver=none",
    ]
    guard()
    native = json.loads(host.run(argv + ["--rm", "--entrypoint", "node", identity, "-e", NATIVE]))
    require(
        native[:2] == [policy["node"], policy["next"]]
        and native[2]
        and native[3]
        and native[4] > 0
        and native[5] == "x64",
        "admin_node_native_baseline_changed",
    )
    probe = host.run(argv + ["-d", "--label", "oripa.oldtest.probe=admin", identity]).decode().strip()
    require(re.fullmatch("[a-f0-9]{64}", probe), "invalid_admin_probe_id")
    try:
        for attempt in range(12):
            guard()
            try:
                response = json.loads(
                    host.run(["/usr/bin/docker", "exec", probe, "node", "-e", HEALTH], timeout=8)
                )
            except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
                if attempt < 11:
                    time.sleep(1)
                    continue
                raise simple.Hold("admin_isolated_readiness_timeout") from None
            require(
                response
                == {
                    "checks": {"process": "ok"},
                    "component": "apps/admin",
                    "readiness_scope": "process",
                    "status": "ok",
                },
                "admin_isolated_health_failed",
            )
            break
    finally:
        observed = json.loads(host.run(["/usr/bin/docker", "inspect", probe]))[0]
        require(
            observed["Id"] == probe
            and observed["Image"] == identity
            and observed["Config"]["Labels"]["oripa.oldtest.probe"] == "admin"
            and observed["HostConfig"]["NetworkMode"] == "none",
            "admin_probe_identity_changed",
        )
        host.run(["/usr/bin/docker", "stop", "--time", "8", probe])
        host.run(["/usr/bin/docker", "rm", probe])


def reports(plan, item, policy, guard):
    identity = item["identity"]
    retained = policy.get("retained", {}).get(identity)
    if retained:
        values = []
        for name in ("scan", "sbom"):
            ref = retained[name]
            raw = host.checked_file(Path(ref["path"]), True)
            require(hashlib.sha256(raw).hexdigest() == ref["sha256"], "admin_retained_report_changed")
            values.append(json.loads(raw))
        validate_reports(*values, identity, item["source"])
        return [retained[n] for n in ("scan", "sbom")]
    root = simple.SESSIONS / "security"
    simple.trusted(root, directory=True, private=True)
    cache_key = simple.digest([identity, item["recipe"], policy["scanner"], policy["node"], policy["next"]])[
        7:
    ]
    report_dir = root / (item["role"] + "-" + cache_key)
    if not report_dir.exists():
        require(shutil.disk_usage(root).free > 4 * 1024**3, "admin_scan_needs_4gib_space")
        scanner = policy["scanner"]
        require(
            re.fullmatch(r"aquasec/trivy:[a-zA-Z0-9.-]+@sha256:[a-f0-9]{64}", scanner),
            "unregistered_admin_scanner",
        )
        with tempfile.TemporaryDirectory(dir=root) as temp:
            work = Path(temp)
            output = work / "reports"
            output.mkdir(mode=0o700)
            image_tar = work / "image.tar"
            guard()
            host.run(["/usr/bin/docker", "image", "save", "-o", str(image_tar), identity], timeout=300)
            # Dedicated scanner cache. No Docker socket, application network or application filesystem.
            cache = Path(policy["scanner_cache"])
            simple.trusted(cache, directory=True, private=True)
            args = [
                "/usr/bin/docker",
                "run",
                "--rm",
                "--pull=never",
                "--cap-drop=ALL",
                "--security-opt=no-new-privileges",
                "--cpus=1",
                "--memory=1g",
                "-v",
                str(image_tar) + ":/image.tar:ro",
                "-v",
                str(output) + ":/reports",
                "-v",
                str(cache) + ":/root/.cache",
                scanner,
                "image",
                "--skip-version-check",
            ]
            guard()
            host.run(
                args
                + [
                    "--scanners",
                    "vuln",
                    "--severity",
                    "CRITICAL,HIGH",
                    "--ignore-unfixed",
                    "--exit-code",
                    "1",
                    "--format",
                    "json",
                    "--output",
                    "/reports/scan.json",
                    "--input",
                    "/image.tar",
                ],
                timeout=600,
            )
            guard()
            host.run(
                args + ["--format", "cyclonedx", "--output", "/reports/sbom.json", "--input", "/image.tar"],
                timeout=300,
            )
            validate_reports(
                *(json.loads((output / n).read_bytes()) for n in ("scan.json", "sbom.json")),
                identity,
                item["source"],
            )
            for path in output.iterdir():
                os.chmod(path, 0o600)
            if not report_dir.exists():
                os.rename(output, report_dir)
    validate_reports(
        *(json.loads(host.checked_file(report_dir / n, True)) for n in ("scan.json", "sbom.json")),
        identity,
        item["source"],
    )
    return [
        {
            "path": str(report_dir / n),
            "sha256": hashlib.sha256(host.checked_file(report_dir / n, True)).hexdigest(),
        }
        for n in ("scan.json", "sbom.json")
    ]


def verify(plan, target, scope, guard):
    require(target == "admin", "admin_security_target_invalid")
    item, policy, go = selected(plan, guard)
    expected = dict(
        source=item["source"],
        identity=item["identity"],
        configuration=plan["configuration"],
        contract=plan["contract"],
        plan=simple.digest(plan),
        operation="switch",
        target="admin",
        deadline=go["expires"],
        artifact_id=item["artifact_id"],
        artifact_digest=item["artifact_digest"],
    )
    require(scope == expected, "admin_measurement_outside_approved_plan")
    folder = simple.SESSIONS / plan["session"]
    recipe = folder / "admin.Dockerfile"
    if not recipe.exists():
        content = host.call(
            plan,
            guard,
            "github-read",
            data="/repos/" + PLATFORM + "/contents/apps/admin/Dockerfile?ref=" + item["source"],
        )
        require(content["encoding"] == "base64", "admin_recipe_unavailable")
        with recipe.open("xb") as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(base64.b64decode(content["content"]))
    raw = host.checked_file(recipe, True)
    require(
        re.search(
            rb"(?m)^FROM node:"
            + re.escape(policy["node"][1:].encode())
            + rb"-alpine@sha256:[a-f0-9]{64}(?:\s|$)",
            raw,
        )
        and re.search(rb"(?m)^USER node\s*$", raw),
        "admin_recipe_baseline_changed",
    )
    reports(plan, item, policy, guard)
    measure_image(item, policy, guard)
    host.approved(plan, guard)
    now = time.time()
    return dict(
        result="PASS",
        binding=expected,
        measured=now,
        expires=min(now + 300, go["expires"]),
        receipt_digest=simple.digest([expected, now, policy["scanner"]]),
    )
