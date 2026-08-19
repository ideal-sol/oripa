# MIG-064 Email Verification Direct Mailgun Delivery

## Task

- Issue: `#309`
- Risk: `R3`
- Base SHA: `3b24a6757f4cfcfe8107dcbd32d17a5580c45cb1`
- Branch: `feat/MIG-064-direct-mailgun-email-verification`

## Result

- `V2EmailVerificationNotifier` resolves to the existing `V2MailEmailVerificationNotifier`.
- Register and resend now use synchronous Laravel Mail directly; no verification message is enqueued in Outbox.
- Existing token generation and hashing, 60-minute expiry, redirect allowlist, resend limits, pending state, and verification-complete behavior remain unchanged.
- A Mail transport exception aborts the containing registration transaction, so no user, token, or verification Outbox record is committed as a false success.
- Generic Outbox and persistent audit boundaries remain required. The policy gate rejects restoration of the email-verification Outbox binding.

## Runtime

- Canonical active V2 Preview source: the Compose project's external environment file.
- `docker-compose.v2.yml` requires `MAIL_MAILER` and injects `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAILGUN_DOMAIN`, `MAILGUN_SECRET`, `MAILGUN_ENDPOINT`, and `MAILGUN_SCHEME` with the existing Laravel defaults.
- Current canonical source and current V2 API process do not configure the required Mail values. No value was displayed or changed, and no dummy value or real-recipient email was used.
- Before runtime activation, set the listed variables in the canonical env source, recreate only the V2 API service through the approved Compose procedure, then perform an approved QA-recipient delivery smoke.

## Impact

- Migrations created: `0`; task migrations applied: `0`; isolated test database applied existing migrations `000001` through `000057` only.
- Public/Admin OpenAPI, generated artifacts, Storefront Client, Site Schema, database schema, payment, draw, inventory, and Outbox infrastructure: unchanged.
- Mailgun transport dependency: unchanged; existing official `symfony/mailgun-mailer` is retained.

## Validation

- PHP 8.4 API image build: PASS.
- Direct-email focused test: PASS, 3 tests / 27 assertions.
- Authentication focused regression: PASS with existing test-environment `.env` warnings.
- PHP syntax, Composer validation, Composer/workspace pnpm/legacy pnpm audit: PASS.
- Policy Unit 138, Quality Unit 4, Security Unit 10; local policy, quality, and security gates: PASS.
- Compose and Laravel Mail configuration validation with an isolated non-production test environment: PASS.
- Browser/E2E, repository-wide backend suite, actual Mailgun recipient delivery: not run.
