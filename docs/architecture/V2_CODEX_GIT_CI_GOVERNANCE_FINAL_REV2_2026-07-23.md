# Oripa V2 Codex, Git, and CI Governance

- Document ID: `V2-CODEX-GIT-CI-GOVERNANCE-001`
- Status: **FINAL / Architecture Baseline 2.0 / Revision 2**
- Confirmed: 2026-07-23
- Repository: `ideal-sol/oripa`

## Supersession

This document supersedes
`V2_CODEX_GIT_CI_GOVERNANCE_FINAL_2026-07-22.md`. The earlier document remains
historical evidence. Its requirements for human PR approval, human-only merge,
disabled auto-merge, and prohibition on Codex repository administration no
longer govern Platform work.

The latest explicit human decision remains the highest authority.

## Purpose

Platform Codex and the `ideal-sol-oripa-codex` GitHub App operate the complete
GitHub task lifecycle while preserving auditable pull requests, required
validation, strict scope, immutable archive refs, and immutable released tags.

Autonomy is not authority to weaken a gate, change product policy, expose a
secret, or make a commercial Production decision.

## Autonomous Responsibility

Platform Codex may:

- create and maintain Issues;
- create task branches and dedicated worktrees;
- implement, test, commit, and push task changes;
- create and ready pull requests;
- inspect CI and required checks;
- create fixed-head machine-readable self-review evidence;
- squash merge an eligible pull request;
- delete the merged task branch and clean local worktrees;
- synchronize local `main` with `origin/main`;
- manage repository Rulesets and General settings;
- manage approved workflows, environments, deployments, releases, and tags;
- update governance and operational evidence.

Platform Codex does not need a GitHub Approval review for its own pull request.
The GitHub App must not submit a synthetic approval. Self-review evidence is an
audit artifact, not a GitHub Approval.

## Reserved Human Decisions

The following remain outside autonomous authority:

- final GO for initial commercial Production;
- legal and regulatory judgment;
- accounting judgment;
- payment, identity, shipping, or other provider selection and unsettled
  provider behavior;
- acceptance of a SEV-0 or SEV-1 defect;
- waiver of a mandatory security or financial correctness gate;
- disclosure of Production secrets or real-user PII.

An explicit human product or architecture decision may still be required where
a specification is genuinely undecided. Human PR operation is not required.

## One-task Lifecycle

Use exactly one Issue, one task branch, one dedicated worktree, and one pull
request per task:

1. Verify Repository, clean base, Remote refs, Task ID, Risk, and scope.
2. Create the Issue and task policy.
3. Create the Remote task branch from a fixed full base SHA.
4. Create the dedicated local worktree.
5. Implement only allowed paths.
6. Run required local validation and GitHub checks.
7. Commit and fast-forward push through the fixed GitHub App wrapper.
8. Create a Draft PR.
9. Fix the expected head SHA.
10. Produce fresh machine-readable self-review evidence.
11. Mark the PR ready only after scope and validation pass.
12. Re-read the PR and checks; reject a changed head.
13. Squash merge without bypassing any gate.
14. Verify `main`, delete the merged task branch, synchronize local `main`, and
    remove the clean task worktree and local branch.
15. Record final evidence and continue only when the next Task is authorized.

## Risk-based Change Lanes

Every Task, root-owned Task Policy, Issue, and PR declares one Lane and one
Application Runtime Activation mode. The only Lane values are `Lite Maintenance`,
`Standard Change`, and `Strict Change`. The only Activation values are `none`,
`deferred`, and `immediate`.

Lane order is `Lite Maintenance` to `Standard Change` to `Strict Change`.
Platform Codex may escalate when paths, domains, evidence, or uncertainty demand
it. Platform Codex must not downgrade a Human-approved or previously escalated
Lane. Missing or invalid Lane/Activation metadata, an unknown changed path, or a
path whose minimum Lane exceeds the declared Lane fails closed.

### Lite Maintenance

Lite is limited to low-risk presentation maintenance: wording, CSS, layout,
icons, display order, filter defaults, and light Admin UI that uses an existing
API or a value already accepted by the Backend. Required verification is:

- Lane and changed-path validation;
- focused lint, typecheck, or test for the changed area;
- high-confidence secret and credential scan of the added diff without value
  readback;
- final-head diff self-review;
- targeted UI confirmation when UI changed.

Lite does not require unrelated full integration, full security, CodeQL,
Dependency Review, migration, runtime, or Security Baseline evidence. Lite must
not pass if the change touches workflow/CI, dependency/lockfile, schema/migration,
Auth/Session/CSRF/MFA/email-verification core, Payment, Coin/Point,
Draw/Inventory, secret/credential, Production, deployment/network, a security
boundary, or immutable history. Lite defaults to `deferred`; use `immediate`
only when the Task requires immediate Runtime confirmation.

### Standard Change

Standard covers ordinary Admin UI logic, non-destructive API changes, additive
normal contract changes, and bounded Shared Preview data maintenance described
below. It requires focused or integration tests for the affected domain plus
normal CI.

Shared Preview data maintenance is Standard-eligible only when every condition
passes: Shared Preview only; one exact target; few rows; named columns; before
and after evidence; rollback available; no schema change; no trigger disable;
no immutable-history mutation; not Auth, Payment, Coin/Point, Draw, or Inventory;
and no PII or secret. Production, ambiguous predicates, bulk changes,
irreversible mutation, and Core Domain data maintenance are Strict.

### Strict Change

Production, schema migration, Auth/Session/CSRF/MFA, Payment, Coin/Point,
Draw/Inventory, secret/credential, security boundary, immutable history,
destructive contract, and ambiguous or non-rollbackable database mutation remain
Strict. Strict keeps the current Required Checks, full applicable security and
integration requirements, and configured self-review freshness window.

Required Check context names stay `policy-gate`, `quality-gate`, `security-gate`,
`integration-gate`, and `ci-gate`. Their internal work may be Lane-aware, but a
required context must still complete successfully. Required contexts are not
deleted or bypassed to accelerate a Lane.

## Merge Gate

Every autonomous merge requires all of the following:

- an Issue and approved Task ID;
- a branch and PR matching the task policy;
- every changed path within the policy's allowed paths;
- no unexpected or out-of-scope change;
- required CI successful;
- every applicable local validation successful;
- no secret, credential, private key, Production data, or PII candidate;
- applicable migration, contract, authorization, security, financial,
  concurrency, and rollback tests successful;
- a full expected head SHA;
- fresh self-review evidence for exactly that head;
- zero open SEV-0 and SEV-1 findings;
- no merge conflict;
- the PR still targets `main`;
- squash merge selected;
- no Ruleset or required-check bypass.

If the head changes after review, all head-dependent checks and self-review
evidence expire. Revalidate before merge.

## Self-review Evidence

The GitHub App posts a machine-readable PR comment containing:

- schema version and Task ID;
- base and reviewed head full SHA;
- UTC timestamp;
- changed paths and scope result;
- local checks and GitHub check summary;
- secret/PII scan result;
- migration and contract impact;
- security and financial impact;
- open finding counts by severity;
- merge recommendation.

Evidence is fresh only when its head equals the current PR head and all merge
gate fields pass.

For Lite and Standard, an unchanged final head and passing evidence are the
freshness authority; elapsed time alone does not expire evidence. Strict retains
the current configured time window in addition to exact-head equality. A changed
head invalidates every Lane's evidence.

Evidence schema records Lane and Activation and must match the Task Policy.

## CI Bootstrap

Before GOV-008 and GOV-009 are merged:

- run every available local validation required by the task;
- require every check currently emitted for the PR;
- record that the fixed five-check set is not yet available;
- do not invent a check name or treat a failed/skipped check as successful.

After GOV-009:

- require `policy-gate`;
- require `quality-gate`;
- require `security-gate`;
- require `integration-gate`;
- require `ci-gate`;
- remove the Bootstrap exception automatically.

## Repository Rules

### `main` and `release/**`

- pull request required;
- required approvals: zero;
- Code Owner review: off;
- latest-push approval: off;
- conversation resolution: on;
- linear history: on;
- deletion prohibited;
- force push prohibited;
- direct push prohibited;
- available required checks must pass;
- no Codex or GitHub App bypass.

### V1 Archive

`archive/v1-current` has no bypass. Updates, deletion, and force pushes are
prohibited.

### Stable Tags

Stable tag creation and immutability use separate Rulesets:

- only the GitHub App may create a matching tag after the Release Gate passes;
- no actor, including the GitHub App, may update or delete a matching tag;
- force updates are prohibited.

Patterns:

- `platform-v*`
- `storefront-client-v*`
- `site-schema-v*`
- `storefront-testkit-v*`
- `site-template-v*`
- `*-site-v*`
- `v1-before-productization-*`

## GitHub App Security

- Access is limited to `ideal-sol/oripa`.
- Installation tokens are generated per operation and are short-lived.
- Tokens, JWTs, IDs, private keys, config values, and Authorization headers are
  never printed, logged, committed, or written to the Worklog.
- Operation, task, repository, branch, base, head, payload, and allowed paths
  are policy constrained.
- Expected Remote and head SHAs are mandatory for mutating operations.
- Direct `main` push, force push, ref deletion by Git push, archive update, and
  stable tag movement/deletion remain rejected.
- Administration permission does not authorize CI bypass or Ruleset weakening.

## Repository Settings

- squash merge: enabled;
- merge commits: disabled;
- rebase merge: disabled;
- auto-merge: enabled;
- automatic deletion of merged head branches: enabled.

The autonomous wrapper may still perform an explicit squash merge after the
fixed-head gate instead of relying on GitHub auto-merge.

## Workflows, Environments, Deployment, Release

Platform Codex may manage these resources only through an approved task policy
and applicable Release Gate.

- Pin third-party Actions to full commit SHAs.
- Do not expose secrets to untrusted PR jobs.
- Use least-privilege workflow tokens.
- Build once and promote immutable digests.
- Do not build on Production servers.
- Do not perform Production migration or use Production data outside an
  explicitly approved deployment operation.
- Commercial Production final GO remains human-only.

## Prohibited Operations

- direct push to `main`;
- force push or shared-history rewrite;
- archive update or deletion;
- stable tag update or deletion;
- CI failure bypass;
- deleting tests or weakening assertions to obtain PASS;
- fabricated or stale self-review evidence;
- secret, token, credential, key, Production data, or PII disclosure;
- autonomous legal, accounting, provider, or commercial Production GO decision.

## Failure Behavior

Stop without merge when:

- Remote or PR head changed unexpectedly;
- a required check failed, was cancelled, or is still pending;
- scope validation failed;
- a secret/PII candidate remains unresolved;
- mergeability is unknown or conflicting;
- self-review evidence is missing or stale;
- a SEV-0 or SEV-1 finding exists;
- required migration, contract, security, or financial testing is incomplete.

Never resolve a gate failure by bypass, force, deletion, or weaker validation.

Ruleset bypass is not an ordinary acceleration mechanism. It may be considered
only for an emergency, a GitHub/CI service failure, or explicit Human approval,
and requires a separate bounded authorization and audit record. Codex must not
infer or silently exercise bypass authority.

## GOV-017 Trial

The Lane policy trial begins when GOV-017 is merged and ends at the earlier of
the first five subsequent Tasks or two weeks. Each Task records total elapsed
time, CI wait time, Check rerun count, Build count, Runtime Activation count,
and Human wait time. Metrics are evaluation evidence, not permission to weaken a
gate. Sensitive readback remains limited to the minimum needed for the Lane.

## Audit and Completion

Task completion means:

- the PR was squash merged by the GitHub App after the Merge Gate;
- `main` contains the squash result;
- Remote and local task branches and the worktree are cleaned;
- local `main` equals `origin/main`;
- Worklog and PR evidence distinguish implementation, checks, merge, deployment,
  release, and Production state.

Commercial release completion remains governed by the Release Gates.
