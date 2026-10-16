# DBSMOKE Phase 3 — Preparation profile / Human review

Status: `DBSMOKE_PHASE3_PREPARATION_PROFILE_HUMAN_REVIEW_PENDING`

## Authority and scope

- Change ID: `DBSMOKE-P3-20261016` (existing branch/App transport identifier;
  canonical CI Task ID `DBPROFILE-20261016` identifies the same single Change,
  branch and PR #576) for Human task
  `DBSMOKE-PHASE3-PREPARATION-PROFILE`. Issue: none; this Draft PR is the
  pending-decision record. Risk R4; Lane **Strict Change**; Application Runtime
  Activation **none**.
- Start-time protected main: `b5c28015ef94cb80f6702a61b1237cacd91d9863`;
  tree `f272a372adef6af42285364097eb0e3681e6ca7a`.
- Dedicated worktree; branch `docs/DBSMOKE-P3-20261016-preparation-profile`.
  Primary checkout's recognized historical worklog edits and every other task's
  worktree/ref remain untouched. No Source Lock: only new evidence paths and a
  task-local Worklog entry. No migration reservation/creation/allocation lock.
- [PR #572](https://github.com/ideal-sol/oripa/pull/572), head
  `d74247babf2cabf4e334bfac8fe34451b5933b90`, is read-only evidence.
  Its test/application/migration/lockfile bytes equal this task's base.
  Its branch, worktree, PR body and comments are not modified.
- The latest Human instruction overrides the normal merge lifecycle:
  **no merge, auto-merge, direct main push, deployment or runtime activation**.
  No Production/operational DB/AWS/existing ENV access or change. No optimization
  or fixture/DB-initialization implementation is authorized by this report.

## Historical observation versus this measurement

[Run 38881675704](https://github.com/ideal-sol/oripa/actions/runs/38881675704)
measured 171.572794 seconds of aggregate Preparation for these 90 invocations:

| Class | Tests | Historical preparation seconds |
| --- | ---: | ---: |
| BannerAssetLifecycleTest | 22 | 42.135955 |
| BannerImportCsvTest | 12 | 21.574510 |
| BannerImportTest | 6 | 12.298116 |
| BannerImportWorkerTest | 24 | 46.913969 |
| NotionSyncTest | 26 | 48.650244 |

That historical event trace cannot retrospectively reveal its internal stages.
**No share of 171.572794 seconds is filled by an estimate.** The new measurements
below are a separate, bounded local experiment at the fixed main source. They
are not a CI speedup or a causal before/after optimization comparison.

## Isolation and measurement method

The task uses its own `mig054-v2-dbsmoke-phase3` PostgreSQL/Redis resources,
validated and initialized through unchanged `scripts/db/v2_database.py`
functions. Only those two disposable services start; no API/Admin server,
worker or application image build starts. The PHP CLI measurement container
joins only that project's internal network, with no published port or external
network. It uses a task-private tracked-source copy, its own vendor/storage/cache
and randomly generated disposable configuration. Existing ENV files and runtime
containers are not read, mounted, restarted or altered.

- PHP 8.4.23; PostgreSQL 17.10; fsync and synchronous_commit remain **on**.
- Existing PHP image pinned by ID
  `sha256:826ab806235ef8aba0a3803caedf688274183b79d3233a35120c01275a119491`;
  PostgreSQL image
  `sha256:cc4c61127125de9f69aa50f7d78b54686576d5d3d835de03128b064d12b97154`.
- Exactly 130 installed Composer package name/version/dist-reference tuples
  equal the locked dependency set. Host vendor was rejected for four mismatches;
  a separate install uses the unchanged lockfile, no plugins or scripts. Its
  PHP platform constraint is checked by actual PHP 8.4 execution; the PHP 8.3
  installer alone ignored that constraint, without resolving new dependencies.
- CLI memory_limit 512M, OPcache CLI disabled; PHP container CPU quota 1 and
  memory cap 768MiB. Shared-host scheduling/storage variation is uncontrolled.
  These are not the four-CPU GitHub runner's resource conditions.
- The existing `phpunit.v2.xml` remains byte-identical. A bounded filter selects
  all invocations of the five classes, including inherited methods and data
  providers, in native order. No method, assertion, fixture value, hash cost,
  timeout, performance threshold, opt-in condition or migration changes.
  The remaining Full V2 tests are not executed by this local experiment.
- Each invocation still creates its random independent schema, runs every one
  of the 87 V2 migrations, creates the original fixtures, runs its body and
  tears its schema down. No template, transaction substitution, shared mutable
  fixture, class sharding or migration skip is introduced.

Control uses original test/application bytes plus a PHPUnit event observer.
Profile uses monotonic `hrtime(true)` boundaries inserted only in the private
copy of Banner/Notion setUp, a delegating `createApplication()` observer and
boundaries around the original `password_hash()` call. Removing these probes
recovers the four original files byte-for-byte. Tests in the worktree are never
edited. The observer stores allowlisted class names, ordinal/counts and durations;
it stores no arguments, SQL, binds, credentials, data-provider labels or results.

One initial probe preparation failed its exact-one-anchor check because a
Notion configuration prefix also occurred in a test body. A mistakenly launched
dependent partial-profile run was stopped; its incomplete XML was rejected and
is **not PASS** or a timing sample. The task-only PostgreSQL/Redis resources were
removed and recreated through the canonical guard. The corrected generator
validates all transformations before writing, and profile launch requires a
four-file checksum manifest. No assertion or original fixture was changed to
recover. Control/profile therefore use the same DB settings but different
disposable DB instances; cache/scheduling differences and probe overhead cannot
be separated by subtracting their totals.

| Component | Exact timing boundary |
| --- | --- |
| PostgreSQL schema creation | Original `DB::statement('CREATE SCHEMA …')` call; client wall time, not server CPU |
| Migration application | Original `$this->artisan('migrate', …)->assertExitCode(0)` statement; includes command/ledger/transaction overhead |
| Laravel application boot | Delegated parent `createApplication()`: application construction, provider/console-kernel bootstrap |
| Admin/auth fixture | Original Admin creation through authorization context; subtract separately measured nested Argon2id |
| Argon2id | Original native `password_hash()` call, unchanged memory65536/time3/threads1; excludes policy validation |
| Storage/HTTP/mock initialization | Existing Storage fake/configuration, clock/configuration/stray-request guard and Banner resolver/transport stub blocks |
| Other preparation | All remaining explicitly delimited Preparation segments, parent setup outside application boot, Banner category/service fixture |

The components form a non-overlapping partition. Migration events additionally
measure the 87 `up()` method intervals inside each command. QueryExecuted
durations provide a nested SQL/client-query diagnostic; they exclude transaction
commit and some connection work. **Neither nested measure is added to the
partition.** Parent-setup and auth totals are inclusive until their measured
children are subtracted. The PHPUnit observer's entry/exit boundary is close to,
but not identical to, the historical event telemetry boundary. Probe overhead
is not independently measured or subtracted. No residual is called SQL time.

## Results

[Numeric evidence](db-smoke-phase3-preparation-profile-evidence.json) includes
all 90 ordinal-only partitions in integer nanoseconds, per-class aggregates,
min/median/max, source/probe digests, counts and the retained aborted-attempt
record. Every partition reconciles exactly to its measured preparation interval.

| Component | Profile total seconds | Share | Median per invocation seconds |
| --- | ---: | ---: | ---: |
| PostgreSQL schema creation | 2.258966421 | 0.5872% | 0.023591742 |
| Migration command, including application of all 87 files | 344.642171140 | 89.5850% | 3.873957182 |
| Laravel application boot | 3.282262056 | 0.8532% | 0.032267934 |
| Admin/auth fixture, excluding native hash | 1.003932956 | 0.2610% | 0.008605566 |
| Native Argon2id password hashing | 31.715824577 | 8.2441% | 0.325162591 |
| Storage/HTTP/mock/configuration initialization blocks | 0.058781244 | 0.0153% | 0.000548281 |
| Other preparation | 1.747905290 | 0.4543% | 0.020949901 |
| **Non-overlapping total** | **384.709843684** | **100%** | **4.282514957** |

The total median is the median of complete preparation intervals, not the sum
of individual component medians. Percentages are rounded.

Nested diagnostics inside the migration command:

- **7,830 measured `up()` intervals** (87 for each of 90 tests):
  **296.362427850s**. The command outside these intervals is **48.279743290s**,
  measured by subtraction of nested intervals from their enclosing command.
  It includes required ledger/transaction/commit/discovery/console work;
  it is not 48.28s of proven removable overhead.
- **154,260 QueryExecuted observations**: **295.114020000s**. These overlap the
  `up()` intervals and also include queries outside them. Do not add these
  figures together. Server CPU, fsync/storage waits and network time were not
  separately measured, so none is asserted as the root cause within SQL time.
- Application boot and native Argon2id each have exactly **90** observed calls.

| Class | Tests | Profile preparation seconds | Profile JUnit lifecycle seconds |
| --- | ---: | ---: | ---: |
| BannerAssetLifecycleTest | 22 | 93.665100017 | 190.713471 |
| BannerImportCsvTest | 12 | 51.660978252 | 83.094604 |
| BannerImportTest | 6 | 26.571792940 | 42.425548 |
| BannerImportWorkerTest | 24 | 101.416433311 | 165.717965 |
| NotionSyncTest | 26 | 111.395539164 | 228.845143 |

Both complete runs pass **90 tests / 1,337 assertions / 1 existing opt-in skip /
0 errors / 0 failures**. Class/method/data-provider invocation identities,
order, per-invocation assertion counts and skip presence match exactly before
discarding private labels. The existing Worker concurrency opt-in remains off.
Both runs also match PR #572's per-class counts/assertions/skips for this subset.

| Run | Preparation seconds | JUnit lifecycle seconds | Docker/process wall seconds |
| --- | ---: | ---: | ---: |
| Original-source control | 540.947773184 | 980.039674 | 988.406505 |
| Observational profile | 384.709843684 | 710.796731 | 712.591358 |

Control per-test preparation ranges **3.522031488–18.903359742s**; profile ranges
**3.593217485–4.965595415s**. This large variation, the fresh DB restart and
uncontrolled cache/scheduling state preclude assigning the lower profile total to an
optimization or inferring negative probe overhead. **No optimization was made.**
The remaining JUnit lifecycle time is not independently measured body/teardown
time. Nor is this local breakdown a measured decomposition of the old CI total.

### Reduction budgets and candidate priority

1. **Keep all migrations:** the measured 344.642171140s migration path dominates.
   Its 48.279743290s outside `up()` can guide further profiling, but still contains
   mandatory work. No specific removable portion or faster equivalent execution
   has been measured. Application boot alone totals just 3.282262056s.
2. **Empty independent schema creation:** even an impossible zero-cost replacement
   could remove no more than **2.258966421s** from these measured preparations.
   It would leave the 344.64s migration path untouched. A populated schema clone
   is a different proposal and needs the full equivalence gate below.
3. **Immutable migrated template:** the gross currently measured schema+command
   budget is **346.901137561s**. Any saving would have to subtract template build,
   each isolated restore, seed equivalence work, validation and cleanup. Those
   costs are **UNMEASURED**; neither this budget nor 171.57s is a promised saving.
4. **Class parallelism:** serial lifecycle sums are **481.951588s for Banner64**
   and **228.845143s for Notion26**. A mathematical two-worker overlap with zero
   extra startup or contention would cap the benefit at **228.845143s** for this
   subset. Five completely isolated class workers would have an ideal longest
   class of **228.845143s**, hence a gross upper bound of **481.951588s** saved.
   These are arithmetic scheduling bounds, **not parallel measurements**. Extra
   runners/DB initialization/report merging/cleanup and resource contention
   reduce them; preparation work and migration/hash call counts do not disappear.
   They are not projections for total Platform CI or Full V2 wall time.

The next **Human approval candidate** is a design/measurement task retaining
every per-test migration while proving fully isolated class execution, with a
bounded two-worker variant as an alternative. In-place console/runner efficiency
can be investigated under the same migration-preserving rule. Template restore
remains HOLD until seed/schema/audit/ledger independence is proved. Empty-schema
creation and application boot are small measured targets. No candidate is
adopted by this report and no positive reduction has been demonstrated.

## Design comparison — proposals, no adoption

Measured cost is an optimization budget, not a guaranteed saving. The following
options have **no measured replacement-path speedup** in this task. Implementing
them before Human approval would exceed the requested measurement boundary.

| Option | Cost basis / possible reduction | Required source and safety work | Assessment |
| --- | --- | --- | --- |
| Keep all migrations on every test | Only the measured command overhead, boot, fixture and SQL costs identify where to investigate; none can be assumed removable | Initially observational decomposition only. Any console/bootstrap optimization would affect shared test setup/helper and require exact assertion/ledger/audit equivalence; any runner-resource change belongs to CI | Preferred next evaluation path; preserves current migration coverage and fresh seed generation |
| Faster independent schema initialization | Empty CREATE SCHEMA is separate from populating 87 migrations. Speeding the empty schema call cannot save the migration budget | Schema switch/search_path, connection purge and cleanup remain necessary. DDL-only clone shortcuts need complete functions/triggers/constraints/sequences/data/ledger proof | No shortcut adopted; distinguish cheap namespace creation from full initialization |
| Immutable migrated template, isolated restore | At most the current migration-path cost before template creation, per-test restore, validation and cleanup costs; all replacement costs UNMEASURED | New test DB provisioner, immutable manifest keyed by source + migration paths/content + dependencies + engine/settings, private clone destinations, fail-closed invalidation and full equivalence harness | HOLD: initial data includes generated time/UUID and current random-schema semantics are not proved equivalent |
| Fully isolated class parallelism | Can overlap measured class wall time without reducing total migration/hash work. Scheduling model is an upper bound, not measured speedup | CI runner/aggregation + separate PHP processes, DB resources/users, Redis, storage/cache/temp/log/lock namespaces per worker; preserve all invocations, skips, thresholds and migration checks | Strong alternative to investigate after approval; do not share a DB or reuse mutable fixtures |

### Why template equivalence is unresolved

Migration `2026_08_17_000030_create_v2_admin_authentication_policy.php` inserts
`Str::uuid7()` and `now()->startOfSecond()`. Other migrations, including 000064,
000068, 000071, 000073, 000074 and 000076, also generate current-time initial
data. A frozen template repeats those generated values instead of producing
fresh ones. Blanket timestamp/UUID normalization would conceal a semantic
difference and is **not** proof. Changing seed generation or refreshing template
rows is also a fixture/initialization implementation decision, not authorized
here. Audit mutation guards can make an ordinary UPDATE-based repair invalid.

PostgreSQL `CREATE DATABASE … TEMPLATE` is a whole-database mechanism; it does
not clone a schema into a new randomly named schema. It requires no other
connections to the template and does not copy database-level settings or
database-level permissions. These remain explicit proof obligations, not an
assumption of full equivalence. See [PostgreSQL 17 CREATE DATABASE](https://www.postgresql.org/docs/17/sql-createdatabase.html).

`CREATE TABLE … LIKE INCLUDING ALL` is not a schema clone: table-copy semantics
do not establish equivalent trigger/function/FK graphs, data or migration
ledger, and sequence-backed defaults require ownership/reference checks.
See [PostgreSQL 17 CREATE TABLE](https://www.postgresql.org/docs/17/sql-createtable.html).
Dump/restore selection also needs dependency and post-data completeness checks;
see [PostgreSQL 17 pg_restore](https://www.postgresql.org/docs/17/app-pgrestore.html).
No such alternative is executed or approved in this task.

### Mandatory equivalence gate for a future implementation

1. Compare schema definitions, column types/defaults/generated/identity values,
   collation, extensions, views, indexes (including partial predicates),
   functions/procedures and dependency resolution under actual search_path.
2. Compare every trigger, enabled state, constraint (including FK/deferral and
   validation state), sequence definition/ownership/current value/is_called and
   first generated ID. Reject cross-schema/template sequence or function links.
3. Compare complete initial data and migration ledger: names, order, batches,
   count and corresponding ordered path/content manifest. Resolve generated
   time/UUID semantics explicitly; do not exclude them merely to get equality.
4. Exercise audit append-only guards, HMAC/key-version expectations and each
   affected financial/domain negative case. Catalog equality alone is not a
   behavioral test. Retain migration application/forward-only rejection and
   backup/restore verification in their current owner and stage.
5. Demonstrate test independence in both orders and repeated execution, including
   failed setup, interrupted restore, concurrent workers, no namespace collision,
   no leaked connection/cache/clock/storage/lock and scoped failure cleanup.
6. Run the current full V2 suite and complete DB smoke with one-to-one invocation
   accounting, unchanged assertions and unchanged skip reasons. Do not freeze
   1336 as a permanent expected count if legitimate feature tests are added.
7. Measure template build/restore/validation/cleanup and resource contention on
   comparable isolated runners. Include all costs in the wall-time comparison.
   Do not disable fsync/WAL, constraints, triggers, transactions or audit guards,
   lower Argon2id cost, or remove migration validation to obtain a faster result.

### Concurrent migration ownership

Feature development continues. This task reserves no migration number, changes
no migration/inventory/forward-only registration and takes no migration lock.
87 files and digest `8717ca2512b108ca938b879616b2fb418a49cadc8204fa9c45d3673f8697655f`
identify this observation only; they are not a new feature-task constraint.
Migration ownership and allocation locks remain with each feature task.

A future initializer must consume an exact protected-main snapshot and reject
stale templates when any ordered migration path/content, application dependency
or relevant engine setting changes. Rebuild in the optimization task's isolated
environment after the feature migration is accepted; never edit that feature's
migration or weaken inventory/forward-only checks to fit a template. Actual
source collisions would be limited to Banner/Notion shared setup, the DB runner,
CI aggregation/workflow and the Worklog, and would require bounded coordination.

Concretely, a future approved setup change would touch
`apps/api/tests/V2/BannerImportTest.php` and
`apps/api/tests/V2/NotionSyncTest.php`, possibly a dedicated test helper rather
than all of `apps/api/tests/TestCase.php`. Runner/shard changes would belong to
`scripts/db/v2_database.py` and `.github/workflows/platform-ci.yml`; aggregate
reporting must reconcile whichever timing implementation is actually approved
on main then. PR #572's unmerged implementation is not silently imported or
made a prerequisite. Banner/F6 and Notion feature owners retain their fixtures;
the optimization task coordinates only actual overlapping paths. No feature
migration file is a planned optimization edit.

## Verification and handoff

Only this report, its numeric evidence JSON and `worklogs/new_ver_main.md` are
delivery changes. Application/test fixture/migration/contract/CI/dependency bytes
remain unchanged. API/auth/point/payment/draw/infrastructure behavior impact:
none. Migration files created: zero. Migration applications occur only inside
task-disposable test schemas, never operational databases.

Executed PASS: two complete 90-test runs, exact invocation/order/assertion/skip
comparison, all 90 timing partitions, 7,830 profiled migration events, four-file
probe-removal equality, 87 unchanged migration files, 130 locked package
identities, source scope/secret/PII/JSON/Markdown/link checks and local policy
gate. The final exact-head GitHub check results and self-review are recorded on
the Draft PR. The earlier invalid-instrumentation attempt is separately
**INCOMPLETE / NOT PASS**, never included in timing totals.

Both completed runs leave zero task test schemas and zero user tables. Final
guarded cleanup removes the task containers/networks/volumes and both synthetic
configuration files. Raw JUnit/output are private temporary evidence and are
removed after identity reconciliation; only sanitized timing data and probe
source are retained. No operational migration was applied; control performed
90 successful original migration commands, profile observed 90 commands/7,830
`up()` calls. The interrupted attempt's migration count is **UNMEASURED**.

Metrics: two complete bounded measurement invocations plus one aborted probe
attempt; application/image Build count **0**, Runtime Activation count **0**,
GitHub Check rerun count **1**, Human wait **0**. Complete measurement process
wall totals **1,700.997863s**, excluding preparation/recovery/analysis/CI. Total
task elapsed was not independently clocked and is **NOT_MEASURED**. CI wait and
final head/check identities are recorded on the PR after delivery; do not
substitute test wall time for total task elapsed.

Initial PR CI run38890912118 failed policy-gate because its PR body omitted
required headings. A local complete-body validation also identified that the
original transport ID does not satisfy CI's narrower Task ID format. The same
PR/branch is retained: canonical CI ID `DBPROFILE-20261016` maps explicitly to
the existing App transport identifier, and required headings/changed paths are
declared. PR title/body and these records are corrected; no policy regex,
workflow, wrapper, required check or scope control is weakened. The failure
remains historical evidence; a new documentation head receives fresh checks.

Full V2 / complete DB smoke / Browser / E2E / frontend build / template restore /
parallel execution / deployment: **NOT RUN** in this task. PR #572's full-suite
PASS is historical evidence, not a new PASS for these omissions. No replacement
initialization equivalence or speedup is claimed. Proposed optimizations require
Human choice and a separate implementation/equivalence task.

The Draft PR, task branch/worktree and sanitized evidence are retained for Human
review. Merge actor/squash SHA: none. Main is not advanced or synchronized by
this task. Rollback is discarding/reverting these documentation records; no
database down migration or runtime rollback is needed.
