# GOV-005 Repository Ruleset Baseline

## Status

This document is a human-reviewed configuration proposal for
`ideal-sol/oripa`. Codex did not apply a ruleset or change a repository
setting. A human repository administrator must apply and verify every setting
in GitHub.

The JSON files in this directory follow the current repository ruleset REST
shape documented by GitHub, but they are review artifacts rather than payloads
approved for direct API submission. In particular, `actor_id: 0` is an explicit
placeholder for the Repository Administrator role and must not be submitted.

Reference: [GitHub REST API endpoints for repository rules](https://docs.github.com/en/rest/repos/rules)

## Purpose

- Require human-reviewed pull requests for `main` and `release/**`.
- Prevent force pushes and deletion of protected branches.
- Make the V1 archive immutable.
- Prevent unauthorized creation, movement, or deletion of stable tags.
- Establish the baseline before required CI checks are enabled in GOV-008 and
  GOV-009.

## Before

Read-only audit performed on 2026-07-23 UTC:

| Setting | Observed state | Evidence limitation |
| --- | --- | --- |
| Repository visibility | Public | Repository API |
| Default branch | `main` | Repository API |
| Repository rulesets | None | Rulesets list returned an empty array |
| `main` protection | Not protected | Branch metadata; detailed endpoint returned 403 |
| `archive/v1-current` protection | Not protected | Branch metadata; detailed endpoint returned 403 |
| Squash merge | Enabled | Repository API |
| Merge commits | Enabled | Repository API |
| Rebase merge | Enabled | Repository API |
| Auto merge | Disabled | Repository API |
| Delete head branches after merge | Disabled | Repository API |
| CODEOWNERS | Present | `.github/CODEOWNERS` |
| GOV-004 remote branch | Present | Remote ref audit |

The 403 responses mean the detailed classic branch-protection configuration is
`UNKNOWN`; they do not authorize inferring hidden settings. The effective
branch metadata reports both audited branches as unprotected.

## Proposed After State

| Ruleset | Target | Bypass | Main controls |
| --- | --- | --- | --- |
| `main-protection` | `main` | Repository administrators, pull requests only | PR, one approval, CODEOWNERS, stale dismissal, latest-push approval, resolved conversations, linear history, no deletion or force push |
| `release-branch-protection` | `release/**` | Repository administrators, pull requests only | Same controls as `main` |
| `v1-archive-lock` | `archive/v1-current` | None | No updates, deletion, or force push |
| `stable-tag-protection` | Stable tag patterns | Repository administrators, always | Only bypass actors may create, update, or delete; no force update |

Do not add the GitHub App, Codex, or GitHub Actions to any bypass list.

## Main Ruleset

Use [main-ruleset.json](main-ruleset.json) as the review artifact.

- Name: `main-protection`
- Target: branch `main`
- Enforcement: Active
- Bypass: Repository administrators, for pull requests only
- Restrict deletions
- Require linear history
- Require a pull request before merging
- Require one approval
- Dismiss stale approvals on new reviewable pushes
- Require review from Code Owners
- Require approval of the most recent reviewable push
- Require conversation resolution
- Block force pushes

Do not enable required status checks, strict/up-to-date branches, merge queue,
required deployments, or signed commits in GOV-005.

## Release Ruleset

Use [release-ruleset.json](release-ruleset.json) as the review artifact.

- Name: `release-branch-protection`
- Target: `release/**`
- Enforcement, bypass, and rules: same as `main-protection`
- Do not add the GitHub App to bypass

## V1 Archive Ruleset

Use [archive-v1-ruleset.json](archive-v1-ruleset.json) as the review artifact.

- Name: `v1-archive-lock`
- Target: `archive/v1-current`
- Enforcement: Active
- Bypass: none
- Restrict updates
- Restrict deletions
- Block force pushes

The current repository ruleset REST schema has an `update` rule, not a separate
`lock_branch` rule type. This proposal implements the requested archive lock as
`Restrict updates` with no bypass, together with deletion and non-fast-forward
restrictions. Do not invent or submit a `lock_branch` rule type.

## Stable Tag Ruleset

Use [stable-tags-ruleset.json](stable-tags-ruleset.json) as the review artifact.

- Name: `stable-tag-protection`
- Enforcement: Active
- Bypass: Repository administrators, always
- Restrict creations, updates, and deletions
- Block force pushes
- Target patterns:
  - `platform-v*`
  - `storefront-client-v*`
  - `site-schema-v*`
  - `storefront-testkit-v*`
  - `site-template-v*`
  - `*-site-v*`
  - `v1-before-productization-*`

Only a human Repository Administrator may create matching tags until a later,
approved release identity is introduced.

## Required Status Checks

Required status checks are intentionally absent. Add them only after GOV-008
and GOV-009 have created and successfully executed these exact checks:

- `policy-gate`
- `quality-gate`
- `security-gate`
- `integration-gate`
- `ci-gate`

At that time, separately decide whether branches must be up to date before
merge. Do not configure a required check name before GitHub has observed it.

## General Repository Settings

A human must set:

| Pull request setting | Required value |
| --- | --- |
| Allow squash merging | On |
| Allow merge commits | Off |
| Allow rebase merging | Off |
| Allow auto-merge | Off |
| Automatically delete head branches | On |

If `docs/GOV-004-issue-pr-templates` remains after PR #8, delete it from the
merged PR page. Codex must not delete that remote branch in GOV-005.

## Human Application Procedure

1. Open `ideal-sol/oripa` in GitHub and confirm `main` still points to the
   GOV-004 squash commit recorded in the GOV-005 PR.
2. Open **Settings > Rules > Rulesets**.
3. Create each of the four rulesets above using the UI. Treat the JSON files as
   comparison aids, not upload-ready authorization.
4. For `main` and `release/**`, select Repository administrators as bypass and
   limit bypass to pull requests.
5. For the archive ruleset, configure no bypass actor and enable Restrict
   updates, Restrict deletions, and Block force pushes.
6. For the stable tag ruleset, allow only Repository administrators to bypass,
   with Always mode.
7. Confirm the GitHub App, Codex, and Actions are absent from all bypass lists.
8. Leave required status checks and every deferred rule disabled.
9. Apply the General Repository Settings table.
10. Delete the merged GOV-004 remote branch from PR #8 if it remains.

## Post-application Verification

Record screenshots or exported settings without credentials, actor IDs, or
tokens, then verify:

- Four active rulesets exist with the exact names and targets above.
- Direct non-bypass updates to `main` and `release/**` require a PR.
- One human approval and CODEOWNERS review are required.
- A new reviewable push dismisses stale approval and requires latest-push
  approval.
- Conversations must be resolved.
- Deletion and force push are blocked.
- The archive has no bypass actor and cannot be updated or deleted.
- Stable tags cannot be created, moved, or deleted by the GitHub App.
- Required status checks remain absent.
- Only squash merge is enabled, auto-merge is disabled, and merged head
  branches are automatically deleted.

Do not perform destructive test pushes against `main`, the archive, or stable
tags. Use GitHub's displayed rule configuration and later controlled task-branch
tests.

## Emergency Bypass

- Routine work never uses bypass.
- Main and release bypass is limited to a Repository Administrator acting
  through a pull request.
- Archive has no emergency bypass in this baseline.
- Stable tags allow Repository Administrators only so a human can perform an
  approved release operation.
- Every emergency use requires an Issue, reason, actor, timestamp, affected ref,
  verification, and follow-up review.
- Codex and the GitHub App never receive emergency bypass.

## Rollback

1. Preserve an audit record of the current settings and the reason for rollback.
2. Prefer disabling one affected ruleset temporarily instead of deleting it.
3. Do not relax archive or stable-tag rules without a separate explicit human
   decision.
4. Restore the approved settings as soon as the incident is resolved.
5. Re-run the post-application verification and record the final state.

Rollback does not authorize force pushes, tag movement, Archive changes, or
Production operations.

## Audit Checklist

- Human administrator and timestamp recorded
- Before and after ruleset exports or screenshots retained securely
- Exact target patterns reviewed
- Bypass actors and modes reviewed
- GitHub App absent from bypass
- Required checks still deferred
- General merge settings verified
- GOV-004 remote branch disposition recorded
- No secret, token, credential, or PII captured

## GitHub App Administration

The GitHub App intentionally has no `Administration` repository permission.
Ruleset creation requires repository Administration write access, and granting
that permission would allow Codex automation to change repository protection.
The App remains limited to approved Issue, task-branch push, and Draft PR
operations; a human applies this baseline.
