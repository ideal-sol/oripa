#!/usr/bin/python3 -ISB
"""Fixed noninteractive adapter. Only the controller entry is granted to the caller."""

import http.client
import json
import os
from pathlib import Path
import re
import socket
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


class Engine(http.client.HTTPConnection):
    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        self.sock.settimeout(25)
        self.sock.connect("/var/run/docker.sock")


def engine(method, route, body=None, *, missing=False):
    client = Engine("localhost")
    try:
        client.request(method, route, body=None if body is None else canonical(body),
                       headers={"Content-Type": "application/json"})
        reply = client.getresponse()
        if missing and reply.status == 404:
            return None
        expected = 200 if method == "GET" else 204 if route.endswith("/start") else 201
        require(reply.status == expected, "engine_response")
        raw = reply.read(2_000_001)
        require(len(raw) <= 2_000_000, "engine_response")
        return json.loads(raw) if raw else None
    finally:
        client.close()


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
                             ("measure", "runner_template", "check_parity", "reader_spec",
                              "read", "evaluate", "runner_history", "quiescent")}), conditions, json.loads(pack)


class Adapter:
    def __init__(self, host, site, conditions, pack, transport=engine):
        self.host = host
        self.plan = host.plan
        self.site = site
        self.conditions = conditions
        self.pack = pack
        self.transport = transport
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
                and snapshot["manual"] is True and isinstance(snapshot["id"], str) and snapshot["id"]
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
        actual = self.transport("GET", "/containers/" + name + "/json", missing=missing)
        if actual is None:
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
        image = self.transport("GET", "/images/" + self.plan["image"] + "/json")
        require(image["Id"] == self.plan["image"] and image["Os"] == "linux"
                and image["Architecture"] == self.conditions["architecture"]
                and image["Config"]["Labels"]["org.opencontainers.image.revision"] == self.plan["source"],
                "image_identity")
        require(all(self.current(i, missing=True) is None for i in range(len(self.files))), "creator_collision")
        self.guard(frozen)
        stamp(self.record("create"), {"plan":self.host.binding, "fence":frozen})
        runners = []
        for i in range(len(self.files)):
            payload = self.template(i)
            self.guard(frozen)
            created = self.transport("POST", "/containers/create?name=" + self.prefix + "-" + str(i), payload)
            actual = self.current(i)
            require(actual["Id"] == created["Id"] and actual["State"]["Status"] == "created", "creator_failed")
            stamp(self.record("runner-" + str(i)), {"runner": actual["Id"]})
            runners.append(actual["Id"])
        ctl.check_runners(self.plan, runners)
        return dict(image=self.plan["image"], state="CREATED_NOT_STARTED", runners=runners)

    def reading(self, index, actual, post, inspect=False):
        # read() uses the original sealed Reader/Observer or a side-effect-free
        # live capture for inspection. The unredacted SQL and rows stay here.
        spec = self.site.reader_spec(self.plan, self.pack, index, post)
        require(spec["index"] == index and spec["post"] is post
                and isinstance(spec["queries"], dict) and spec["queries"]
                and all(isinstance(sql, str) and sql.startswith("SELECT ") and ";" not in sql
                        for sql in spec["queries"].values()), "invalid_reader_spec")
        capture = self.site.read(self.plan, spec, inspect)
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
                and type(history["starts"]) is int
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
        original = json.loads(ctl.private_file(self.record("runner-" + str(index))))
        require(original == {"runner": actual["Id"]} and actual["Id"] == proof["runner"], "runner_identity")
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
            original = json.loads(ctl.private_file(self.record("pre-" + proof["pre"])))
            require(ctl.verify_reader(self.plan,index,original,actual["Id"],frozen,False) == proof["pre"],
                    "start_identity")
            self.guard(frozen)
            latest = self.current(index)
            require(latest["Id"] == actual["Id"] and latest["State"]["Status"] == "created",
                    "start_identity")
            stamp(start_path, dict(runner=actual["Id"], pre=proof["pre"], fence=frozen))
            self.transport("POST", "/containers/" + actual["Id"] + "/start")
            return dict(runner=actual["Id"],pre=proof["pre"])
        return result

    def inspect(self,index,proof,fence):
        all_runners = [self.current(i,missing=True) for i in range(len(self.files))]
        create = self.record("create")
        settled = self.site.quiescent(self.plan,index) is True
        state = dict(fence, settled=settled)
        if not any(all_runners):
            return dict(state, state="NOT_STARTED", runners=[], no_create_intent=not create.exists(),
                        no_start_intent=not any(self.record("start-"+str(i)).exists() for i in range(len(self.files))))
        require(all(all_runners) and json.loads(ctl.private_file(create)) == {
            "plan":self.host.binding,"fence":fence["fence_snapshot_sha256"]}, "creator_identity")
        runners = [r["Id"] for r in all_runners]
        ctl.check_runners(self.plan,runners)
        require(all(json.loads(ctl.private_file(self.record("runner-" + str(i)))) == {"runner": runner}
                    for i, runner in enumerate(runners)), "creator_identity")
        actual = all_runners[index]
        require(proof["runner"] in (None,actual["Id"]), "runner_identity")
        state.update(runners=runners,runner=actual["Id"],original_runner_set_verified=True)
        status = actual["State"]["Status"]
        if status == "running":
            require(self.record("start-"+str(index)).exists(), "start_identity")
            return dict(state,state="RUNNING")
        require(settled and status in ("created","exited"), "outcome_unknown")
        post = status == "exited"
        intent = self.record("start-"+str(index))
        state.update(self.reading(index,actual,post,inspect=True))
        require(state["fence_snapshot_sha256"] == fence["fence_snapshot_sha256"], "fence_lost")
        ctl.verify_reader(self.plan,index,state,actual["Id"],state["fence_snapshot_sha256"],post)
        if post:
            expected = dict(runner=actual["Id"],pre=proof["pre"],fence=state["fence_snapshot_sha256"])
            require(json.loads(ctl.private_file(intent)) == expected, "start_identity")
            return dict(state,state="EXITED",pre=proof["pre"])
        return dict(state,state="NOT_STARTED",no_start_intent=not intent.exists())

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
