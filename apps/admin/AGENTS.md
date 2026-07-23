# Admin Application Rules

## Scope

These instructions apply to the future shared V2 Next.js Admin application under
`apps/admin/`. They refine and do not override the Root `AGENTS.md`.

## Application Boundary

- Build the V2 Admin as a new, initially empty application with deliberate
  modules and routes.
- Do not bootstrap it by copying the V1 monolithic `admin-dashboard.tsx`.
- Use Admin API contracts only.
- Do not depend on User cookies, Public API contracts, or the Storefront Client.
- Keep Admin authentication, MFA, permission checks, audit visibility, and
  `noindex` behavior intact.
- Do not implement draw, point, payment, inventory, or authorization decisions
  in the browser.
- Keep the shared Admin product neutral; do not embed Site-specific design,
  credentials, provider configuration, or customer data.

## UI And Contract Work

- Follow the approved Admin API and generated types rather than guessing fields.
- Preserve loading, empty, error, unauthorized, and forbidden states.
- Keep accessibility, responsive layout, and operational clarity in scope for
  every user-facing Admin change.
- Do not expose provider secrets, internal probability data, or User realm state.

## Verification

- Run lint, typecheck, unit tests, and the approved build check when relevant.
- Run Admin E2E for changed critical workflows when the task and environment
  permit it.
- Record Browser/E2E as not run unless it was actually executed.
- Follow the Root autonomous GitHub lifecycle. Platform Codex may squash merge
  only after the current head, scope, available CI, self-review evidence, and
  applicable Admin security/E2E gates are revalidated.
