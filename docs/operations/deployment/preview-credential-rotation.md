# Shared Preview Credential Rotation

## Scope

This runbook applies only to the isolated Shared V2 Preview Compose project. It
does not authorize Production, Storefront, provider-account, migration, or
business-data changes.

## Secret Handling

- Never print, log, hash, digest, or pass credential values as command-line
  arguments.
- Read and write credentials only through a root-owned non-symlink script,
  protected standard input, or a mode `0600` canonical file operation.
- Preserve a mode `0600` rollback candidate outside the Repository until the
  post-rotation gate passes, then remove every non-canonical temporary copy.
- Evidence records key names and `ROTATED` or `BLOCKED` only.

## Compatibility

- `V2_APP_KEY` becomes the Laravel active encryption key. The immediately
  previous key is supplied through `V2_APP_PREVIOUS_KEYS`; Laravel writes only
  with the active key and may decrypt historical ciphertext with a previous
  key.
- `V2_AUDIT_HMAC_KEY` becomes the active versioned Audit key. The previous key
  and its version remain available through
  `V2_AUDIT_HMAC_PREVIOUS_KEY` and
  `V2_AUDIT_HMAC_PREVIOUS_KEY_VERSION`. New records use
  `V2_AUDIT_HMAC_KEY_VERSION`; historical records select their key by the
  persisted `hmac_key_version`.
- `V2_PII_CORRELATION_KEY` becomes the active write key.
  `V2_PII_CORRELATION_PREVIOUS_KEYS` supplies read candidates for historical
  correlation lookup. Existing rows are not rewritten.
- Previous compatibility material must not be removed until a separately
  authorized retention or re-encryption decision proves that it is no longer
  needed.

## Rotation Order

1. Confirm clean latest `main`, the fixed Task head, all Shared Locks, the
   Preview OS lock, canonical file ownership and mode, active image identity,
   service health, and restart counts.
2. Acquire the Shared Preview Deployment Lock and the OS lock before the first
   mutable operation.
3. Create one protected rollback candidate and generate independent CSPRNG
   values without output.
4. Change PostgreSQL and Redis authentication through protected input, then
   atomically update only the canonical Preview source.
5. Add compatibility fields, replace the active Application, Audit, and PII
   keys, and recreate only PostgreSQL, Redis, and API as required.
6. Verify API, Admin, PostgreSQL, Redis, API-to-DB, API-to-Redis, historical
   ciphertext read, historical Audit chain verification, historical PII
   correlation lookup, restart stability, and gateway errors without a
   business mutation.
7. Roll back from the protected candidate if any required health or historical
   compatibility check fails. Never repair the failure by rewriting historical
   rows.

## Provider Boundary

`MAILGUN_SECRET` may be rotated only through an approved provider operation
whose account is proven Preview-only. Repository or Runtime configuration alone
cannot rotate the provider credential. Missing provider authority or possible
Production sharing is a fail-closed result for Mailgun and does not block safe,
independent Preview credentials from rotating.

## Evidence

The authoritative root-only record and its public-safe Repository summary must
identify the Task, UTC time, key names, result, canonical source, compatibility
mode, dependent service action, Runtime health, remaining blocker, and migration
activation readiness. Values, hashes, and digests are prohibited.
