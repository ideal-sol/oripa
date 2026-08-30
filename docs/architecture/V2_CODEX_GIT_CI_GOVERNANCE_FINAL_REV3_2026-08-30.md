# Oripa V2 Codex, Git, and CI Governance

- Document ID: `V2-CODEX-GIT-CI-GOVERNANCE-001`
- Status: **FINAL / Architecture Baseline 3.0 / Revision 3**
- Confirmed: 2026-08-30
- Repository: `ideal-sol/oripa`
- Operating model: **Engineering Safety Strict / Git Lite**

## Supersession

This document supersedes
`V2_CODEX_GIT_CI_GOVERNANCE_FINAL_REV2_2026-07-23.md`. Revision 2 and the
2026-07-22 document are historical evidence only. Their universal Issue,
dedicated Worktree, Task Policy, exact `allowed_paths`, and related Git ceremony
requirements do not govern current work.

The latest explicit human decision remains the highest authority.

## Purpose

Platform Codex and the `ideal-sol-oripa-codex` GitHub App operate the complete
GitHub change lifecycle while keeping engineering and domain safety strict.
Git Lite removes ceremony that does not reduce risk. It does not weaken branch
protection, CI, self-review, migration safety, security boundaries, or reserved
Human decisions.

## Canonical Change Unit

The default lifecycle is:

`1 Change = 1 Branch = 1 PR`

“Task” may be used as an operational synonym for “Change.” A Change has one
Change or Task ID, one fixed base authority, one declared Risk, one Lane, one
Application Runtime Activation mode, and one final PR head.

The following are conditional controls, not universal prerequisites:

- **Issue:** create one when coordination, a durable defect/follow-up record, a
  pending Human decision, cross-Change dependency, or post-merge operational
  evidence needs it. Otherwise record `none` in the PR.
- **Dedicated Worktree:** create one when parallel work, isolation, or protection
  of another active worktree requires it. Sequential work may use a clean task
  branch in the normal worktree.
- **Task Policy / exact `allowed_paths`:** use one when Human direction, a
  privileged administrative or release operation, ambiguous scope, or a
  material scope-control risk requires a deny-by-default envelope. Ordinary
  Changes may rely on the exact Git diff and declared changed-file inventory.
- **Source Lock:** acquire one only when concurrent work has a real semantic or
  file-level collision risk. Do not acquire it by default.
- **Migration Allocation Lock:** acquire it before reserving or creating any
  migration identifier. Hold it through the migration Change closeout and
  release it only after the canonical handoff or cleanup. This control is not
  optional for migration work.

When a conditional control is used, its identity and disposition are recorded
in the PR and Worklog. Absence of an optional control is not a merge failure.

## Change Lifecycle

1. Verify the Repository, clean base, live protected `main`, Remote refs, Change
   ID, Risk, Lane, Activation mode, scope, and required validation.
2. Decide whether an Issue, dedicated Worktree, Task Policy, exact
   `allowed_paths`, Source Lock, or Migration Allocation Lock is actually
   required.
3. Create one task branch from the fixed full SHA of latest protected `main`.
4. Implement only the authorized Change and preserve all out-of-scope systems.
5. Keep the PR changed-file inventory equal to the actual Git diff. If an exact
   path policy is used, the diff must also remain inside that policy.
6. Run applicable local validation and all Required Checks.
7. Fix the final PR head SHA and produce lane-valid machine-readable self-review
   evidence for exactly that head.
8. Re-read the PR, changed paths, checks, evidence, mergeability, and protected
   base immediately before merge.
9. Squash merge through the approved GitHub App path without bypass.
10. Verify protected `main`, delete the merged task branch, synchronize local
    `main`, and remove a dedicated Worktree only when one was created.
11. Record merge, deployment, migration, artifact, Production, and cleanup state
    separately.

Do not switch or repurpose a worktree that contains another active Change. Stop
on an unrecognized local change or unexpected Remote movement.

## Risk-based Change Lanes

Every Change and PR declares exactly one Lane and one Application Runtime
Activation mode. A Task Policy or Issue, when used, declares the same values.
The only Lane values are `Lite Maintenance`, `Standard Change`, and
`Strict Change`. The only Activation values are `none`, `deferred`, and
`immediate`.

Lane order is `Lite Maintenance` to `Standard Change` to `Strict Change`.
Platform Codex may escalate but never downgrade. Missing or invalid metadata,
an unknown changed path, or a changed path whose minimum Lane exceeds the
declared Lane fails closed.

### Lite Maintenance

Lite is limited to low-risk presentation maintenance: wording, CSS, layout,
icons, display order, filter defaults, and light Admin UI that uses an existing
API or a value already accepted by the Backend. Lite requires changed-path
validation, focused verification, added-diff secret scanning, final-head
self-review, and targeted UI confirmation when UI changes.

Lite must not cover workflow/CI, dependencies/lockfiles, schema/migrations,
Auth/Session/CSRF/MFA/email-verification core, Payment, Coin/Point,
Draw/Inventory, secrets/credentials, Production, deployment/network, security
boundaries, or immutable history.

### Standard Change

Standard covers ordinary Admin UI logic, non-destructive API changes, additive
normal contract changes, and bounded Shared Preview data maintenance that meets
the canonical safety criteria. It requires affected-domain tests and normal CI.

### Strict Change

Production, workflow/CI, schema migration, Auth/Session/CSRF/MFA, Payment,
Coin/Point, Draw/Inventory, secrets/credentials, security boundaries, immutable
history, destructive contracts, and ambiguous or non-rollbackable data mutation
remain Strict. Strict retains all current Required Checks, applicable security
and integration requirements, and the configured self-review freshness window.

Required Check context names remain `policy-gate`, `quality-gate`,
`security-gate`, `integration-gate`, and `ci-gate`. They are not deleted,
skipped, or bypassed to accelerate a Lane.

## Engineering Blockers in the Same PR

A small CI or Git blocker may be fixed in the same PR when it is directly
required for the original Change to pass, is narrowly bounded, and does not
weaken Security, permissions, branch protection, required checks, test
assertions, secret handling, or domain safeguards.

The PR must disclose the blocker, include focused regression coverage, escalate
Risk or Lane when required, and rerun all head-dependent checks and self-review
on the new final head. A broad redesign, unrelated defect, or security boundary
change remains a separate Change.

Do not create a repeating `Task -> GOV -> Task -> GOV` chain for a small safe
blocker that can be resolved and reviewed within the original PR.

## Merge Gate

Every autonomous merge requires:

- one Change ID, one task branch, and one PR targeting protected `main`;
- fixed full base and current head SHAs;
- the declared changed-file inventory equal to the actual Git diff;
- any selected exact scope policy satisfied;
- every Required Check successful without skip or bypass;
- every applicable local validation successful;
- no secret, credential, private key, Production data, or PII candidate;
- applicable migration, contract, authorization, security, financial,
  concurrency, and rollback tests successful;
- exact-head machine-readable self-review evidence;
- zero open SEV-0 and SEV-1 findings;
- no merge conflict or unexpected protected-base movement;
- squash merge selected.

An Issue, dedicated Worktree, Task Policy, exact `allowed_paths`, or Source Lock
is a merge requirement only when the Change explicitly selected that control.
If the head changes, all head-dependent checks, Build authority, and self-review
evidence expire.

## Self-review Evidence

Every Lane requires evidence for exactly the final PR head. Lite and Standard
evidence remains valid while the reviewed final head is unchanged and all
evidence fields pass. Strict evidence also satisfies the configured freshness
window. A changed head invalidates evidence in every Lane.

Evidence records the Change ID, Risk, Lane, Activation mode, base and reviewed
head SHAs, actual changed paths, optional scope-control result, local and GitHub
checks, secret/PII result, domain and migration impact, findings by severity,
UTC creation time, and merge recommendation. It matches a Task Policy when one
is used; it does not require a Task Policy to exist as a governance principle.

## Reviewed Tree Authority

An application image built from the final reviewed PR head may become Runtime
Activation Authority only in this exact order:

`Final PR Head -> Required Checks PASS -> Fresh self-review PASS -> Build -> Squash Merge -> Head tree == Merge tree -> content diff 0`

The Build input and OCI revision remain the exact final PR head SHA. The image
does not authorize Activation before merge. After squash merge, the PR head tree
and merge commit tree must be identical and a direct content diff must be zero.
The common tree then proves that the reviewed and built bytes are the merged
bytes.

Required evidence for a Reviewed Tree Activation is:

- Final PR Head SHA and Tree SHA;
- Squash Merge SHA and Tree SHA;
- explicit tree equality result;
- explicit content diff result of zero;
- immutable image digest or image ID;
- OCI revision equal to the Final PR Head SHA.

Any changed head, protected-base drift, failed or stale review, image/OCI
mismatch, tree mismatch, or non-zero content diff prohibits Migration and
Runtime Activation with that image. The prior Runtime remains authoritative.

Merge-first Builds from exact protected `main` remain valid. Reviewed Tree
Authority is an additional byte-equivalent path, not permission to build on a
Production server or activate before merge. For documentation/policy-only
Changes with Build count zero, image digest and OCI revision are
`NOT_APPLICABLE`.

## Repository and GitHub Safety

- `main` and `release/**` remain PR-only, linear, protected from deletion,
  direct push, and force push.
- Required approvals remain zero; Code Owner and latest-push approval remain
  off; conversation resolution remains on.
- The GitHub App is not a bypass actor for `main`, release branches, Archive, or
  Stable Tag immutability.
- `archive/v1-current` remains immutable.
- Stable Tags may be created only after the applicable Release Gate and may
  never be moved or deleted.
- Installation credentials remain short-lived, least-privilege, redacted, and
  absent from Repository, Issue, PR, Worklog, command arguments, and Git config.

Ruleset bypass is prohibited for ordinary acceleration. It is limited to an
emergency, GitHub/CI outage, or explicit Human approval and requires separate
bounded evidence. Codex must never infer bypass authority.

## Domain and Production Safety

Git Lite does not relax Site isolation, Auth, Session, CSRF, MFA, Payment,
Coin/Point, Draw, Inventory, Webhook, provider, migration, transaction,
concurrency, rollback, audit, secret, or PII controls. Production migration,
Production data mutation, and initial commercial Production GO remain governed
by their dedicated gates and Human checkpoints.

## Completion and Audit

Completion means the PR was gate-compliantly squash merged, protected `main`
contains the result, the Remote and local task branch are cleaned, any dedicated
Worktree and selected locks are cleaned, and local `main` equals Remote `main`.

Record Issue or `none`, worktree mode, Task Policy or `none`, Source Lock or
`none`, Migration Allocation Lock disposition, base/final head/merge SHAs,
changed paths, checks, self-review, merge actor, cleanup, Build/Activation counts,
and Runtime/Migration/Artifact/Production state separately.
