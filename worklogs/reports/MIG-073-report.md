# MIG-073 Email Verification Failure UX / Closed Email Re-registration Report

## Task

- Issue: `#360`
- Pull Request: `#361`
- Lane: `Strict Change`
- Risk: `R4`
- Activation: `immediate` (Shared Preview API only)
- Base: `1ebaf50004461e405e1b39993d697b053b403480`
- Final Head: `9da1fc107a5ff258b40c95d893ce2e69d77adf98`
- Squash Merge: `663ec853f53d4d02e3622707f2b08162785f4e64`
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
- Strict GitHub Required 5 Checks: PASS on the exact Final Head.
- Fresh exact-head self-review: PASS, `#issuecomment-5386128970`, with SEV-0/1/2/3 all zero.

## Progressive Acceptance

- Browser invalid verification: 303 `/verify-email/error`, no query: PASS in focused HTTP test.
- JSON invalid verification: existing 410 Problem Details and code: PASS.
- Valid verification: Session/CSRF and 303 `/mypage`: PASS.
- User A closed, same email User B new identity, old unused token rejected for B, new token succeeds: PASS.
- Old Wallet/Payment/Shipping/Verification remain with A; B has no Wallet/Payment/Draw/User Prize/Shipping rows: PASS.
- Active/restricted/suspended/anonymized verified owners retain the claim: PASS.
- Canonical Build Run `32640130061`, Artifact `9493412716`, and artifact digest `sha256:4823ab27d737940354ccbc1ffd2a15e8bb9a641b576205929fa77bfbe2beff40`: verified for the exact Final Head.
- Shared Preview backup: custom-format validation PASS. Migration `000063`: applied as batch `30`; ledger count `63`; closed-email index predicate readback PASS.
- API image `oripa-v2-api:preview-MIG-073-9da1fc107a5f`: activated once. Admin remained on MIG-072; PostgreSQL and Redis were not recreated.
- API/Admin/PostgreSQL/Redis: healthy, restart count zero. API alone retained the existing egress network; `v2_private` remained internal and Admin/PostgreSQL/Redis remained private-only.
- Actual Nginx Browser failure: 303 to exact `/verify-email/error`, no query or internal code: PASS. Storefront error page: 200.
- JSON failure: existing 410 Problem Details contract: PASS. Valid verification: Session/CSRF and 303 `/mypage`: PASS.
- Shared Preview closed-email re-registration used a synthetic notifier and an outer database transaction. Distinct identity, old-token isolation, new verification, non-inherited Session/history, retained old Wallet/Payment/Shipping/Verification, empty new Wallet/Payment/Draw/User Prize/Shipping, and restricted-owner claim: PASS. The transaction rolled back.
- Activation-window Nginx HTTP 500/502/504 and error-log matches: zero.

## Impact

- Migration created: yes, `000063`.
- Task DB migration applied: yes. Shared Preview migration applied: yes. Production: not applied.
- Public response behavior changes only for Browser verification failure; JSON contract shape is unchanged.
- Storefront, Admin, OpenAPI, Point/Coin authority, Payment authority, Draw/Inventory authority, infrastructure, Production, secrets, and credentials: unchanged.
- Security Baseline: SEC-011 metadata remains fresh through `2026-08-25`; no date extension or broad audit was performed.

## Closeout

- PR `#361` was Squash Merged by `ideal-sol-oripa-codex[bot]` as `663ec853f53d4d02e3622707f2b08162785f4e64`; Issue `#360` was closed as completed.
- Remote and local Task branches, dedicated Worktree, Task Policy, Task database resources, Integration/Migration/Preview locks, and temporary runtime harnesses were removed or released.
- Local, origin, and GitHub `main` were synchronized at the Squash Merge SHA.
- Production changes, Production migration, secret mutation, real email delivery, real-user PII, Storefront/Admin source changes, and unrelated Application changes: zero.
- Rollback API image and the root-only pre-migration Shared Preview backup were retained. Migration rollback fails closed after persistent verified-email reuse rather than deleting or rewriting old identity history.
- Remaining blocker: none.

## Metrics

- Task total time: `1h 11m 50s`.
- CI wait time: `13m 36s`.
- Check rerun count: `1` additional explicit dispatch; failed-check reruns: `0`.
- Build count: `1` canonical GitHub build; Preview-host builds: `0`.
- Runtime Activation count: `1` API-only activation.
- Human wait time: approximately `17m` for the canonical Preview workflow dispatch.
