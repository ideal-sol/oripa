# DB smoke Phase 2

Change DBSMOKE-20261016; Strict Change, Risk R4, Activation none.
Status: `PR572_HUMAN_REVIEW_HOLD_PERFORMANCE_NOT_DEMONSTRATED`.
Draft only; merge is not authorized. Full V2 diagnosis and final-head evidence
are recorded on PR #572. The bytecode flag is removed from standard CI.

## Human-review follow-up: Full V2 diagnosis

Human reviewed head `24a90d0f78855ce4d2af04affd3765f04dae3301`, tree
`5e8219e77ba3d888504d2476f475df4ccfff6fd6`, and held performance adoption.
Smoke 1158.179326s -> 1170.974336s and Full V2 908.171254s -> 915.618347s
do not demonstrate acceleration. Reuse those measurements; do not rerun the
whole smoke merely to seek a favorable time. Existing cache code remains an
explicit, nonstandard experimental opt-in; it is not approved for adoption.

The next necessary source-head CI run diagnoses the unchanged full invocation
with stock PHPUnit 12.5.29 `--log-junit` and `--log-events-verbose-text`.
No filter, order, fixture, hash cost, timeout, assertion or skip change is made.
No custom subscriber, application instrumentation or profiling dependency is
introduced. Private raw files live only in a random mode-0700 directory in the
disposable API container; they are removed on success/failure/interruption.
Raw reads suppress exception-output excerpts. Neither raw file is uploaded.

Only source-allowlisted class/method identifiers, numeric invocation ordinals,
counts and timing aggregates escape. Dataset labels, paths, XML properties,
system output, exception bodies and telemetry messages are discarded. Invalid,
oversized, unknown or inconsistent reports fail the diagnostic; they never
downgrade a test failure. JUnit totals must match the unchanged PHPUnit summary,
and per-class event completion counts must match JUnit. Output contains top 20
test invocations and top 10 classes, not a raw per-test/event dump.

The [pinned JUnit logger](https://github.com/sebastianbergmann/phpunit/blob/9aa66a47db3ea70f1a468e66dd969f67e594945a/src/Logging/JUnit/JunitXmlLogger.php)
measures the test lifecycle, including preparation and teardown for prepared
tests. Standard telemetry separates PreparationStarted -> Prepared, including
framework preparation, setUp and preconditions. It is **not** an isolated setUp
method profiler. Tests skipped before Prepared are excluded from that preparation
subtotal; prepared tests that later skip remain included. The remainder includes
body, verification, teardown and framework work.
The [pinned test runner](https://github.com/sebastianbergmann/phpunit/blob/9aa66a47db3ea70f1a468e66dd969f67e594945a/src/Framework/TestRunner/TestRunner.php)
emits Finished only for prepared tests. An unprepared Skipped event is therefore
a terminal event; a prepared skip must still be followed by Finished. Both the
completed and skipped event counts must match JUnit per class. Finished and
unprepared-skip counts are reported separately. Bounded numeric failure codes
identify aggregation guards without exposing input values.
The [pinned lifecycle](https://github.com/sebastianbergmann/phpunit/blob/9aa66a47db3ea70f1a468e66dd969f67e594945a/src/Framework/TestCase.php)
emits hook-called events after hooks execute, so their preceding event delta
cannot safely be called isolated teardown time. DB initialization and password
hashing are not independently timed by these stock logs. They remain explicitly
unmeasured until correlated source analysis or separately justified profiling.
The [official logging options](https://docs.phpunit.de/en/12.5/textui.html#logging)
do not select or reorder tests. Logging overhead and runner variation prevent
using this diagnostic run alone as an optimization before/after claim.

Record measured rankings, source-linked cost classification, safe candidates
(or why none is proved), fresh checks and exact-head review on the Draft PR.
No speculative optimization is authorized by a slow test name alone.

## Authority and scope

Initial base `3faa1872c7fe23ba0d50e829b8251b7add57da67`. PR #571 merged at
06:43:58 UTC as `c4efbee32a7846bc94f7375d0d5f71779aa0ac77` during this Task.
Normal merge `df26339ae9cd677cf1602f6a7318cd2a1859634e` synchronizes that official
main; the canonical merge tree matches, with no conflicts and only this Change's
six paths in the net diff. Current base is the PR #571 squash SHA. Its inventory,
forward-only registration and migration 000087 are preserved byte-for-byte.
No migration is created by this Change; all 87 current-main migrations remain.
The canonical Phase 1 applicability plan and required wrappers are unchanged.

## Measurement

The initial measurement revision separates the existing Compose build/start
operation into build followed by `up --no-build --wait`; both API and Admin are
still built from the checked-out source. PostgreSQL/Redis missing-image pulls
are measured separately. BuildKit dependency/base-image pulls remain included
in build wall time. No imported cache or Production artifact is used.

`smoke-timing.json` records monotonic phase/total durations, outcomes, source and
tree identities, migration digest/count, runner classification, workflow run and
attempt, PHPUnit summary counts and native BuildKit cache observations. Raw
output, generated credentials, backup contents and environment values are not
uploaded. Missing cache progress is not asserted to be a verified cold cache.
The CI artifact uploads only this JSON, including on failure. Cleanup attempts
both projects even if restore cleanup fails; error evidence never reports PASS.

The shared local host is not used for container builds or full smoke because
other tasks are running and available memory is limited. Full measurements use
GitHub-hosted disposable runners. Local tests inject lifecycle failures and
inventory/schema/ledger mismatches without connecting to any database.

## Baseline

Historical observations are not equivalent-source comparisons:

| PR | Run | CI wall seconds (Human baseline) | DB job seconds |
| --- | --- | ---: | ---: |
| 564 | 38539975756 | 1006 | 958 |
| 568 | 38674790708 | 1092 | 1044 |
| 570 | 38842154829 | 1170 | 1122 |

GitHub confirms PR #570 smoke step 1114 seconds. Its runner/source differ from
this Change; no speedup is inferred from that observation.

The instrumented baseline is [run 38861040458](https://github.com/ideal-sol/oripa/actions/runs/38861040458),
head `544f217882d60332f321d506b52b6cc4ceb5fe73`, tree
`897424d779d0f27019a1e295a693ab77b306316d`. All five Required Checks PASS.
CI first-job-start to last-job-completion: 925 seconds; DB job: 883 seconds;
measured smoke: 871.058014 seconds. Four-CPU GitHub-hosted Linux x86_64 runner;
86 migrations. The full V2 invocation discovers 1324 tests / 17344 assertions,
with 17 existing opt-in skips. Draw: 6 tests / 45 assertions / 4 existing skips;
QA: 1 / 13; Reporting: 1 / 22; Content/Contact: 1 / 31. These are invocation
counts, not a deduplicated test total. No new skip is introduced.

| Phase | Baseline seconds |
| --- | ---: |
| PostgreSQL/Redis image pull | 6.195 |
| API/Admin build | 53.573 |
| Source startup/health | 12.137 |
| First / second fresh migration | 1.492 / 2.193 |
| Forward-only / migration status | 0.898 / 0.237 |
| Full V2 suite (historical identity phase name) | 673.125 |
| Draw / QA load | 46.698 / 18.356 |
| Reporting / Content/Contact | 11.934 / 5.541 |
| API / Admin health | 0.098 / 0.081 |
| Source inventory/schema/ledger | 0.431 |
| Backup | 2.421 |
| Restore startup / restore | 6.874 / 13.623 |
| Restore inventory/schema/ledger | 0.447 |
| Restore / source cleanup | 2.582 / 11.942 |

The baseline build-progress observer did not capture stdout, so its zero parsed
steps are **unreported**, not proven cold cache. The candidate observes both
streams; any cached step may also be intra-build reuse. Neither run imports a
cross-run cache. Base-image pulls remain within build time; registry/network and
runner hardware variation are not independently controlled.

### Comparable baseline after official main synchronization

Run [38864095716](https://github.com/ideal-sol/oripa/actions/runs/38864095716)
at head `9ae96bf6dce97206085e67bd977b558adbda8613`, tree
`4d1c7bcd479f904b6b241533c5c87c7f70831f63`, passes every Required Check.
It includes all 87 migrations and explicitly disables the bytecode opt-in.
CI wall is 1213 seconds, DB job 1166 seconds, smoke 1158.179326 seconds,
full V2 908.171254 seconds, build 59.004282 seconds. Full V2 discovers
1336 tests / 17497 assertions / 18 existing opt-in skips. Other invocation
counts remain Draw 6/45/4 skips, QA 1/13, Reporting 1/22, Content/Contact 1/31.
Both fresh runs, forward-only refusal, all health/load checks, schema/ledger
equality, backup/restore and both resource cleanups pass. Build progress contains
46 completed steps and zero cached steps; no cross-run cache is imported.
The exact phase table and validation digests are in the JSON evidence. Compare
the final candidate against this 87-migration run, not the earlier 86-migration
observation. Hosted-machine variation remains uncontrolled.

## Selected improvement

The full V2 suite accounts for about 77.3% of smoke time; build is about 6.2%.
The candidate's `--identity-bytecode-cache` opt-in enables PHP bytecode caching
only for that disposable PHPUnit process. Without the flag, the original uncached
execution remains available for controlled comparisons. It retains the same
suite, ordering, 512M memory limit, fixtures,
password hashing, assertions and skip conditions. Separate Draw/QA/Reporting/
Content load invocations retain their original PHP options and thresholds.
Persistent-development commands and all image/runtime configuration are unchanged.

Only the OPcache module already present in the freshly built API image may be
used. Before enabling it, a tiny synthetic probe proves cold miss, warm hit and
automatic invalidation after a source-file timestamp/content change. Temporary
probe files are removed. Unavailable/failed cache probes fall back to the original
normal execution; a failed PHPUnit invocation is never retried or downgraded.
Bytecode cache is process-local, disk file-cache is disabled, timestamp validation
is on with revalidation on every access, comments are retained, and JIT and extra
opcode optimization are disabled. The process-local opcode cache is bounded to
128 MiB in addition to the unchanged PHP allocator limit; no memory improvement
is claimed. No cache is imported, exported, or treated as
verification authority. PHP's [configuration reference](https://www.php.net/manual/en/opcache.configuration.php)
defines these controls. The probe passed on local PHP 8.3.31 without a DB or
container; target PHP 8.4 and complete evidence are verified by final-head CI.

This is a measured candidate, not a predeclared speedup: the amount of repeated
compilation inside the suite was not separately profiled. Final comparison must
report actual phase/total deltas even if small or negative. Application,
migration, Dockerfile and dependency bytes are identical to the baseline, but
different hosted runners and registry/cache conditions prevent a strict causal
claim from two runs. Do not extrapolate this PR's timing to all feature PRs.

## Alternatives not selected

- Cross-run build cache: build is a small fraction; introducing another cache
  trust boundary is unnecessary for this bounded Change.
- Test sharding or parallel DB tests: existing suites retain state, perform
  migrations and exercise concurrency. Equivalent initial/final state for
  arbitrary shards has not been proved; no same-DB parallelism is introduced.
- Redundant-test removal: no existing verification is removed or reused.
- Multiple CI jobs: no evidence that extra DB/image setup improves this path
  without changing the stateful suite's semantics; Phase 1 wrappers remain intact.
- Password-hash cost, performance thresholds, timeouts, data counts and fixture
  substitution: unchanged. Large speedups may require separately reviewed fixture
  isolation; their safety is not inferred here.

## Review evidence

[Baseline timing and validation identities](db-smoke-phase2-evidence.json) are
public-safe numeric/digest evidence. The final run's immutable artifact and
before/after comparison are linked on [PR #572](https://github.com/ideal-sol/oripa/pull/572)
so reporting results does not change the reviewed head. Required Checks,
Readiness Shadow and fresh self-review must refer to that final head.

## Boundaries and rollback

Production Readiness remains SHADOW ONLY, blocking authority false, Production
Fast Lane DISABLED, Phase 2 Promotion NOT STARTED and Human GO NOT GENERATED.
No operational database, environment, dependency, application, feature flag,
deployment, contract publication, runtime or Production change is authorized.
Rollback is a reviewed revert of this CI-only Change; no database down migration.
