# DB smoke Phase 4: isolated class parallelism feasibility

Task `DBPARALLEL-20261016`; Risk R4; Lane **Strict Change**; Application
Runtime Activation **none**. Issue none: this Change's Draft PR is the pending
Human decision record. Dedicated branch
`docs/DBPARALLEL-20261016-isolated-experiment` and worktree are retained.

## Authority and boundaries

Human decisions `DBSMOKE_PHASE4_ISOLATED_PARALLEL_EXPERIMENT_GO` and
`DBSMOKE_PHASE4_BOUNDED_RETRY_GO` authorize this bounded experiment and Draft PR.
**Merge is not authorized.** Production impact NONE; Runtime impact NONE;
operational DB migration NONE; feature activation NONE.

Final status: **DBSMOKE_PHASE4_PARALLEL_EXPERIMENT_HUMAN_REVIEW_PENDING**.
Experiment result: **FAIL / INCOMPLETE**. Parallel execution is **NOT RUN**.
Recommendation: **HOLD / do not adopt**. Measured shortening is **UNMEASURED**.

The live protected `main` at initial preparation and immediately before the
authorized new attempt is `bd4c1a3289fc11ce844805fe8ac0c6bb735b0b26`.
All three prepared copies use that exact source, lockfile and all 88 V2
migrations, including `2026_10_16_000088_create_v2_member_rank_settings.php`.
No migration is reserved, created, rewritten or applied to an operational DB.
Migration inventory and forward-only registration remain Feature-owned.

The finalized Governance REV3, Release Gates REV2 and GitHub Operations REV1
remain applicable. The explicit Human no-merge boundary supersedes normal
merge/branch-cleanup completion. No Ready, merge, auto-merge, activation or
check-retrigger operation is granted by the task's narrow App delivery policy.

[PR #572](https://github.com/ideal-sol/oripa/pull/572), head
`d74247babf2cabf4e334bfac8fe34451b5933b90`, and
[PR #576](https://github.com/ideal-sol/oripa/pull/576), head
`106c0eb6b21136a819f3221dbc48c27bb10fe9cc`, are read-only references.
Their branches, worktrees and PR metadata are not modified. The primary
checkout's historical uncommitted Worklog and unrelated worktrees are retained.

## Retained failed attempt and bounded correction

The initial serial attempt began at 2026-10-16 13:29:23 UTC and is permanently
**FAIL / INCOMPLETE**, not a successful timing sample. Environment startup took
10.316024s; orchestration ended after 12.537242s including 1.628013s cleanup.
PHP was launched, but the harness raised `KeyError: 'Tmpfs'` while recording
PostgreSQL container metadata. No complete JUnit or migration observer report
was produced. Completed tests, assertions and applied migration count for this
aborted attempt are UNKNOWN. The initial handler did not retain PHP output;
that evidence gap is explicit, not filled with invented zeros.

Docker omits the optional `HostConfig.Tmpfs` key on containers without tmpfs
mounts. The PHP container has `/tmp`; the PostgreSQL and Redis containers do not
have that optional field. The information retrieval correction is:

```diff
- 'tmpfs': data['HostConfig']['Tmpfs']
+ 'tmpfs': data['HostConfig'].get('Tmpfs', {})
```

The failed harness source, corrected source, traceback classification, timing,
authorization and regression source are retained in the task evidence area.
The initial source snapshot is reconstructed from the recorded patches; its
checksum was not captured before the first launch.
Two orchestration guards additionally require completed source preparation
before launch and capture private PHP output before cleanup on a harness error.
Neither changes PHPUnit arguments, test behavior, timeouts or resource limits.
No application fix is made in response to this failure.

Focused local regression **6/6 PASS**, before the authorized new executions:

1. Reproduce the original direct-index `KeyError` on an absent server tmpfs key.
2. Accept absent PostgreSQL/Redis tmpfs while preserving the PHP `/tmp` mapping.
3. Reject a container belonging to another Task.
4. Reject an extra network attachment.
5. Reject a published host port.
6. Reject a stopped container.

Python compilation, PHP observer syntax and all three copies' original test,
migration and hash-policy byte comparisons also pass. The Human explicitly
authorized one new serial attempt followed by one parallel attempt, conditional
on serial test/DB safety PASS. The initial failure is never replaced or retried
silently; measurement output paths refuse replacement.

## Experiment design and measurement definitions

The existing suite discovery and exact class filter select all 90 invocations,
including inherited BannerImport methods. The serial order is the existing
PHPUnit order. Each parallel worker retains its subsequence of that order;
cross-worker execution overlaps by design. No explicit cross-class dependency
annotation is present in these five classes. This static check is not proof
that every Full V2 class is independent.

| Execution | Classes | Expected invocations |
| --- | --- | ---: |
| Serial | All five classes, one PHP process | 90 |
| Worker A | BannerAssetLifecycleTest, BannerImportWorkerTest | 46 |
| Worker B | BannerImportCsvTest, BannerImportTest, NotionSyncTest | 44 |

The configured design gives each worker a separate PHP container, PostgreSQL cluster/container/data
volume, Redis container/data volume, internal Docker network, complete
application/dependency copy, storage, cache, temporary files, logs and file-lock
namespace. Only the serial environment was actually started and inspected;
two-worker runtime isolation is **NOT PROVEN**. Its private PID/IPC namespaces
were checked. Host ports were not published;
the PHP root filesystem is read-only except its own explicit writable mounts and
tmpfs. The common observer mount is read-only. Only task-generated credentials
are used and their values are neither exported nor committed.

The Banner and Notion tests use PostgreSQL advisory locks, making distinct
PostgreSQL server/cluster identities necessary even when schemas differ. Redis
is separately provisioned and readiness-checked in the serial environment, while the unchanged PHPUnit
configuration uses array cache/session defaults. This subset therefore does
not establish all possible Redis queue/cache/lock behavior for Full V2.

Per-worker PHP quota is 1 CPU / 768 MiB; PostgreSQL 0.5 CPU / 256 MiB; Redis
0.1 CPU / 64 MiB. Container swap is disabled. PHP memory_limit remains 512 MiB;
CLI OPcache is disabled in both modes. The same immutable image IDs and 130
lock-matched Composer packages are used. No build or dependency update occurs.

This is a shared 2-vCPU host, not a dedicated GitHub Actions runner. Two workers
have twice the configured aggregate quota/limits, but share the same host CPU,
memory, kernel and storage device. Existing host load and swap use are observed
without modifying other workloads. OS page cache and background scheduling are
not controlled. One serial sample followed by one parallel sample cannot prove
a causal speedup or statistical repeatability.

An identical removable hook in the **temporary copied** base TestCase calls
the unchanged parent application initialization and attaches Laravel event
observers. The original repository TestCase, all five test bodies/fixtures,
assertions, skip conditions, timeouts, thresholds, hash costs and migrations
remain unchanged. Removing the hook yields byte-identical original source.
The hook observes, rather than replaces, each test's existing migration command.
Observer overhead is present in both modes and is not independently measured.

- Source/dependency copy preparation is measured once for all three copies and
  reported separately; it is excluded from each environment lifecycle.
- Initial startup covers fresh network/volume creation, PostgreSQL/Redis startup,
  readiness and empty-database/durability checks.
- Worker wall time covers PHP container launch through process completion;
  PHPUnit window covers first launch through all workers' completion and log
  collection. JUnit lifecycle time is separately available per invocation.
- Migration command time is nested within PHPUnit time. Migration `up()` event
  time is nested within command time; neither is added to whole-run wall time.
- Aggregation covers report parsing and per-invocation migration reconciliation.
  Final DB state verification follows aggregation and is included in overall
  wall time. Cleanup removes only label-verified task resources/configuration.
- Overall wall-clock spans environment startup through cleanup. One-time source
  preparation, operator preparation, failed attempts and CI are separate costs.
- Cgroup CPU/throttle, memory/OOM, I/O and PSI data plus host CPU/memory/swap/PSI
  are sampled every two seconds during the test phase. Final sub-interval
  counters may be missing after an exited PHP cgroup disappears. Startup before
  the first sample and cleanup after the last sample are outside this resource
  sampling window. Host counters include unrelated work.

## Results and second failure

The authorized new serial run started at 2026-10-16 13:33:48 UTC. PHP ran from
13:33:55.362726630 to 13:48:26.672942373 UTC and exited **0**, without OOM kill.
Nevertheless the experiment is **FAIL**, because aggregation rejected valid
PHPUnit data-provider names at this assertion:

```python
assert test.get('name').startswith('test_') and 'data set' not in test.get('name')
```

Fourteen of the 90 invocations have data-provider suffixes. The first rejected
invocation is ordinal 15, `BannerAssetLifecycleTest::test_unknown_put_is_tracked_without_reput_or_delete`.
This is an information/aggregation defect, not a PHPUnit failure. The corrected
Tmpfs handling worked; the narrow six regression tests did not cover discovery
or end-to-end aggregation. The failed result remains unchanged. No additional
execution or measurement-harness repair follows this failure.

Read-only analysis of the already written JUnit and migration-event files
reconciles **90 total / 89 non-skipped PASS / 1337 assertions / 1 existing skip /
0 errors / 0 failures**. Each invocation is retained with a numeric ordinal;
data-provider labels and fixture/output values are not published. This offline
reconciliation is not a replacement successful experiment or a parallel
equivalence result.

An additional telemetry gap was found offline: all 90 invocations have zero
observed Console `CommandStarting`/`CommandFinished` events. The locked Laravel
Console Kernel explicitly skips `rerouteSymfonyCommandEvents()` while
`runningUnitTests()` is true. Therefore full migration-command durations and
independent command-exit telemetry are **UNAVAILABLE**, not zero. The unchanged
tests' existing `assertExitCode(0)` checks pass, and all ordered MigrationEnded
records match the 88 source filenames. Nested `up()` duration is available.
No application mode or event routing was changed to obtain a measurement.

| Measurement | Authorized serial run | Parallel run |
| --- | ---: | --- |
| Fresh environment startup | 6.571667 s | NOT RUN |
| PHP worker launch-to-completion | 872.450988 s | NOT RUN |
| PHPUnit window including collection | 872.553695 s | NOT RUN |
| JUnit lifecycle sum | 869.902775 s | NOT RUN |
| PreparationStarted → Prepared sum | 465.864863 s | NOT RUN |
| Migration command duration | UNAVAILABLE | NOT RUN |
| Nested migration `up()` duration | 370.041773 s | NOT RUN |
| Aggregation | FAIL; duration not recorded | NOT RUN |
| Task container/network/volume cleanup | 2.396582 s | NOT RUN |
| Overall failed-run wall-clock | 881.570611 s | NOT RUN |
| Additional parallel initialization | UNMEASURED | NOT RUN |
| Actual shortening | UNMEASURED | NOT RUN |

These rows overlap; do not sum preparation, migration, JUnit and process times.
The failed serial overall duration is not a successful comparative baseline.
One-time preparation of three source/dependency copies and final file cleanup
are separately recorded in the [numeric evidence](db-smoke-phase4-isolated-parallelism-evidence.json).

### Current-source partition reassessment

| Class | Invocations | Assertions | Skip | Serial JUnit seconds |
| --- | ---: | ---: | ---: | ---: |
| BannerAssetLifecycleTest | 22 | 272 | 0 | 231.095901 |
| BannerImportCsvTest | 12 | 147 | 0 | 100.073397 |
| BannerImportTest | 6 | 80 | 0 | 52.653218 |
| BannerImportWorkerTest | 24 | 240 | 1 | 211.837352 |
| NotionSyncTest | 26 | 598 | 0 | 274.242907 |

The proposed A/B grouping remains the best arithmetic two-way class partition
among all nonempty partitions of these five measured class weights:
A **442.933253 s**, B **426.969522 s**, imbalance **15.963731 s**.
This is serial-weight arithmetic only. Neither 426.969522 s nor any other
overlap estimate is a measured parallel saving; startup, contention and worker
runtime are unknown.

### Isolation, migrations and resource observations

- All 88 migration source files and all tracked API source files in all three
  copies were compared after execution. The observer is removable byte-for-byte.
  The successful serial test process emitted **7,920 `up()` completions**, with
  **Migration 000088 present in all 90 invocations**. Initial aborted-attempt
  migration count remains UNKNOWN and is not included in 7,920.
- The serial PHP, PostgreSQL and Redis containers had distinct process IDs,
  one task-only internal network with exactly three members, private IPC/PID
  namespaces, no host ports, no shared writable mounts and no operational
  attachments. The complete two-worker arrangement was never started, so
  simultaneous A/B isolation, distinct cluster identities and lock behavior
  are not experimentally proven.
- 436 resource samples span 871.054929 s. Host one-minute load ranged
  **2.17–5.17**; non-idle/non-I/O-wait CPU **65.33%**, I/O wait **3.15%**;
  minimum available memory **2,552,972 KiB**. Host swap counters show 14 pages
  read and 0 written in the sampled interval; these include unrelated work.
- Observed cumulative task CPU: PHP **108.798056 s**, PostgreSQL
  **366.335606 s**, Redis **4.979686 s**. PostgreSQL quota throttling accumulated
  **180.194439 s**. Its sampled memory reached the **256 MiB** configured limit;
  recorded cgroup peak was 268,599,296 bytes. There were **0 observed OOM/kill
  events**. Combined sampled memory peak was **471,302,144 bytes**.
- Observed block write counters: PostgreSQL **2,050,551,808 bytes**, PHP
  **22,134,784 bytes**, Redis **4,096 bytes**. Host CPU/memory/I/O `some` pressure
  totals increased by **355.243281 / 5.370096 / 41.653571 s**. These establish
  pressure during serial execution, not causal attribution or measured
  two-worker contention. Cgroup maxima are sampled, not exact final counters.

The existing skip is
`BannerImportWorkerTest::test_real_processes_claim_one_job_once`. Its unchanged
source requires `V2_BANNER_IMPORT_CONCURRENCY_TEST=1` and states
“Requires the dedicated isolated Banner import concurrency run.” JUnit stores
an empty skipped node, so the reason is source evidence rather than a runtime
reason string. No skip condition was bypassed to manufacture coverage.

### Cleanup and stop decision

Both failed attempts' cleanup removed their task containers, networks and
volumes. Final label-filtered discovery verifies **zero** remaining task
containers, networks and volumes. Unused worker credentials and all six
generated application copies, including storage/cache/log data, are removed.
Only task-private diagnostic reports, source snapshots and sanitized public
evidence are retained. Existing runtime, ENV and operational databases are
untouched.

Because the second failure happened before the explicit post-test schema/table
inspection, **zero residual test schemas/tables before volume removal is NOT
VERIFIED**. Destruction of the disposable volume is verified separately. No
claim about residual state is inferred from successful teardown or volume
cleanup. This incomplete safety/aggregation evidence prevents starting the
authorized parallel trial.

Attempt accounting: initial serial **1 FAIL/INCOMPLETE**, authorized new serial
**1 harness FAIL** with successful PHPUnit execution, parallel **0**, additional
retry **0**. The allowance to run parallel is not treated as an obligation to
continue through incomplete evidence. Both failures and the missing telemetry
remain visible in this Draft PR. Adoption remains **HOLD**; a future Human
decision would be needed for parser/discovery/observer validation and any new
measurement. No such implementation or retry is performed here.

## Full V2 adoption boundary

These 90 invocations alone cannot establish Full V2 or complete Platform CI
equivalence. A future approved expansion must inventory cross-class dependencies,
global mutable state, process-spawning concurrency tests, shared/default schema
assumptions, Redis queues/locks, storage paths and externally opted-in tests.
Class weights must be remeasured after migration/source changes. Exact discovery
coverage, inherited/data-provider identities, assertions, skips and order
requirements need fail-closed aggregation across all shards. Worker failures,
partial reports, cancellation and cleanup need dedicated coverage.

Full DB smoke also includes backup/restore, schema/ledger comparison, health,
load and support tests. Their placement, costs and equivalence are not covered
by this subset. GitHub runner resource limits and billing/startup overhead may
change the result. CPU/memory/IO contention needs representative runner evidence
before adopting a permanent workflow or officially splitting Full V2.

No persistent workflow, Required Gate, test initialization strategy or production
feature is changed. Rollback is discarding/reverting documentation and removing
only remaining task-owned evidence if authorized; no runtime/database rollback
is required. Branch/worktree and sanitized evidence remain for Human review.
