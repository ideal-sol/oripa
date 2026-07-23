# Oripa V2 Release Gates

- Document ID: `V2-RELEASE-GATES-001`
- Status: **FINAL / Architecture Baseline 1.1 / Revision 1**
- Confirmed: 2026-07-23

## Supersession

This document supersedes `V2_RELEASE_GATES_FINAL_2026-07-22.md`. The earlier
document remains historical evidence. Its human-only PR merge and Stable Tag
creation rules are replaced by the fixed-head autonomous gates below.

The latest explicit human decision remains the highest authority.

## State Separation

Never conflate:

- specification approval;
- implementation;
- local validation;
- CI;
- migration creation;
- migration application;
- commit;
- push;
- merge;
- artifact publication;
- Staging deployment;
- Production deployment;
- commercial Production GO;
- post-release verification.

Use `NOT_STARTED`, `IN_PROGRESS`, `PASS`, `FAIL`, `WAIVED`, or
`NOT_APPLICABLE`. Evidence is required for `PASS`.

## Task Merge Gate

Platform Codex may squash merge a task PR when:

- Task ID, Issue, task branch, worktree, and PR are present;
- base and current head are fixed full SHAs;
- the current head matches the reviewed head;
- changed paths are within the task policy;
- local validation and every available required check pass;
- no required check is skipped, cancelled, pending, or bypassed;
- secret/PII and dependency/generated-file review pass;
- applicable migration, contract, authorization, security, financial,
  concurrency, and rollback tests pass;
- machine-readable self-review evidence is fresh for the current head;
- there is no merge conflict;
- SEV-0 count is zero;
- SEV-1 count is zero;
- squash is the selected merge method.

GitHub Approval and Code Owner Review are not Task Merge requirements.

## Bootstrap Gate

Until GOV-009 is merged:

- require all task-specific local validation;
- require all checks that GitHub currently emits;
- record missing standard checks as pending governance work, not PASS;
- do not configure nonexistent required-check names.

GOV-009 must remove this exception and require:

- `policy-gate`
- `quality-gate`
- `security-gate`
- `integration-gate`
- `ci-gate`

## Self-review Gate

Self-review evidence must include:

- evidence schema version;
- Task ID and Risk;
- base SHA and reviewed head SHA;
- changed paths;
- scope result;
- local and GitHub check result;
- secret/PII result;
- migration and contract impact;
- authorization, financial, draw, infrastructure, and release impact;
- findings grouped by severity;
- UTC creation time;
- merge recommendation.

Evidence expires if the PR head changes.

## Defect Gate

- SEV-0: no merge, release, or deployment.
- SEV-1: no merge, release, or deployment.
- SEV-2: may merge only with a tracked Issue, bounded impact, mitigation, and
  explicit policy disposition.
- SEV-3: may merge with a tracked limitation.

Security Critical/High, financial inconsistency, authorization bypass, and
unrecoverable migration failures cannot be waived autonomously.

## Feature Completion

A feature is complete only when applicable specification, Backend, Frontend,
contract, authorization, audit, migration, Unit, Feature, integration, E2E, and
operational evidence are present. A merged partial task is not automatically a
complete feature.

## Platform Release Gate

Before creating a Platform Stable Tag or Release:

- all required CI passes on the exact release commit;
- package and contract compatibility checks pass;
- migration forward and rollback strategy is documented and tested as required;
- artifacts are built once and identified by digest;
- SBOM and provenance/attestation requirements pass where configured;
- secret/PII and vulnerability gates pass;
- release manifest and rollback instructions exist;
- SEV-0 and SEV-1 counts are zero;
- self-review evidence is fixed to the release commit.

After this gate, Platform Codex may create a new protected Stable Tag and GitHub
Release. It may never move or delete that tag.

## Site Deployment Gate

For each independently deployed Site:

- exact Platform and Site versions are compatible;
- environment configuration and provider readiness are verified;
- backup and restore evidence is current;
- migration plan and rollback point are recorded;
- immutable image digests are used;
- Site-specific smoke, integration, and security checks pass;
- no credential or data is shared with another Site.

Codex may execute an approved deployment workflow. Initial commercial
Production final GO remains a human decision.

## Commercial Production Gate

The final GO for initial commercial Production requires human confirmation of:

- legal readiness;
- accounting treatment;
- payment/provider readiness;
- external security or penetration-test requirements;
- operational ownership and incident response;
- any regulated disclosure or customer commitment.

Codex may assemble evidence but may not make this GO decision.

## Hotfix Gate

Hotfixes still require an Issue, isolated branch/worktree/PR, fixed-head
self-review, available required CI, no direct `main` push, and squash merge.
Urgency does not authorize force push, archive change, stable-tag mutation, or
test weakening.

## Merge, Release, and Cleanup Evidence

Record:

- Issue and PR URLs;
- base, reviewed head, and squash commit full SHAs;
- check names and conclusions;
- self-review evidence URL or comment identifier;
- changed paths and scope result;
- merge method and actor;
- created tag and release identifiers when applicable;
- local/Remote branch cleanup;
- local `main` synchronization;
- deployment and Production state separately.

## Prohibited Gate Handling

- no required-check bypass;
- no skipped test reported as PASS;
- no assertion weakening for a green run;
- no stale evidence reuse;
- no force push;
- no direct `main` push;
- no Archive update;
- no Stable Tag movement or deletion;
- no secret/PII disclosure;
- no autonomous legal, accounting, provider, or commercial Production GO.
