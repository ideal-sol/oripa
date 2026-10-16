# Member Rank Platform M3 handoff

## Authority and status

Human Change `MEMBERRANK-M3-20261016`, Task `RANKMONTH-20261016`, Issue none.
Risk R3, Strict Change, Application Runtime Activation deferred.
Base protected main `dc85579a3d593b3b0df410e9923ccada268d49d4`, tree
`d244df198a75b16ba2968ec14fade2289625d5d2`, contains M2 PR #577.
Specification v1.5 SHA-256
`688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc`;
phased plan REV2 and M3 execution prompt dated 2026-10-16.
Source implementation and isolated verification are recorded below. Final commit,
tree, PR URL, exact-head CI and fresh machine-readable Draft HOLD self-review are
recorded in this Change's canonical PR (avoiding a self-referential evidence commit).
Only that live evidence establishes Draft completion; it never authorizes merge.
Human authorizes source, isolated tests and Draft handoff only.

## Design before implementation

A shared pure monthly evaluator consumes a single database snapshot for both
read-only projection and materialization. It advances baseline/judgment months
in JST order, uses each preceding month's historical setting and paid total,
consumes one skip per boundary and demotes at most one level per month.
Missing state is beginner. Reads never create state, jobs, audit or history.
Materialization requires an owning transaction and User lock, preserves totals
and entitlements, and records immutable demotion atomically (specification §15.2).
Existing Payment/Point audit remains in its owning transaction. Monthly history
does not acquire the global audit chain before later Plan/Wallet locks.

Payment retains Event/Payment -> User -> Rank -> Plan/Wallet/Lot ordering.
Its paid grant time is sampled after acquiring User; accounting materializes
through that persisted grant month before incrementing points or promoting.
Worker cannot judge ahead of an in-flight grant because both own User. A stale
historical unaccounted grant behind the cursor fails closed; first activation
must complete activation-month backfill before exposing ON/live workers.
Activation/backfill orchestration is NOT IMPLEMENTED and belongs to Final Gate.

A durable unique monthly job has a bounded user cursor, owner/lease/heartbeat,
progress and bounded failure retries. One owner processes a monthly job at a
time; per-user transactions couple judgment and cursor advancement. A crash
replays safely after lease expiration. The command discovers JST months itself;
Scheduler remains OFF. Future runtime launch is documented against canonical
Preview/Production image authority and requires separate Human Runtime GO.

## Ownership and allocation

Dedicated branch/worktree protects the dirty Primary and historical task trees.
Open PRs and all local worktree statuses were inspected. #572 owns smoke
execution/timing, #576/#578 own diagnostic documents; M3 owns only migration
inventory/forward-only registration in shared scripts and its own tests/log.
The historical first-24h payment settlement hunk already exists in main.
No Source or Artifact Lock selected. Alpha.45 and candidate null are unchanged.
Exclusive allocation flock acquired before reserving `000090`; existing
`000088`/`000089` remain byte-immutable. Retain reservation at Draft handoff;
release process lock only after publishing canonical evidence.

## Known open limitation

M2-PERF-01 / SEV-3 / OPEN: extra median 121.102ms versus 50ms guideline
(OFF39.382ms, ON160.484ms, eight samples, three promotion bonuses, shared host,
outer commit excluded). Prior extra60.003ms remains historical evidence in M2.
M3 same-host measurement: OFF median33.274315ms, ON117.1867985ms,
extra83.9124835ms (eight samples, three bonuses, outer commit excluded), still
above50ms. This is not a controlled same-host/prior-head comparison and does not
prove the absence of regression or resolve M2-PERF-01.
Representative pre-activation profiling/optimization and Human disposition remain
required. M9 means manual refund/chargeback recording, not first activation.

M3-WORKER-PERF-01 / SEV-3 / OPEN: 10,000 synthetic bronze-through-VIP users,
one-month catchup, one in seven skip1, zero prior-month points; 728.086820 seconds
versus600-second guideline, 13.734626 users/second. Batches100,101 invocations
(including final completion probe), fresh worker instance per batch, concurrency1,
batch p50=6869.183277ms/p95=11516.327953ms, peak48,758,784 bytes,
89,180 measured queries. Fixture33.584857 seconds excluded; each user actually
commits, no outer transaction. All10,000 states advanced exactly once, skip
consumed, no rank-point or achievement grant; functional test1/11 PASS.
Shared-host contention and per-user SQL/commit cost require profiling; neither
is asserted as a proven sole cause. Representative isolated capacity, concurrent
live payments and historical-data scale must be measured before activation.

## Executed verification and limits

- Exact130 Composer locked packages verified; actual isolated PHP8.4.23 platform
  requirements PASS. No lockfile/dependency edits. PostgreSQL17 disposable DBs,
  network none/no ports, Unix sockets, read-only source/non-root API, local
  storage/no AWS metadata credentials/no operational ENV; isolation preflight PASS.
- M3 monthly19 plus M2 accounting22:41 tests/484 assertions PASS. Thresholds,
  JST boundary, prior-month settings, reservation overwrite/cancel, skip0/1/2,
  six-month catchup, no-write projection, payment creation/settlement, injected
  atomic rollback, re-promotion entitlement, OFF and preregistration500 covered.
- Real-process concurrency4/80 PASS: two workers/two payments/replays/reads,
  deliberate PostgreSQL deadlock/retry, old-month grant held across boundary,
  actual SIGTERM during command processing plus subsequent command resume.
  Test-only Laravel signal availability is enabled in the fork because Laravel
  disables signal registration under PHPUnit; production handling is unchanged.
- Existing M1/preregistration/Payment/Point/limited-bonus/fincode regressions:
  running at source freeze; exact final local counts/results are included in
  the canonical PR's machine-readable review, required before Draft completion.
- DB unit70, policy unit290 and OpenAPI41 PASS; policy gate, quality gate and source-release
  validation PASS. Quality checks1030 PHP files plus JSON/YAML/XML/TOML/contracts.
- Fresh twice,90 migrations/143 tables, exact source/restore schema and migration
  ledger, inventory and forward-only rejection without mutation PASS.
  Schema SHA-256 `06f7a663f707871c0f6c1ae570ecfb9bb14d6942a008989a7a258e3d3b20b499`;
  ledger SHA-256 `fa9f4abee3b69446c6fcc541f24ece6a7af8f9c1ba8946eb9a03bcf76567c3fe`.
- Final-head Required Checks and all available checks must PASS before final
  Draft handoff; their URLs/counts and Strict fresh review live in the PR body.
  Generic CI intentionally skips dedicated opt-in concurrency/performance tests;
  those are executed locally above, not counted as CI execution.
- Historical failures retained: preregistration test queried a nonexistent
  aggregate column; bulk fixture duplicated/then malformed referral codes;
  PHPUnit disabled signal traps; initial policy saw unstaged new paths.
  Corrected fixture/test setup and tracked-tree validation pass without weaker
  assertions, altered production signal behavior or security-gate changes.
  Early vendor-copy results predate exact-lock installation and are superseded
  by the exact-dependency focused/concurrency/performance results above.
- Browser/E2E/visual acceptance NOT_RUN (no UI/endpoint change). Runtime provider
  tests, operational migration/worker activation and feature ON NOT_RUN/forbidden.

## Internal usage contract for M4 through M8

`app(V2MemberRankResolver::class)->project($userId)` returns enabled, current
level, remaining skip_count, baseline_month/baseline_level, last_judged_month,
current JST rank_points and virtual ordered judgments from one SELECT snapshot.
M6 read APIs/M7 detail should use it, never materialize during GET. Null state
and feature OFF project beginner. Optional instant is for controlled domain
evaluation/tests, not a public caller-supplied date or historical reranking API.

For a rank-dependent write, use the existing owning transaction runner and
`app(V2MemberRankResolver::class)->materialize($userId)` before consuming rank.
It locks User then Rank; no standalone commit. Payment owns Event/Payment first,
then User/Rank before Plan/Wallet; worker does not lock payments or plans.
M4/M5 must consume this returned level before bonus/eligibility decisions, not
read stale member_rank_states directly. M7 must materialize before manual rank,
skip or point changes under User, then deliberately set its own baseline/skip
without rewinding judgment cursor or resetting already consumed entitlements.
Account-closure snapshots/frozen-rank integration remains a future consumer
responsibility before activation; M3 does not add a closure API or imply that
the full feature is operationally complete.

`app(V2MemberRankMonthlyWorker::class)->progress()` is read-only internal M7
observation: enabled, remaining bronze-or-higher states, provisional, current
job and last_completed_month. Mark aggregates provisional while remaining>0,
including failed or leased jobs; no hidden repair on read. History preserves
`month` as the points/settings month, `judgment_month` as the JST month-start
origin and `occurred_at` as processing time. M7/M8 corrections cannot overwrite
those immutable facts or use materialize to recalculate an already judged month.

Launch preparation is
`docs/operations/deployment/member-rank-monthly-worker.md`: inactive supervisor,
explicit approval marker and exact approved image/network/environment binding.
No installed unit, enabled service, Scheduler or Compose change is included.

Operational migration, Runtime/Worker activation, Storefront changes and contract
publication are zero. Ready/merge/auto-merge and feature ON remain prohibited.
Rollback is a reviewed source change retaining additive schema and immutable
history; persisted corrections require forward migration.
