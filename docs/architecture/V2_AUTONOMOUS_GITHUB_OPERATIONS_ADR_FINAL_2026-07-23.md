# ADR: Autonomous GitHub Operations

- ADR ID: `V2-ADR-GITHUB-AUTONOMY-001`
- Status: **FINAL**
- Decision date: 2026-07-23

## Context

The original V2 governance required human GitHub Approval and merge for every
Platform pull request. The latest human decision delegates the GitHub task
lifecycle to Platform Codex and the `ideal-sol-oripa-codex` GitHub App while
retaining PRs, CI, scope control, secret/PII checks, immutable archive refs, and
immutable Stable Tags.

## Decision

Adopt autonomous Issue, branch, commit, push, PR, check inspection, self-review,
squash merge, branch cleanup, repository administration, workflow,
environment, deployment, release, and tag operations.

Do not require GitHub Approval or Code Owner Review as merge gates. Do not create
a synthetic self-approval. Store fixed-head machine-readable self-review
evidence instead.

## Merge Protocol

1. Bind every operation to a root-owned task policy.
2. Require a full base SHA, expected Remote SHA, and expected PR head SHA.
3. Validate changed paths before push and again from the GitHub PR.
4. Run local validation and inspect every available GitHub check.
5. Scan for secrets and PII without printing candidate values.
6. Post self-review evidence for exactly the current head.
7. Mark the PR ready.
8. Re-fetch the PR, checks, scope, evidence, and mergeability.
9. Refuse merge if the head changed or any gate is incomplete.
10. Squash merge, verify `main`, delete the merged task branch, synchronize local
    `main`, and remove the clean worktree and local branch.

## Ruleset Design

### Main and Release

Require PR, linear history, conversation resolution, no deletion, and no
non-fast-forward update. Require zero approvals and no Code Owner review.
Required checks are added only after GitHub has emitted them.

### Archive

No bypass. Reject updates, deletion, and force pushes.

### Stable Tags

Use two Rulesets because one bypass would otherwise permit both creation and
mutation:

- creation Ruleset: only the GitHub App may bypass creation restriction;
- immutability Ruleset: no bypass for update, deletion, or force update.

The protected-tag wrapper additionally requires a passing Release Gate and a
commit reachable from `main`.

## Bootstrap

Before GOV-009, merge requires all available local validation and GitHub checks.
The absence of the future five standard checks is recorded explicitly. GOV-009
must configure the five checks and remove Bootstrap behavior.

## Security Controls

- short-lived Installation Token per operation;
- fixed Repository and API host;
- root-owned non-symlink task policies;
- operation and payload allowlists;
- expected SHA optimistic locks;
- no arbitrary URL, repository, refspec, branch, or Git option;
- no token, JWT, ID, private key, config, or Authorization-header output;
- no direct `main` push or force push;
- no archive update;
- no Stable Tag update or deletion;
- no merge with failed/pending/skipped required validation;
- no stale self-review evidence.

## Consequences

### Positive

- GitHub operations are reproducible and auditable.
- Task throughput does not depend on human PR interaction.
- Scope and validation become machine-enforced.
- Branch and tag cleanup is consistent.

### Risk

- The GitHub App has significant repository authority.
- A wrapper defect could affect repository settings or refs.
- Bootstrap has fewer GitHub-hosted checks before GOV-009.

### Mitigation

- deny-by-default task policies;
- no Ruleset bypass for `main`, `release/**`, or Archive;
- split Stable Tag creation and immutability rules;
- fixed-head self-review and check revalidation;
- narrow operation-specific validation;
- audit comments and Worklog evidence;
- fail closed on ambiguity.

## Human Boundary

Human GitHub PR operation is not required. Human authority remains required for
initial commercial Production GO and unresolved legal, accounting, and provider
decisions.

## Superseded Decisions

This ADR supersedes:

- human-only PR approval and merge;
- mandatory Code Owner review as a merge gate;
- prohibition on Codex merge and repository administration;
- permanently disabled auto-merge;
- the decision not to use dedicated review automation.
