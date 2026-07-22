# Package Rules

## Scope

These instructions apply to first-party packages under `packages/`, including:

- `storefront-client`
- `site-schema`
- `storefront-testkit`
- `admin-client`

They refine the Root `AGENTS.md` and the finalized Package Version and
Compatibility Policy.

## Package Boundaries

- Keep the Storefront Client thin and contract-focused.
- Do not implement draw, point, wallet, payment, refund, inventory, or
  authorization decisions in a client package.
- Pin first-party package dependencies to exact versions in released Site
  products; do not use ranges, `latest`, or wildcards.
- Keep Public and Admin client surfaces separated.
- Do not expose Admin, Webhook, provider, secret, or internal domain fields from
  Storefront packages.

## Generated Code And Compatibility

- Do not manually edit generated files.
- Change the source contract or generator and review the generated diff.
- Follow the finalized Package Version and Compatibility Policy for versioning,
  support windows, and release evidence.
- A breaking change requires an explicit human decision and an appropriate
  package version change.
- Do not silently broaden exports or change runtime/environment compatibility.

## Verification

- Run package build, typecheck, unit tests, export-surface checks, and generated
  diff checks applicable to the task.
- Record compatibility and downstream Site impact explicitly.
