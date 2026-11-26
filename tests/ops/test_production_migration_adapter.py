import json
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

from scripts.ops import production_migration_adapter as adapter
from scripts.ops import production_migrations as ctl
from tests.ops.test_production_migrations import approval, hash_of, publish_fixture, release


class SiteFixture:
    def __init__(self, database, now):
        self.db = database
        self.now = now
        self.settled = True
        self.rollback = True
        self.drain = True
        self.parity = True
        self.events = {}
        self.intents = {}
        self.terminal = {}

    def measure(self, plan):
        return dict(database="fixture", writers_fenced=True, financial_drain=self.drain,
                    continuous_fence=True, frozen_at=self.now-30, observed_at=int(time.time()),
                    freeze_sha256=hash_of("fence"), available_snapshot_id="fixture-snapshot")

    def runner_template(self, plan, row):
        return dict(Image=plan["image"], Env=["PRIVATE=synthetic_value"],
                    Cmd=["php", "artisan", "migrate", "--path=database/migrations-v2/" + row["file"],
                         "--force", "--no-interaction", "--no-ansi"], HostConfig={"RestartPolicy": {"Name": "no"}})

    def check_parity(self, plan, index, actual, payload):
        return self.parity and actual["HostConfig"] == payload["HostConfig"]

    def reader_spec(self, plan, pack, index, post):
        return dict(queries=pack, phase=("post" if post else "pre") + str(index), index=index, post=post)

    def read(self, plan, spec):
        self.db.execute("BEGIN")
        try:
            rows = {key: self.db.execute(query).fetchall() for key, query in spec["queries"].items()}
        finally:
            self.db.rollback()
        return dict(status="READ_ONLY_OBSERVATION_NOT_GATE_PASS", phase=spec["phase"], source_sha=plan["source"],
                    image_id=plan["image"], query_pack_sha256=self.pack_digest,
                    read_only_transaction=True, transaction_rolled_back=self.rollback,
                    image_migration_hashes={r["file"]: r["sha256"] for r in plan["migrations"]},
                    results=rows, observed_at=int(time.time()))

    def evaluate(self, plan, index, post, capture, spec):
        return dict(ledger_names=[r[0] for r in capture["results"]["ledger"]], **dict.fromkeys(ctl.GATES, True))

    inspect_read = read

    def effect_history(self, plan, binding):
        return dict(binding=binding, complete=self.settled, exclusive_effect_path=True,
                    intents=self.intents, terminal=self.terminal,
                    observed_at=int(time.time()))

    def runner_history(self, plan, runner):
        return dict(runner=runner, complete=True, exclusive_start_path=True, starts=self.events.get(runner, 0))


class AdapterTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.folder = Path(temp.name)
        self.db = sqlite3.connect(self.folder / "synthetic-site.sqlite")
        self.addCleanup(self.db.close)
        self.db.execute("CREATE TABLE applied (name TEXT PRIMARY KEY)")
        now = int(time.time())
        self.site = SiteFixture(self.db, now)
        self.pack = {"ledger": "SELECT name FROM applied ORDER BY name"}
        self.site.pack_digest = ctl.digest(adapter.canonical(self.pack))
        self.conditions = dict(database="fixture", architecture="amd64", query_pack_sha256=self.site.pack_digest,
                               recovery_read_budget_ms=8700)
        conditions_path = self.folder / "conditions.json"
        conditions_path.write_bytes(adapter.canonical(self.conditions))
        conditions_path.chmod(0o600)
        self.plan = release()
        self.plan.update(conditions_sha256=ctl.digest(adapter.canonical(self.conditions)),
                         adapter_sha256=ctl.digest(Path(adapter.__file__).read_bytes()))
        names = []
        for row in self.plan["migrations"]:
            row["before_ledger_sha256"] = ctl.digest(adapter.canonical(names))
            names.append(row["file"][:-4])
            row["after_ledger_sha256"] = ctl.digest(adapter.canonical(names))
        publish_fixture(self.folder, {"stage": self.plan})
        stage = ctl.digest((self.folder / "stage-receipt").read_bytes())
        self.records = dict(stage=self.plan, go=approval(self.plan, stage),
                            snapshot=dict(site=self.plan["site"], release=self.plan["release"], stage_sha256=stage,
                                          database="fixture", id="fixture-snapshot", manual=True, human_instance_mapping_confirmed=True, status="available",
                                          freeze_sha256=hash_of("fence"), freeze_started_at=now-30,
                                          created_at=now-20, available_at=now-10))
        publish_fixture(self.folder, self.records)
        for name, value in (("CONFIG", self.folder), ("STATE", self.folder), ("ADAPTER", Path(adapter.__file__)),
                            ("exchange", self.exchange)):
            mock = patch.object(ctl, name, value)
            mock.start()
            self.addCleanup(mock.stop)
        # The subject's normal root-owned ancestor test rejects host temp directories.
        trusted = patch.object(ctl, "trusted")
        trusted.start()
        self.addCleanup(trusted.stop)
        self.host = ctl.Host(self.plan["release"])
        self.journal = sqlite3.connect(self.folder / "synthetic-journal.sqlite")
        self.addCleanup(self.journal.close)
        ctl.initialize(self.journal)
        self.containers = {}
        self.starts = []
        self.lose_reply = False

        self.site.create_runners = self.create_runners
        self.site.start_runner = self.start_runner
        self.site.runner = lambda plan, index, name: self.containers.get(name)
        self.site.image = lambda plan: dict(Id=plan["image"], Os="linux", Architecture="amd64",
                                            Config={"Labels": {"org.opencontainers.image.revision": plan["source"]}})
        self.site.created_set = lambda plan: self.creator_receipt

    def create_runners(self, plan, names, latch):
        intent = latch()
        self.site.intents[intent] = dict(kind="create", index=None)
        ids = []
        for row, name in zip(plan["migrations"], names):
            self.assertNotIn(name, self.containers)
            body = self.site.runner_template(plan, row)
            self.containers[name] = dict(Id=hash_of(name), Name="/" + name, Config=body, HostConfig=body["HostConfig"],
                                         Image=body["Image"], RestartCount=0,
                                         State=dict(Status="created", Running=False, OOMKilled=False, ExitCode=0))
            ids.append(hash_of(name))
        self.creator_receipt = dict(intent_sha256=intent, runners=ids)
        self.site.terminal[intent] = "COMPLETED"
        return ids

    def start_runner(self, plan, index, identity, latch, before_send):
        intent = latch()
        self.site.intents[intent] = dict(kind="start", index=index)
        before_send()
        runner = next(r for r in self.containers.values() if r["Id"] == identity["runner"])
        self.assertEqual(runner["State"]["Status"], "created")
        filename = runner["Config"]["Cmd"][3].split("/")[-1]
        self.db.execute("INSERT INTO applied VALUES (?)", (filename[:-4],))
        self.db.commit()
        runner["State"].update(Status="exited", Running=False)
        self.starts.append(filename)
        self.site.events[runner["Id"]] = self.site.events.get(runner["Id"], 0) + 1
        self.site.terminal[intent] = "COMPLETED"
        if self.lose_reply:
            self.lose_reply = False
            raise ctl.Hold("adapter_timeout")

    def exchange(self, request, lock_fd=None, *, inspect_wait=None):
        host = ctl.Host(request["release"])
        if request["phase"] == "inspect":
            self.last_inspect_wait = inspect_wait
        runtime = adapter.Adapter(host, self.site, self.conditions, self.pack)
        return runtime.dispatch(request), 0

    def test_real_controller_dispatch_and_exactly_once_recovery(self):
        self.lose_reply = True
        report = ctl.execute(self.journal, self.host)
        self.assertEqual((report["result"], report["phase"], len(self.starts)), ("HOLD", "start", 1))
        before = self.journal.total_changes
        self.site.settled = False
        self.assertEqual(ctl.inspect_run(self.journal, self.host.release)["observation"], "UNKNOWN")
        self.site.settled = True
        self.assertEqual(ctl.inspect_run(self.journal, self.host.release)["observation"], "APPLIED")
        self.assertIsNone(self.last_inspect_wait)
        self.assertEqual(self.journal.total_changes, before)
        report = ctl.execute(self.journal, self.host, resume=True)
        self.assertEqual(self.last_inspect_wait, 8.7)
        self.assertEqual((report["result"], report["confirmed"]), ("PASS", 2))
        self.assertEqual(ctl.execute(self.journal, self.host), report)
        self.assertEqual(self.starts, [row["file"] for row in self.plan["migrations"]])
        self.assertNotIn("synthetic_value", json.dumps(report))

    def test_denies_start_without_original_readonly_evidence(self):
        self.site.rollback = False
        report = ctl.execute(self.journal, self.host)
        self.assertEqual((report["result"], report["phase"], self.starts), ("HOLD", "pre", []))

    def test_snapshot_go_and_financial_binding_before_create(self):
        for kind, key, wrong in (("snapshot", "database", "other"), ("snapshot", "manual", False),
                                 ("snapshot", "freeze_sha256", hash_of("other")), ("go", "authenticated_human", False)):
            with self.subTest(kind=kind, key=key):
                records = dict(self.records)
                records[kind] = dict(records[kind], **{key: wrong})
                publish_fixture(self.folder, records)
                with self.assertRaises(ctl.Hold):
                    self.host.call("fence", None, {})
        publish_fixture(self.folder, self.records)
        self.site.drain = False
        with self.assertRaises(ctl.Hold):
            self.host.call("fence", None, {})
        self.assertFalse(self.containers)

    def test_parity_failure_stops_before_start(self):
        self.site.parity = False
        result = ctl.execute(self.journal, self.host)
        self.assertEqual((result["result"], result["phase"], self.starts), ("HOLD", "create", []))

    def test_ambiguous_engine_history_cannot_resume(self):
        self.lose_reply = True
        self.assertEqual(ctl.execute(self.journal, self.host)["result"], "HOLD")
        runner, = self.site.events
        self.site.events[runner] = 2
        self.assertEqual(ctl.inspect_run(self.journal, self.host.release)["observation"], "UNKNOWN")
        with self.assertRaises(ctl.Hold):
            ctl.execute(self.journal, self.host, resume=True)
        self.assertEqual(len(self.starts), 1)

    def test_missing_terminal_or_unaccounted_reader_blocks_recovery(self):
        self.lose_reply = True
        self.assertEqual(ctl.execute(self.journal, self.host)["result"], "HOLD")
        last = next(reversed(self.site.intents))
        del self.site.terminal[last]
        self.assertEqual(ctl.inspect_run(self.journal, self.host.release)["observation"], "UNKNOWN")
        self.site.terminal[last] = "DEFINITIVELY_REJECTED"
        self.assertEqual(ctl.inspect_run(self.journal, self.host.release)["observation"], "UNKNOWN")
        self.site.terminal[last] = "COMPLETED"
        self.site.intents[hash_of("pending reader")] = dict(kind="reader", index=0)
        with self.assertRaises(ctl.Hold):
            ctl.execute(self.journal, self.host, resume=True)
        self.assertEqual(len(self.starts), 1)

    def test_lost_creator_response_adopts_original_complete_set(self):
        def lose_response(plan, names, latch):
            self.create_runners(plan, names, latch)
            raise ctl.Hold("adapter_timeout")
        self.site.create_runners = lose_response
        self.assertEqual(ctl.execute(self.journal, self.host)["phase"], "create")
        self.assertEqual(ctl.inspect_run(self.journal, self.host.release)["observation"], "NOT_STARTED")
        self.assertEqual(ctl.execute(self.journal, self.host, resume=True)["result"], "PASS")
        self.assertEqual(len(self.starts), 2)

    def test_running_observation_keeps_original_request(self):
        def running(plan, index, identity, latch, before_send):
            intent = latch()
            self.site.intents[intent] = dict(kind="start", index=index)
            before_send()
            runner = next(r for r in self.containers.values() if r["Id"] == identity["runner"])
            runner["State"].update(Status="running", Running=True)
            raise ctl.Hold("adapter_timeout")
        self.site.start_runner = running
        self.assertEqual(ctl.execute(self.journal, self.host)["phase"], "start")
        self.assertEqual(ctl.inspect_run(self.journal, self.host.release)["observation"], "RUNNING")
        with self.assertRaisesRegex(ctl.Hold, "recovery_wait"):
            ctl.execute(self.journal, self.host, resume=True)
        self.assertEqual(len(self.site.intents), 2)

    def test_revoked_fence_between_latch_and_send_prevents_start(self):
        def change(plan, index, identity, latch, before_send):
            latch()
            self.site.drain = False
            before_send()
            self.fail("revoked fence accepted")
        self.site.start_runner = change
        report = ctl.execute(self.journal, self.host)
        self.assertEqual((report["result"], report["phase"], self.starts), ("HOLD", "start", []))

    def test_tampered_private_inputs_are_rejected_before_loading(self):
        conditions = self.folder / "conditions.json"
        conditions.write_bytes(adapter.canonical(self.conditions))
        conditions.chmod(0o600)
        source = self.folder / "measurement.py"
        source.write_bytes(b"raise AssertionError('must never execute')")
        source.chmod(0o600)
        self.conditions["measurement_sha256"] = hash_of("different")
        with self.assertRaises(ctl.Hold):
            adapter.load_site(dict(self.plan, conditions_sha256=hash_of("different")))
        conditions.write_bytes(adapter.canonical(self.conditions))
        with self.assertRaisesRegex(ctl.Hold, "measurement_changed"):
            adapter.load_site(dict(self.plan, conditions_sha256=ctl.digest(conditions.read_bytes())))

    def test_entry_suppresses_incidental_private_output_and_limits_diagnostics(self):
        program = """
import os, sys
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, sys.argv.pop())
from scripts.ops import production_migration_adapter as a
def private_failure(plan):
    os.write(1, b'private fixture stdout')
    os.write(2, b'private fixture stderr')
    raise a.ctl.Hold('sql_error', '57014')
with patch.object(a.os, 'geteuid', return_value=0), patch.object(a.ctl, 'Host'), \\
     patch.object(a.ctl, 'ADAPTER', Path(a.__file__)), patch.object(a.ctl, 'trusted'), \\
     patch.object(a.ctl, 'STATE', Path.cwd()), patch.object(a, 'load_site', private_failure):
    sys.exit(a.main())
"""
        run = subprocess.run([sys.executable, "-ISB", "-c", program, str(Path(adapter.__file__).parents[2])],
                             input=b'{"release":"synthetic","phase":"post"}', capture_output=True,
                             cwd=self.folder, timeout=5)
        self.assertEqual((run.returncode, run.stderr), (1, b""))
        self.assertEqual(json.loads(run.stdout), dict(result="HOLD", error="sql_error", sqlstate="57014"))
