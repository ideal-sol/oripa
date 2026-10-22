# MEMBERRANK-ACTIVATION — Prelaunch REV2 Source handoff

Status: `MEMBERRANK_ACTIVATION_PRELAUNCH_SOURCE_HOLD`.
R3 / Strict Change / Application Runtime Activation `deferred`.
Issue603 / Draft PR604 / existing dedicated task branch retained.
This checkpoint implements read-only inspection, not an executable Activation.

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

Isolated inspection runs with actual configured rewards500 and750 each PASS:
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
