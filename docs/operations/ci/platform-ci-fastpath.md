# Platform CI Fastpath Phase 1

Change: PLATFORM-CI-FASTPATH-20261013. Risk R4, Strict Change, activation none.
Status: implemented for Human review; merge and auto-merge are not authorized.
Authority: Human PCFI task, current Governance REV3 and Release Gates REV2.

## Execution and evidence

`lane_policy.py` reuses the existing canonical source classifier and emits
`platform-ci-plan.json` beside `canonical-change.json`. Every scope carries
APPLICABLE, NOT_APPLICABLE or UNKNOWN, its reason, affected paths, canonical
component deltas and the complete changed-path digest. The plan binds base,
head, tree, canonical change digest, event, lane and activation. It contains no
caller-controlled skip flags. It is preserved in the canonical-change artifact.

Scope exclusion requires valid complete canonical evidence AND coverage of all
changed paths, including deletions and rename sources. Dependency changes,
execution policy changes and shared infrastructure force all scopes. An
unrecognized scope input executes all scopes. Invalid canonical evidence is
still rejected by the pre-existing lane/security boundaries; it never permits
a skip. Non-PR events and activation requests retain full validation.

The policy-gate implementation and its tests are validation-only inputs already
executed by policy-gate; they do not alone invalidate unrelated runtime scopes.
This preserves concurrent feature policy additions without touching the reserved
`scripts/ci/policy_gate.py` or `tests/ci/policy/test_policy_gate.py` files.

| Scope | Required execution |
| --- | --- |
| Backend/API, including test-only changes | Entire existing `php artisan test` suite plus V1 test DB migrations |
| Backend/Admin, migration, contract or shared infrastructure | Entire existing V2 isolated migration, identity, draw/load, reporting, health, backup/restore smoke |
| Admin / Agency | Existing generated contract, typecheck, lint, test and build in quality-gate |
| Legacy frontend | Typecheck/lint in quality-gate; build in integration-support |
| Contract / Client / Schema / Testkit | Existing checks in quality-gate; contract inputs fan out across consumers |
| Release/artifact | Existing release tests/source validation in quality-gate, Compose in integration-support |
| ARM64 | Existing Strict PR requirement retained, except verified AUTHORITY_FOCUSED |

Database and migration are separate scopes: migration NONE does not justify
skipping database smoke. That command also exercises application, identity,
draw/load, reporting and container health. PR #553 therefore retains the full
smoke despite creating no migration. No backend test subset is introduced.

`integration-suite` (Backend), `integration-database` (complete existing smoke)
and `integration-support` (legacy build, Site Template, Compose) start after
policy-gate in parallel with quality/security/ARM64. These are not new Required
contexts. Each has its own checkout and generated-file clean check.

Duplicate Admin/package/release/contract checks are owned by quality-gate in
the same workflow run and exact source checkout. integration-gate explicitly
requires quality-gate success; no filesystem or cross-run success is reused.
It recomputes the plan from Git and verifies the policy-gate plan digest and
every job result. Required jobs must succeed; N/A jobs must explicitly be
skipped. Missing, failed, cancelled or unexpected results fail the wrapper.
Existing Lite focused quality behavior and verified AUTHORITY_FOCUSED behavior
remain intact. Required contexts remain policy-gate, quality-gate, security-gate,
integration-gate and ci-gate. Security audits and posture handling are unchanged.

## Baseline and replay

[Machine-readable timing and replay](platform-ci-fastpath-evidence.json) uses
GitHub API timestamps for [run 37940609962](https://github.com/ideal-sol/oripa/actions/runs/37940609962).
Measured durations: policy 21s, quality 233s, ARM64 220s, integration 1103s,
security 30s, integration wrapper 4s and final wrapper 4s. First job start to last
job completion is 1141s. The isolated smoke alone accounts for 790s; Backend
tests account for 141s. Baseline evidence was collected before implementation.

Real source replay uses the PR base/head pair returned by GitHub, not synthetic
path fixtures or the current main paired with an old feature head:

- #547: base `95a6c581d9a27eaa5741301a774d03d399061afe`, head
  `47742e1fd16c542bdd8234deb5c11a6a644d60ab`; AUTHORITY_FOCUSED, heavy scopes N/A.
- #553: base `54a20622d609a13cad4255dba563deee57d387c3`, head
  `5e63e1fbae79242a1726f99553c9e88fd14b6da6`; Backend, DB smoke, Admin,
  contract checks, release/artifact and ARM64 applicable. Migration delta NONE;
  legacy, Agency, Client, Testkit, Schema and Site Template N/A.

These are applicability replays, not new feature CI runs or fresh authority
approval. Neither PR is modified. The integration wrapper success scenario is
tested using simulated job conclusions, never presented as an executed CI PASS.

Projection for comparable #553 feature runners: approximately 840–900s wall
time (14–15 minutes), versus measured 1141s (19m01s), about 21–26% shorter.
This retains the measured 790s smoke and allows 50–110s for setup, scheduling
and gates. It is an estimate, not measured feature acceleration. The optimization
PR itself invalidates all scopes because it changes CI execution policy. Measure
the next actual feature PR using job timestamps after Human merge authorization.
No dependency cache or Production artifact exemption is added in this phase.

## Boundaries and rollback

Readiness remains SHADOW ONLY with blocking_authority=false, Production Fast
Lane disabled, Phase 2 and promotion not started, and no generated Human GO.
Unapproved Security findings remain visible as HOLD even when Development CI
passes for unchanged dependency inputs. There is no environment, secret,
provider, application, schema, runtime or Production configuration change.

Rollback is a reviewed revert PR restoring the previous CI orchestration.
There is no runtime rollback. Branch/worktree are retained for Human review.
Final-head checks, Shadow observation and self-review are recorded on this
Change's PR; documentation does not predeclare their success.
