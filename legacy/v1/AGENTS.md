# V1 Legacy Preservation Rules

## Scope

These instructions apply to preserved V1 material under `legacy/v1/`. V1 is a
behavioral reference and evidence source, not the V2 architecture authority.

## Preservation

- Do not add new product features to V1.
- Do not move, delete, overwrite, or retarget the V1 Archive Branch or stable
  annotated tag.
- Do not alter or invalidate V1 Evidence Bundle files, checksums, schema evidence,
  migration evidence, or inventories.
- Do not include V1 code in a V2 Production image.
- Do not treat V1 implementation structure as the V2 source of truth.
- Do not combine a mechanical file move with behavior changes in one PR.

## Exceptions

- An emergency V1 correction requires an explicitly authorized hotfix Task,
  dedicated Issue, branch, worktree, tests, fixed-head self-review, and PR.
- Preserve before/after evidence and keep the V2 implementation separate.
- Never use a V1 hotfix to bypass V2 Release Gates or security policy.

## Frontend Reference

The V1 Frontend is preserved at `legacy/v1-frontend/` and follows its nearest
`AGENTS.md` in addition to this file. It remains outside V2 Apps, Packages,
Production Images, and Runtime Dependencies.

## Verification

- Confirm archive refs and evidence checksums remain unchanged for any approved
  preservation Task.
- Report all unexecuted runtime and Browser/E2E checks as not run.
- The Root autonomous merge lifecycle applies to an authorized hotfix, but no
  GitHub App or administrator may bypass the Archive Ruleset.
