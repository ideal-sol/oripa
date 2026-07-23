# GOV-005R1 Autonomous Repository Ruleset Baseline

## Status

This is the active baseline for `ideal-sol/oripa`. It supersedes the
human-approval proposal committed by GOV-005.

Platform Codex applies and verifies these settings through a fixed,
policy-constrained GitHub App wrapper. Application does not authorize bypassing
CI, direct push to `main`, force push, Archive mutation, or Stable Tag mutation.

Reference: [GitHub REST API endpoints for repository rules](https://docs.github.com/en/rest/repos/rules)

## Governance Decision

- GitHub Approval count: zero
- Code Owner review: not required
- latest-push approval: not required
- pull request: required
- current-head self-review evidence: required
- available required CI: required
- scope and secret/PII validation: required
- squash merge: required
- direct `main` push: prohibited
- force push: prohibited
- branch deletion for protected refs: prohibited

## Rulesets

| Name | Target | Bypass | Purpose |
| --- | --- | --- | --- |
| `main-protection` | `main` | None | PR-only linear merge, conversations resolved, no deletion/force push |
| `release-branch-protection` | `release/**` | None | Same branch controls as `main` |
| `v1-archive-lock` | `archive/v1-current` | None | No update, deletion, or force push |
| `stable-tag-immutability` | Stable tag patterns | None | No update, deletion, or force update |
| `stable-tag-creation` | Stable tag patterns | GitHub App, always | Only the gated GitHub App may create a new Stable Tag |

The GitHub App is not a bypass actor for `main`, `release/**`, Archive, or
Stable Tag immutability.

## Main and Release

Use [main-ruleset.json](main-ruleset.json) and
[release-ruleset.json](release-ruleset.json).

- enforcement: Active
- require a pull request before merging
- allowed merge method: squash
- required approvals: 0
- dismiss stale approvals: off
- Code Owner review: off
- approval of most recent push: off
- conversation resolution: on
- linear history: on
- deletion: restricted
- non-fast-forward update: blocked
- bypass: none

No check name is configured until GitHub has emitted it. Before GOV-009,
Platform Codex requires all available local validation and all checks emitted
for the exact PR head. After GOV-009, update both Rulesets to require:

- `policy-gate`
- `quality-gate`
- `security-gate`
- `integration-gate`
- `ci-gate`

GOV-009 removes the Bootstrap exception.

## V1 Archive

Use [archive-v1-ruleset.json](archive-v1-ruleset.json).

- no bypass
- restrict updates
- restrict deletions
- block force pushes

The REST Ruleset schema represents the lock with the `update` rule. Do not
invent a `lock_branch` rule type.

## Stable Tags

Use [stable-tags-ruleset.json](stable-tags-ruleset.json) for immutability and
[stable-tag-creation-ruleset.json](stable-tag-creation-ruleset.json) for
creation.

Patterns:

- `platform-v*`
- `storefront-client-v*`
- `site-schema-v*`
- `storefront-testkit-v*`
- `site-template-v*`
- `*-site-v*`
- `v1-before-productization-*`

The split is mandatory. A single Ruleset with the GitHub App in its bypass list
would also let the App bypass update/deletion protection.

The committed creation JSON uses `actor_id: 0` as a non-submit placeholder.
The administration wrapper replaces it in memory with the App identity while
applying the Ruleset and never prints or records that ID.

The protected-tag wrapper requires:

- a policy-allowed tag pattern;
- a full source commit SHA reachable from `main`;
- a passing Release Gate evidence file for that exact commit;
- no existing tag with that name;
- no direct update or delete operation.

## Repository Settings

The GitHub App applies:

| Setting | Value |
| --- | --- |
| Allow squash merging | On |
| Allow merge commits | Off |
| Allow rebase merging | Off |
| Allow auto-merge | On |
| Automatically delete head branches | On |

Autonomous merge still uses the fixed-head wrapper after all gates pass.

## Application Procedure

1. Confirm the task policy, expected base SHA, and clean main.
2. Read current settings and Rulesets through authenticated API calls.
3. Validate every JSON file and replace only the approved internal App actor
   placeholder.
4. Create or update Rulesets by exact name; do not delete an unknown Ruleset.
5. Update only the five approved Repository General settings.
6. Read back every Rule, target, enforcement state, bypass actor type/mode, and
   General setting.
7. Redact actor IDs and all authentication material from output and evidence.
8. Refuse any mismatch rather than weakening protection.

## Merge Verification

Before each merge:

- PR Task ID, branch, base, and head match policy;
- current head equals expected reviewed head;
- changed files are in allowed paths;
- every available required check passes;
- local required validation passes;
- no secret/PII candidate remains;
- applicable migration/contract/security tests pass;
- fixed-head self-review evidence is fresh;
- SEV-0 and SEV-1 counts are zero;
- conversations are resolved;
- mergeability is clean;
- merge method is squash.

## Emergency Handling

Do not bypass Rulesets or checks. If governance configuration prevents safe
recovery:

1. stop automated merge and release operations;
2. preserve redacted before-state evidence;
3. create a dedicated recovery Issue and policy;
4. change only the minimum affected configuration;
5. restore and verify the baseline immediately.

Archive and Stable Tag immutability cannot be waived by Codex.

## Rollback

- Repository settings may be restored to a prior recorded safe value through a
  dedicated task.
- A newly created incorrect Ruleset may be disabled, not silently deleted,
  pending investigation.
- Never rollback by force push, direct `main` push, Archive update, or Stable Tag
  movement/deletion.
- Record before/after JSON and rule identifiers without IDs, tokens, or secrets.

## Audit Evidence

Record:

- Task, Issue, PR, branch, base, and head SHA;
- redacted before/after Ruleset summaries;
- Repository setting results;
- local and GitHub checks;
- self-review evidence identifier;
- squash commit and merge actor;
- Remote/local branch cleanup and local `main` synchronization;
- Bootstrap or post-GOV-009 mode.
