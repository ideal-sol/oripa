# V1 Frontend Legacy Rules

## Scope

These instructions apply to the V1 Next.js reference under
`legacy/v1-frontend/`. Follow Root `AGENTS.md` and
`legacy/v1/AGENTS.md`; this file may only make their preservation rules more
specific.

## Preservation

- Treat this Frontend as a V1 behavioral, screen, copy, and approved-asset
  reference only.
- Do not add new features or perform ordinary bug fixes here.
- An emergency V1 correction requires a separate approved Hotfix Task, Issue,
  branch, worktree, tests, fixed-head self-review, and PR.
- Do not copy Components directly into V2 Apps, Packages, or Site Templates.
- Do not treat V1 direct API `fetch` behavior as the V2 Contract.
- Do not use `admin-dashboard.tsx` as the starting point for the V2 Admin App.
- Do not include this Directory in a V2 Production Image or Runtime Dependency.
- Do not commit secrets, credentials, Production data, or real-user PII.
- Only approved Assets, wording, screen requirements, and Characterization
  evidence may be extracted through an explicitly scoped V2 Task.

## Verification

- Preserve Source, Route, Asset, Package Manifest, Lockfile, and Test content.
- Keep V1 Reference validation separate from V2 Runtime validation.
- Report unexecuted Browser／E2E and Production checks as not run.
