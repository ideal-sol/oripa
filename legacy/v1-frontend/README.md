# V1 Frontend Reference

## Status

This Directory preserves the V1 Next.js Frontend for behavior,
Characterization, approved Asset, wording, and screen-requirement reference.
It is not a V2 App, is not approved for Production deployment, and must not be
included in a V2 Production Image.

## Responsibility

- Preserve the V1 UI, Route, Component, API-call, CSS, and Asset behavior.
- Provide a non-Production comparison target while V2 contracts and Apps are
  implemented separately.

## Ownership

Platform Codex owns preservation tasks under Root `AGENTS.md`,
`legacy/v1/AGENTS.md`, and the nearest `AGENTS.md`.

## Planned Components

No new Component is planned here. Existing V1 files are retained as reference
material only.

## Allowed Scope

- Read-only V1 behavior and screen investigation.
- Explicitly scoped Characterization.
- Extraction of approved Asset, wording, and screen requirements.
- A separately authorized emergency V1 Hotfix.

## Forbidden Scope

- New V1 features or routine fixes.
- Direct Component copying into V2 Apps, Packages, or Site Templates.
- Treating V1 direct API `fetch` behavior as the V2 Contract.
- V2 Production Image, Production Runtime, or Runtime Dependency inclusion.
- Production secrets, credentials, data, or real-user PII.

## Local Reference Commands

Use Node 22 and pnpm 10.12.1. The locked dependency graph remains unchanged.

```bash
cd legacy/v1-frontend
pnpm --ignore-workspace install --frozen-lockfile
pnpm --ignore-workspace typecheck
pnpm --ignore-workspace lint
pnpm --ignore-workspace build
pnpm --ignore-workspace start
```

`--ignore-workspace` keeps this V1 dependency graph and Lockfile independent
from the Root V2 Workspace. `package.json` has no Frontend Test Script. Do not
report Frontend Tests as PASS unless an approved Test Script is added in a
separate Task.

For development comparison, copy the non-secret template manually:

```bash
cp .env.example .env.local
```

Do not copy or auto-move the old Git-untracked `frontend/.env.local`. Never put
an actual secret in this document or Git. Connect V1 API calls only to an
explicit non-Production environment.

## Production Isolation

The Docker Compose `frontend` service is a local V1 reference service. Its
Build Context is limited to this Directory. The corresponding Dockerfile is
not a V2 Production Dockerfile. Future V2 Production Dockerfiles are rejected
by `policy-gate` if they copy `legacy/v1-frontend`.
