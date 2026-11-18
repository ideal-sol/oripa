# LINE D1–D7 additional authentication removal

Task LINE-DELIVERY-REMOVE-FRESH-MFA-20261118; R3; Strict Change;
Application Runtime Activation: deferred. Source and Draft PR only.

The explicit Human decision of 2026-11-18 removes every LINE-specific
additional MFA, TOTP, WebAuthn and Fresh MFA challenge. A normally authenticated
administrator with the existing operation permission can act without another code,
including when the session MFA timestamp is absent or older than five minutes.
This supersedes the corresponding requirements in the earlier D1–D7 designs.
It does not change global MFA policy or any non-LINE authentication implementation.

| Area | Result |
| --- | --- |
| D1 | Remove mutate freshness check and BOT/login reauthentication UI; preserve ManageLineBot, encryption, revision, idempotency and audit. |
| D2 | No additional challenge found; recipient eligibility and identity vault unchanged. |
| D3 | Remove delivery and quota authorize freshness checks; retain Owner permission, quota baseline evidence, halt, caps and send fences. |
| D4 | Remove settings update freshness check and UI; retain revision, validation, event content and audit. |
| D5 | Remove write and media freshness checks and UI; preserve read/write roles, upload MIME/size/hash/generation checks and confirmations. |
| D6 | Chat/template authorization already uses ordinary permission checks; shared D5 media uploads now need no extra challenge. Reply/template permissions stay distinct. |
| D7 | No MFA challenge found. CLI Human approval, same-provider confirmation and actor requirements remain unchanged. |

Shared V2AdminFreshMfaAuthorizer is retained for its ordinary session and permission
methods. Its class name does not imply that authorizePermission checks freshness.
Controllers, route middleware and Admin API client request handling were traced;
LINE screens no longer invoke the common reauthentication API. Common authentication,
session expiry/revocation, realm isolation, CSRF/Origin and all non-LINE paths remain
unchanged. Public LINE account linking reauthentication is a separate User flow.

No new migration, dependency, worker, environment, provider configuration or runtime
change. Existing schema is retained. Rollback is a reviewed source revert, with no
database rollback. Browser/E2E, OLD Test and Production acceptance are not performed
by this task; a separate Human GO is required for Ready, merge and activation.

The standalone delivery specification v1.4 was not located in this checkout or the
available task evidence. The committed D1–D7 designs record its authority and relevant
overrides; the current explicit Human instruction fully specifies this narrow change.
The unrelated docs/md/spec_v1.4.md is not a substitute for that delivery specification.
