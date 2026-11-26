"""Restricted NEW migration controller. The installed host adapter owns all private I/O."""

import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import select
import sqlite3
import stat
import subprocess
import sys
import tempfile
import threading
import time


CONFIG = Path("/etc/oripa-production-migrations")
STATE = Path("/var/lib/oripa-production-migrations")
ADAPTER = Path("/usr/local/libexec/oripa-production-migration-adapter")
ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,95}")
FILE = re.compile(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\.php")
HASH = re.compile(r"[a-f0-9]{64}")
SHA = re.compile(r"[a-f0-9]{40}")
GATES = ("db_identity", "schema_exact", "engine_identity", "ddl_capabilities",
         "existing_data_preserved", "feature_defaults", "financial_drain", "writers_fenced")
ERRORS = {"creator_failed", "reader_failed", "starter_failed", "observation_failed",
          "fence_lost", "snapshot_invalid", "financial_unknown", "sql_error"}
SQLSTATES = {"23503", "23505", "40001", "40P01", "55P03", "57014"}
INSPECT_WAIT = 5
REPLY_WAIT = 300


class Hold(Exception):
    def __init__(self, reason, sqlstate=None):
        self.reason = reason
        self.sqlstate = sqlstate


def require(ok, reason):
    if not ok:
        raise Hold(reason)


def matches(pattern, value):
    return isinstance(value, str) and pattern.fullmatch(value) is not None


def digest(data):
    return hashlib.sha256(data).hexdigest()


def trusted(path, directory=False):
    for component in (*reversed(path.parents), path):
        info = component.lstat()
        expected = stat.S_ISDIR if component != path or directory else stat.S_ISREG
        require(expected(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022,
                "untrusted_installation")


def private_file(path):
    trusted(path)
    require(not path.stat().st_mode & 0o077, "untrusted_installation")
    return path.read_bytes()


def evidence(kind):
    """Read the protected receipt from its enrolled publisher using the configured fields."""
    sources = json.loads(private_file(CONFIG / "evidence-sources.json"))
    spec = sources[kind]
    path = Path(spec["path"])
    require(path.is_absolute() and ".." not in path.parts, "invalid_evidence_source")
    raw = private_file(path)
    source = json.loads(raw)
    def field(keys):
        require(isinstance(keys, list) and all(isinstance(k, str) for k in keys), "invalid_field_path")
        value = source
        for key in keys:
            value = value[key]
        return value
    require(field(spec["issuer_field"]) == spec["issuer"], "unexpected_evidence_issuer")
    return {key: field(keys) for key, keys in spec["fields"].items()}, digest(raw)


def validate_plan(plan, release):
    require(matches(ID, release) and plan["release"] == release and matches(ID, plan["site"])
            and matches(SHA, plan["source"]) and matches(SHA, plan["tree"])
            and matches(SHA, plan["authority"]) and matches(ID, plan["artifact"])
            and matches(ID, plan["build"]) and matches(HASH, plan["adapter_sha256"])
            and matches(HASH, plan["conditions_sha256"])
            and matches(re.compile(r"sha256:[a-f0-9]{64}"), plan["image"]), "invalid_release")
    services = plan["services"]
    require(isinstance(services, list) and services and all(matches(ID, s) for s in services)
            and len(services) == len(set(services)), "invalid_release")
    stages = plan["stage_receipts"]
    require(isinstance(stages, dict) and "platform" in stages and set(stages) <= {"platform", "storefront"}
            and all(matches(HASH, value) for value in stages.values())
            and ("storefront" not in services or "storefront" in stages), "invalid_stage_receipts")
    rows = plan["migrations"]
    require(isinstance(rows, list) and rows and type(plan["start_ledger"]) is int
            and plan["start_ledger"] >= 0 and type(plan["end_ledger"]) is int
            and plan["end_ledger"] == plan["start_ledger"] + len(rows), "invalid_ledger")
    names = []
    for row in rows:
        require(matches(FILE, row["file"]) and all(matches(HASH, row[k]) for k in
                ("sha256", "before_ledger_sha256", "after_ledger_sha256")), "invalid_migration")
        names.append(row["file"])
    require(names == sorted(set(names)) and all(a["after_ledger_sha256"] == b["before_ledger_sha256"]
            for a, b in zip(rows, rows[1:])), "invalid_order")


class Host:
    def __init__(self, release):
        self.release = release
        self.plan, self.stage_hash = evidence("stage")
        validate_plan(self.plan, release)
        self.binding = digest(json.dumps([self.stage_hash, self.plan], sort_keys=True,
                                         separators=(",", ":"), allow_nan=False).encode())

    def check_binding(self):
        plan, binding = evidence("stage")
        require(binding == self.stage_hash and plan == self.plan, "release_changed")
        trusted(ADAPTER)
        require(digest(ADAPTER.read_bytes()) == self.plan["adapter_sha256"], "adapter_changed")

    def authorized(self):
        self.check_binding()
        go, _ = evidence("go")
        now = time.time()
        require(go["release"] == self.release and go["site"] == self.plan["site"]
                and go["approved"] is True
                and all(isinstance(go[k], str) and go[k].strip() for k in ("approver", "audit_event"))
                and go["scope"] == "production_release" and go["stage_sha256"] == self.stage_hash
                and type(go["issued"]) is int and type(go["expires"]) is int
                and 0 < go["issued"] <= now < go["expires"], "go_invalid")

    def call(self, phase, index, proof, *, recovery=False):
        inspect_wait = None
        if phase == "inspect":
            self.check_binding()
            if recovery:
                raw = private_file(CONFIG / "conditions.json")
                require(digest(raw) == self.plan["conditions_sha256"], "conditions_changed")
                # Recorded during qualification of this exact private reader.
                budget = json.loads(raw)["recovery_read_budget_ms"]
                require(type(budget) is int and 0 < budget <= REPLY_WAIT * 1000, "invalid_recovery_budget")
                inspect_wait = budget / 1000
        else:
            require(not recovery, "invalid_request")
            self.authorized()
        # No shell, configurable argv, caller environment, or inherited terminal.
        request = dict(release=self.release, plan_sha256=self.binding, phase=phase,
                       index=index, proof=proof, nonce=secrets.token_hex(16))
        reply, code = exchange(request, getattr(self, "lock_fd", None), inspect_wait=inspect_wait)
        if code or reply.get("result") != "PASS":
            error = reply.get("error")
            state = reply.get("sqlstate")
            raise Hold(error if isinstance(error, str) and error in ERRORS else "adapter_failed",
                       state if isinstance(state, str) and state in SQLSTATES else None)
        require(all(reply.get(k) == request[k] for k in ("release", "plan_sha256", "phase", "index", "nonce")),
                "receipt_identity")
        return reply


def exchange(request, lock_fd=None, *, inspect_wait=None):
    inspect = request["phase"] == "inspect"
    wait = INSPECT_WAIT if inspect else REPLY_WAIT
    if inspect_wait is not None:
        require(inspect and 0 < inspect_wait <= REPLY_WAIT, "invalid_recovery_budget")
        wait = inspect_wait
    deadline = time.monotonic() + wait
    # A regular input file avoids blocking on an adapter that never reads stdin.
    with tempfile.TemporaryFile() as payload:
        payload.write(json.dumps(request).encode())
        payload.seek(0)
        proc = subprocess.Popen([str(ADAPTER)], stdin=payload, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, cwd="/", start_new_session=True,
                                pass_fds=() if lock_fd is None else (lock_fd,),
                                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin"})
    raw = bytearray()
    try:
        while True:
            remaining = deadline - time.monotonic()
            require(remaining > 0 and select.select([proc.stdout], [], [], remaining)[0], "adapter_timeout")
            chunk = os.read(proc.stdout.fileno(), 65537 - len(raw))
            if not chunk:
                break
            raw.extend(chunk)
            require(len(raw) <= 65536, "adapter_protocol")
        try:
            code = proc.wait(timeout=max(0, deadline - time.monotonic()))
        except subprocess.TimeoutExpired:
            raise Hold("adapter_timeout") from None
        return json.loads(raw), code
    finally:
        # Only the read-only inspection helper can be cancelled. Never signal
        # an effectful adapter, a process group, the Runner or a DB session.
        if inspect and proc.poll() is None:
            proc.kill()
        proc.stdout.close()
        if proc.poll() is None:
            threading.Thread(target=proc.wait, daemon=True).start()


def verify_fence(reading, now):
    require(all(reading[k] is True for k in ("db_identity", "writers_fenced", "financial_drain",
            "continuous_fence", "snapshot_available")), "fence_or_snapshot_invalid")
    times = [reading[k] for k in ("frozen_at", "snapshot_at", "available_at", "observed_at")]
    require(all(type(v) is int and v > 0 for v in times) and times == sorted(times)
            and times[-1] <= now <= times[-1] + 300 and matches(HASH, reading["fence_snapshot_sha256"]),
            "fence_or_snapshot_invalid")


def verify_reader(plan, index, reading, runner, fence, post):
    row = plan["migrations"][index]
    verify_fence(reading, time.time())
    command = ["php", "artisan", "migrate", "--path=database/migrations-v2/" + row["file"],
               "--force", "--no-interaction", "--no-ansi"]
    require(reading["fence_snapshot_sha256"] == fence and reading["runner"] == runner
            and reading["source"] == plan["source"] and reading["conditions_sha256"] == plan["conditions_sha256"]
            and reading["image"] == plan["image"] and reading["file"] == row["file"]
            and reading["command"] == command and reading["file_sha256"] == row["sha256"]
            and matches(HASH, reading["receipt_sha256"]),
            "reader_identity")
    require(type(reading["ledger"]) is int and reading["ledger"] == plan["start_ledger"] + index + post
            and reading["ledger_sha256"] == row["after_ledger_sha256" if post else "before_ledger_sha256"]
            and all(reading[k] is True for k in GATES) and reading["readonly_rollback"] is True,
            "reader_checks")
    require(reading["runner_state"] == ("exited" if post else "created")
            and type(reading["starts"]) is int and reading["starts"] == int(post)
            and (not post or type(reading["exit"]) is int and reading["exit"] == 0), "runner_state")
    return reading["receipt_sha256"]


def initialize(db):
    db.execute("CREATE TABLE IF NOT EXISTS runs (release TEXT PRIMARY KEY, binding TEXT NOT NULL,"
               " state TEXT NOT NULL, phase TEXT NOT NULL, idx INTEGER, reason TEXT, sqlstate TEXT)")
    db.execute("CREATE UNIQUE INDEX IF NOT EXISTS one_open ON runs ((1)) WHERE state != 'PASS'")
    db.execute("CREATE TABLE IF NOT EXISTS receipts (release TEXT, idx INTEGER, phase TEXT,"
               " runner TEXT, evidence TEXT, PRIMARY KEY(release,idx,phase))")
    db.execute("CREATE TABLE IF NOT EXISTS recoveries (release TEXT, prior_state TEXT, prior_phase TEXT,"
               " prior_idx INTEGER, reason TEXT, sqlstate TEXT, observation TEXT, recorded_ns INTEGER)")


def record_post(db, release, index, runners, post):
    db.execute("INSERT INTO receipts VALUES (?,?,?,?,?)", (release, index, "post", runners[index], post))
    if index + 1 == len(runners):
        db.execute("UPDATE runs SET state='PASS',phase='complete' WHERE release=?", (release,))


def status(db, release):
    row = db.execute("SELECT state,phase,idx,reason,sqlstate FROM runs WHERE release=?", (release,)).fetchone()
    if row is None:
        return {"result": "NOT_STARTED"}
    report = dict(zip(("result", "phase", "index", "error", "sqlstate"), row))
    report["confirmed"] = db.execute("SELECT COUNT(*) FROM receipts WHERE release=? AND phase='post'",
                                     (release,)).fetchone()[0]
    return report


def recovery_view(db, host, report, *, recovery=False):
    release = host.release
    binding, = db.execute("SELECT binding FROM runs WHERE release=?", (release,)).fetchone()
    require(binding == host.binding, "release_changed")
    index = report["confirmed"]
    require(index < len(host.plan["migrations"]), "journal_inconsistent")
    receipts = db.execute("SELECT idx FROM receipts WHERE release=? AND phase='post' ORDER BY idx",
                          (release,)).fetchall()
    require(receipts == [(i,) for i in range(index)], "journal_inconsistent")
    baseline = db.execute("SELECT evidence FROM receipts WHERE release=? AND phase='fence'",
                          (release,)).fetchone()
    require(baseline or report["phase"] == "fence", "original_fence_missing")
    runners = [row[0] for row in db.execute(
        "SELECT runner FROM receipts WHERE release=? AND phase='created' ORDER BY idx", (release,))]
    pre = db.execute("SELECT evidence FROM receipts WHERE release=? AND idx=? AND "
                     "(phase='pre' OR phase LIKE 'pre:%') ORDER BY rowid DESC LIMIT 1",
                     (release, index)).fetchone()
    proof = {"runner": runners[index] if runners else None, "pre": pre[0] if pre else None,
             "fence": baseline[0] if baseline else None}
    reading = host.call("inspect", index, proof, recovery=recovery)
    verify_fence(reading, time.time())
    fence = reading["fence_snapshot_sha256"]
    require(not baseline or baseline[0] == fence, "fence_changed")
    state = reading["state"]
    if not runners:
        # Creator may have completed before its response was recorded. Adopt
        # only a complete independently verified original set. Never recreate
        # after a possible create unless the host excludes even a pending intent.
        require(not index and report["phase"] in ("fence", "create"), "runner_set_missing")
        runners = reading["runners"]
        if not runners:
            require(runners == [] and state == "NOT_STARTED" and reading["no_create_intent"] is True
                    and reading["no_start_intent"] is True and reading["settled"] is True,
                    "creation_unknown")
            return state, index, runners, fence, None
        require(report["phase"] == "create" and reading["original_runner_set_verified"] is True,
                "creator_identity")
    check_runners(host.plan, runners)
    runner = runners[index]
    require(reading["runner"] == runner, "runner_identity")
    if state == "RUNNING":
        return state, index, runners, fence, None
    require(reading["settled"] is True, "outstanding_effect")
    if state == "NOT_STARTED":
        require(reading["no_start_intent"] is True, "start_unknown")
        verify_reader(host.plan, index, reading, runner, fence, False)
        return state, index, runners, fence, None
    require(state == "EXITED" and pre and reading["pre"] == pre[0], "outcome_unknown")
    evidence = verify_reader(host.plan, index, reading, runner, fence, True)
    require(evidence != pre[0], "post_identity")
    return "APPLIED", index, runners, fence, evidence


def inspect_run(db, release):
    report = status(db, release)
    if report["result"] in ("NOT_STARTED", "PASS"):
        return report
    report["observation"] = "UNKNOWN"
    try:
        report["observation"] = recovery_view(db, Host(release), report)[0]
    except Exception:
        pass
    return report


def check_runners(plan, runners):
    require(isinstance(runners, list) and len(runners) == len(plan["migrations"])
            and all(matches(HASH, r) for r in runners) and len(set(runners)) == len(runners),
            "creator_identity")


def execute(db, host, *, resume=False):
    plan, release = host.plan, host.release
    validate_plan(plan, release)
    host.authorized()
    index = 0
    runners = []
    fence = None
    if resume:
        report = status(db, release)
        require(report["result"] in ("INTENT", "HOLD"), "no_interrupted_run")
        state, index, runners, fence, post = recovery_view(db, host, report, recovery=True)
        require(state in ("APPLIED", "NOT_STARTED"), "recovery_wait")
        host.authorized()
        with db:
            db.execute("INSERT INTO recoveries VALUES (?,?,?,?,?,?,?,?)",
                       (release, report["result"], report["phase"], report["index"], report["error"],
                        report["sqlstate"], state, time.time_ns()))
            if post:
                record_post(db, release, index, runners, post)
                index += 1
            db.execute("UPDATE runs SET state='INTENT',reason=NULL,sqlstate=NULL WHERE release=? AND state!='PASS'",
                       (release,))
    else:
        try:
            with db:
                db.execute("INSERT INTO runs VALUES (?,?,'INTENT','fence',NULL,NULL,NULL)",
                           (release, host.binding))
        except sqlite3.IntegrityError:
            prior = db.execute("SELECT binding FROM runs WHERE release=?", (release,)).fetchone()
            require(prior and prior[0] == host.binding, "other_or_changed_release")
            return status(db, release)

    def call(phase, index=None, **proof):
        with db:
            db.execute("UPDATE runs SET phase=?,idx=? WHERE release=?", (phase, index, release))
        return host.call(phase, index, proof)

    try:
        if index == len(plan["migrations"]):
            return status(db, release)
        if fence is None:
            observed = call("fence")
            verify_fence(observed, time.time())
            fence = observed["fence_snapshot_sha256"]
        with db:
            db.execute("INSERT OR IGNORE INTO receipts VALUES (?,-1,'fence',NULL,?)", (release, fence))
        if not runners:
            created = call("create", fence=fence)
            require(created["image"] == plan["image"] and created["state"] == "CREATED_NOT_STARTED",
                    "creator_identity")
            runners = created["runners"]
            check_runners(plan, runners)
        with db:
            db.executemany("INSERT OR IGNORE INTO receipts VALUES (?,?,'created',?,?)",
                           [(release, i, r, fence) for i, r in enumerate(runners)])
        for index in range(index, len(runners)):
            runner = runners[index]
            pre = call("pre", index, runner=runner, fence=fence)
            pre_hash = verify_reader(plan, index, pre, runner, fence, False)
            with db:
                phase = "pre:" + secrets.token_hex(16) if resume else "pre"
                db.execute("INSERT INTO receipts VALUES (?,?,?,?,?)", (release, index, phase, runner, pre_hash))
            # Starter must recheck the live runner, pre receipt, snapshot and
            # continuous fence at the last possible instant before starting it.
            accepted = call("start", index, runner=runner, fence=fence, pre=pre_hash)
            require(accepted["runner"] == runner and accepted["pre"] == pre_hash, "start_identity")
            after = call("post", index, runner=runner, fence=fence, pre=pre_hash)
            post_hash = verify_reader(plan, index, after, runner, fence, True)
            require(after["pre"] == pre_hash and post_hash != pre_hash, "post_identity")
            with db:
                record_post(db, release, index, runners, post_hash)
    except Exception as exc:
        failure = exc if isinstance(exc, Hold) else Hold("invalid_or_unknown_result")
        with db:
            db.execute("UPDATE runs SET state='HOLD',reason=?,sqlstate=? WHERE release=?",
                       (failure.reason, failure.sqlstate, release))
    return status(db, release)


def main(args):
    require(len(args) == 2 and args[0] in ("run", "resume", "status") and matches(ID, args[1]), "invalid_request")
    op, release = args
    os.umask(0o077)
    trusted(STATE, directory=True)
    require(STATE.stat().st_mode & 0o077 == 0, "untrusted_installation")
    path = STATE / "journal.sqlite"
    if path.is_symlink() or path.exists():
        trusted(path)
    if op == "status" and not path.exists():
        return {"result": "NOT_STARTED"}
    uri = path.as_uri() + ("?mode=ro" if op == "status" else "?mode=rwc")
    with contextlib.closing(sqlite3.connect(uri, uri=True)) as db:
        if op == "status":
            return inspect_run(db, release)
        db.execute("PRAGMA synchronous=FULL")
        initialize(db)
        host = Host(release)
        fd = os.open(STATE, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise Hold("controller_active") from None
            # An adapter that outlives a lost reply retains this lock until exit.
            host.lock_fd = fd
            return execute(db, host, resume=(op == "resume"))
        finally:
            os.close(fd)


if __name__ == "__main__":
    try:
        result = main(sys.argv[1:])
    except Exception as error:
        result = {"result": "HOLD", "error": error.reason if isinstance(error, Hold) else "unavailable"}
    print(json.dumps(result))
    sys.exit(0 if result["result"] == "PASS" or sys.argv[1:2] == ["status"] and result["result"] != "HOLD" else 1)
