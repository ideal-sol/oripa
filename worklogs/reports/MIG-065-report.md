# MIG-065 Email Verification Browser UX

## Task

- Issue: `#313`
- Risk: `R4`
- Base SHA: `ec7ce40fb9af3c862b492be3b0aa453183bbe43b`
- Branch: `fix/MIG-065-email-verification-browser-ux`
- Worktree: `/var/www/oripa-worktrees/MIG-065`
- Task Policy SHA-256: `ade4a41d860179d1691ad9810e05df29c7fe60eadad836bc2d8449af0b9a8343`

## Scope

- Generate the verification mail URL from canonical `v2_identity.origins.user`, sourced from `V2_PUBLIC_ORIGIN`.
- Redirect a normal browser after successful verification while preserving the existing JSON response for clients that request JSON.
- Preserve all existing token, hash, expiry, resend, verification state, Session, CSRF, Mailgun, Outbox, and redirect-allowlist semantics.

## Implementation

- `V2MailEmailVerificationNotifier` requires a canonical absolute HTTPS origin without credentials, path, query, or fragment, then appends the unchanged verification path, user UUID, 64-hex token, and encoded stored redirect path.
- `V2PublicAuthController::verify` uses Laravel `expectsJson()` content negotiation. JSON requests receive the existing `authenticated`, `user`, and `redirect_path` response. Other requests receive HTTP 303 with the stored validated relative redirect path.
- Both representations retain private/no-store and API-version headers, add `Vary: Accept`, and use the existing Session Manager and CSRF Service to attach the same User Session and CSRF cookies.
- The verification request query is not accepted as redirect authority. The Domain result returns the redirect path persisted only after the existing exact allowlist validation during Register or Resend.

## Security

- Scheme-bearing external URLs, scheme-relative URLs such as `//evil.example/`, and allowlist-external relative paths remain rejected with `INVALID_REDIRECT`.
- A tampered verification query cannot override the stored `/` redirect.
- Invalid valid-shape tokens remain rejected with `INVALID_VERIFICATION_LINK`; expired-token and replay rejection remain covered by the existing Authentication Flow regression.
- No redirect destination was added. The production allowlist remains only `/`.

## Validation

- Focused Direct Mail and Authentication Flow: 23 tests / 162 assertions PASS on PHP 8.4.
- Covered absolute canonical HTTPS URL, user/token/query preservation, Browser 303, Session/CSRF cookies, JSON compatibility, external/scheme-relative/unallowlisted redirect rejection, query tampering, invalid token, expired token, replay, Register, and Resend.
- Policy Unit 144, Quality Unit 4, Security Unit 10: PASS.
- Local Policy, Quality, and Security Gates: PASS.
- Composer validation, Composer audit, workspace pnpm audit, and legacy pnpm audit: PASS with zero findings.
- Changed PHP syntax and `git diff --check`: PASS.
- Task-only PostgreSQL applied the existing 57 V2 migrations without `migrate:fresh`; focused tests passed and all Task containers/network were removed.

## Impact

- Migrations created: `0`.
- Task/Preview/Production migrations applied: `0`.
- Database schema changes: `0`.
- Public/Admin OpenAPI or generated contract changes: `0`.
- Artifact and Storefront changes: `0`.
- Point, Payment, Draw, Inventory, infrastructure, Mailgun runtime, Notifier binding, and Outbox infrastructure: unchanged.

## Not Performed

- Real-recipient mail, new registration smoke, Resend smoke, Browser/E2E, Preview deployment, and Production operations were not performed.
- Final browser UX is reserved for the human verification requested by the Task.

## Rollback

- Revert the notifier URL composition and controller negotiation changes. No data, schema, contract artifact, or runtime resource rollback is required.

## Closeout

- Required Checks, fresh fixed-head self-review, squash merge, Issue close, branch/worktree cleanup, Integration Lock release, and main synchronization are pending.
