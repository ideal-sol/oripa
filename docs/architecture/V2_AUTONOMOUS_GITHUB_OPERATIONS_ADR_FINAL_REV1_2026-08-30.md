# ADR: Autonomous GitHub Operations — Engineering Safety Strict / Git Lite

- ADR ID: `V2-ADR-GITHUB-AUTONOMY-001`
- Status: **FINAL / Revision 1**
- Decision date: 2026-08-30

## Supersession

This ADR supersedes
`V2_AUTONOMOUS_GITHUB_OPERATIONS_ADR_FINAL_2026-07-23.md`, which remains
historical evidence. The latest explicit human decision remains highest.

## Context

The earlier autonomous lifecycle preserved safety but made an Issue, dedicated
Worktree, root-owned Task Policy, and exact path allowlist mandatory for every
Change. That ceremony was applied even when it did not reduce risk and could
create a repeating `Task -> GOV -> Task -> GOV` chain for small CI blockers.

Protected `main`, Required Checks, fixed-head self-review, migration allocation,
security and domain safeguards, and Human Production checkpoints remain
necessary.

## Decision

Adopt **Engineering Safety Strict / Git Lite**:

- default to `1 Change = 1 Branch = 1 PR`;
- create an Issue only when coordination or a durable follow-up record needs it;
- create a dedicated Worktree only for parallelism or isolation;
- require a Task Policy or exact `allowed_paths` only for material scope risk,
  privileged operations, or explicit Human direction;
- acquire a Source Lock only for real collision risk;
- retain Migration Allocation Lock for every migration reservation or creation;
- retain protected `main`, PR-only merge, Required Checks, and exact-head
  self-review;
- retain the configured freshness window for Strict Changes;
- allow a directly necessary small CI/Git blocker in the same PR when it does
  not weaken Security, permissions, protections, or tests;
- keep Production GO and unresolved legal, accounting, or provider decisions
  Human-only.

Optional controls become mandatory for a Change once selected or required by
its risk. Removing universal ceremony does not authorize bypass or incomplete
evidence.

## Merge Protocol

1. Fix the Change ID, base SHA, Risk, Lane, Activation mode, and actual scope.
2. Select only the conditional controls justified by risk.
3. Create one task branch and one PR targeting protected `main`.
4. Keep the declared changed-file inventory equal to the actual Git diff and
   enforce exact paths when an exact scope policy is selected.
5. Run applicable local validation and all Required Checks.
6. Scan for secrets and PII without printing candidate values.
7. Create machine-readable self-review evidence for exactly the final head.
8. Re-fetch PR identity, checks, scope, evidence, mergeability, and base state.
9. Refuse merge if the head/base changed or any gate is incomplete.
10. Squash merge, verify protected `main`, delete the task branch, synchronize
    local `main`, and remove only Worktrees or locks that were actually created.

## Reviewed Tree Authority

An image built from the final reviewed PR head may be used after merge when the
ordered gate is:

`Final PR Head -> Required Checks PASS -> Fresh self-review PASS -> Build -> Squash Merge -> Head tree == Merge tree -> content diff 0`

Evidence records Final Head SHA/Tree SHA, Merge SHA/Tree SHA, equality, content
diff zero, immutable image digest, and OCI revision equal to Final Head SHA. The
image cannot activate before merge. Any mismatch blocks Migration and Runtime
Activation and preserves the prior Runtime.

## Security Controls

- short-lived Installation Token per operation;
- fixed Repository and API host;
- operation-specific payload, ref, and SHA validation;
- root-owned non-symlink policy files when a Task Policy is used;
- no arbitrary repository, refspec, Git option, or unapproved URL;
- no token, JWT, ID, private key, config, or Authorization-header output;
- no direct `main` push or force push;
- no Archive update or Stable Tag mutation;
- no merge with failed, pending, skipped, or bypassed Required Checks;
- no stale self-review evidence;
- no Security, permission, or assertion weakening to resolve a blocker.

## Consequences

### Positive

- Low-value ceremony no longer blocks straightforward Changes.
- Scope and validation remain visible through the exact PR diff.
- High-risk Changes keep stronger optional controls where they add safety.
- Small necessary CI/Git blockers can be resolved without a governance loop.
- Reviewed Tree Authority avoids a redundant Build while proving merged byte
  equality.

### Risk

- A Change may omit a control that its actual risk required.
- A broad blocker could be misclassified as a small in-scope fix.
- A tree or image identity error could activate unmerged bytes.

### Mitigation

- missing/invalid Lane and unknown paths fail closed;
- Codex may escalate but never lower Lane or Risk;
- all five Required Checks and exact-head self-review remain mandatory;
- optional controls become binding when selected;
- blocker scope, tests, and final-head reruns are recorded;
- tree equality, content diff zero, image digest, and OCI revision are explicit
  Activation gates;
- ambiguity stops merge or Activation.

## Human Boundary

Human GitHub Approval and Code Owner Review are not required. Human authority
remains required for initial commercial Production GO and unresolved legal,
accounting, security-policy, or provider decisions.
