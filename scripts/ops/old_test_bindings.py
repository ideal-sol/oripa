"""Functions enrolled individually by the OLD Test operator in the private operation registry."""

import json
import os

import old_test_admin_measure as admin_gate
import old_test_host as host
import old_test_simple as simple
import old_test_simple_build as build
from old_test_simple_source import require


def prepare_inputs(plan, target, data, guard):
    settings = host.config(plan)
    require(plan["contract"] in settings["contracts"], "contract_not_adopted")
    roles = {simple.asset_role(t) for t in plan["targets"]}
    missing = sorted(r for r in roles if r in build.FILES and host.cached(plan, r) is None)
    existing = build.remote_recipes(plan, missing, guard) if missing else {}
    cold = sorted(set(missing) - existing.keys())
    if cold:
        existing.update(build.current_recipes(plan, cold, guard))
    return existing


def selected(plan, settings):
    targets = plan["targets"]
    all_workers = set(simple.WORKERS) if "api" in targets else set(targets) & simple.WORKERS.keys()
    states = host.pinned(settings["warm"])["active_info"](all_workers) if all_workers else {}
    affected = list(targets)
    if "api" in targets:
        affected.extend(w for w in states if states[w] == "active" and simple.WORKERS[w] in ("pin", "image"))
    return states, affected


def snapshot(plan):
    settings = host.config(plan)
    states, affected = selected(plan, settings)
    if "previous" in plan:
        affected = list(plan["previous"])
    identities, configuration = {}, {}
    for t in affected:
        if t in ("api", "admin") or t in settings["containers"]:
            current = host.container(settings, t)
            identities[t] = current["Image"]
            configuration[t] = host.stable_container(current)
        elif t in simple.WORKERS and simple.WORKERS[t] in ("pin", "exec"):
            current = host.container(settings, "api")
            identities[t] = current["Image"]
            configuration[t] = settings["worker_specs"][t]["definition"]
        elif t == "storefront":
            import old_test_storefront_host as sf

            identities[t], configuration[t] = sf.current(settings)
        else:
            raise simple.Hold("missing_worker_definition_" + t)
    return states, identities, simple.digest(configuration)


def propose(plan, target, data, guard):
    settings = host.config(plan)
    states, previous, fingerprint = snapshot(plan)
    recipes = {}
    for t in previous:
        role = simple.asset_role(t)
        item = host.cached(plan, role)
        if item:
            require(item["contract"] == plan["contract"], "admitted_contract_changed")
            recipes[role] = item["recipe"]
        elif role == "storefront":
            import old_test_storefront_host as sf

            recipes[role] = sf.recipe(plan, settings)
        else:
            require(role in data["inputs"], "asset_recipe_unavailable_" + role)
            recipes[role] = data["inputs"][role]
    migration = {"files": data["migrations"], "writers": [], "backup": False}
    if "api" in plan["targets"]:
        import old_test_migration_host as db

        migration = db.prepare(plan, settings, states, migration)
    result = dict(
        plan,
        previous=previous,
        recipes=recipes,
        workers=states,
        configuration=fingerprint,
        migration=migration,
        recovery="reconcile-only",
    )
    simple.shape(result, plan["targets"])
    if states:
        import old_test_worker_host as worker

        pins = {w: worker.baseline(result, w, settings) for w in previous if simple.WORKERS.get(w) == "pin"}
        path = simple.SESSIONS / plan["session"] / "previous-pins.json"
        if path.exists():
            require(
                json.loads(host.checked_file(path, True)) == json.loads(json.dumps(pins)),
                "session_worker_pins_changed",
            )
        else:
            simple.persist(path, pins)
    return result


def preflight(plan, target, data, guard):
    host.approved(plan, guard)
    settings = host.config(plan)
    states, identities, config = snapshot(plan)
    require(set(plan["targets"]) <= set(settings["enabled_targets"]), "target_not_enrolled")
    require(not plan["migration"]["files"] or settings["migration_enabled"], "migration_not_enrolled")
    affected, roles = simple.shape(plan, plan["targets"])
    required = {"observe", "find-asset", "verify-retained", "health", "prepare-evidence"}
    if "api" in roles:
        required.update(("php-gate-inputs", "authorize-php-request"))
    if set(roles) - {"api"}:
        required.add("security")
    if set(affected) & set(simple.SERVICES):
        required.add("switch-binding")
    if any(t in simple.WORKERS and simple.WORKERS[t] != "exec" for t in affected):
        required.add("switch")
    if any(simple.WORKERS.get(t) == "exec" for t in affected):
        required.add("exec-binding")
    if plan["workers"]:
        required.add("worker-states")
    if plan["migration"]["files"]:
        required.update(("database-binding", "migration-inventory"))
    if plan["migration"]["writers"]:
        required.update(("drain", "resume"))
    if "line-sender" in plan["migration"]["writers"]:
        required.add("worker-lock-released")
    if any(host.cached(plan, r) is None for r in roles if r in build.FILES):
        required.update(("ensure-formal-build", "cache-admission"))
    if "storefront" in roles and host.cached(plan, "storefront") is None:
        required.add("prepare-build")
    missing = required - json.loads(host.checked_file(simple.BINDINGS, True))["operations"].keys()
    require(not missing, "missing_binding_" + "_".join(sorted(missing)))
    for role in plan["targets"]:
        if role in ("api", "admin"):
            current = host.container(settings, role)
            host.compose_binding(current, settings["containers"][role])
        if role == "api":
            flags = dict(x.split("=", 1) for x in current["Config"]["Env"])
            require(
                flags.get("V2_UNIVAPAY_MODE") == "test"
                and flags.get("V2_PAIDY_MODE") == "test"
                and flags.get("FINCODE_PAYMENT_ENABLED") == "false",
                "old_test_provider_mode_changed",
            )
            for w in states:
                if states[w] == "active" and simple.WORKERS[w] in ("image", "pin"):
                    require(w in settings["enabled_targets"], "worker_transition_not_enrolled_" + w)
    # Host enrollment pins effective ENV, mounts, network IDs and proxy configuration.
    host.pinned(settings["proxy"])["configuration"]()
    simple.canonical_idle()
    stats = os.statvfs(simple.SESSIONS)
    return dict(
        environment="old-test",
        workers=states,
        identities=identities,
        configuration=config,
        contract=plan["contract"],
        compatible=plan["contract"] in settings["contracts"],
        provider_fences=config == plan["configuration"],
        canonical_idle=True,
        space=stats.f_bavail * stats.f_frsize >= settings["minimum_free_bytes"],
        inodes=stats.f_favail >= settings["minimum_free_inodes"],
    )


def find_asset(plan, target, data, guard):
    if target == "storefront":
        import old_test_storefront_host as sf

        return sf.local_asset(plan)
    item = host.cached(plan, target)
    if item is None and target in build.FILES:
        return build.download(plan, target, guard)
    return item


def cache_admission(plan, target, data, guard):
    host.approved(plan, guard)
    require(target in build.FILES, "unsupported_admission")
    item = dict(data, verified_local_identity=simple.Site(plan).local_image(target, data))
    simple.asset(plan, target, item)
    directory = simple.SESSIONS / "assets" / target
    simple.trusted(directory, private=True, directory=True)
    path = directory / (item["source"] + ".json")
    with simple.runtime_lock(directory, session_directory=True):
        if path.exists():
            require(json.loads(host.checked_file(path, True)) == item, "different_asset_already_admitted")
        else:
            simple.persist(path, item)


def ensure_formal_build(plan, target, data, guard):
    return build.ensure(plan, target, data, guard)


def observe(plan, target, data, guard):
    host.approved(plan, guard)
    op, expected = data["operation"], data["input"]
    if op == "prepare-build" and target in build.ROLES:
        return build.status(plan, target, guard)
    if op == "switch" and target in build.FILES:
        return host.observed_service(plan, target, expected)
    if target == "storefront":
        import old_test_storefront_host as sf

        return sf.observe(plan, op, expected, guard)
    if op in ("migrate", "backup"):
        import old_test_migration_host as db

        return db.observe(plan, op, target, guard)
    import old_test_worker_host as worker

    return worker.observe(plan, op, target, expected, guard)


def switch_binding(plan, target, data, guard):
    if target in build.FILES:
        return host.service_switch(plan, target, data, guard)
    import old_test_storefront_host as sf

    return sf.switch_binding(plan, target, data, guard)


def utc_security(plan, target, data, guard):
    import old_test_timezone as utc
    return utc.issue_request(plan, target, data, guard, host)


def security(plan, target, data, guard):
    if target == "admin":
        return admin_gate.verify(plan, target, data, guard)
    if target == "storefront":
        import old_test_storefront_host as sf

        return sf.verify(plan, target, data, guard)
    import old_test_worker_host as worker

    return worker.sms_security(plan, target, data, guard)


def prepare_build(plan, target, data, guard):
    import old_test_storefront_host as sf

    require(target == "storefront", "no_independent_build_for_role_" + target)
    return sf.stage(plan, target, data, guard)


def prepare_evidence(plan, target, data, guard):
    if target == "admin":
        admitted, policy, _ = admin_gate.selected(plan, guard)
        require(admitted == data, "admin_artifact_changed")
        admin_gate.reports(plan, admitted, policy, guard)
    elif target == "api":
        import old_test_php_authority as php

        php.prepare(plan, data, guard)
        import old_test_worker_lifecycle as life

        folder, _ = host.approved(plan, guard)
        settings = host.config(plan)
        for worker, prepare in (("member-rank", life.rank_config), ("line-sender", life.line_config)):
            if plan["workers"].get(worker) == "active" and plan["previous"].get(worker) != data["identity"]:
                prepare(plan, settings, host.container(settings, worker), data["identity"], folder)


def verify_retained(plan, target, data, guard):
    value = find_asset(plan, target, None, guard)
    require(value == data and simple.asset(plan, target, value) == data["identity"], "retained_asset_changed")
    return value


def health(plan, target, data, guard):
    if target in simple.WORKERS:
        import old_test_worker_host as worker

        return worker.health(plan, target, data, guard)
    return host.pinned(host.config(plan)["routes"])["route"](plan, target, data, guard)


def switch(plan, target, data, guard):
    import old_test_worker_host as worker

    return worker.switch(plan, target, data, guard)


def exec_binding(plan, target, data, guard):
    import old_test_worker_host as worker

    return worker.exec_binding(plan, target, data, guard)


def drain(plan, target, data, guard):
    import old_test_worker_host as worker

    return worker.writer(plan, target, "drain", guard)


def resume(plan, target, data, guard):
    import old_test_worker_host as worker

    return worker.writer(plan, target, "resume", guard)


def database_binding(plan, target, data, guard):
    import old_test_migration_host as db

    return db.database_binding(plan, target, data, guard)


def migration_inventory(plan, target, data, guard):
    import old_test_migration_host as db

    return db.inventory(plan, target, data, guard)


def php_inputs(plan, target, data, guard):
    import old_test_php_authority as php

    return php.inputs(plan, target, data, guard)


def authorize_php(plan, target, data, guard):
    import old_test_php_authority as php

    return php.authorize(plan, target, data, guard)


def worker_lock_released(plan, target, data, guard):
    require(target == "line-sender" and target in plan["migration"]["writers"], "worker_lock_outside_scope")
    folder, _ = host.approved(plan, guard)
    # Each fence is scoped to a context-managed PostgreSQL connection. A short
    # acquisition and rollback proves the row is available before resuming.
    from old_test_worker_lifecycle import sender_fence

    with sender_fence(host.config(plan), guard) as check:
        check()
    return True
