# Codex Repository Access Baseline

## Status

- Status: CURRENT
- Verified: 2026-07-23
- Applies to: Platform Codex and future Site Codex provisioning

## Purpose

This baseline separates GitHub repository access for Platform and Site Codex
environments. It documents verified controls separately from future
requirements and approved trust exceptions.

## Current Platform Control

- Platform Codex uses the `ideal-sol-oripa-codex` GitHub App.
- The Installation uses selected-repository access.
- The only installed repository visible to a newly issued Installation token
  is `ideal-sol/oripa`.
- Repository administration permission applies only within that selected
  repository scope.
- Direct pushes to `main`, force pushes, Archive updates, and Stable Tag
  updates or deletions remain prohibited.
- Changes use one Issue, branch, worktree, PR, fixed-head self-review, checks,
  Squash Merge, cleanup, and local-main synchronization.
- Stable Tags may only be created through an approved Release Gate.

The machine-readable current profile is
[platform-access-profile.json](platform-access-profile.json).

## Root Trust Exception

Platform Codex continues to run as `root`. Human approval permits this process
to reach the GitHub App private key through the external token broker.

This exception means:

- OS user isolation is **not implemented**;
- filesystem isolation from the GitHub App private key is **not implemented**;
- repository access separation is enforced by GitHub App Installation scope
  and task-policy wrappers, not by an unprivileged OS account; and
- private key, configuration values, JWTs, tokens, and authorization headers
  must never be displayed, logged, committed, or written to the Worklog.

Do not describe the root trust model as complete environment isolation.

## Production Boundary

Platform Codex has no approved access to Production secrets, customer PII,
Production databases, or Production networks under this baseline. Repository
administration does not grant commercial Production GO or direct Production
data access.

## Future Site Gate

No Site Repository, Site GitHub App, or Site Codex environment is created by
GOV-006. Before a Site Codex is activated, all controls in
[site-access-profile.example.json](site-access-profile.example.json) must be
instantiated and verified.

Required boundaries:

- one Site, one repository;
- one Site, one Codex environment;
- one Site, one GitHub App or dedicated credential boundary;
- selected-repository Installation scope;
- no read or write access to another customer Site;
- no write access to the Platform Repository;
- Platform changes only through a Platform Change Request; and
- no sharing of Site secrets or deployment credentials.

See [repository-access-matrix.md](repository-access-matrix.md) for the
normative access matrix.

## Verification Procedure

1. Issue a new Installation token without using a cached token.
2. Read Installation metadata without displaying credential values or IDs.
3. Require repository selection to be `selected`.
4. Require the repository list to equal `["ideal-sol/oripa"]`.
5. Require repository-scoped administration permission for the Platform task.
6. Do not probe another repository with a write operation.
7. Stop the task if any unexpected repository is visible.

GOV-006 verified this procedure with one selected repository and no unexpected
access.
