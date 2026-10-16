# DB smoke Phase 2

Change DBSMOKE-20261016; Strict Change, Risk R4, Activation none.
Status: measurement in progress; Draft only, Human review required before merge.

## Authority and scope

Base `3faa1872c7fe23ba0d50e829b8251b7add57da67`. PR #571 remains Draft at
`a12665b8d1d591541696aad483a9d4376932d4f9`; its inventory and forward-only
registration changes are independent of this smoke execution work. No migration
is created or imported. Existing 86 migrations and all smoke checks remain.
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
this Change; no speedup is inferred from that observation. Instrumented baseline
and final measurements will be recorded after execution, before claiming success.

## Boundaries and rollback

Production Readiness remains SHADOW ONLY, blocking authority false, Production
Fast Lane DISABLED, Phase 2 Promotion NOT STARTED and Human GO NOT GENERATED.
No operational database, environment, dependency, application, feature flag,
deployment, contract publication, runtime or Production change is authorized.
Rollback is a reviewed revert of this CI-only Change; no database down migration.
