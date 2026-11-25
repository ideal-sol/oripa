"""Fixed OLD Test worker dependencies. Never invoke a worker job to measure its health."""

import hashlib
import json
import os
from pathlib import Path
import re
import tempfile
import time

import old_test_host as host
import old_test_simple as simple
from old_test_simple_source import PLATFORM, require

CID = rb"(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])"


def prop(unit, field):
    return host.run(["/usr/bin/systemctl", "show", unit, "--property=" + field, "--value"]).decode().strip()


def scope(plan, worker, guard):
    host.approved(plan, guard)
    require(
        worker in simple.WORKERS and plan["workers"].get(worker) == "active", "worker_outside_active_plan"
    )
    settings = host.config(plan)
    spec = settings["worker_specs"][worker]
    return settings, spec


def projection(kind, raw):
    if kind == "unit":
        found = re.findall(CID, raw)
        require(len(found) == 1, "ambiguous_worker_container_binding")
        return raw.replace(found[0], b"APPROVED_CONTAINER_ID"), found[0].decode()
    if kind == "runner":
        matches = re.findall(rb'(?m)^IMAGE = "(sha256:[0-9a-f]{64})"$', raw)
        require(len(matches) == 1, "ambiguous_worker_image_binding")
        return raw.replace(matches[0], b"APPROVED_IMAGE"), matches[0].decode()
    require(kind == "identity", "unknown_worker_pin")
    data = json.loads(raw)
    require(
        re.fullmatch(CID.decode(), data["id"])
        and simple.DIGEST.fullmatch(data["image"])
        and simple.SHA.fullmatch(data["source"]),
        "invalid_monitor_binding",
    )
    live = (data.pop("id"), data.pop("image"), data.pop("source"))
    return json.dumps(data, sort_keys=True).encode(), live


def read_pin(spec):
    path = Path(spec["file"])
    raw = host.checked_file(path)
    static, pinned = projection(spec["kind"], raw)
    require(hashlib.sha256(static).hexdigest() == spec["definition"], "worker_definition_changed")
    if spec["kind"] == "unit":
        unit = spec["service"]
        require(
            prop(unit, "FragmentPath") == str(path) and not prop(unit, "DropInPaths"),
            "worker_unit_override_changed",
        )
    return raw, pinned


def values(plan, worker, settings, desired):
    api = host.container(settings, "api")
    require(api["Image"] == desired, "worker_requires_selected_api_image")
    return {
        "unit": api["Id"],
        "runner": desired,
        "identity": (api["Id"], desired, plan["sources"][PLATFORM]["sha"]),
    }


def baseline(plan, w, settings):
    spec = settings["worker_specs"][w]
    if spec["kind"] in ("unit", "runner", "identity"):
        _, value = read_pin(spec)
        return value
    return None


def observe(plan, op, worker, desired, guard):
    settings, spec = scope(plan, worker, guard)
    if op in ("drain", "resume"):
        require(worker in plan["migration"]["writers"], "writer_outside_plan")
        state = host.pinned(settings["warm"])["active_info"]([worker])[worker]
        return "done" if state == ("inactive" if op == "drain" else "active") else "ready"
    require(op == "switch" and simple.DIGEST.fullmatch(desired), "unsupported_worker_operation")
    kind = spec["kind"]
    if kind in ("unit", "runner", "identity"):
        actual = read_pin(spec)[1]
        expected = values(plan, worker, settings, desired)[kind]
        if actual == expected:
            if kind == "unit" and expected not in prop(spec["service"], "ExecStart"):
                return "ready"
            return "done"
        folder = simple.SESSIONS / plan["session"]
        previous = json.loads(host.checked_file(folder / "previous-pins.json", True))[worker]
        require(actual == (tuple(previous) if kind == "identity" else previous), "unexpected_worker_pin")
        return "ready"
    if kind == "exec":
        return "done" if exec_binding(plan, worker, None, guard)["active_api_image"] == desired else "ready"
    require(kind == "container", "unknown_worker_binding")
    return host.observed_service(plan, worker, desired)


def write_pin(plan, worker, desired, guard):
    settings, spec = scope(plan, worker, guard)
    kind = spec["kind"]
    api = host.container(settings, "api")
    expected = values(plan, worker, settings, desired)[kind]
    raw, previous = read_pin(spec)
    if kind == "runner" and not ("api" in plan["targets"]):
        require(previous == expected, "shared_f2b_pin_requires_api_plan")
    path = Path(spec["file"])
    if kind == "identity":
        changed = json.loads(raw)
        changed.update(id=api["Id"], image=desired, source=plan["sources"][PLATFORM]["sha"])
        new = (json.dumps(changed, sort_keys=True) + "\n").encode()
    else:
        new = raw.replace(previous.encode(), expected.encode())
    require(projection(kind, new)[0] == projection(kind, raw)[0], "worker_rebind_exceeds_scope")
    if kind == "unit":
        # Holding the shared deployment lock excludes future scheduled uses.
        for attempt in range(8):
            if prop(spec["service"], "ActiveState") == "inactive":
                break
            guard()
            time.sleep(1)
        else:
            raise simple.Hold("worker_oneshot_not_drained_" + worker)
    if new != raw:
        guard()
        require(observe(plan, "switch", worker, desired, guard) == "ready", "worker_pin_changed_before_write")
        fd, name = tempfile.mkstemp(dir=path.parent)
        try:
            with os.fdopen(fd, "wb") as output:
                os.fchmod(output.fileno(), path.stat().st_mode & 0o777)
                output.write(new)
                output.flush()
                os.fsync(output.fileno())
            require(host.checked_file(path) == raw, "worker_pin_changed_before_write")
            os.replace(name, path)
            directory = os.open(path.parent, os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
        finally:
            Path(name).unlink(missing_ok=True)
    if kind == "unit" and expected not in prop(spec["service"], "ExecStart"):
        guard()
        host.run(["/usr/bin/systemctl", "daemon-reload"])
    require(observe(plan, "switch", worker, desired, guard) == "done", "worker_rebind_not_effective")


def exec_binding(plan, worker, data, guard):
    settings, spec = scope(plan, worker, guard)
    require(spec["kind"] == "exec" and simple.WORKERS[worker] == "exec", "worker_exec_scope")
    api = host.container(settings, "api")
    raw = host.checked_file(Path(spec["file"]))
    require(
        hashlib.sha256(raw).hexdigest() == spec["definition"]
        and host.canonical.API in prop(spec["service"], "ExecStart")
        and not prop(spec["service"], "DropInPaths"),
        "worker_exec_definition_changed",
    )
    return dict(worker=worker, next_run_uses_api=True, active_api_image=api["Image"])


def health(plan, worker, data, guard):
    settings, spec = scope(plan, worker, guard)
    active = host.pinned(settings["warm"])["active_info"]([worker])[worker]
    if "timer" in spec:
        unit = spec["timer"]
        require(prop(unit, "Id") == unit and prop(unit, "LoadState") == "loaded", "worker_timer_missing")
        running = prop(spec["service"], "ActiveState") in ("active", "activating")
        upcoming = prop(unit, "NextElapseUSecRealtime")
        monotonic = prop(unit, "NextElapseUSecMonotonic")
        scheduled = any(x not in ("", "0", "n/a", "infinity") for x in (upcoming, monotonic))
        return dict(
            state=active,
            process_ok=active == "active",
            next="scheduled" if scheduled else "infinity",
            oneshot_running=running,
        )
    c = host.container(settings, worker)
    return dict(
        state=active, process_ok=c["State"]["Running"] and not c["State"]["Paused"] and c["State"]["Pid"] > 0
    )


def switch(plan, worker, desired, guard):
    settings, spec = scope(plan, worker, guard)
    require(
        simple.asset(plan, simple.asset_role(worker), host.cached(plan, simple.asset_role(worker)))
        == desired,
        "worker_image_not_admitted",
    )
    if spec["kind"] in ("unit", "runner", "identity"):
        if spec["kind"] == "runner" and "api" not in plan["targets"]:
            with simple.runtime_lock(Path(spec["lock"])) as check:
                check()
                return write_pin(plan, worker, desired, guard)
        return write_pin(plan, worker, desired, guard)
    require(
        spec["kind"] == "container" and worker in ("member-rank", "line-sender"),
        "worker_lifecycle_not_enrolled",
    )
    import old_test_worker_lifecycle as lifecycle

    return lifecycle.switch_selected(plan, worker, desired, guard)


def writer(plan, worker, operation, guard):
    settings, spec = scope(plan, worker, guard)
    require(
        worker in plan["migration"]["writers"] and operation in ("drain", "resume"), "writer_outside_plan"
    )
    if "timer" in spec:
        guard()
        if operation == "drain":
            host.run(["/usr/bin/systemctl", "stop", spec["timer"]])
            require(prop(spec["service"], "ActiveState") == "inactive", "writer_still_active")
        else:
            host.run(["/usr/bin/systemctl", "start", spec["timer"]])
        return
    import old_test_worker_lifecycle as lifecycle

    if operation == "drain":
        return lifecycle.stop(plan, worker, guard)
    return lifecycle.resume_selected(plan, worker, guard)


def sms_security(plan, target, data, guard):
    settings, spec = scope(plan, "sms", guard)
    require(target == "sms" and plan["targets"] == ["sms"] and "security" in spec, "independent_sms_scope")
    import old_test_php_authority as php

    folder, go = host.approved(plan, guard)
    reference = spec["security"]
    request = php.checked(reference["request"], True)
    policy = php.checked(reference["policy"], True)
    selected = request["images"]
    require(
        request["operation"] == "sms"
        and request["mode"] == "local_exception"
        and len(selected) == 1
        and selected[0]["role"] == "sms"
        and policy["gate_sha256"] == reference["gate"]["sha256"],
        "independent_sms_authority_invalid",
    )
    item = host.cached(plan, "sms")
    identity = simple.asset(plan, "sms", item)
    expected = dict(
        source=item["source"],
        identity=identity,
        configuration=plan["configuration"],
        contract=plan["contract"],
        plan=simple.digest(plan),
        operation="switch",
        target="sms",
        deadline=go["expires"],
    )
    require(
        data == expected
        and selected[0]["image_id"] == identity
        and selected[0]["source_sha"] == item["source"]
        and identity not in policy["denied_image_ids"],
        "independent_sms_authority_changed",
    )
    gate = host.pinned(reference["gate"])
    with tempfile.TemporaryDirectory(dir=folder) as temporary:
        path = Path(temporary) / "request.json"
        simple.persist(path, dict(request, observed_at=time.time(), expires_at=go["expires"]))
        grant = path.with_name("verification-policy.json")
        simple.persist(
            grant, dict(policy, verification_request_sha256=[hashlib.sha256(path.read_bytes()).hexdigest()])
        )
        verifier = gate["Gate"](str(grant))
        native = verifier.verify(str(path))
        verifier.validate_receipt(str(path), native)
        require(
            native["runtime"]["sms"]["image_id"] == identity and native["runtime_authorized"] is False,
            "sms_receipt_changed",
        )
        return dict(
            result="PASS",
            binding=expected,
            measured=native["observed_at"],
            expires=min(go["expires"], native["expires_at"]),
            receipt_digest=simple.digest([expected, native]),
        )
