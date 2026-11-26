import json
import os
from pathlib import Path
import sqlite3
import tempfile
import time
import unittest
from unittest.mock import patch

from scripts.ops import production_migrations as m


def hash_of(value):
    return m.digest(str(value).encode())


def release():
    rows = [dict(file=f"2026_01_01_{n:06}_test.php", sha256=hash_of(n),
                 before_ledger_sha256=hash_of(n-1), after_ledger_sha256=hash_of(n)) for n in (1, 2)]
    return dict(release="TEST-1", site="SYNTHETIC", artifact="artifact", build="build",
                source="a"*40, tree="b"*40, authority="c"*40, image="sha256:"+hash_of("image"),
                services=["api"], adapter_sha256=hash_of("adapter"), conditions_sha256=hash_of("gates"),
                start_ledger=0, end_ledger=2, migrations=rows)


class SyntheticHost:
    def __init__(self):
        self.plan = release()
        self.release = self.plan["release"]
        self.binding = hash_of(self.plan)
        self.calls = []
        self.modify = lambda phase, reply: None
        self.runners = [hash_of("runner1"), hash_of("runner2")]

    def authorized(self):
        return None

    def call(self, phase, index, proof):
        self.calls.append((phase, index))
        now = int(time.time())
        reply = {k: True for k in m.GATES}
        reply.update(continuous_fence=True, snapshot_available=True, frozen_at=now-30, snapshot_at=now-20,
                     available_at=now-10, observed_at=now, fence_snapshot_sha256=hash_of("fence"))
        if phase == "create":
            reply.update(image=self.plan["image"], state="CREATED_NOT_STARTED", runners=self.runners)
        if index is not None:
            row = self.plan["migrations"][index]
            post = phase == "post"
            reply.update(proof, source=self.plan["source"], image=self.plan["image"],
                         command=["php","artisan","migrate","--path=database/migrations-v2/"+row["file"],
                                  "--force","--no-interaction","--no-ansi"],
                         conditions_sha256=self.plan["conditions_sha256"], file=row["file"],
                         file_sha256=row["sha256"], readonly_rollback=True, ledger=index+int(post),
                         ledger_sha256=row["after_ledger_sha256" if post else "before_ledger_sha256"],
                         receipt_sha256=hash_of((phase,index)), starts=int(post), exit=0,
                         runner_state="exited" if post else "created")
        self.modify(phase, reply)
        return reply


class ControllerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "state.sqlite"
        self.db = sqlite3.connect(self.path)
        self.addCleanup(self.db.close)
        m.initialize(self.db)
        self.host = SyntheticHost()

    def test_order_and_receipts_survive_reopen_without_repeating(self):
        report = m.execute(self.db, self.host)
        self.assertEqual((report["result"],report["confirmed"]), ("PASS",2))
        self.assertEqual(self.host.calls, [("fence",None),("create",None),("pre",0),("start",0),("post",0),
                                          ("pre",1),("start",1),("post",1)])
        with sqlite3.connect(self.path) as second:
            self.assertEqual(m.execute(second,self.host), report)
            self.assertEqual(len(self.host.calls),8)
            self.assertEqual(second.execute(
                "SELECT count(*) FROM receipts WHERE phase IN ('pre','post')").fetchone(), (4,))

    def test_disconnect_after_start_and_simultaneous_requests(self):
        def interrupt(phase, reply):
            if phase == "start":
                with sqlite3.connect(self.path) as other:
                    self.assertEqual(m.execute(other,self.host)["result"], "INTENT")
                raise SystemExit
        self.host.modify = interrupt
        with self.assertRaises(SystemExit):
            m.execute(self.db,self.host)
        report = m.execute(self.db,self.host)
        self.assertEqual((report["phase"],report["result"],report["confirmed"]), ("start","INTENT",0))
        self.assertEqual(self.host.calls.count(("start",0)),1)
        self.host.release = self.host.plan["release"] = "TEST-OTHER"
        with self.assertRaisesRegex(m.Hold,"other_or_changed_release"):
            m.execute(self.db,self.host)

    def test_failed_checks_stop_at_first_discrepancy(self):
        for phase, bad in [
            ("fence", {"snapshot_available":False}), ("fence", {"financial_drain":False}),
            ("fence", {"frozen_at":int(time.time())+1}), ("fence", {"writers_fenced":False}),
            ("create", {"runners":[hash_of(1)]*2}), ("pre", {"observed_at":0}),
            ("pre", {"file_sha256":hash_of("wrong")}), ("pre", {"image":"unexpected"}),
            ("pre", {"command":["php","artisan","migrate"]}),
            ("pre", {"file":"wrong"}), ("pre", {"starts":1}), ("pre", {"readonly_rollback":False}),
            ("pre", {"continuous_fence":False}), ("start", {"pre":hash_of(0)}),
            ("post", {"exit":1}), ("post", {"ledger":2}), ("post", {"schema_exact":False}),
            ("post", {"existing_data_preserved":False}), ("post", {"ledger_sha256":hash_of("old")}),
        ]:
            with self.subTest(phase=phase,bad=bad), sqlite3.connect(":memory:") as db:
                m.initialize(db)
                host = SyntheticHost()
                host.modify = lambda stage,r: r.update(bad) if stage == phase else None
                report = m.execute(db,host)
                self.assertEqual((report["phase"],report["confirmed"],report["result"]), (phase,0,"HOLD"))
                self.assertNotIn(("pre",1),host.calls)
                before = host.calls.copy()
                m.execute(db,host)
                self.assertEqual(host.calls,before)

    def test_private_diagnostic_never_published(self):
        for stage in ("create","pre","post"):
            with self.subTest(stage=stage), sqlite3.connect(":memory:") as db:
                m.initialize(db)
                host = SyntheticHost()
                def failure(phase, reply):
                    if phase==stage:
                        raise RuntimeError("synthetic_private_value")
                host.modify = failure
                report=m.execute(db,host)
                self.assertEqual(report["phase"],stage)
                self.assertNotIn("synthetic_private_value",json.dumps(report))

    def test_status_after_disconnect_is_read_only(self):
        def disconnect(phase, reply):
            if phase == "start":
                raise SystemExit
        self.host.modify = disconnect
        with self.assertRaises(SystemExit):
            m.execute(self.db, self.host)
        self.host.modify = lambda phase, r: r.update(state="EXITED")
        writes_before = self.db.total_changes
        with patch.object(m, "Host", return_value=self.host):
            report = m.inspect_run(self.db, self.host.release)
        self.assertEqual((report["observation"], report["result"], report["confirmed"]), ("UNKNOWN","INTENT",0))
        self.assertEqual(self.db.total_changes, writes_before)
        self.assertEqual(self.host.calls[-1], ("inspect",0))

    def test_resume_applied_row_only_starts_the_remaining_runner(self):
        def lost_post(phase, reply):
            if phase == "post":
                raise m.Hold("reader_failed")
        self.host.modify = lost_post
        self.assertEqual(m.execute(self.db, self.host)["result"], "HOLD")
        def reconcile(phase, reply):
            if phase == "inspect":
                row = self.host.plan["migrations"][0]
                reply.update(state="EXITED", settled=True, runner_state="exited", starts=1,
                             ledger=1, ledger_sha256=row["after_ledger_sha256"])
        self.host.modify = reconcile
        with patch.object(m, "Host", return_value=self.host):
            self.assertEqual(m.inspect_run(self.db, self.host.release)["observation"], "APPLIED")
        self.assertEqual(m.execute(self.db, self.host, resume=True)["confirmed"], 2)
        self.assertEqual(self.host.calls.count(("start",0)), 1)
        self.assertEqual(self.host.calls.count(("create",None)), 1)
        self.assertEqual(self.db.execute("SELECT prior_state,reason,observation FROM recoveries").fetchall(),
                         [("HOLD","reader_failed","APPLIED")])

    def test_resume_requires_settled_unstarted_runner_or_verified_post(self):
        def interrupted(phase, reply):
            if phase == "start":
                raise SystemExit
        self.host.modify = interrupted
        with self.assertRaises(SystemExit):
            m.execute(self.db, self.host)
        for fields in (dict(state="RUNNING"), dict(state="UNKNOWN", settled=True),
                       dict(state="NOT_STARTED", settled=False),
                       dict(state="NOT_STARTED", settled=True, no_start_intent=False),
                       dict(state="EXITED", settled=True, exit=1)):
            with self.subTest(fields=fields):
                self.host.modify = lambda phase, reply: reply.update(fields)
                with self.assertRaises(m.Hold):
                    m.execute(self.db, self.host, resume=True)
                self.assertEqual(self.host.calls[-1], ("inspect",0))
                self.assertEqual(m.status(self.db, self.host.release)["result"], "INTENT")
        self.host.modify = lambda phase, reply: reply.update(state="NOT_STARTED", settled=True,
                                                             no_start_intent=True) if phase == "inspect" else None
        self.assertEqual(m.execute(self.db, self.host, resume=True)["result"], "PASS")
        self.assertEqual(self.db.execute("SELECT COUNT(*) FROM receipts WHERE idx=0 AND phase LIKE 'pre%'")
                         .fetchone()[0], 2)
        self.assertEqual(self.host.calls.count(("create",None)), 1)

    def test_idle_polling_and_pid_change_do_not_override_fence_evidence(self):
        self.host.modify = lambda phase, r: r.update(pid=101 if phase=="pre" else 202, idle_poll=True)
        self.assertEqual(m.execute(self.db,self.host)["result"], "PASS")

    def test_reconcile_creator_without_duplicate_creation(self):
        def lose_create(phase, reading):
            if phase == "create":
                raise SystemExit
        self.host.modify = lose_create
        with self.assertRaises(SystemExit):
            m.execute(self.db, self.host)
        def observe_created(phase, reading):
            if phase == "inspect":
                reading.update(state="NOT_STARTED", settled=True, no_start_intent=True,
                               original_runner_set_verified=True, runners=self.host.runners,
                               runner=self.host.runners[0])
        self.host.modify = observe_created
        self.assertEqual(m.execute(self.db, self.host, resume=True)["result"], "PASS")
        self.assertEqual(self.host.calls.count(("create",None)), 1)

    def test_no_creator_replay_when_its_attempt_is_unresolved(self):
        def fail_create(phase, reading):
            if phase == "create":
                raise m.Hold("creator_failed")
        self.host.modify = fail_create
        self.assertEqual(m.execute(self.db, self.host)["result"], "HOLD")
        for no_intent in (False, True):
            self.host.modify = lambda phase,r: r.update(runners=[],state="NOT_STARTED",settled=True,
                no_create_intent=no_intent,no_start_intent=True) if phase=="inspect" else None
            if not no_intent:
                with self.assertRaisesRegex(m.Hold, "creation_unknown"):
                    m.execute(self.db, self.host, resume=True)
                self.assertEqual(self.host.calls.count(("create",None)), 1)
            else:
                self.assertEqual(m.execute(self.db, self.host, resume=True)["result"], "PASS")


    def test_unapproved_order_or_caller_input(self):
        plan = release()
        plan["migrations"].reverse()
        with self.assertRaisesRegex(m.Hold,"invalid_order"):
            m.validate_plan(plan,plan["release"])
        for args in (["run","../test"],["run","TEST","--image","x"],["shell","TEST"]):
            with self.assertRaisesRegex(m.Hold,"invalid_request"):
                m.main(args)


class HostBoundaryTest(unittest.TestCase):
    def test_inspection_has_deadline_and_effect_timeout_does_not_stop_adapter(self):
        with tempfile.TemporaryDirectory() as name:
            root = Path(name)
            marker = root / "finished"
            adapter = root / "adapter"
            adapter.write_text("#!/usr/bin/python3\nimport os, time\nfrom pathlib import Path\n"
                               "os.close(1)\ntime.sleep(.5)\nPath(__file__).with_name('finished').touch()\n")
            adapter.chmod(0o700)
            with patch.object(m, "ADAPTER", adapter), patch.object(m, "REPLY_WAIT", .1):
                with self.assertRaisesRegex(m.Hold, "adapter_timeout"):
                    m.exchange({"phase":"start"})
                for _ in range(100):
                    if marker.exists():
                        break
                    time.sleep(.02)
                self.assertTrue(marker.exists())
            adapter.write_text("#!/usr/bin/python3\nimport time\nprint('{', end='', flush=True)\ntime.sleep(15)\n")
            with patch.object(m, "ADAPTER", adapter), patch.object(m, "INSPECT_WAIT", .1):
                started = time.monotonic()
                with self.assertRaisesRegex(m.Hold, "adapter_timeout"):
                    m.exchange({"phase":"inspect"})
                self.assertLess(time.monotonic() - started, 3)

    def test_untrusted_parents_rejected(self):
        with tempfile.TemporaryDirectory() as name:
            Path(name).chmod(0o777)
            with self.assertRaisesRegex(m.Hold,"untrusted_installation"):
                m.trusted(Path(name), directory=True)

    def test_fixed_process_boundary_and_redacted_failure(self):
        with tempfile.TemporaryDirectory() as name:
            folder=Path(name)
            plan=release()
            adapter=folder/"adapter"
            # The fixture can observe only the fixed protocol and clean environment.
            adapter.write_text("#!/usr/bin/python3\nimport json,os,sys\n"
                               "q=json.load(sys.stdin)\n"
                               "assert set(q)=={'index','phase','plan_sha256','proof','release','nonce'}\n"
                               "assert len(sys.argv)==1 and 'PARENT_SECRET' not in os.environ\n"
                               "print('private stderr test',file=sys.stderr)\n"
                               "print(json.dumps(dict(q,result='HOLD',error='sql_error',sqlstate='57014',"
                               "extra='private stdout test')))\n")
            adapter.chmod(0o700)
            plan["adapter_sha256"]=m.digest(adapter.read_bytes())
            config=folder/"release.json"
            config.write_text(json.dumps(plan))
            go=folder/"go.json"
            go.write_text(json.dumps(dict(release=plan["release"],site=plan["site"],approved=True,
                                          plan_sha256=m.digest(config.read_bytes()),expires=int(time.time())+600)))
            for p in (config,go):
                p.chmod(0o600)
            with patch.object(m,"CONFIG",folder), patch.object(m,"ADAPTER",adapter), patch.dict(
                    os.environ,{"PARENT_SECRET":"synthetic"}), patch.object(m,"trusted"):
                host=m.Host(plan["release"])
                with self.assertRaises(m.Hold) as raised:
                    host.call("pre",0,{})
                self.assertEqual((raised.exception.reason,raised.exception.sqlstate),("sql_error","57014"))
                adapter.write_text("changed")
                with self.assertRaisesRegex(m.Hold,"adapter_changed"):
                    host.authorized()
                config.write_text("{}")
                with self.assertRaisesRegex(m.Hold,"release_changed"):
                    host.authorized()

    def test_invalid_approval_and_unrecognized_sqlstate(self):
        host=object.__new__(m.Host)
        host.release="TEST"
        host.plan={"site":"SITE"}
        host.binding=hash_of("bound")
        valid=dict(site="SITE",release="TEST",plan_sha256=host.binding,expires=int(time.time())+100,approved=True)
        for bad in ({"release":"OTHER"},{"site":"OTHER"},{"expires":0},{"approved":1},{"plan_sha256":"other"}):
            with patch.object(host,"check_binding"), patch.object(m,"private_file",
                    return_value=json.dumps(dict(valid,**bad)).encode()), self.assertRaisesRegex(m.Hold,"go_invalid"):
                host.authorized()
        reply=({"error":"private","sqlstate":"XXXXX"},1)
        with patch.object(host,"authorized"),patch.object(m,"exchange",return_value=reply),self.assertRaises(m.Hold) as err:
            host.call("pre",0,{})
        self.assertEqual((err.exception.reason,err.exception.sqlstate),("adapter_failed",None))
