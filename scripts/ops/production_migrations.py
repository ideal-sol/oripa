"""Restricted NEW migration controller. The installed host adapter owns all private I/O."""

import contextlib
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import stat
import subprocess
import sys
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
        raw = private_file(CONFIG / "release.json")
        self.plan = json.loads(raw)
        validate_plan(self.plan, release)
        self.binding = digest(raw)

    def check_binding(self):
        require(digest(private_file(CONFIG / "release.json")) == self.binding, "release_changed")
        trusted(ADAPTER)
        require(digest(ADAPTER.read_bytes()) == self.plan["adapter_sha256"], "adapter_changed")

    def authorized(self):
        self.check_binding()
        go = json.loads(private_file(CONFIG / "go.json"))
        require(go["release"] == self.release and go["site"] == self.plan["site"]
                and go["approved"] is True and go["plan_sha256"] == self.binding
                and type(go["expires"]) is int and time.time() < go["expires"], "go_invalid")

    def call(self, phase, index, proof):
        if phase == "inspect":
            self.check_binding()
        else:
            self.authorized()
        # No shell, configurable argv, caller environment, or inherited terminal.
        request = dict(release=self.release, plan_sha256=self.binding, phase=phase,
                       index=index, proof=proof, nonce=secrets.token_hex(16))
        reply, code = exchange(request)
        if code or reply.get("result") != "PASS":
            error = reply.get("error")
            state = reply.get("sqlstate")
            raise Hold(error if isinstance(error, str) and error in ERRORS else "adapter_failed",
                       state if isinstance(state, str) and state in SQLSTATES else None)
        require(all(reply.get(k) == request[k] for k in ("release", "plan_sha256", "phase", "index", "nonce")),
                "receipt_identity")
        return reply


def exchange(request):
    with subprocess.Popen([str(ADAPTER)], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                          stderr=subprocess.DEVNULL, cwd="/",
                          env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin"}) as proc:
        proc.stdin.write(json.dumps(request).encode())
        proc.stdin.close()
        raw = proc.stdout.read(65537)
        if len(raw) > 65536:
            proc.kill()
            raise Hold("adapter_protocol")
        return json.loads(raw), proc.wait()


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


def status(db, release):
    row = db.execute("SELECT state,phase,idx,reason,sqlstate FROM runs WHERE release=?", (release,)).fetchone()
    if row is None:
        return {"result": "NOT_STARTED"}
    report = dict(zip(("result", "phase", "index", "error", "sqlstate"), row))
    report["confirmed"] = db.execute("SELECT COUNT(*) FROM receipts WHERE release=? AND phase='post'",
                                     (release,)).fetchone()[0]
    return report


def inspect_run(db, release):
    report = status(db, release)
    if report["result"] in ("NOT_STARTED", "PASS"):
        return report
    # Observation can neither clear an intent nor mark a migration as passed.
    # Retain the durable status even when the reader or active plan is unavailable.
    report["observation"] = "UNKNOWN"
    try:
        host = Host(release)
        binding, = db.execute("SELECT binding FROM runs WHERE release=?", (release,)).fetchone()
        require(binding == host.binding, "release_changed")
        index = report["index"]
        row = db.execute("SELECT runner,evidence FROM receipts WHERE release=? AND idx=? AND phase='pre'",
                         (release, index)).fetchone()
        proof = {"runner": row[0], "pre": row[1]} if row else {}
        reading = host.call("inspect", index, proof)
        if (reading.get("runner") == proof.get("runner") and
                reading.get("state") in ("NOT_STARTED", "RUNNING", "EXITED", "UNKNOWN")):
            report["observation"] = reading["state"]
    except Exception:
        pass
    return report


def execute(db, host):
    plan, release = host.plan, host.release
    validate_plan(plan, release)
    host.authorized()
    # The reservation is committed before any host call. It survives process
    # death, SSH loss and release changes. Only the reserving invocation runs.
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
        observed = call("fence")
        verify_fence(observed, time.time())
        fence = observed["fence_snapshot_sha256"]
        created = call("create", fence=fence)
        runners = created["runners"]
        require(created["image"] == plan["image"] and created["state"] == "CREATED_NOT_STARTED"
                and isinstance(runners, list) and len(runners) == len(plan["migrations"])
                and all(matches(HASH, r) for r in runners) and len(set(runners)) == len(runners),
                "creator_identity")
        for index, runner in enumerate(runners):
            pre = call("pre", index, runner=runner, fence=fence)
            pre_hash = verify_reader(plan, index, pre, runner, fence, False)
            with db:
                db.execute("INSERT INTO receipts VALUES (?,?,?,?,?)", (release, index, "pre", runner, pre_hash))
            # Starter must recheck the live runner, pre receipt, snapshot and
            # continuous fence at the last possible instant before starting it.
            accepted = call("start", index, runner=runner, fence=fence, pre=pre_hash)
            require(accepted["runner"] == runner and accepted["pre"] == pre_hash, "start_identity")
            after = call("post", index, runner=runner, fence=fence, pre=pre_hash)
            post_hash = verify_reader(plan, index, after, runner, fence, True)
            require(after["pre"] == pre_hash and post_hash != pre_hash, "post_identity")
            with db:
                db.execute("INSERT INTO receipts VALUES (?,?,?,?,?)", (release, index, "post", runner, post_hash))
                if index + 1 == len(runners):
                    db.execute("UPDATE runs SET state='PASS',phase='complete' WHERE release=?", (release,))
    except Exception as exc:
        failure = exc if isinstance(exc, Hold) else Hold("invalid_or_unknown_result")
        with db:
            db.execute("UPDATE runs SET state='HOLD',reason=?,sqlstate=? WHERE release=?",
                       (failure.reason, failure.sqlstate, release))
    return status(db, release)


def main(args):
    require(len(args) == 2 and args[0] in ("run", "status") and matches(ID, args[1]), "invalid_request")
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
        return execute(db, Host(release))


if __name__ == "__main__":
    try:
        result = main(sys.argv[1:])
    except Exception as error:
        result = {"result": "HOLD", "error": error.reason if isinstance(error, Hold) else "unavailable"}
    print(json.dumps(result))
    sys.exit(0 if result["result"] == "PASS" or sys.argv[1:2] == ["status"] and result["result"] != "HOLD" else 1)
