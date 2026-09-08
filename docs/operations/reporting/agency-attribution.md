# AGENCY-004A Platform Advertising Attribution

Lane: Strict Change. Application Runtime Activation: immediate (old Shared
Preview API only). Production mutation: 0. Storefront checkout access: 0.

## Public contract

`GET /api/v2/advertising-code-validation?advertising_code=<candidate>` is the
anonymous `validateAdvertisingCode` operation. Its uncached response contains
only `{"valid":true}` or `{"valid":false}`. It reveals no Agency identity or
contact information. Validation means the case-sensitive Code exists and its
Agency is currently active. The endpoint is advisory; registration revalidates.

`POST /api/v2/auth/register` and both
`POST /api/v2/auth/external/{google|line}/start` accept optional
`advertising_code`. Requests without it retain existing behavior and responses.
Malformed, nonexistent and suspended candidates do not reject registration.
Input is never trimmed or case-normalized. Public API field naming does not
select the Storefront URL query key.

External login starts retain only a syntactically valid candidate in the
existing server-side `external_identity_transactions` row. Nullable field
`advertising_code_candidate` is bound to the existing opaque state, browser
binding, expiry, nonce and PKCE boundary and guarded against UPDATE. It is never
sent to the provider. Callback query parameters cannot replace it. Link and
reauthentication starts ignore it. Existing identity login, explicit link and
email-collision handling never attach or replace attribution.

## Laravel authority

`V2AgencyAttributionService` lives alongside the existing Agency domain services
in Identity. Both User-creation hooks run inside their existing DB transaction.
It checks the exact immutable Code, locks the same Agency row used by Admin
suspension, then rechecks current status before inserting once. Existing
attribution remains unchanged. Reloaded existing Users are not backfilled.
The canonical `V2SessionPolicy` clock supplies `attributed_at`.

The existing `user_advertising_attributions` schema and append-only DB guards
remain authoritative. No duplicate agency_id, click/session table, referral
reuse, follow-up attribution API or asynchronous attribution job is added.
AGENCY-003 queries remain unchanged: User period uses `users.created_at`; current
verified non-revoked SMS controls full classification, and canonical succeeded
Payments contribute to Sales.

Migration `000075` adds only the nullable immutable external candidate. Apply
before the new API image. Prior API remains compatible with the additive column;
retain the migration on runtime rollback. Schema rollback refuses to discard
recorded candidate history. No Production application is authorized.

## Packages and publication

Public OpenAPI advances to alpha.32 (76 operations); Admin and Webhook retain
alpha.31 and Agency is unchanged. Client and Testkit candidate is alpha.36,
referencing immutable Site Schema alpha.23. Use
`createStorefrontIdentityClient(transport).validateAdvertisingCode(code)`,
`.register(input)`, `.startGoogleLogin(input, options)` and
`.startLineLogin(input, options)`.

Testkit exports `PUBLIC_ADVERTISING_ATTRIBUTION_FIXTURES` for valid, invalid,
suspended, registration with/without Code, external start and external new-user
responses. Existing `createMockFetch` supplies the test harness; it does not
implement Platform attribution logic.

At task start alpha.35 was already published by SMS-001 but remained pending in
the ledger. Under the canonical Artifact Release Lock, its exact GitHub outer
digest, five-file inventory, manifest, packed packages and Public contract were
read back with the existing validator. Reconciliation is generated from those
verified bytes; alpha.35 is never rebuilt or reassigned. The next unused
alpha.36 candidate follows it. The publication validator permits independently
versioned unchanged Admin/Webhook contracts while rejecting regressions and
version skips. Current protected-main workflow, exact checks, immutable upload
and readback gates still apply. Publication and subsequent release-metadata PR
are recorded separately from Source merge. No Storefront pin is changed.

## AGENCY-004B locked handoff

Cookie and URL parsing remain unimplemented here. AGENCY-004B chooses the URL
query key from Storefront source and consumes the published immutable bundle.

- 30-day Last Click: valid A then valid B becomes B.
- Valid A then invalid Code keeps A.
- A code-less page keeps the existing valid Cookie.
- Expired Cookie supplies no candidate.
- Suspended Agency Code is not stored as a new valid Last Click.
- Existing User receives no new attribution.
- Cookie consent UI: NO. Banner: 0. Popup: 0. Button: 0. Alert: 0. CMP: 0.

No Human Browser Acceptance is required for 004A. Storefront end-to-end and
Human Browser acceptance follow 004B. Business acceptance uses isolated backend
tests; Shared Preview acceptance performs read-only validity and malformed
registration checks without creating Users or sending mail.
