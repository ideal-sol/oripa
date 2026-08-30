# Oripa V2 Release Gates

- Document ID: `V2-RELEASE-GATES-001`
- Status: **FINAL / Architecture Baseline 2.0 / Revision 2**
- Confirmed: 2026-08-30
- Operating model: **Engineering Safety Strict / Git Lite**

## Supersession

This document supersedes `V2_RELEASE_GATES_FINAL_REV1_2026-07-23.md`. Revision 1
and the 2026-07-22 document are historical evidence only. The latest explicit
human decision remains the highest authority.

## State Separation

Never conflate specification approval, implementation, local validation, CI,
migration creation, migration application, commit, push, merge, Build, artifact
publication, Runtime Activation, Staging deployment, Production deployment,
commercial Production GO, or post-release verification.

Use `NOT_STARTED`, `IN_PROGRESS`, `PASS`, `FAIL`, `WAIVED`, or
`NOT_APPLICABLE`. Evidence is required for `PASS`.

## Change Merge Gate

Platform Codex may squash merge a Change PR when:

- one Change ID, one task branch, and one PR are present;
- the PR targets protected `main` and base/current head are fixed full SHAs;
- the current head equals the reviewed head;
- declared changed files equal the actual Git diff;
- an exact scope policy, when selected, passes;
- applicable local validation and all five Required Checks pass;
- no Required Check is skipped, cancelled, pending, or bypassed;
- secret/PII and dependency/generated-file review passes;
- applicable migration, contract, authorization, security, financial,
  concurrency, and rollback tests pass;
- lane-valid machine-readable self-review evidence passes for the exact head;
- there is no merge conflict or unexpected protected-base movement;
- SEV-0 and SEV-1 counts are zero;
- squash is the selected merge method.

GitHub Approval and Code Owner Review are not merge requirements. Issue,
dedicated Worktree, Task Policy, exact `allowed_paths`, and Source Lock are
conditional controls under the canonical Governance. When selected, they must
pass and be closed or released; when not selected, their absence does not block
merge. Migration Allocation Lock remains mandatory for migration work.

Every Lane still requires successful `policy-gate`, `quality-gate`,
`security-gate`, `integration-gate`, and `ci-gate` contexts. Strict retains the
complete security, integration, and freshness requirements.

## Self-review Gate

Self-review evidence includes Change ID, Risk, Lane, Activation mode, base and
reviewed head SHAs, actual changed paths, optional scope-control result, local
and GitHub checks, secret/PII result, domain and migration impact, findings by
severity, UTC creation time, and merge recommendation.

Evidence expires when the PR head changes. Lite and Standard have no time-only
expiry while the final head remains unchanged and all evidence fields pass.
Strict also satisfies the configured freshness window. Evidence matches a Task
Policy when one is used.

## Reviewed Tree Activation Gate

A pre-merge application Build is eligible for post-merge Activation only after:

`Final PR Head -> Required Checks PASS -> Fresh self-review PASS -> Build -> Squash Merge -> Head tree == Merge tree -> content diff 0`

Evidence must record Final Head SHA/Tree SHA, Merge SHA/Tree SHA, tree equality,
content diff zero, immutable image digest or image ID, and OCI revision equal to
the Final Head SHA. The image is not Activation Authority before merge.

Any head/base drift, stale review, failed check, image/OCI mismatch, tree
mismatch, or non-zero content diff blocks Migration and Activation and preserves
the prior Runtime. Merge-first Build from exact protected `main` remains valid.
Documentation/policy-only Changes record Build and Activation count zero and
image evidence as `NOT_APPLICABLE`.

## Defect Gate

- SEV-0: no merge, release, or deployment.
- SEV-1: no merge, release, or deployment.
- SEV-2: merge requires bounded impact, mitigation, and a durable tracked
  disposition; create an Issue when ongoing follow-up is required.
- SEV-3: merge may proceed with a tracked limitation.

Security Critical/High findings, financial inconsistency, authorization bypass,
and unrecoverable migration failures cannot be waived autonomously.

## Feature Completion

A feature is complete only when applicable specification, Backend, Frontend,
contract, authorization, audit, migration, Unit, Feature, integration, E2E, and
operational evidence are present. A merged partial Change is not automatically a
complete feature.

## Platform Release Gate

Before creating a Platform Stable Tag or Release:

- all Required Checks pass on the exact release commit;
- package and contract compatibility checks pass;
- migration forward and rollback strategy is documented and tested as required;
- artifacts are built once and identified by immutable digest;
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
Production final GO remains a Human decision.

## Commercial Production Gate

The final GO for initial commercial Production requires Human confirmation of
legal readiness, accounting treatment, provider readiness, external security
requirements, operational ownership, and regulated disclosures or commitments.
Codex may assemble evidence but may not make this GO decision.

## Hotfix Gate

Hotfixes use one Change, one branch, and one PR with fixed-head self-review,
all Required Checks, no direct `main` push, and squash merge. Add an Issue,
dedicated Worktree, Task Policy, exact scope policy, or Source Lock only when the
hotfix risk actually requires it. Urgency never authorizes force push, Archive
change, Stable Tag mutation, security weakening, or weaker tests.

## Merge, Release, and Cleanup Evidence

Record:

- Change ID, optional Issue URL, branch, and worktree mode;
- Task Policy, exact scope policy, Source Lock, and Migration Allocation Lock
  disposition;
- base, reviewed head, head tree, squash commit, and merge tree SHAs;
- Required Check names and conclusions;
- self-review evidence URL or comment identifier;
- changed paths and scope result;
- merge method and actor;
- image digest and OCI revision when Built;
- Runtime, Migration, Artifact, Staging, and Production state separately;
- Remote/local branch cleanup and local `main` synchronization.

## Prohibited Gate Handling

- no Required Check or Ruleset bypass;
- no skipped test reported as PASS;
- no assertion or security-control weakening for a green run;
- no stale evidence reuse;
- no force push or direct `main` push;
- no Archive update or Stable Tag movement/deletion;
- no secret/PII disclosure;
- no autonomous legal, accounting, provider, or commercial Production GO.
