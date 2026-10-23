# MEMBERRANK-ACTIVATION — Prelaunch REV2 Source handoff

Source implementation and isolated acceptance are recorded below. The final
HEAD, Required Checks and Fresh Self-review on Draft604 determine Source status;
Hotfix Source Merge and all Runtime operations remain HOLD.
R3 / Strict Change / Application Runtime Activation `deferred`.
Issue603 / Draft PR604 / existing dedicated task branch retained.
The latest continuation adds executable Source and isolated-only activation
tests; see [admission/origin inventory](MEMBER_RANK_PRELAUNCH_ADMISSION_INVENTORY_2026-10-22.md).
The observation-only checkpoint below remains historical evidence. Neither
inspection method alone authorizes Activation. Runtime/merge HOLD is unchanged.

## Approved synchronization and Source acceptance — 2026-10-23

Human GO fixes protected main `6b70ca9f21ea2d316cdb523e13fbdec05f7b5692` for the
existing branch and Draft604. Original base8a656d1a and remote6e7e1695 remain
historical checkpoints. No new branch/PR or history rewrite.

- Protected four WIP files with a private binary patch/hash manifest and local
  checkpoint `2b28cc0e`. All four file bytes matched before/after checkpoint,
  including the exact test SHA256 associated with the earlier2/185 evidence.
- Preview found a Worklog append conflict only. Task-owned records were moved
  across append boundaries without changing any nonempty line; checkpoint
  `c62c31426c1d3c083f39876dd10e4cab75ce8bcf` retains the entire prior history.
  No policy/financial semantic conflict was introduced. CONTROL pins/negative
  tests and Activation ID/097 registration are both present.
- Canonical no-force sync produced merge
  `2fe9d0e72a40a63054a7314893efc50fae6e529d`, parents c62c3142 and6b70ca9f,
  tree `f75c3490b6ca727505ffba7155947966e2e7fbd7`, equal to clean merge-tree.
  Wrapper verified35 net paths. One-use sync permission was removed immediately;
  only then was Task Policy base advanced to6b70ca9f. Ready/Merge remain absent.
- The seven nonshared upstream files, including dependencies/lockfile and
  Control manifest, remain byte-identical. All97 migration bytes match6e7e1695;
  097 SHA256 `98f451218ec4195ab8e852baf0fd0cc9662689a162d0d4d17beb95ca93d97604`.
  No new migration reservation or operational migration.
- Added a focused reproduction of a completion gap: another verified success
  receipt for an already-settled payment stayed unresolved even after regular
  replay, causing permanent `PENDING_PROVIDER_EVENTS` HOLD. The pre-fix test
  fails after20 assertions. Minimal fix appends the existing successful provider
  attempt before returning the existing grant. It does not rewrite receipts,
  payments, Wallet/Lot/Point/Rank/Audit/Mail or grant again. Signature/event/time
  checks and event→User→Payment locks precede that return as before.
- Expanded exact policy/PR scope to36 before this Payment service edit. A payment
  Source flock protects the bounded completion hunk. The concurrent POINT-FIRST
  worktree's creation/eligibility edits were inspected read-only and are not
  imported or changed. Source lock disposition is recorded in Draft604.
- Post-fix isolated Source cases PASS: atomic0-Origin/free7501/151 (65.126s),
  notification/real deadlock/Writer recovery1/142 (59.125s), pending/auth/reward/
  late-success receipt recovery1/47 (26.409s), nested SMS/LINE identity recovery
  1/36 (51.057s), total4/376. Synthetic PostgreSQL17.10/PHP8.4.23 only. Source
  hashes: Payment service `e6297ba32838cff4f0332b845fa529836db69a5a8a3ee52192b6a91d045f3dbc`,
  activation test `b73de172fac350e9ca6e86b0e18e31eeb1c6e87d03c1075b6f5f775d993ddffc`.
- SMS with a pending referral returns HTTP500 while gated, leaving the challenge
  unused, first-SMS/phone/session unchanged and referral pending. The same code
  succeeds after release, grants100/50 once and rotates the session; stale-session
  replay401 adds nothing. Ordinary SMS without financial reward succeeds under
  the same gate. Rate limits and delivery checks are retained.
- LINE identity callback with pending follow rejects409, marks only the OAuth
  attempt failed and rolls back user/session/reward creation. Durable follow
  remains pending. A fresh OAuth start after release claims75 free points once;
  another login does not regrant. A used OAuth code/state is not a replay queue.
  OIDC/PKCE/claims use the existing synthetic provider fixture; no real sender
  or provider traffic is certified by these tests.
- Final notification sample: publication call1.517560s/TX1.442740s includes inline
  probes; observed TX age before probe0.554172s; HTTP refusal0.711357s, Point
  0.079768s, new notification0.073630s, LINE0.021858s, eight Writer refusals
  0.003808–0.232591s; WAL5072bytes/database-growth57344bytes. This is a constrained
  single sample, not a production maintenance window, peak or approved budget.
  Prior0/10/1000 evidence is retained and not needlessly rebuilt.
- Integrated Policy/base-sync/Control regression297 and full Policy329 PASS,
  focused Fincode/foundation regression6/71 PASS (40.708s), OpenAPI62 and DB tooling
  84 PASS, generated Admin parity/typecheck and all bundles PASS. Local quality
  PASS (5 contracts/143 JSON/1074 PHP/1 TOML/2 XML/25 YAML). Fresh97 migration
  and custom backup/restore normalized schema/ledger equality PASS. Final Source
  regression/quality/Required5 and Fresh review are recorded for the final HEAD
  in Draft604; earlier6e results are not substituted.
- Intermediate sync-head CI2fe9d0e7 failed metadata validation while the PR body
  still carried the old template/base; local validation also caught required
  headings/Changed-files heading levels during its rewrite. The canonical parser
  is unchanged. Final PR metadata is validated before push/check dispatch.

Acceptance separates Source guarantees from the remaining external Final Gates:
enabled sender redelivery, replacement-provider M2/M4/M8 settlement and recovery,
deployed supported-entry inventory, fresh site read-only data checks, privileged
site authorization provisioning, and representative capacity/rollback/bloat and
Human outage budgets. No production volume or allowed wait seconds are inferred.
Hotfix Technical Acceptance, old-PHP rollback prevention and explicit Human
Source Merge restart remain required. OLD/NEW each need their own artifact,
schema, Runtime and Activation GO; operational Worker and Production GO remain
independent. Browser E2E is NOT RUN (no UI added); HTTP route tests are executed.

Actual Source Merge/Runtime/operational DB/real Feature ON/backfill/operational
Worker changes0. No formal Release Artifact or Contract publication. CI
verification-only images, when built, are recorded separately. Keep Draft,
branch and worktree. Financial history rollback by deletion is prohibited.

## Historical remaining-acceptance stop — 2026-10-22

Remaining-acceptance work resumes the6e7e1695 checkpoint; it does not replace the
existing implementation or redo the1000-Origin fixture. Result is
`MEMBERRANK_ACTIVATION_PRELAUNCH_SOURCE_HOLD` because protected main moved during
validation and the required base/head gates have not been re-established.

- Protected main observed at stop: `6b70ca9f21ea2d316cdb523e13fbdec05f7b5692`.
  PR REST still reports base `8a656d1a4fb44e15155cbec3ab0ad7b927553587`, Draft=true,
  HEAD `6e7e1695f35167689dff2ff331de7aa620f55f94`, tree
  `b0ffef8e2b5716c91cd8da666afe3e377a78612f`,35 committed changed paths.
  These base observations are deliberately distinguished, not conflated.
- New uncommitted overlay: the existing activation test, admission inventory,
  this handoff and Worklog. No production application Source, Contract/generated
  bytes, Migration097 or prior migration is rewritten. No additional migration,
  allocation reservation, dependency edit, branch, PR, commit, push or main
  import. Task Policy remains exact35; Source/Migration Locks:none.
- Notification case PASS1 test/142 assertions,66.963s, PHPUnit81MiB.
  Pending/auth/reward case PASS1/43,26.065s,71MiB. Test Source SHA256
  `fb5b0be0abee5d60e3f6828920e3a1bbd7abe0db11aab86274a78af86b365a29`.
  Real PostgreSQL17.10/PHP8.4.23, network-none, synthetic only,0.5CPU/512MiB each.
  Raw logs `remaining-1792681861662627371-10-500.log` and
  `remaining-1792682045264867641-10-500.log` plus resource JSON are retained in
  task-private evidence. Fresh97 migration applied only to the disposable DB.
- Final notification sample: publication call3.681555s; actual TX3.376347s
  **including deliberately interleaved probes**; publisher TX age before probes
  1.372263s. Fincode HTTP500 refusal1.512267s; Point refusal0.173322s; subsequent
  new-payment notification refusal0.060744s; LINE refusal0.090734s. Other eight
  Writer refusals0.051124–0.409041s, all recover after release. No refusal time
  is called a total business outage or sender-recovery bound.
- WAL interval5104bytes, database size delta57344bytes; interval includes
  failed writer probes. Eleven whole-test resource samples: PG CPU max28.49%,
  memory max169.6MiB; API CPU max35.24%, memory max150.8MiB. PG sampled block-I/O
  stays15.2MB read/0B write on tmpfs; this is not a durable-disk benchmark.
  Session statement_timeout/lock_timeout0 and deadlock_timeout1s are recorded;
  Activation's own100ms defensive lock timeout is unchanged. One real synthetic
  deadlock emits40P01 and completes in3 total attempts in this final sample.
- Functional PASS includes real HTTP500/no successful ACK, preserved receipt
  after interruption, two forked replay processes, one new grant/M2/M4, old
  Origin exclusion, refund pending/uncertain/recovery/M8, LINE exactly-once free
  reward/reply, preregistration persisted failed-target→new-batch recovery750,
  referral pending recovery, concurrent ON refusal, role revocation, mid-TX MFA
  expiry, protected paid-user HOLD, and real consume/G2/manual/lazy/monthly/M9/
  withdrawal/settings refusal and recovery. See the inventory's per-entry
  ACK/persistence/retry-owner matrix for precise limits.
- Local quality PASS (5 contracts/143 JSON/1074 PHP/1 TOML/2 XML/25 YAML),
  OpenAPI62 PASS, whitespace review PASS. Previous6e5/5 checks and HOLD review
  remain historical; **new-overlay Required Checks/Fresh exact-head review are
  NOT RUN**, not PASS. No Browser E2E or external provider transmission.
- Development failures retained: PHP numeric SQLSTATE array-key coercion;
  expected401 context-role mismatch initially misidentified as403; Draw's
  existing503 retry-exhaustion translation initially expected as raw SQLSTATE;
  artificial real-deadlock schedule produced4 total attempts rather than an
  unjustified exact3. Assertions now match the unchanged contract:1–3 attempts
  per process, every aborted attempt40P01, both transactions succeed. No retry,
  financial constraint or production assertion was weakened.
- Required next step: explicitly resolve unexpected protected-base movement
  under Root's STOP rule, preserve CONTROL-20261022 development-dependency and
  Control authority changes without release/payload promotion, then validate
  the resulting exact scope, commit/push,5 Required Checks and Fresh Self-review.
  Shared policy files have different hunks; no API/migration collision was
  observed. Do not infer authority to rewrite history or silently repin base.
- Capacity budgets, representative load/rollback/bloat bounds, enabled sender
  redelivery and the replacement-provider M2/M4/M8 integration remain separate
  Runtime Final Gates. External sender guarantees are NOT RUN, not Mock PASS.
  Existing0/10/1000 observations are retained; no production SLO is invented.
  OLD/NEW each need explicit artifact/schema/runtime and Activation GO, fresh
  site read-only checks, authorized site-record provisioning, and provider
  readiness. Hotfix acceptance/old-PHP rollback prevention plus explicit Human
  Source Merge restart are still required before Ready/merge.

Actual Runtime/operational DB/real Feature ON/backfill/operational Worker changes0;
Source Merge0; this continuation's Build/formal Release Artifact count0. Existing
CI verification-only images remain historical, not a released artifact. Preserve
the uncommitted overlay and existing branch/worktree/Draft; no cleanup of refs.

## Earlier technical implementation continuation (historical)

Migration097 is formally reserved under the Migration Allocation Lock after
checking latest main096, fetched refs and task reservations. Its Source adds
transaction-scoped shared/exclusive admission, a generation fence for stale RR
snapshots, immutable site authorization/run/excluded origins, and atomic
post-cutover M2/M4 grant constraints. No applied migration is edited.

The Owner/Fresh-MFA/CSRF/idempotency/revision API is contract-first, with generated
Admin types. Ordinary settings PUT still refuses first ON. Shared M2/M4/M8
classification uses exact Origin membership rather than a rounded timestamp.
Activation never modifies old financial rows or preregistered users. The
authorization table is empty after migration: real provisioning is a separate
privileged site-bound Runtime decision, not an Admin boolean or public ENV.

Focused synthetic PostgreSQL17/PHP8.4 acceptance currently includes actual
HTTP commit, authentication/Owner/CSRF/exact five-minute MFA refusal, all33
guarded table writers independently preventing ON, old-RR rejection, deferred
commit failure and complete rollback, partial M4 failure/retry/replay, immutable
origins, same grant timestamp across cutover, old rank-zero CB, new M2/M4/M8,
protected free rewards and post-ON G2 login draw. The ten-origin500pt run passes
1 test/149 assertions;1000 origins/free750 pass1/1139, including one membership
assertion for every excluded Origin. Successful publication including commit
took10.174331s in the latter constrained synthetic run, not an approved outage
budget. Further outcomes are recorded in Draft604 and the worklog, separately
from historical bef04c Required Checks.

Status remains Source HOLD until the complete required failure/concurrency
matrix and capacity evidence are accepted. Actual process termination during
publication, exhaustive retirement/manual/lazy/monthly/M9/draw interleavings,
replacement-provider recovery and production-equivalent load are not established
by the focused test. No business wait/volume limit is inferred. No actual
Runtime/operational database/Worker/Feature ON changes have been made.

## Historical observation-only checkpoint

## Authority and protected users

Human REV2 Source authority SHA256:
`e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248`.
Original v1.5 SHA256 remains
`688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc`;
phased REV2 remains
`b918b3b4ec95b0ce1ceeaf7a5cab2cf601ab4042aeefc41bc263b6171831f633`.
Original specifications are not rewritten.

For this Human-authorized prelaunch site only, previously granted test payments
are not retrospectively ranked, even in the initial ON month. This exception
concerns payment origins, not users. The legitimate pre-registration users
registered from2026-10-21 onward must retain identity, SMS/email verification,
qualification, original registration time, reward eligibility/history and all
Wallet/Lot records. They remain ordinary rank participants for future paid
purchases. The date is not a general payment cutoff or a test-user classifier.
Existing test users and test balances are not deleted or reset either.

`V2PreRegistrationService::activateUser` changes `users.created_at` after a
successful reward, while `pre_registered_at` and qualification history remain.
Therefore created_at alone cannot identify the protected population. Free
pre-registration rewards (default500, otherwise actual configured amount) are
not payments or rank points. Existing phone-HMAC duplicate prevention is retained.

The original [A/B/C design and experiments](MEMBER_RANK_ACTIVATION_DESIGN_GATE_2026-10-22.md)
remain historical research. General multi-site backfill is deferred to another
Change. Neither the old300-second diagnostic window nor its timing results are
acceptance evidence for the new exception.

## Base and scope

Protected main was re-read: G2 PR602 merged at
`8a656d1a4fb44e15155cbec3ab0ad7b927553587`; migration000096 is consumed,
not available for reuse. Initial merge-tree inspection found only the shared
Worklog append conflict. Preserve both records, relocate this task's historical
section before the upstream append boundary, then use the canonical no-force
base-sync wrapper. It verified both parents, exact canonical tree
`c2f607ff0c5720bee5009ff1172dd1d0de05147e`, no conflicts and net five-path scope
at synchronized head`e68b1c81a9e95d1a7f38ea2f2b045e245ba4ebe4`.
G2 application/contract/migration bytes were not edited or cherry-picked.

The root-private exact-path policy and Draft scope were expanded before Source
edits for the inspection service, opt-in PostgreSQL test and this handoff.
The one-use base-sync permission was removed after successful synchronization;
the installed API wrapper does not accept that Git-only policy operation.
No wrapper or protection was changed to bypass validation.
No Source collision lock is required for the new independent files. No
migration is created/reserved, so no Migration Allocation Lock is acquired.
No Hotfix, deployment, artifact or network lock is acquired or altered.

## Resume after model capacity interruption

The formal2026-10-22 resume instruction was read without recreating the Change.
Local/remote checkpoint`ed7bbfe548364f93dee80eeba0313dad8d49d9e9` and base
`8a656d1a4fb44e15155cbec3ab0ad7b927553587` matched; the worktree was clean.
G2 was not integrated again. The interrupted216-test/1950-assertion regression
and local quality validation both completed PASS at that checkpoint. They are
not automatically final-head validation for later inspection edits.

CI39744901372 failed because the PR declared five changed paths while its diff
contained eight. The body was corrected to the exact eight paths, with the
same transport policy, without a gate relaxation or a metadata-only Source
commit. Canonical same-head dispatch subsequently passed policy, quality and
security checks; final-head integration/aggregate status belongs in the PR.
The earlier failed run is retained as failure evidence.

The continuation adds `inspectQuiescent()` to the existing inspection service.
It uses an owning READ COMMITTED transaction, takes NOWAIT ACCESS EXCLUSIVE
on settings and NOWAIT SHARE ROW EXCLUSIVE on25 listed financial tables, then
observes the same aggregates. It never updates business rows or authorizes ON.
Busy acquisition rolls back, reports `PRELAUNCH_INSPECTION_BUSY`, and does not
retry indefinitely. Nested transactions are rejected. Ordinary `inspect()`
retains its original REPEATABLE READ / READ ONLY behavior.

The lock-protected method is **not** a PostgreSQL READ ONLY transaction: strong
table locks require a writable transaction. Its Source performs SELECT/LOCK
only. It does not lock users, identity tables or the audit chain. An independent
user-row update can proceed while these financial locks are held; this proves
only that row-lock boundary, not complete registration/authentication E2E.
The method releases its fence before returning, explicitly reports that fact,
has no HTTP/CLI entry, and cannot be used as a durable activation permit.
No operational invocation or maintenance-duration approval is implied.

### Lock graph and observed limits

| Actor | Lock/snapshot order | Result / limitation |
| --- | --- | --- |
| Quiescent observer | Settings ACCESS EXCLUSIVE NOWAIT →25 financial relation locks NOWAIT → SELECT aggregates → commit/release | No User or audit lock acquisition; no ON; partial acquisitions released on failure. |
| Already admitted payment/refund/Point/draw/rank table writer | Existing relation RowExclusive → observer attempts conflicting relation lock | Each of26 relations independently rejects observer with55P03; no wait/drain claim. |
| Existing OFF reader | Settings AccessShare retained until transaction end → observer attempts ACCESS EXCLUSIVE | Observer fails busy rather than publishing across that reader. |
| New payment while observer owns fence | Plan preparation → payment INSERT attempts RowExclusive | Actual common payment service receives55P03 under diagnostic100ms lock timeout; its transaction is rolled back. |
| Existing user-row holder | User row lock/update; no financial/settings access | Does not block observer; observer never waits for that User, avoiding Settings→User inversion in this method. |
| Older REPEATABLE READ transaction not yet touching settings | Acquire MVCC snapshot → observer acquires all26 locks → synthetic fixture publishes ON → old transaction reads old OFF and settles | Real committed paid grant without rank origin reproduced; table locks do not invalidate an earlier snapshot. M8 subsequently rejects the missing origin. |
| Financial service audit tail | User/financial locks → audit-chain advisory lock | Existing order is unchanged; observer never acquires audit. A future audited ON cannot infer audit/User safety from this observer alone. |

The last snapshot case deliberately establishes REPEATABLE READ on an independent
connection and calls the real nested payment/Point transaction runner. Production
business writers were not observed using that isolation level; this is a
supported-connection counterexample, **not evidence of a live production loss**.
No production incident or impossibility of all no-backfill designs is claimed.
It refutes treating relation locks alone as a complete outer-transaction drain.
The synthetic ON is injected by a test-only query listener in the owning
observer transaction; there is no corresponding Source activation method.

### Continuation measurements and remaining gate

The extended isolated test passes with both500 and750 free-reward configurations:
1 test/191 assertions each. In the initial extended runs, the26-table probe
including injected failure took794.988ms and640.338ms; blocked payment call wall
times were224.898ms and147.804ms; subsequent rollback calls took0.124ms and0.158ms.
Those are diagnostic, small-fixture measurements on the constrained synthetic
environment, not an approved outage budget or100000-origin acceptance. The
payment wall times include service work and timeout-censored waits, not pure
lock-wait duration. No business performance threshold was inferred.

Every relation was independently occupied in another transaction; all busy
inspections rolled back. An injected failure after lock acquisition released
all26 locks, independently reacquired NOWAIT, with financial/user row hashes
unchanged. Successful observation also left those hashes unchanged. The separate
prior-snapshot negative control intentionally commits synthetic ON and a paid
grant to demonstrate missing rank state; its disposable database is reset after
the test, not rolled back by weakening irreversible settings constraints.

**Dependent Source remains HOLD, not Draft-ready completion.** Still required:
an admission boundary established before financial transactions take snapshots
or business locks (or a proved, enforced isolation/entry alternative), complete
entry inventory including direct/nested transactions and pending intents, an
immutable site-bound authorization/run and exact grant cutoff, shared M2/M4/M8
eligibility, and the audited Owner/Fresh-MFA/CSRF/idempotency endpoint with API
and end-to-end acceptance. None is replaced by a zero-count snapshot or this
short-lived fence. No migration number is reserved for an unproved protocol.
These are remaining engineering obligations, not a request to reapprove the
Human no-backfill exception or to approve B/C. Runtime authority must later bind
the exact site/run/origin review; permitted business delay remains undecided.

## Implemented safe subset

`V2MemberRankPrelaunchInspection::inspect` owns a PostgreSQL REPEATABLE READ,
READ ONLY transaction and refuses an existing transaction. It does not acquire
User/settings write locks, update data, grant points, classify test users,
expose an HTTP/CLI operation or set ON. It returns aggregate counts without
identifiers, phone/email data, provider payloads or credentials.

The conservatively protected observation set is every user with
`pre_registered_at`, a pre-registration target, or a pre-registration grant.
It has no date filter and does not depend on mutable created_at. Historical
test applicants may also be flagged; that is a Human review item, not permission
to delete or silently exclude them. Users outside this observation set are
unclassified, never automatically approved test users.

Observations include protected successful payments, paid grant origins, every
draw request including free/QA requests, non-pre-registration Point operations,
pending payments/events/provider operations/adjustments, unassociated provider
events, succeeded payments missing grants, invalid origin references and existing
rank business rows. Ordinary free reward operations are recognized by their
actual pre-registration grant references, not by a hardcoded500 amount.
All checks are observation-only, not a complete financial reconciliation.

Nonzero users or nonzero historical payments alone are not a zero-data gate.
Any observed protected financial/draw activity is reported as a data HOLD.
Even an empty/no-hold report always returns `activation_authorized=false` and
identifies the unproven site authority, exact cutover, admission/drain, provider
integration and Human Activation GO gates. A snapshot cannot approve a later ON.

## Concrete cutover blockers and proposed next work

1. **Timestamp precision and equal-second origins.** `V2DatabaseTimestamp`
   serializes seconds. The legacy payment grant column is `timestamptz(0)` while
   rank activation's SQL column is unqualified `timestamptz`. Distinct instants
   inside one second serialize identically. Replacing month-start with a bare
   `< activated_at` comparison does not distinguish old and new same-second
   grants. No global precision rewrite or guessed timestamp is applied.
2. **Snapshot is not drain.** Independent real settlement can commit after a
   read-only snapshot is fixed; the snapshot correctly cannot see it. A zero
   protected-transaction result is not durable proof. A financial admission
   protocol must cover every outer caller before business locks and then drain
   admitted work. Existing normal registration/authentication must not be
   indiscriminately fenced. No complete protocol has been proved here.
3. **M2/M8 are still month-based.** With test-only ON, unchanged M8 rejects a
   same-month unaccounted pre-cutover payment; unchanged M2 can retrospectively
   account it. Removing409 or changing M8 alone would respectively allow wrong
   accrual or conceal genuine post-cutover missing origins. Neither is done.
4. **Site authority is not ordinary Admin input.** A future immutable mode/run
   must bind the exact reviewed environment/settings identity and Human
   exception. A request-body boolean, mutable ENV switch, registration date,
   empty-user count or automatically generated test-user list is insufficient.
   No site capability is provisioned by this Source checkpoint.

Required next design: a narrowly scoped admission boundary covering settlement,
paid grants, webhook receipt/replay and provider operation completion, M9/manual
Point/refund/CB, relevant draws, settings, rank/lazy/monthly and closure paths;
no Settings-exclusive→User or audit-chain→User inversion. Prove equal-second and
month-boundary handling under that boundary, then introduce a forward-only
immutable activation mode/run under formal migration allocation. Share its exact
eligibility predicate between M2/M4/M8 while retaining ordinary month behavior
for other sites. The Owner/Fresh-MFA/CSRF/idempotent/expected-revision audited
Activation contract and Admin operation remain dependent and unimplemented.

The latest Human business exception resolves the old backfill interpretation
decision. These remaining engineering proofs must not be represented as a new
request to approve B/C or as evidence that no-backfill is impossible. A future
site-bound authority record and exact protected/unclassified-origin review need
Human operational approval before use, not automatic classification from dates.

## Verification and evidence boundaries

New opt-in test requires the exact disposable synthetic DB, testing mode, Unix
socket connection, empty initial users and OFF. It exercises actual committed
pre-registration reward services for multiple legitimate applicants, preserves
all inspected row hashes, reproduces the created_at change without changing
protection, distinguishes free rewards from paid activity, detects pending
payments/notifications and real free draws, and commits an independent settlement
inside the inspector's snapshot interval. Test-only ON is confined to a rolled
back counterexample of existing M2/M8, not a new activation path.

Initial checkpoint inspection runs with actual configured rewards500 and750 each PASS:
1 test /65 assertions. Initial development run failed after57 assertions because
the test read a nonexistent exception.errorCode; the corrected exact assertion
uses the real exception message. No production behavior/assertion is weakened.
The same tests verify actual DB column precision0/6 and preserve all business
rows across inspection. PostgreSQL17.10/PHP8.4.23 are test-only, not PHP Hotfix
acceptance evidence. All96 unchanged migrations were applied only to the
task-owned synthetic database. Other results and fixed-head checks are recorded
in Draft PR604. PASS,
FAIL and NOT RUN remain separate. New Activation HTTP/MFA/CSRF/idempotency/API,
immutable-mode migration, exact cutover races, no-backfill acceptance at large
historical counts, full reconciliation and deployed E2E are NOT RUN because the
dependent protocol is not implemented. Existing regression PASS cannot replace
these missing acceptance tests. Existing performance OPEN remains OPEN.

## Separate operational handoffs

**OLD Test:** explicit Source Merge restart after PHP Hotfix acceptance and
old-PHP rollback prevention; fresh final-head gates; approved artifact/schema
plan and separate Runtime GO; read-only origin/registration/queue inspection
under separately approved operational access; proven drain and reconciled
exact-run evidence; separate irreversible Activation GO. None executed here.

**NEW Production:** the same independent gates, plus explicit protection of
legitimate pre-registration users, read-only proof of no unexpected transactions
for those users immediately before activation, reviewed disposition of every
unclassified origin and delayed/pending notification, and commercial public GO.
No user, eligibility, authentication state, phone-HMAC history or real reward
may be included in test-data cleanup. Cleanup and existing test-balance usability
are separate Human decisions, not actions of this Change.

**Provider change:** present common settlement calls M4 before M2 after paid
grant creation. No provider-specific Source is changed. The replacement
provider's success/paid-grant/replay/refund/CB integration and real-provider
acceptance require the separate provider Change and must pass before Activation.

Ready/Merge/auto-merge, OLD/NEW runtime, operational migrations/backfill/ON,
worker startup and formal Release/Contract publication remain prohibited.
Only task-owned network-none synthetic test resources may be created/removed.
