# OPS-017 Shared Preview Credential Remediation

## Scope

- Risk: R4
- Issue: #346
- Pull Request: #347
- Base: `a75a88f113ab29a7af5c55cdf4f51bb7c2812629`
- Runtime Application Source: `dbc5cbec574c00725c3209c922c05dbcc78b52c6`
- Production, Storefront, Nginx, DNS, network topology, Payment, Coin, Draw, and business semantics were not changed.

## Credential Result

| Key | Result | Compatibility |
| --- | --- | --- |
| `V2_APP_KEY` | ROTATED | Laravel native previous-key read; new-key write |
| `V2_DB_PASSWORD` | ROTATED | Database role updated before dependent recreation |
| `V2_REDIS_PASSWORD` | ROTATED | Runtime password updated before dependent recreation |
| `V2_AUDIT_HMAC_KEY` | ROTATED | Persisted v1 key version remains verifiable; v2 is active for future writes |
| `V2_PII_CORRELATION_KEY` | ROTATED | New active write plus explicit previous-key lookup candidates |
| `MAILGUN_SECRET` | BLOCKED | External provider rotation authority and Preview-only account boundary are not authoritative |

No credential value, credential hash, or credential digest was printed or stored in Repository evidence. Canonical files remained root-owned mode `0600`. The protected rollback candidate is retained; staged and task-test secret files are removed.

## Runtime Verification

- API, Admin, PostgreSQL, and Redis are healthy with restart count 0.
- API internal health returned 200 and verifies Application, PostgreSQL, Redis, and storage.
- Public Session and Admin HTTPS health returned 200.
- Historical ciphertext, Audit chain, Contact correlation, and Shipping correlation passed read-only verification after rotation.
- Mailgun configuration, DNS, TCP 443, and unauthenticated HTTPS connectivity passed without sending a message.
- HTTP 500, 502, and 504 were 0 during the activation window.
- Business mutation and historical row rewrite were 0.
- API-only egress and private-only Admin/PostgreSQL/Redis boundaries were preserved.

## Validation

- Focused compatibility: 32 tests, 179 assertions, PASS.
- Full V2 suite: 489 tests, 4721 assertions, 9 skipped, PASS.
- Database guard unit: 39 tests, PASS.
- Policy unit: 152 tests, PASS.
- Quality unit: 4 tests, PASS.
- Security unit: 10 tests, PASS.
- Local Policy, Quality, and Security Gates: PASS.
- Application Source Required 5 Checks: PASS.
- `git diff --check`: PASS.
- Browser/E2E, real recipient email, provider credential rotation, and Production verification were not run.

The first Application Source Integration run failed because the isolated V2 suite exceeded PHP's default 128 MB memory limit. No assertion was weakened. The smoke runner now gives only the 489-test V2 process a 512 MB limit; the exact full suite and the next Required 5 Checks passed.

## Blocker

`MAILGUN_SECRET` remains exposed. The direct cause is the absence of an approved callable provider rotation operation and an authoritative Preview-only provider-account boundary; Production sharing cannot be excluded. The minimum resolution is an authorized provider operation that proves the boundary, rotates the provider credential, and updates `/var/www/oripa/.env` without displaying the value. This genuinely requires separate external-provider authority and is not resolvable by another repository or Runtime implementation change.

Because one of six credentials remains BLOCKED, `000056 / 000057` Migration Activation is **not startable**.
