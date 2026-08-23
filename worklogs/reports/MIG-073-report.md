# MIG-073 Email Verification Failure UX / Closed Email Re-registration Report

## Task

- Issue: `#360`
- Lane: `Strict Change`
- Risk: `R4`
- Activation: `immediate` (Shared Preview API only)
- Base: `1ebaf50004461e405e1b39993d697b053b403480`
- Branch: `fix/MIG-073-email-verification-withdrawn-reregistration`
- Worktree: `/var/www/oripa-worktrees/MIG-073`
- Migration allocation: `000063`
- Started before Issue creation; Issue created at `2026-08-23T11:52:38Z`

## Cause

- Browser verification failures were rendered by the common V2 Problem Details exception handler because every `/api/v2/*` request was JSON-rendered on exception.
- `users_verified_email_unique` claimed normalized email for every verified User, including the canonical `closed` state, so a new User could not verify the same email without destroying old identity data.

## Implementation

- Browser verification failures return HTTP 303 to exactly `/verify-email/error`, with no query contract or internal failure data. JSON clients retain the current Problem Details behavior.
- Successful verification retains the stored exact allowlist redirect, including HTTP 303 `/mypage`, and existing Session/CSRF issuance.
- Verification tokens remain hash-only and owner-bound. Verification now additionally requires the token owner to remain `pending_verification`.
- Migration `000063` changes only the partial unique index predicate so verified `closed` Users no longer claim email. Every other verified state remains claimed.
- Rollback restores the prior predicate only when no reused verified email exists; otherwise it fails closed before index replacement.
- No old User row, identity field, profile, wallet, payment, draw, prize, shipping relation, or verification row is deleted, anonymized, reassigned, merged, or copied.

## Verification

- PHP syntax for changed Application, test, and Migration files: PASS.
- Focused Auth/Delivery/Schema: 35 tests, 305 assertions: PASS.
- Closed email isolation, non-closed claim, rollback failure: 3 tests, 31 assertions: PASS.
- Final unused-old-token ownership regression: 1 test, 20 assertions: PASS.
- V2 Identity full run: 501 tests: PASS.
- Task DB: 63 migrations, fresh apply twice, latest rollback/reapply, schema inventory: PASS.
- Policy Unit: 155 tests: PASS.
- DB Guard Unit: 39 tests: PASS.
- Quality Unit: 4 tests: PASS.
- Security Unit: 10 tests: PASS.
- Local Policy Gate and Quality Gate: PASS.
- Composer manifest/lock validation and `git diff --check`: PASS.
- Strict GitHub Required 5 Checks and fresh exact-head self-review: pending final head.

## Progressive Acceptance

- Browser invalid verification: 303 `/verify-email/error`, no query: PASS in focused HTTP test.
- JSON invalid verification: existing 410 Problem Details and code: PASS.
- Valid verification: Session/CSRF and 303 `/mypage`: PASS.
- User A closed, same email User B new identity, old unused token rejected for B, new token succeeds: PASS.
- Old Wallet/Payment/Shipping/Verification remain with A; B has no Wallet/Payment/Draw/User Prize/Shipping rows: PASS.
- Active/restricted/suspended/anonymized verified owners retain the claim: PASS.
- Shared Preview runtime acceptance: pending exact-source activation.

## Impact

- Migration created: yes, `000063`.
- Task DB migration applied: yes. Shared Preview: pending. Production: not applied.
- Public response behavior changes only for Browser verification failure; JSON contract shape is unchanged.
- Storefront, Admin, OpenAPI, Point/Coin authority, Payment authority, Draw/Inventory authority, infrastructure, Production, secrets, and credentials: unchanged.
- Security Baseline: SEC-011 metadata remains fresh through `2026-08-25`; no date extension or broad audit was performed.

## Metrics

- CI wait time: pending.
- Check rerun count: `0`.
- Build count: `0`.
- Runtime Activation count: `0`.
- Human wait time: `0`.
