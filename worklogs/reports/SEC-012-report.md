# SEC-012 Bodyless Logout Browser Security Fix

## Task

- Task ID: `SEC-012`
- Risk: `R4`
- Issue: `#325`
- Branch: `fix/SEC-012-bodyless-logout-browser-security`
- Base SHA: `7d2f85d2a4e2dadf993594c559f3ffc6c6add04d`
- Worktree: `/var/www/oripa-worktrees/SEC-012-bodyless-logout-browser-security`

## Root Cause

`EnforceV2BrowserSecurity` required `Content-Type: application/json` for every POST before Origin and CSRF validation. Canonical bodyless `POST /api/v2/auth/logout` therefore failed with HTTP 415 before session revocation reached the controller.

## Implementation

- Exempt only the named route `v2.public.auth.logout` when the raw request body is exactly empty from the JSON media-type check.
- Keep cross-site rejection before the exemption.
- Keep Origin and CSRF validation after the exemption.
- Keep non-JSON body rejection for Logout, Login, and the existing contact mutation.
- Keep controller, session revocation, session cookie expiry, CSRF rotation, and private cache semantics unchanged.

## Verification Performed

- Focused PHP 8.4 test run on an isolated Task PostgreSQL 17 database with the existing 57 V2 migrations: `24 passed`, `147 assertions`.
- Canonical bodyless Logout reached HTTP 204 with a valid session, Origin, and CSRF token.
- Logout revoked the hashed session row, expired the secure HttpOnly session cookie, rotated the secure non-HttpOnly CSRF cookie, and retained private cache semantics.
- Missing CSRF, invalid CSRF, invalid Origin, and `Sec-Fetch-Site: cross-site` returned typed HTTP 403 Problem Details.
- Non-JSON bodies on Logout, Login, and the existing contact API mutation returned `UNSUPPORTED_MEDIA_TYPE` / HTTP 415.
- PHP syntax for all three changed PHP files: PASS.
- Composer manifest validation: PASS.
- Local `quality-gate`: PASS (`818` PHP, `108` JSON, `19` YAML, `4` contracts).
- Policy unit tests: `144 passed`.
- Quality unit tests: `4 passed`.
- Security unit tests: `10 passed`.
- `git diff --check`: PASS.
- High-confidence secret candidate scan: PASS.
- Allowed Paths and five changed paths: PASS.
- Task Docker PostgreSQL, Redis, and network resources: removed.

## Verification Not Performed

- Real Storefront user session Logout: not run; human verification remains authoritative and no real-user session was mutated.
- Browser/E2E visual verification: not run.
- Runtime activation: not run under SEC-012 because the Policy has no deployment evidence path, Preview build operation, or Preview Deployment Lock authority.
- Production operations: not run and prohibited.

## Impact

- Migration files created: `0`
- Runtime migrations applied: `0`
- Database schema changes: `0`
- Public/Admin OpenAPI changes: `0`
- Storefront Client changes: `0`
- Site Schema changes: `0`
- Storefront Testkit changes: `0`
- Generated contract/artifact changes: `0`
- Storefront Repository changes: `0`
- Admin, Payment, Point, Draw, and Production changes: `0`

## Runtime Boundary

SEC-012 is a source-only closeout. Runtime activation requires a separate approved R4 OPS Task with a deployment evidence path, canonical Preview image build authority for an open exact-head PR, Preview Deployment Lock authority, and API-only activation under the existing OPS-010/OPS-011 network boundary.

## Rollback

Revert the SEC-012 squash commit to restore the prior middleware behavior. No database, contract, artifact, or runtime data rollback is required.
