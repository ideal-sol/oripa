"""One OLD Test maintenance selection. The deployment caller supplies no ENV or host operations."""
import contextlib
import copy
import hashlib
import json
import re
import time
from pathlib import Path

if __package__:
    from . import old_test_simple as simple
    from . import old_test_deploy as canonical
else:
    import old_test_simple as simple
    import old_test_deploy as canonical

require = simple.require
ROLES = ("api", "line-sender", "sms", "member-rank")
BINDINGS = ("utc-preflight", "utc-observe", "utc-security", "utc-switch", "utc-finish")
CID = re.compile(r"[0-9a-f]{64}")
PINS = ("d4", "univapay-receipts", "univapay-monitor", "f2b-worker", "f2b-purge",
        "scheduled-publish", "prize-exchange")


def registry():
    simple.trusted(simple.BINDINGS, private=True)
    raw = simple.BINDINGS.read_bytes()
    reg = json.loads(raw)
    require(reg["environment"] == "old-test" and reg["site"]["machine"] ==
            hashlib.sha256(Path("/etc/machine-id").read_bytes()).hexdigest(), "wrong_utc_site")
    require(set(BINDINGS) <= reg["operations"].keys(), "utc_bindings_not_enrolled")
    return "sha256:" + hashlib.sha256(raw).hexdigest(), reg


def projected_configuration(current, stable):
    # Keep secret values in the measured process. Plans contain only fingerprints.
    original = current["Config"]["Env"]
    require(not any(v.partition("=")[0] == "DB_TIMEZONE" for v in original), "unexpected_previous_db_timezone")
    cfg = dict(current["Config"], Env=[*original, "DB_TIMEZONE=UTC"])
    return stable(dict(current, Config=cfg))


def validate_snapshot(facts, enrolled, api, contract):
    require(set(facts) == {"targets", "workers", "timer_pins", "registration", "recovery", "sms_successor",
                           "authority"} and set(facts["targets"]) == set(ROLES), "utc_profile_incomplete")
    require(set(facts["workers"]) == set(simple.WORKERS) and
            all(v in ("active", "inactive") for v in facts["workers"].values()), "utc_worker_inventory")
    require(set(facts["timer_pins"]) == set(PINS) and
            all(simple.DIGEST.fullmatch(v) for v in facts["timer_pins"].values()), "utc_timer_inventory")
    require(all(simple.DIGEST.fullmatch(facts[k]) for k in ("registration", "recovery", "authority")),
            "utc_unsealed_registration")
    require(CID.fullmatch(facts["sms_successor"]), "utc_sms_successor_not_staged")
    ids = set()
    for role in ROLES:
        row = facts["targets"][role]
        require(set(row) == {"old_id", "old_image", "image", "before", "after"} and
                CID.fullmatch(row["old_id"]) and all(simple.DIGEST.fullmatch(row[k])
                for k in ("old_image", "image", "before", "after")), "utc_target_identity")
        require(row["before"] == enrolled[role]["configuration"] and row["before"] != row["after"],
                "utc_configuration_not_bound")
        require(row["image"] == (row["old_image"] if role == "sms" else api["identity"]),
                "utc_unapproved_target_image")
        if role in ("api", "line-sender"):
            require(row["old_image"] == row["image"], "utc_unapproved_image_change")
        ids.add(row["old_id"])
    require(len(ids) == len(ROLES) and facts["sms_successor"] not in ids, "utc_container_collision")
    require(api["contract"] == contract, "utc_contract_changed")


def registration(folder, reg, facts, host):
    # The operator pre-stages only the new observed settings. Never accept a
    # replacement procedure, authority, privilege, service name or feature switch here.
    raw = host.checked_file(folder / "utc-registration.json", True)
    candidate = json.loads(raw)
    require("sha256:" + hashlib.sha256(raw).hexdigest() == facts["registration"],
            "utc_registration_not_sealed")
    expected = copy.deepcopy(reg)
    for role in ROLES:
        expected["site"]["containers"][role]["configuration"] = facts["targets"][role]["after"]
    compose = candidate["site"]["containers"]["api"]["compose"]
    require(simple.DIGEST.fullmatch(compose) and compose != reg["site"]["containers"]["api"]["compose"],
            "utc_compose_fingerprint_missing")
    expected["site"]["containers"]["api"]["compose"] = compose
    require(expected == candidate and raw == json.dumps(candidate, sort_keys=True, allow_nan=False).encode(),
            "utc_registration_exceeds_scope")
    return candidate


def measured_plan(seed, call, reg, host):
    # The private preflight validates recovery, the stopped SMS successor and its exact
    # native authority, prospective registration, launch inputs, queues and timer pins.
    facts = call("utc-preflight", "", None)
    api = host.cached(seed, "api")
    require(api and simple.DIGEST.fullmatch(api["recipe"]), "utc_existing_artifact_required")
    seed = dict(seed, recipes={"api": api["recipe"]})
    require(simple.asset(seed, "api", api) == api["identity"], "utc_existing_artifact_required")
    require(simple.Site(seed).local_image("api", api) == api["identity"], "utc_local_image_changed")
    validate_snapshot(facts, reg["site"]["containers"], api, seed["contract"])
    for role in ROLES:
        live = host.container(reg["site"], role)
        row = facts["targets"][role]
        require(live["State"]["Running"] and live["Id"] == row["old_id"] and live["Image"] == row["old_image"]
                and projected_configuration(live, host.stable_container) == row["after"],
                "utc_live_profile_changed_" + role)
        if role == "sms":
            verify_sms_successor(live, host.canonical.inspect(facts["sms_successor"]), facts["sms_successor"])
    require(facts["registration"] != seed["site_policy"], "utc_registration_not_changed")
    return dict(seed, facts=facts, artifact={"id": api["artifact_id"], "digest": api["artifact_digest"]})


def verify_sms_successor(old, staged, identifier):
    require(staged["Id"] == identifier and not staged["State"]["Running"] and staged["State"]["Status"] == "created"
            and staged["Image"] == old["Image"], "utc_sms_not_staged")
    expected = dict(old["Config"], Env=sorted([*old["Config"]["Env"], "DB_TIMEZONE=UTC"]))
    actual = dict(staged["Config"], Env=sorted(staged["Config"]["Env"]))
    if expected["Hostname"] == old["Id"][:12] and actual["Hostname"] == identifier[:12]:
        actual["Hostname"] = expected["Hostname"]
    require(actual == expected, "utc_sms_full_config_changed")
    def normalize_host(item):
        require(item.get("OomKillDisable") in (None, False), "utc_sms_oom_changed")
        return dict(item, OomKillDisable=False)
    require(normalize_host(old["HostConfig"]) == normalize_host(staged["HostConfig"]),
            "utc_sms_host_config_changed")
    require(old["Mounts"] == staged["Mounts"] or
            sorted(old["Mounts"], key=lambda m: m["Destination"]) ==
            sorted(staged["Mounts"], key=lambda m: m["Destination"]), "utc_sms_mounts_changed")
    require(set(staged["NetworkSettings"]["Networks"]) == {staged["HostConfig"]["NetworkMode"]},
            "utc_sms_stage_requires_private_network")


def verify_go(folder, plan, check):
    check()
    simple.trusted(folder / "go.json", private=True)
    go = json.loads((folder / "go.json").read_bytes())
    require(go["session"] == plan["session"] and go["decision"] == "GO" and go["plan"] == simple.digest(plan)
            and type(go["issued"]) is int and type(go["expires"]) is int
            and 0 < go["expires"] - go["issued"] <= 3600 and go["issued"] <= time.time() < go["expires"],
            "utc_exact_human_go_required")
    return go


def security_scope(plan, role, go):
    facts = plan["facts"]
    return dict(session=plan["session"], plan=simple.digest(plan), target=role,
                operation="db-timezone-utc", source=plan["sources"][simple.PLATFORM]["sha"],
                artifact=plan["artifact"], contract=plan["contract"], target_identity=facts["targets"][role],
                sms_successor=facts["sms_successor"], authority=facts["authority"], deadline=go["expires"])


def validate_receipt(receipt, scope, go):
    require(receipt["result"] == "PASS" and receipt["binding"] == scope and
            simple.DIGEST.fullmatch(receipt["receipt_digest"]) and
            go["issued"] <= receipt["measured"] <= time.time() < receipt["expires"] <= go["expires"],
            "utc_native_receipt_invalid_" + scope["target"])


def issue_request(plan, role, scope, guard, host):
    # Enroll this function as the site authority. Construct the request independently
    # from retained evidence and the on-disk Human approval, never from a caller hash.
    folder, go = host.approved(plan, guard)
    require(plan["operation"] == "db-timezone-utc" and plan["delta"] == {"DB_TIMEZONE": "UTC"}
            and role in ROLES and scope == security_scope(plan, role, go), "utc_authority_scope_changed")
    site = host.config(plan)
    def checked(ref):
        raw = host.checked_file(Path(ref["path"]), True)
        require(hashlib.sha256(raw).hexdigest() == ref["sha256"], "utc_authority_input_changed")
        return json.loads(raw)
    def save(name, obj):
        path = folder / ("utc-" + role + "-" + name + ".json")
        simple.persist(path, obj)
        return {"path": str(path), "sha256": hashlib.sha256(host.checked_file(path, True)).hexdigest()}
    source = plan["sources"][simple.PLATFORM]
    item = host.cached(plan, "api")
    require(item and simple.asset(plan, "api", item) == plan["facts"]["targets"]["api"]["image"]
            and plan["artifact"] == {"id": item["artifact_id"], "digest": item["artifact_digest"]},
            "utc_authority_asset_changed")
    material = json.loads(host.checked_file(simple.SESSIONS / "security" / ("api-" + source["sha"] + ".json"), True))
    artifact = material["artifact"]
    context = {k: artifact[k] for k in ("id", "digest", "authority")}
    require(artifact["id"] == item["artifact_id"] and artifact["digest"] == item["artifact_digest"]
            and artifact["authority"]["source_sha"] == source["sha"]
            and artifact["authority"]["source_pr"] == source["pr"], "utc_artifact_authority_changed")
    candidate = {"target": "old-test", "task_id": plan["session"], "scope": scope, "artifact": artifact,
                 "images": {"api": {"image_id": item["identity"]}}, "state": {"phase": "prepared"}}
    candidate_id = simple.digest({k: v for k, v in candidate.items() if k != "state"})[7:]
    selected_image = scope["target_identity"]["image"]
    if role == "sms":
        refs = site["worker_specs"]["sms"]["security"]
        template = checked(refs["request"])
        require(template["artifact"] == context and template["mode"] == "local_exception"
                and template["operation"] == "sms" and len(template["images"]) == 1, "utc_sms_exception_scope")
        image = template["images"][0]
        require(image["image_id"] == selected_image == scope["target_identity"]["old_image"]
                and image["role"] == "sms", "utc_sms_historical_image_changed")
        local = checked(image["local_authority"])
        require(local["image"]["image_id"] == selected_image and local["candidate_identity"] ==
                template["candidate_identity"] and local["artifact_context"] == context, "utc_sms_history_changed")
        selection = dict(image, local_authority=save("local-authority", dict(local, candidate_identity=candidate_id)))
        request = dict(template, images=[selection])
    else:
        refs = site["php_gate"]
        require(material["gate"] == refs["gate"] and material["policy"] == refs["policy"]
                and selected_image == item["identity"], "utc_php_gate_registration_changed")
        recipe = json.loads(host.checked_file(Path(material["recipe"]["path"])))
        native_role = "api" if role == "api" else "worker"
        request = dict(schema=1, target="old-test", mode="canonical",
                       operation="apply" if role == "api" else "worker", runtime_authorized=False,
                       artifact=context, artifact_files=artifact["files"], evidence=material["evidence"],
                       control_sha=artifact["authority"]["control_sha"], recipe=material["recipe"],
                       recipe_authority=recipe["authority_id"], images=[dict(role=native_role,
                       platform="linux/amd64", image_id=selected_image, source_sha=source["sha"],
                       recipe_authority=recipe["authority_id"])])
    guard()
    request.update(candidate=save("candidate", candidate), candidate_phase="prepared",
                   candidate_identity=candidate_id, observed_at=time.time(), expires_at=go["expires"])
    request_ref = save("request", request)
    policy = dict(checked(refs["policy"]), verification_request_sha256=[request_ref["sha256"]])
    return {"request": request_ref, "policy": save("policy", policy)}


def native_receipt(plan, role, scope, material, reg, host):
    # These three native inputs are enrolled outside the caller. Only the one
    # verified request hash can differ from the retained OLD Test policy.
    sms = role == "sms"
    enrolled = reg["site"]["worker_specs"]["sms"]["security"] if sms else reg["site"]["php_gate"]
    gate = host.pinned(enrolled["gate"])["Gate"]
    def read(ref, private=True):
        raw = host.checked_file(Path(ref["path"]), private)
        require(hashlib.sha256(raw).hexdigest() == ref["sha256"], "utc_security_input_changed")
        return json.loads(raw)
    request = read(material["request"])
    policy = read(material["policy"])
    require(policy == dict(read(enrolled["policy"]), verification_request_sha256=[material["request"]["sha256"]]),
            "utc_policy_exceeds_native_template")
    candidate = read(request["candidate"])
    op = "sms" if sms else ("apply" if role == "api" else "worker")
    native_role = role if role in ("sms", "api") else "worker"
    require(candidate["scope"] == scope and request["operation"] == op and request["target"] == "old-test"
            and request["mode"] == ("local_exception" if sms else "canonical")
            and request["runtime_authorized"] is False and request["expires_at"] == scope["deadline"]
            and request["artifact"]["id"] == plan["artifact"]["id"]
            and request["artifact"]["digest"] == plan["artifact"]["digest"]
            and len(request["images"]) == 1, "utc_request_scope_changed")
    selected = request["images"][0]
    source = read(enrolled["request"])["images"][0]["source_sha"] if sms else scope["source"]
    require(selected["image_id"] == scope["target_identity"]["image"] and selected["role"] == native_role
            and selected["source_sha"] == source, "utc_gate_image_changed")
    verifier = gate(material["policy"]["path"])
    raw = verifier.verify(material["request"]["path"])
    verifier.validate_receipt(material["request"]["path"], raw)
    require(raw["runtime_authorized"] is False and raw["operation"] == op
            and raw["runtime"][native_role]["image_id"] == selected["image_id"], "utc_gate_result_changed")
    return dict(result="PASS", binding=scope, receipt_digest=simple.digest(raw),
                measured=raw["observed_at"], expires=raw["expires_at"])


def transition(plan, go, call, guard, journal, save):
    key = simple.digest(plan)
    require(journal["plan"] == key, "utc_journal_changed")
    # All roles must have real evidence before the first drain. Re-measure short-lived
    # receipts under the same GO. A receipt handler must verify AND validate the native request.
    receipts = {}
    for role in ROLES:
        guard()
        scope = security_scope(plan, role, go)
        receipt = call("utc-security", role, scope)
        validate_receipt(receipt, scope, go)
        receipts[role] = receipt
    for role in ROLES:
        guard()
        state = call("utc-observe", role, None)
        if state != "after":
            require(state == "before" and journal.get("intent") in (None, role) and
                    role not in journal.get("completed", []), "utc_reconciliation_required_" + role)
            # An interrupted operation must be reconciled before another dispatch.
            require(journal.get("intent") is None, "utc_unresolved_intent_" + role)
            scope = security_scope(plan, role, go)
            receipt = receipts[role]
            if receipt["expires"] <= time.time():
                receipt = call("utc-security", role, scope)
            validate_receipt(receipt, scope, go)
            journal["intent"] = role
            save(journal)
            guard()
            call("utc-switch", role, receipt)
            require(call("utc-observe", role, None) == "after", "utc_effect_unconfirmed_" + role)
        if journal.get("intent") == role:
            journal.pop("intent")
        completed = journal.setdefault("completed", [])
        if role not in completed:
            completed.append(role)
        save(journal)
    guard()
    require("intent" not in journal, "utc_unresolved_intent")
    # The site verifies all live sessions, process/HTTP health, old container retention
    # and effective timer pins before the atomic publication of the approved registry.
    call("utc-finish", "", {"registration": plan["facts"]["registration"]})


def observed(plan, role, state, reg, host):
    row = plan["facts"]["targets"][role]
    container = canonical.inspect(reg["site"]["containers"][role]["name"])
    require(state in ("before", "after") and container["State"]["Running"], "utc_indeterminate_" + role)
    if state == "before":
        require(container["Id"] == row["old_id"] and container["Image"] == row["old_image"]
                and host.stable_container(container) == row["before"], "utc_baseline_changed_" + role)
    else:
        require(container["Id"] != row["old_id"] and container["Image"] == row["image"]
                and host.stable_container(container) == row["after"]
                and (role != "sms" or container["Id"] == plan["facts"]["sms_successor"]),
                "utc_successor_changed_" + role)
        probe = ('require "vendor/autoload.php"; $a=require "bootstrap/app.php";'
                 '$a->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
                 '$db=$a["db"]->connection("pgsql"); echo json_encode([config("app.timezone"),'
                 'config("database.connections.pgsql.timezone"), date_default_timezone_get(),'
                 '$db->selectOne("SHOW TIME ZONE")->TimeZone,$db->selectOne("SHOW transaction_read_only")->transaction_read_only]);')
        result = host.run(["/usr/bin/docker", "exec", "--env",
                           "PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=5000",
                           container["Id"], "php", "-r", probe])
        require(json.loads(result) == ["Asia/Tokyo", "UTC", "Asia/Tokyo", "UTC", "on"],
                "utc_session_not_effective_" + role)
        if role in ("sms", "member-rank"):
            prior = canonical.inspect(row["old_id"])
            require(prior["Id"] == row["old_id"] and prior["Image"] == row["old_image"]
                    and not prior["State"]["Running"], "utc_recovery_container_missing_" + role)
    return state


def entry(args, folder, host):
    policy, reg = registry()
    with simple.runtime_lock(folder, session_directory=True) as session, contextlib.ExitStack() as stack:
        def take_locks():
            common = stack.enter_context(simple.runtime_lock())
            f2b = stack.enter_context(simple.runtime_lock(canonical.F2B_LOCK))
            def check():
                session()
                common()
                f2b()
                simple.canonical_idle()
            check()
            return check
        path = folder / "plan.json"
        log = folder / "journal.json"
        if args.prepare:
            source = re.fullmatch(r"([1-9][0-9]{0,9})@([0-9a-f]{40})", args.platform_source or "")
            require(source and args.contract in reg["site"]["contracts"], "utc_pinned_source_contract_required")
            seed = dict(environment="old-test", session=args.session, operation="db-timezone-utc",
                        delta={"DB_TIMEZONE": "UTC"}, sources={simple.PLATFORM: {
                            "pr": int(source[1]), "sha": source[2]}}, contract=args.contract, site_policy=policy)
            site = simple.Site(seed)
            site.guard = session
            # The admitted artifact already identifies a reviewed source. Check the PR proof as well.
            proof = simple.merged_source(simple.PLATFORM, int(source[1]), source[2],
                                         lambda p: site.call("github-read", data=p))
            site.guard = take_locks()
            require(registry()[0] == policy, "utc_policy_changed_during_prepare")
            plan = measured_plan(seed, site.private_operation, reg, host)
            registration(folder, reg, plan["facts"], host)
            if path.exists():
                require(json.loads(host.checked_file(path, True)) == plan, "session_already_bound")
            else:
                simple.persist(path, plan)
            if not log.exists():
                simple.persist(log, {"plan": simple.digest(plan), "source": proof})
            print(json.dumps({"session": args.session, "approval_digest": simple.digest(plan), "plan": plan}))
            return
        require(not args.platform_source and not args.contract, "approved_plan_is_immutable")
        plan = json.loads(host.checked_file(path, True))
        require(plan.get("operation") == "db-timezone-utc" and plan["session"] == args.session and
                plan["environment"] == "old-test" and plan["delta"] == {"DB_TIMEZONE": "UTC"},
                "utc_plan_scope_changed")
        journal = json.loads(host.checked_file(log, True))
        require(journal["plan"] == simple.digest(plan), "utc_journal_changed")
        locked = take_locks()
        policy, reg = registry()
        go = verify_go(folder, plan, locked)
        if policy != plan["site_policy"]:
            require(policy == plan["facts"]["registration"] and journal.get("completed") == list(ROLES)
                    and journal.get("accepted_registry") == policy and "intent" not in journal,
                    "utc_registration_drift")
        else:
            site = simple.Site(plan)
            def guard():
                require(verify_go(folder, plan, locked) == go, "utc_approval_changed")
            site.guard = guard
            def call(op, role, value):
                guard()
                if op == "utc-switch":
                    def before_effect():
                        guard()
                        validate_receipt(value, security_scope(plan, role, go), go)
                    site.guard = before_effect
                try:
                    result = site.private_operation(op, role, value)
                    if op == "utc-finish":
                        for target in simple.SERVICES:
                            reading = simple.http_health(target, result["routes"][target])
                            require(simple.health(target, reading), "utc_http_unhealthy_" + target)
                        import old_test_worker_host as worker
                        api = canonical.inspect(reg["site"]["containers"]["api"]["name"])
                        for name in PINS:
                            spec = reg["site"]["worker_specs"][name]
                            if spec["kind"] in ("unit", "identity", "runner"):
                                value = worker.read_pin(spec)[1]
                                expected = {"unit": api["Id"], "identity": (api["Id"], api["Image"],
                                            plan["sources"][simple.PLATFORM]["sha"]), "runner": api["Image"]}
                                require(value == expected[spec["kind"]], "utc_timer_reference_changed_" + name)
                                if spec["kind"] == "unit":
                                    require(api["Id"] in worker.prop(spec["service"], "ExecStart"),
                                            "utc_timer_reference_not_loaded_" + name)
                    if op == "utc-observe":
                        return observed(plan, role, result, reg, host)
                    if op == "utc-security":
                        return native_receipt(plan, role, value, result, reg, host)
                    return result
                finally:
                    site.guard = guard
            if not journal.get("completed") and "intent" not in journal:
                require(call("utc-preflight", "", None) == plan["facts"], "utc_preflight_drift")
                for role in ROLES:
                    require(call("utc-observe", role, None) == "before", "utc_baseline_changed_" + role)
            approved_registry = registration(folder, reg, plan["facts"], host)
            transition(plan, go, call, guard, journal, lambda j: simple.persist(log, j))
            guard()
            require(registry()[0] == policy, "utc_registry_changed_before_publication")
            for role in ROLES:
                require(call("utc-observe", role, None) == "after", "utc_role_drift_" + role)
            live_api = canonical.inspect(reg["site"]["containers"]["api"]["name"])
            host.compose_binding(live_api, approved_registry["site"]["containers"]["api"])
            workers = host.pinned(reg["site"]["warm"])["active_info"](set(simple.WORKERS))
            require(workers == plan["facts"]["workers"], "utc_worker_state_not_restored")
            # persist() atomically writes json.dump(sort_keys=True) in the protected directory.
            approved_raw = json.dumps(approved_registry, sort_keys=True, allow_nan=False).encode()
            require("sha256:" + hashlib.sha256(approved_raw).hexdigest() == plan["facts"]["registration"],
                    "utc_registration_encoding")
            guard()
            journal["accepted_registry"] = plan["facts"]["registration"]
            simple.persist(log, journal)
            guard()
            simple.persist(simple.BINDINGS, approved_registry)
        final_hash, final_reg = registry()
        require(final_hash == plan["facts"]["registration"], "utc_registration_not_published")
        final_site = simple.Site(dict(plan, site_policy=final_hash))
        final_site.guard = lambda: verify_go(folder, plan, locked)
        for role in ROLES:
            final_site.guard()
            require(final_reg["site"]["containers"][role]["configuration"] == plan["facts"]["targets"][role]["after"]
                    and observed(plan, role, final_site.private_operation("utc-observe", role, None),
                                 final_reg, host) == "after",
                    "utc_registered_runtime_not_accepted_" + role)
        workers = host.pinned(final_reg["site"]["warm"])["active_info"](set(simple.WORKERS))
        require(workers == plan["facts"]["workers"], "utc_worker_state_not_restored")
        journal["result"] = "PASS"
        simple.persist(log, journal)
        print(json.dumps({"session": args.session, "result": "PASS"}))
