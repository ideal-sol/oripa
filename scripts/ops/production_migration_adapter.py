#!/usr/bin/python3 -ISB
"""Fixed noninteractive adapter. Only the controller entry is granted to the caller."""

import json
import os
from pathlib import Path
import re
import sys
import time
from types import SimpleNamespace

if __package__:
    from . import production_migrations as ctl
else:
    # The installation directory and controller module are owned by the trusted installer.
    sys.path.insert(0, "/usr/local/lib/oripa-production-migrations")
    import production_migrations as ctl


require = ctl.require
PHASES = {"fence", "create", "pre", "start", "post", "inspect"}


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def stamp(path, record):
    """Persist an exclusive attempt before sending any irreversible request."""
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, "wb") as stream:
        stream.write(canonical(record))
        stream.flush()
        os.fsync(stream.fileno())
    fd = os.open(path.parent, os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def load_site(plan):
    # The manifest pins private, reviewed noninteractive data/measurement code
    # separately from the public adapter. No module or query comes from stdin.
    raw = ctl.private_file(ctl.CONFIG / "conditions.json")
    require(ctl.digest(raw) == plan["conditions_sha256"], "conditions_changed")
    conditions = json.loads(raw)
    source = ctl.private_file(ctl.CONFIG / "measurement.py")
    require(ctl.digest(source) == conditions["measurement_sha256"], "measurement_changed")
    pack = ctl.private_file(ctl.CONFIG / "readonly-pack.json")
    require(ctl.digest(pack) == conditions["query_pack_sha256"], "query_pack_changed")
    functions = {}
    exec(compile(source, "<sealed-measurement>", "exec"), functions)
    return SimpleNamespace(**{k: functions[k] for k in
                             ("measure", "image", "runner", "runner_template", "check_parity",
                              "create_runners", "created_set", "start_runner", "reader_spec",
                              "read", "inspect_read", "evaluate", "runner_history", "effect_history")}), conditions, json.loads(pack)


class Adapter:
    def __init__(self, host, site, conditions, pack):
        self.host = host
        self.plan = host.plan
        self.site = site
        self.conditions = conditions
        self.pack = pack
        self.prefix = "migration-" + host.binding
        self.files = self.plan["migrations"]

    def record(self, suffix):
        return ctl.STATE / (self.prefix + "-" + suffix + ".json")

    def fence(self):
        snapshot, snapshot_hash = ctl.evidence("snapshot")
        live = self.site.measure(self.plan)
        require(snapshot["release"] == self.plan["release"] and snapshot["site"] == self.plan["site"]
                and snapshot["database"] == live["database"] == self.conditions["database"]
                and snapshot["stage_sha256"] == self.host.stage_hash and snapshot["status"] == "available"
                and snapshot["manual"] is True and snapshot["human_instance_mapping_confirmed"] is True
                and isinstance(snapshot["id"], str) and snapshot["id"]
                and live["available_snapshot_id"] == snapshot["id"]
                and ctl.matches(ctl.HASH, live["freeze_sha256"])
                and snapshot["freeze_sha256"] == live["freeze_sha256"]
                and snapshot["freeze_started_at"] == live["frozen_at"], "snapshot_invalid")
        result = dict(db_identity=True, writers_fenced=live["writers_fenced"] is True,
                      financial_drain=live["financial_drain"] is True, continuous_fence=live["continuous_fence"] is True,
                      frozen_at=live["frozen_at"], snapshot_at=snapshot["created_at"],
                      available_at=snapshot["available_at"], observed_at=live["observed_at"],
                      snapshot_available=True, fence_snapshot_sha256=ctl.digest(canonical([snapshot_hash,live["freeze_sha256"]])))
        ctl.verify_fence(result, time.time())
        return result

    def template(self, index):
        row = self.files[index]
        payload = self.site.runner_template(self.plan, row)
        command = ["php","artisan","migrate","--path=database/migrations-v2/" + row["file"],
                   "--force","--no-interaction","--no-ansi"]
        require(payload["Image"] == self.plan["image"] and payload["Cmd"] == command
                and not payload["HostConfig"].get("PortBindings")
                and not payload["HostConfig"].get("Privileged")
                and payload["HostConfig"]["RestartPolicy"]["Name"] in ("", "no"), "runner_template")
        return payload

    def current(self, index, *, missing=False):
        name = self.prefix + "-" + str(index)
        actual = self.site.runner(self.plan, index, name)
        if actual is None:
            require(missing, "runner_missing")
            return None
        require(ctl.matches(ctl.HASH, actual["Id"]) and actual["Name"] == "/" + name
                and actual["Image"] == self.plan["image"] and actual["RestartCount"] == 0
                and not actual["State"]["OOMKilled"], "runner_identity")
        require(actual["State"]["Running"] is (actual["State"]["Status"] == "running"),
                "runner_state")
        payload = self.template(index)
        require(actual["Config"]["Cmd"] == payload["Cmd"] and actual["Config"]["Env"] == payload["Env"]
                and self.site.check_parity(self.plan,index,actual,payload) is True, "runner_parity")
        return actual

    def guard(self, frozen):
        self.host.authorized()
        require(self.fence()["fence_snapshot_sha256"] == frozen, "fence_lost")

    def create(self, frozen):
        image = self.site.image(self.plan)
        require(image["Id"] == self.plan["image"] and image["Os"] == "linux"
                and image["Architecture"] == self.conditions["architecture"]
                and image["Config"]["Labels"]["org.opencontainers.image.revision"] == self.plan["source"],
                "image_identity")
        require(all(self.current(i, missing=True) is None for i in range(len(self.files))), "creator_collision")
        def latch():
            self.guard(frozen)
            stamp(self.record("create"), {"plan": self.host.binding, "fence": frozen})
            return ctl.digest(ctl.private_file(self.record("create")))

        names = [self.prefix + "-" + str(i) for i in range(len(self.files))]
        runners = self.site.create_runners(self.plan, names, latch)
        ctl.check_runners(self.plan, runners)
        self.verify_created_set(runners, frozen)
        for index, runner in enumerate(runners):
            actual = self.current(index)
            require(actual["Id"] == runner and actual["State"]["Status"] == "created", "creator_failed")
        return dict(image=self.plan["image"], state="CREATED_NOT_STARTED", runners=runners)

    def verify_created_set(self, runners, frozen):
        # The private Creator's durable, ordered result can outlive its response.
        raw = ctl.private_file(self.record("create"))
        original = self.site.created_set(self.plan)
        require(json.loads(raw) == {"plan": self.host.binding, "fence": frozen}
                and original["intent_sha256"] == ctl.digest(raw)
                and original["runners"] == runners, "creator_identity")

    def reading(self, index, actual, post, inspect=False):
        # read() uses the original sealed Reader/Observer or a side-effect-free
        # live capture for inspection. The unredacted SQL and rows stay here.
        spec = self.site.reader_spec(self.plan, self.pack, index, post)
        require(spec["index"] == index and spec["post"] is post
                and isinstance(spec["queries"], dict) and spec["queries"]
                and all(isinstance(sql, str) and sql.startswith("SELECT ") and ";" not in sql
                        for sql in spec["queries"].values()), "invalid_reader_spec")
        capture = (self.site.inspect_read if inspect else self.site.read)(self.plan, spec)
        require(capture["status"] == "READ_ONLY_OBSERVATION_NOT_GATE_PASS"
                and capture["phase"] == spec["phase"] and capture["source_sha"] == self.plan["source"]
                and capture["image_id"] == self.plan["image"]
                and capture["query_pack_sha256"] == self.conditions["query_pack_sha256"]
                and capture["read_only_transaction"] is True and capture["transaction_rolled_back"] is True
                and set(capture["results"]) == set(spec["queries"])
                and capture["image_migration_hashes"] == {r["file"]: r["sha256"] for r in self.files},
                "observation_failed")
        measured = self.site.evaluate(self.plan,index,post,capture,spec)
        ledger = measured["ledger_names"]
        require(isinstance(ledger,list) and all(isinstance(n,str) for n in ledger), "reader_checks")
        row = self.files[index]
        result = self.fence()
        # Freshness applies to both the actual capture and the live fence.
        observed = capture["observed_at"]
        require(type(observed) is int and result["available_at"] <= observed <= time.time() <= observed + 300,
                "observation_failed")
        result["observed_at"] = min(result["observed_at"], observed)
        history = self.site.runner_history(self.plan, actual["Id"])
        require(history["runner"] == actual["Id"] and history["complete"] is True
                and history["exclusive_start_path"] is True and type(history["starts"]) is int
                and history["starts"] == int(self.record("start-" + str(index)).exists()), "runner_history")
        for k in ctl.GATES:
            require(measured[k] is True, "reader_checks")
            result[k] = True
        result.update(source=self.plan["source"], conditions_sha256=self.plan["conditions_sha256"],
                      file=row["file"], file_sha256=capture["image_migration_hashes"][row["file"]],
                      image=actual["Image"], runner=actual["Id"], command=actual["Config"]["Cmd"],
                      runner_state=actual["State"]["Status"], starts=history["starts"],
                      exit=actual["State"]["ExitCode"], readonly_rollback=True,
                      ledger=len(ledger), ledger_sha256=ctl.digest(canonical(ledger)))
        result["receipt_sha256"] = ctl.digest(canonical([self.host.binding,index,post,result,ctl.digest(canonical(capture))]))
        return result

    def observed(self, phase, index, proof, frozen):
        actual = self.current(index)
        runners = self.site.created_set(self.plan)["runners"]
        ctl.check_runners(self.plan, runners)
        self.verify_created_set(runners, frozen)
        require(runners[index] == actual["Id"] == proof["runner"], "runner_identity")
        start_path = self.record("start-" + str(index))
        if phase == "post":
            deadline = time.monotonic() + ctl.REPLY_WAIT
            while actual["State"]["Status"] == "running" and time.monotonic() < deadline:
                time.sleep(.25)
                actual = self.current(index)
                require(actual["Id"] == proof["runner"], "runner_identity")
        post = phase == "post"
        result = self.reading(index, actual, post, inspect=(phase == "start"))
        ctl.verify_reader(self.plan,index,result,actual["Id"],frozen,post)
        if post:
            intent = json.loads(ctl.private_file(start_path))
            require(intent == {"runner":actual["Id"],"pre":proof["pre"],"fence":frozen}, "start_identity")
            result["pre"] = proof["pre"]
        elif phase == "pre":
            path = self.record("pre-" + result["receipt_sha256"])
            if path.exists():
                require(json.loads(ctl.private_file(path)) == result, "reader_identity")
            else:
                stamp(path, result)
        else:
            intent = dict(runner=actual["Id"], pre=proof["pre"], fence=frozen)

            def validate_start():
                self.guard(frozen)
                latest = self.current(index)
                require(latest["Id"] == intent["runner"] and latest["State"]["Status"] == "created",
                        "start_identity")
                original = json.loads(ctl.private_file(self.record("pre-" + proof["pre"])))
                require(ctl.verify_reader(self.plan, index, original, latest["Id"], frozen, False)
                        == proof["pre"], "start_identity")

            def latch():
                validate_start()
                stamp(start_path, intent)
                return ctl.digest(ctl.private_file(start_path))

            def before_send():
                require(json.loads(ctl.private_file(start_path)) == intent, "start_identity")
                validate_start()

            self.site.start_runner(self.plan, index, intent, latch, before_send)
            require(json.loads(ctl.private_file(start_path)) == intent, "start_identity")
            return dict(runner=actual["Id"],pre=proof["pre"])
        return result

    def effect_history(self, fence, completed=()):
        history = self.site.effect_history(self.plan, self.host.binding)
        now = time.time()
        observed = history["observed_at"]
        require(history["binding"] == self.host.binding and history["complete"] is True
                and history["exclusive_effect_path"] is True
                and type(observed) is int and fence["available_at"] <= observed <= now <= observed + 300,
                "outcome_unknown")
        intents, terminal = history["intents"], history["terminal"]
        require(isinstance(intents, dict) and isinstance(terminal, dict)
                and set(intents) == set(terminal), "outstanding_effect")
        for key, attempt in intents.items():
            require(ctl.matches(ctl.HASH, key) and attempt["kind"] in ("create", "start", "reader")
                    and (attempt["index"] is None if attempt["kind"] == "create" else
                         type(attempt["index"]) is int and 0 <= attempt["index"] < len(self.files))
                    and terminal[key] in ("COMPLETED", "DEFINITIVELY_REJECTED"), "outstanding_effect")
        local = [(self.record("create"), {"kind": "create", "index": None})]
        local.extend((self.record("start-" + str(i)), {"kind": "start", "index": i}) for i in range(len(self.files)))
        for path, attempt in local:
            if path.exists():
                require(intents.get(ctl.digest(ctl.private_file(path))) == attempt, "outstanding_effect")
        for path in completed:
            require(terminal.get(ctl.digest(ctl.private_file(path))) == "COMPLETED", "outcome_unknown")
        return list(intents.values())

    def inspect(self,index,proof,fence):
        all_runners = [self.current(i,missing=True) for i in range(len(self.files))]
        create = self.record("create")
        state = dict(fence, settled=False)
        if not any(all_runners):
            attempts = self.effect_history(fence)
            state["settled"] = True
            return dict(state, state="NOT_STARTED", runners=[],
                        no_create_intent=not any(a["kind"] == "create" for a in attempts),
                        no_start_intent=not any(a["kind"] == "start" for a in attempts))
        require(all(all_runners) and json.loads(ctl.private_file(create)) == {
            "plan":self.host.binding,"fence":fence["fence_snapshot_sha256"]}, "creator_identity")
        runners = [r["Id"] for r in all_runners]
        ctl.check_runners(self.plan,runners)
        self.verify_created_set(runners, fence["fence_snapshot_sha256"])
        actual = all_runners[index]
        require(proof["runner"] in (None,actual["Id"]), "runner_identity")
        state.update(runners=runners,runner=actual["Id"],original_runner_set_verified=True)
        status = actual["State"]["Status"]
        if status == "running":
            intent = json.loads(ctl.private_file(self.record("start-" + str(index))))
            require(intent == dict(runner=actual["Id"], pre=proof["pre"], fence=fence["fence_snapshot_sha256"]),
                    "start_identity")
            return dict(state,state="RUNNING")
        require(status in ("created","exited"), "outcome_unknown")
        post = status == "exited"
        intent = self.record("start-"+str(index))
        attempts = self.effect_history(fence, (create, intent) if post else (create,))
        state["settled"] = True
        state.update(self.reading(index,actual,post,inspect=True))
        require(state["fence_snapshot_sha256"] == fence["fence_snapshot_sha256"], "fence_lost")
        ctl.verify_reader(self.plan,index,state,actual["Id"],state["fence_snapshot_sha256"],post)
        if post:
            expected = dict(runner=actual["Id"],pre=proof["pre"],fence=state["fence_snapshot_sha256"])
            require(json.loads(ctl.private_file(intent)) == expected, "start_identity")
            return dict(state,state="EXITED",pre=proof["pre"])
        return dict(state,state="NOT_STARTED",no_start_intent={"kind": "start", "index": index} not in attempts)

    def dispatch(self,q):
        require(set(q)=={"release","plan_sha256","phase","index","nonce","proof"}
                and q["release"]==self.host.release and q["plan_sha256"]==self.host.binding
                and re.fullmatch("[a-f0-9]{32}",q["nonce"]) and q["phase"] in PHASES,"invalid_request")
        phase,index,proof=q["phase"],q["index"],q["proof"]
        keys = {"fence":set(),"create":{"fence"},"pre":{"runner","fence"},"start":{"runner","fence","pre"},
                "post":{"runner","fence","pre"},"inspect":{"runner","fence","pre"}}[phase]
        require(isinstance(proof,dict) and set(proof)==keys and all(
            (phase=="inspect" and v is None) or ctl.matches(ctl.HASH,v) for v in proof.values()),"invalid_proof")
        require(index is None if phase in ("fence","create") else type(index) is int and 0<=index<len(self.files),
                "invalid_index")
        if phase == "inspect":
            self.host.check_binding()
        else:
            self.host.authorized()
        live = self.fence()
        frozen = live["fence_snapshot_sha256"]
        require(proof.get("fence") in (None,frozen), "fence_lost")
        if phase == "fence":
            data = live
        elif phase == "create":
            data = self.create(frozen)
        elif phase == "inspect":
            data = self.inspect(index,proof,live)
        else:
            data = self.observed(phase,index,proof,frozen)
        return dict(data, **{k:q[k] for k in ("release","plan_sha256","phase","index","nonce")}, result="PASS")


def main():
    # Private helpers, including incidental stdout/stderr, are inside the boundary.
    output = os.dup(1)
    with open(os.devnull,"wb") as sink:
        os.dup2(sink.fileno(),1)
        os.dup2(sink.fileno(),2)
    os.umask(0o077)
    phase = None
    try:
        require(os.geteuid()==0 and len(sys.argv)==1 and not sys.stdin.isatty()
                and Path(__file__) == ctl.ADAPTER and sys.flags.isolated and sys.flags.no_site
                and sys.flags.dont_write_bytecode, "invalid_invocation")
        raw = sys.stdin.buffer.read(65537)
        require(len(raw)<=65536,"invalid_request")
        q=json.loads(raw)
        phase=q["phase"]
        host=ctl.Host(q["release"])
        host.check_binding()
        ctl.trusted(ctl.STATE,directory=True)
        require(ctl.STATE.stat().st_mode & 0o077 == 0, "untrusted_installation")
        result=Adapter(host,*load_site(host.plan)).dispatch(q)
    except Exception as exc:
        errors={"create":"creator_failed","pre":"reader_failed","post":"reader_failed","start":"starter_failed"}
        reason = errors.get(phase, "observation_failed") if isinstance(phase, str) else "observation_failed"
        if isinstance(exc, ctl.Hold) and isinstance(exc.reason, str) and exc.reason in ctl.ERRORS:
            reason = exc.reason
        state=getattr(exc,"sqlstate",None)
        result = dict(result="HOLD", error=reason, sqlstate=state if isinstance(state, str) and state in ctl.SQLSTATES else None)
    with os.fdopen(output,"wb") as response:
        response.write(canonical(result))
    return int(result["result"]!="PASS")


if __name__ == "__main__":
    sys.exit(main())
