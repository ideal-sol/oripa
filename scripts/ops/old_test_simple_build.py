"""Authenticated OLD Test AMD64 workflow adapter. No user-supplied URL, ref or workflow."""

import base64
import hashlib
import json
import os
import shutil
import tempfile
import time
import zipfile
from pathlib import Path

import old_test_host as host
import old_test_simple as simple
from old_test_simple_source import Hold, PLATFORM, merged_source, recipe_identity, require

REPO = "/repos/" + PLATFORM
FLOW = REPO + "/actions/workflows/old-test-main-artifact.yml"
ROLES = {"api": "api-only", "admin": "admin-only", "api+admin": "normal"}
FILES = {"api": "infra/docker/backend/Dockerfile", "admin": "apps/admin/Dockerfile"}
CONTROLS = (
    simple.WORKFLOW,
    "scripts/ops/preview_image_artifact.py",
    "scripts/ops/old_test_source_authority.py",
    "scripts/ops/old_test_simple_source.py",
)


def build_inputs(plan, group):
    _, roles = simple.shape(plan, plan["targets"])
    require(group in ROLES and set(group.split("+")) <= set(roles), "unapproved_build_group")
    source = plan["sources"][PLATFORM]
    return {
        "ref": "main",
        "inputs": {
            "task_id": plan["session"],
            "source_sha": source["sha"],
            "pr_number": source["pr"],
            "simple_mode": ROLES[group],
            "simple_plan": simple.digest(plan),
        },
    }


def title(payload):
    inputs = payload["inputs"]
    return "OLD Test {simple_plan} {simple_mode} {source_sha}".format(**inputs)


def read(api, path, guard):
    for attempt in range(3):
        guard()
        try:
            return api["api_get"](path)
        except (Exception, SystemExit) as error:
            code = str(error)
            if code not in (
                "github_api_http_502",
                "github_api_http_503",
                "github_api_http_504",
                "github_api_connection_failed",
                "github_api_timeout",
            ):
                if code in ("github_api_http_403", "github_api_http_401"):
                    raise Hold("actions_read_denied_check_app_installation") from None
                raise Hold("actions_read_failed_check_workflow_or_run") from None
            if attempt < 2:
                time.sleep(attempt + 1)
    raise Hold("actions_read_temporarily_unavailable_resume_session")


def scoped_run(run, actor):
    return (
        run.get("event") == "workflow_dispatch"
        and run.get("path") == simple.WORKFLOW
        and run.get("head_branch") == "main"
        and run.get("repository", {}).get("full_name") == PLATFORM
        and run.get("run_attempt") == 1
        and run.get("actor", {}).get("id") == actor
    )


def artifact_name(payload):
    inputs = payload["inputs"]
    return "oripa-old-test-simple-images-{task_id}-{source_sha}".format(**inputs)


def legacy_completed(api, actor, payload, guard):
    # Older protected-main controls do not include the plan in the display
    # title. Discover by exact artifact name, then check embedded authority on admission.
    name = artifact_name(payload)
    rows = read(api, REPO + "/actions/artifacts?name=" + name + "&per_page=10", guard)["artifacts"]
    candidates = [r for r in rows if r["name"] == name and not r["expired"]]
    require(len(candidates) <= 1, "ambiguous_legacy_build_artifact")
    if candidates:
        run = read(api, REPO + "/actions/runs/" + str(candidates[0]["workflow_run"]["id"]), guard)
        require(
            scoped_run(run, actor) and run["status"] == "completed" and run["conclusion"] == "success",
            "legacy_artifact_run_invalid",
        )
        return run


def matching_run(api, actor, payload, guard, known=None):
    if known:
        run = read(api, REPO + "/actions/runs/" + str(known), guard)
        if not (scoped_run(run, actor) and run.get("display_title") == title(payload)):
            recovered = legacy_completed(api, actor, payload, guard)
            require(recovered and recovered["id"] == known, "recorded_build_identity_changed")
        return run
    for page in range(1, 6):
        page_data = read(
            api, FLOW + "/runs?branch=main&event=workflow_dispatch&per_page=50&page=" + str(page), guard
        )
        runs = page_data["workflow_runs"]
        matches = [r for r in runs if scoped_run(r, actor) and r.get("display_title") == title(payload)]
        require(len(matches) <= 1, "duplicate_scoped_build_runs")
        if matches:
            return matches[0]
        if len(runs) < 50:
            break
    return legacy_completed(api, actor, payload, guard)


def context(plan, group, guard):
    folder, _ = host.approved(plan, guard)
    settings = host.config(plan)["actions"]
    api = host.pinned(settings["reader"])
    payload = build_inputs(plan, group)
    marker = folder / ("build-" + ROLES[group] + ".json")
    record = json.loads(host.checked_file(marker, True)) if marker.exists() else {}
    require(not record or record["payload"] == payload, "recorded_build_scope_changed")
    run = matching_run(api, settings["actor_id"], payload, guard, record.get("run"))
    if run and not record.get("run"):
        simple.persist(marker, {"payload": payload, "run": run["id"]})
    return api, settings, payload, marker, record, run


def status(plan, group, guard):
    api, _, payload, marker, record, run = context(plan, group, guard)
    if not run:
        if record.get("rejected"):
            require(record["attempts"] < 2, "formal_dispatch_still_rejected_" + record["rejected"])
            return "resumable"
        return "in-progress" if record else "ready"
    if run["status"] != "completed":
        return "in-progress"
    require(run["conclusion"] == "success", "formal_build_failed_inspect_run_" + str(run["id"]))
    rows = read(api, REPO + "/actions/runs/" + str(run["id"]) + "/artifacts", guard)["artifacts"]
    name = artifact_name(payload)
    items = [r for r in rows if r["name"] == name and not r["expired"] and simple.DIGEST.fullmatch(r["digest"])]
    require(len(items) == 1, "formal_artifact_missing_or_expired")
    simple.persist(marker, {"payload": payload, "run": run["id"], "artifact": items[0]["id"]})
    return "done"


def ensure(plan, group, data, guard):
    api, settings, payload, marker, record, run = context(plan, group, guard)
    require(data == {**payload, "workflow": simple.WORKFLOW}, "dispatch_inputs_outside_plan")
    if run or (record and not record.get("rejected")):
        return
    attempts = record.get("attempts", 0) + 1
    require(attempts <= 2, "formal_dispatch_retry_limit")
    require(
        all(host.cached(plan, role) is None for role in group.split("+")),
        "formal_image_became_available_resume_acquisition",
    )
    src = plan["sources"][PLATFORM]
    merged_source(
        PLATFORM, src["pr"], src["sha"], lambda path: host.call(plan, guard, "github-read", data=path)
    )
    require(
        current_recipes(plan, group.split("+"), guard) == {r: plan["recipes"][r] for r in group.split("+")},
        "approved_build_recipe_changed",
    )
    post = host.pinned(settings["dispatcher"])["request"]
    # Match the Canonical dispatch wire format while retaining the typed plan and journal.
    wire_inputs = dict(payload["inputs"], pr_number=str(src["pr"]))
    host.approved(plan, guard)
    simple.persist(marker, {"payload": payload, "attempted": True, "attempts": attempts})
    try:
        code, reply = post("POST", FLOW + "/dispatches", dict(payload, inputs=wire_inputs))
    except (Exception, SystemExit) as error:
        code = str(error)
        if code in ("github_http_401", "github_http_403", "github_http_404", "github_http_422"):
            # GitHub definitively rejected this POST. An operator can correct the
            # cause and resume this plan after checking for an existing run.
            simple.persist(marker, {"payload": payload, "rejected": code, "attempts": attempts})
            raise Hold("formal_dispatch_rejected_" + code) from None
        # A lost acknowledgement may have started a run. Observe, never POST a second time.
        return
    require(code in (200, 201, 204), "formal_dispatch_response_unknown")
    if isinstance(reply, dict) and type(reply.get("workflow_run_id")) is int:
        simple.persist(marker, {"payload": payload, "run": reply["workflow_run_id"]})


def current_recipes(plan, roles, guard):
    get = lambda path: host.call(plan, guard, "github-read", data=REPO + "/" + path)
    control = get("branches/main")["commit"]["sha"]
    require(simple.SHA.fullmatch(control), "invalid_workflow_control")
    blobs = [get("contents/" + path + "?ref=" + control)["sha"] for path in CONTROLS]
    source = plan["sources"][PLATFORM]["sha"]
    result = {}
    for role in roles:
        content = get("contents/" + FILES[role] + "?ref=" + source)
        require(content["encoding"] == "base64", "invalid_recipe_content")
        raw = base64.b64decode(content["content"])
        folder = simple.SESSIONS / plan["session"]
        path = folder / (role + ".Dockerfile")
        if path.exists():
            require(host.checked_file(path, True) == raw, "prepared_recipe_changed")
        else:
            with path.open("xb") as stream:
                os.fchmod(stream.fileno(), 0o600)
                stream.write(raw)
        result[role] = recipe_identity(role, raw, blobs)
    return result


def remote_recipes(plan, roles, guard):
    """Adopt an available formal artifact before scheduling any new workflow."""
    settings = host.config(plan)["actions"]
    api = host.pinned(settings["reader"])
    src = plan["sources"][PLATFORM]
    folder = simple.SESSIONS / plan["session"]
    selected = {}
    for page in range(1, 11):
        rows = read(api, REPO + "/actions/artifacts?per_page=100&page=" + str(page), guard)["artifacts"]
        for record in rows:
            name = record["name"]
            if (
                record["expired"]
                or not name.startswith(("oripa-old-test-images-", "oripa-old-test-simple-images-"))
                or not name.endswith("-" + src["sha"])
            ):
                continue
            rid = record["workflow_run"]["id"]
            run = read(api, REPO + "/actions/runs/" + str(rid), guard)
            if not (scoped_run(run, settings["actor_id"]) and run["conclusion"] == "success"):
                continue
            identifier = record["id"]
            require(type(identifier) is int and identifier > 0, "invalid_existing_artifact")
            archive = folder / ("artifact-" + str(identifier) + ".zip")
            if not archive.exists():
                require(
                    0 < record["size_in_bytes"] <= 2 * 1024**3
                    and shutil.disk_usage(folder).free > record["size_in_bytes"] + 1024**3,
                    "formal_download_needs_space",
                )
                with tempfile.TemporaryDirectory(dir=folder) as temp:
                    path = Path(temp) / "archive"
                    actual = api["download_to"](REPO + "/actions/artifacts/" + str(identifier) + "/zip", path)
                    api["require_outer_digest"](record["digest"], actual)
                    os.chmod(path, 0o600)
                    os.replace(path, archive)
            with zipfile.ZipFile(archive) as zipped:
                for required in ("source-authority.json", "manifest.json"):
                    require(
                        zipped.namelist().count(required) == 1
                        and zipped.getinfo(required).file_size <= 1024 * 1024,
                        "formal_artifact_metadata_invalid",
                    )
                authority = json.loads(zipped.read("source-authority.json"))
                manifest = json.loads(zipped.read("manifest.json"))
            require(
                authority["source_sha"] == src["sha"]
                and authority["source_pr"] == src["pr"]
                and authority["control_sha"] == run["head_sha"]
                and authority["run_id"] == rid,
                "existing_formal_authority_changed",
            )
            control_files = CONTROLS if authority.get("authority_mode") == "simple" else CONTROLS[:3]
            get = lambda p: host.call(plan, guard, "github-read", data=REPO + "/contents/" + p)
            blobs = [get(p + "?ref=" + run["head_sha"])["sha"] for p in control_files]
            for role in set(roles) - selected.keys():
                if role not in [i["name"] for i in manifest["images"]]:
                    continue
                raw = base64.b64decode(get(FILES[role] + "?ref=" + src["sha"])["content"])
                override = authority.get("build_recipe") if role == "api" else None
                if override and override["recipe_sha256"] != hashlib.sha256(raw).hexdigest():
                    continue
                path = folder / (role + ".Dockerfile")
                if path.exists():
                    require(host.checked_file(path, True) == raw, "prepared_recipe_changed")
                else:
                    with path.open("xb") as stream:
                        os.fchmod(stream.fileno(), 0o600)
                        stream.write(raw)
                selected[role] = recipe_identity(role, raw, blobs)
                simple.persist(folder / (role + "-existing.json"), {"id": identifier, "recipe": selected[role]})
        if set(selected) == set(roles) or len(rows) < 100:
            break
    return selected


def download(plan, role, guard):
    folder, _ = host.approved(plan, guard)
    previous = folder / (role + "-existing.json")
    if previous.exists():
        info = json.loads(host.checked_file(previous, True))
        require(info["recipe"] == plan["recipes"][role], "previous_artifact_recipe_changed")
        return {"download_id": info["id"]}
    for group in (role, "api+admin"):
        if not (folder / ("build-" + ROLES[group] + ".json")).exists():
            continue
        if status(plan, group, guard) != "done":
            return None
        api, _, _, marker, _, _ = context(plan, group, guard)
        record = json.loads(host.checked_file(marker, True))
        identifier = record["artifact"]
        path = REPO + "/actions/artifacts/" + str(identifier)
        info = read(api, path, guard)
        require(info["workflow_run"]["id"] == record["run"] and not info["expired"], "artifact_run_changed")
        dest = folder / ("artifact-" + str(identifier) + ".zip")
        if not dest.exists():
            require(
                0 < info["size_in_bytes"] <= 2 * 1024**3
                and shutil.disk_usage(folder).free > info["size_in_bytes"] + 1024**3,
                "formal_download_needs_space",
            )
            for attempt in range(3):
                guard()
                with tempfile.TemporaryDirectory(dir=folder) as tmp:
                    archive = Path(tmp) / "payload.zip"
                    try:
                        checksum = api["download_to"](path + "/zip", archive)
                    except (Exception, SystemExit) as error:
                        if (
                            str(error)
                            not in (
                                "artifact_download_failed",
                                "github_api_connection_failed",
                                "github_api_http_502",
                                "github_api_http_503",
                                "github_api_http_504",
                            )
                            or attempt == 2
                        ):
                            raise Hold(
                                "artifact_download_failed_resume_existing_run_" + str(record["run"])
                            ) from None
                        time.sleep(attempt + 1)
                        continue
                    api["require_outer_digest"](info["digest"], checksum)
                    os.chmod(archive, 0o600)
                    os.replace(archive, dest)
                    break
        return {"download_id": identifier}
