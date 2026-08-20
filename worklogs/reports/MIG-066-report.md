# MIG-066 Email Verification `/mypage` Redirect Activation

## Task

- Issue: `#317`
- Risk: `R4`
- Base SHA: `3699526536c6ecaee8e09aa5da5a28d3b1d744a1`
- Branch: `fix/MIG-066-email-verification-mypage-redirect`
- Worktree: `/var/www/oripa-worktrees/MIG-066`

## Scope

- Add the exact relative path `/mypage` to the Email Verification safe redirect allowlist while retaining `/`.
- Reuse the existing exact-match redirect validation without accepting arbitrary paths, external URLs, scheme-relative URLs, wildcards, malformed paths, or query-authoritative redirects.
- Preserve MIG-065 Browser HTTP 303, Session/CSRF cookies, and JSON client semantics.
- After source merge, activate an immutable API image containing MIG-065 and MIG-066 on only the active V2 API service under the OPS-010 API-only egress boundary.

## Impact

- Migrations created/applied: `0`.
- Database schema changes: `0`.
- Public/Admin contract changes: `0`.
- Repository artifact changes: `0`.
- Storefront changes: `0`.

## Validation

- Task-only PostgreSQL applied the existing 57 V2 migrations without `migrate:fresh`; Migration creation and active Runtime migration application remain `0`.
- Direct Email Verification plus Authentication Flow: 23 tests / 166 assertions PASS on PHP 8.4. The suite covers `/` and `/mypage` acceptance, external URL, `//evil.example/`, unallowlisted relative path, malformed relative path, wildcard, and query-bearing redirect rejection, Browser HTTP 303 to `/mypage`, Session/CSRF cookies, JSON semantics, query tampering, invalid/expired/replay token behavior, Register, and Resend.
- The first test execution reached 0 assertions and failed because `phpunit.xml` correctly forced database `oripa_test` while the ephemeral PostgreSQL database had a different Task-only name. The Task database was recreated with the canonical test name and the unchanged tests then passed.
- Policy Unit 144, Quality Unit 4, Security Unit 10, local Policy/Quality/Security Gates, Composer validation, Composer/workspace pnpm/legacy pnpm audits with 0 findings, PHP syntax, and `git diff --check`: PASS.
- Contract, generated artifact, Storefront, migration, database schema, controller, notifier, and validation implementation diffs: `0`.
- Required Checks, fixed-head self-review, source merge, runtime activation, and cleanup remain pending.
- Real-recipient email, new registration runtime smoke, Resend runtime smoke, and Verification Complete are prohibited and will not be executed.
