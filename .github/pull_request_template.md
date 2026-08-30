## Task

- Task ID:
- Issue (`none` allowed):
- Risk:
- Lane:
- Application Runtime Activation:
- UI Verification:
- Codex role:
- Base SHA:
- Head branch:
- Base branch:
- Worktree mode (`current` / `dedicated`):
- Task Policy (`none` allowed):
- Source Lock (`none` allowed):
- Migration Allocation Lock (`not applicable` allowed):

## Summary

<!-- State the result produced by this PR. -->

## Specification sources

<!-- List finalized documents, ADRs and explicit human decisions. -->

## Scope

### Allowed paths

<!-- Optional exact scope control. Delete or leave empty when not used. -->

### Changed files

-

### Explicitly not changed

-

## Technical impact

- Application:
- API / OpenAPI:
- Database / migration:
- Authentication / authorization:
- Point / payment / draw:
- Infrastructure / deployment:
- Package / compatibility:

## Migration state

- Migration created:
- Migration applied locally:
- Migration applied to staging:
- Migration applied to production:

## Verification performed

-

## Verification not performed

-

## GitHub checks

- Expected head SHA:
- Available checks:
- Required checks:
- Bootstrap mode:
- Pending / failed / skipped checks:

## Self-review evidence

- Evidence schema:
- Reviewed head SHA:
- Scope result:
- Secret / PII result:
- SEV-0 findings:
- SEV-1 findings:
- Evidence URL / comment:

## Security and privacy

- [ ] No secrets, credentials, tokens or private keys are included.
- [ ] No production data or personal information is included.
- [ ] Authorization and site-isolation boundaries were reviewed where relevant.
- [ ] Generated files and dependencies were reviewed where relevant.

## Deployment and rollback

- Deployment required:
- Rollback procedure:
- Production approval required:

## Trial metrics

- Task elapsed time:
- CI wait time:
- Checks rerun count:
- Build count:
- Runtime Activation count:
- Human wait time:

## Known risks and limitations

-

## Reserved human decisions

-

## Checklist

- [ ] One Change, one Branch and one PR were used.
- [ ] Issue, dedicated Worktree, Task Policy and Source Lock were used only when risk required them.
- [ ] Lane and Application Runtime Activation match the PR and any selected policy or Issue.
- [ ] Lane was not downgraded by Codex.
- [ ] Declared changed files match the actual Git diff.
- [ ] Exact allowed paths pass when that optional scope control was selected.
- [ ] Unexpected changes were not ignored.
- [ ] Executed and unexecuted tests are clearly separated.
- [ ] Commit, push, merge, staging and production states are clearly separated.
- [ ] `worklogs/new_ver_main.md` was updated when required.
- [ ] The current head SHA matches the reviewed head SHA.
- [ ] Fresh machine-readable self-review evidence exists for the current head.
- [ ] Every available required check passed without bypass.
- [ ] No SEV-0 or SEV-1 finding remains.
- [ ] Reviewed Tree evidence passes before Activation when a final-head Build is used.
- [ ] This PR is ready for autonomous squash merge.
- [ ] Commercial Production GO, legal, accounting and provider decisions remain outside this PR.
