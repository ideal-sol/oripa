"""Independent verifier for the exact OLD Test PHP Gate request. No runtime dispatch."""

import hashlib
import json
import os
from pathlib import Path
import time

import old_test_host as host
import old_test_simple as simple
from old_test_simple_source import PLATFORM, require


def ref(path):
    raw = host.checked_file(path)
    return {"path": str(path), "sha256": hashlib.sha256(raw).hexdigest()}


def checked(ref_value, private=False):
    raw = host.checked_file(Path(ref_value["path"]), private)
    require(hashlib.sha256(raw).hexdigest() == ref_value["sha256"], "php_reference_changed")
    return json.loads(raw)


def bound(plan, target, scope, guard):
    folder, go = host.approved(plan, guard)
    affected, _ = simple.shape(plan, plan["targets"])
    require(target == "api", "php_role_outside_plan")
    subject, operation = scope["target"], scope["operation"]
    if operation == "switch":
        valid = subject in affected and simple.asset_role(subject) == "api"
    elif operation in ("drain", "resume"):
        valid = subject in plan["migration"]["writers"]
    elif operation == "migrate":
        valid = subject in plan["migration"]["files"]
    else:
        valid = operation == "backup" and subject == "api" and plan["migration"]["backup"]
    require(valid, "php_operation_outside_plan")
    item = host.cached(plan, "api")
    image = simple.asset(plan, "api", item)
    expected = dict(
        source=item["source"],
        identity=image,
        configuration=plan["configuration"],
        contract=plan["contract"],
        plan=simple.digest(plan),
        operation=operation,
        target=subject,
        deadline=go["expires"],
        artifact_id=item["artifact_id"],
        artifact_digest=item["artifact_digest"],
    )
    require(scope == expected, "php_scope_mismatch")
    material = json.loads(
        host.checked_file(simple.SESSIONS / "security" / ("api-" + item["source"] + ".json"), True)
    )
    a = material["artifact"]
    require(
        a["id"] == item["artifact_id"]
        and a["digest"] == item["artifact_digest"]
        and a["authority"]["source_sha"] == item["source"]
        and a["authority"]["source_pr"] == plan["sources"][PLATFORM]["pr"],
        "php_artifact_mismatch",
    )
    return folder, material


def inputs(plan, target, scope, guard):
    return bound(plan, target, scope, guard)[1]


def authorize(plan, target, data, guard):
    scope = data["scope"]
    folder, material = bound(plan, target, scope, guard)
    require(data["template"] == material["policy"], "php_policy_selection_changed")
    policy = checked(material["policy"], True)
    recipe = checked(material["recipe"])
    request = Path(data["request"]["path"])
    require(request.name == "request.json" and request.parent.parent == folder, "php_request_outside_session")
    q = checked(data["request"], True)
    require(
        q["candidate"]["path"] == str(request.with_name("selection.json")), "php_selection_outside_session"
    )
    artifact = material["artifact"]
    projection = dict(
        target="old-test",
        task_id=plan["session"],
        scope=scope,
        artifact=artifact,
        images={"api": {"image_id": scope["identity"]}},
        state={"phase": "prepared"},
    )
    require(checked(q["candidate"], True) == projection, "php_candidate_outside_plan")
    role = (
        "migration"
        if scope["operation"] == "migrate"
        else "worker"
        if scope["target"] in simple.WORKERS
        else "api"
    )
    observed = q["observed_at"]
    require(type(observed) in (float, int) and 0 <= time.time() - observed <= 60, "php_request_expired")
    expected = dict(
        schema=1,
        target="old-test",
        mode="canonical",
        operation="worker" if role == "worker" else "apply",
        observed_at=observed,
        expires_at=scope["deadline"],
        runtime_authorized=False,
        candidate=q["candidate"],
        candidate_phase="prepared",
        candidate_identity=simple.digest({k: v for k, v in projection.items() if k != "state"})[7:],
        artifact={k: artifact[k] for k in ("id", "digest", "authority")},
        artifact_files=artifact["files"],
        control_sha=artifact["authority"]["control_sha"],
        recipe=material["recipe"],
        recipe_authority=recipe["authority_id"],
        evidence=material["evidence"],
        images=[
            dict(
                role=role,
                source_sha=scope["source"],
                image_id=scope["identity"],
                platform="linux/amd64",
                recipe_authority=recipe["authority_id"],
            )
        ],
    )
    require(q == expected, "php_native_request_exceeds_scope")
    grant = request.with_name("verified-request-policy.json")
    with grant.open("x") as out:
        os.fchmod(out.fileno(), 0o600)
        json.dump(dict(policy, verification_request_sha256=[data["request"]["sha256"]]), out, sort_keys=True)
    return ref(grant)


def prepare(plan, item, guard):
    folder, _ = host.approved(plan, guard)
    root = simple.SESSIONS / "security"
    path = root / ("api-" + item["source"] + ".json")
    if path.exists():
        material = json.loads(host.checked_file(path, True))
        require(material["artifact"]["digest"] == item["artifact_digest"], "php_security_artifact_changed")
        return
    template = host.config(plan)["php_gate"]
    mapping = checked(template["recipe"])
    proof_path = folder / "api-admitted.json"
    proof = json.loads(host.checked_file(proof_path, True))
    require(
        proof["item"] == {k: v for k, v in item.items() if k != "verified_local_identity"}
        and proof["authority"]["source_sha"] == item["source"]
        and proof["recipe_sha256"] == mapping["source_recipe_sha256"],
        "approved_php_stage_mapping_changed",
    )
    recipe_file = folder / "api.Dockerfile"
    require(ref(recipe_file)["sha256"] == proof["recipe_sha256"], "php_stage_recipe_changed")
    manifest = [i for i in proof["manifest"]["images"] if i["name"] == "api"]
    require(
        len(manifest) == 1 and manifest[0]["image_id"] == item["identity"], "php_selected_manifest_mismatch"
    )
    from old_test_admin_measure import reports

    scanner = host.config(plan)["admin_gate"]
    scan_refs = reports(plan, item, scanner, guard)
    require(scan_refs, "fresh_php_scan_required")
    recipe = dict(
        mapping,
        source_sha=item["source"],
        control_sha=proof["authority"]["control_sha"],
        authority_id=plan["session"] + "/formal-api",
        files=[ref(recipe_file), ref(proof_path)],
    )
    derived = folder / "api-gate-recipe.json"
    simple.persist(derived, recipe)
    artifact = dict(
        id=item["artifact_id"],
        digest=item["artifact_digest"],
        authority=proof["authority"],
        directory=str(folder),
        files={proof_path.name: ref(proof_path)["sha256"], recipe_file.name: ref(recipe_file)["sha256"]},
    )
    simple.persist(
        path,
        dict(
            artifact=artifact,
            recipe=ref(derived),
            gate=template["gate"],
            policy=template["policy"],
            evidence=[ref(proof_path), *scan_refs],
        ),
    )
