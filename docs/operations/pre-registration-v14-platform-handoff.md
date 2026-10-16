# Pre-registration v1.4 Platform source handoff

Status: Draft source implementation; Human review and operational GO pending.
Change: `PREREGISTRATION-BONUS-V14-20261016` (delivery ID `PREREG-V14-20261016`).
Risk R3; Strict Change; Application Runtime Activation deferred; Issue none.

## Authority and preflight

The Human-supplied START_HERE, integrated specification v1.4 and Platform prompt
v1.4 were read in full. Integrated specification SHA-256:
`36b838fd2cb73e41f8a8df72e03195f39096b3001f6688dfd81e3f354fb61414`.
The ZIP checksum manifest passed. Storefront design patches/assets are references,
not Platform implementation inputs. The operational OPEN date remains unset.

Base `3faa1872c7fe23ba0d50e829b8251b7add57da67` is the merged PR #570 head.
Published alpha.44 history/latest are unchanged. Unpublished alpha.45 remains the
additive candidate: Public/Admin alpha.41, unchanged Webhook alpha.40,
Client/Testkit alpha.45, Site Schema alpha.23. Candidate Public operations: 81.
No artifact, release or package publication is performed.

An isolated worktree preserves pre-existing primary-worktree and prior-task
changes. No overlapping active feature PR was found. Migration 000086 was the
latest source migration; 000087 was reserved under the shared Migration Allocation
Lock before creation. Its reservation remains with this Draft; no rank migration
or membership-rank implementation is included.

## As-built source investigation

| Source | Decision and impact |
| --- | --- |
| `V2UserAuthenticationService`, `V2PublicAuthController` | Dedicated operation reuses registration validation, password policy, age validation, rate limiting, advertising attribution and verification delivery. Normal registration cannot opt in through request flags or redirect. |
| `V2ExternalIdentityService` | Unchanged Google/LINE account creation; neither sets `pre_registered_at`. |
| `v2_identity.email_verification.redirect_allowlist` | Add only exact `/pre-register/sms`; resend for dedicated applicants preserves it. No `/thanks`, trailing slash or query variant. |
| Verification/template mail | Suppress `registration_completed` only for dedicated applicants before cutoff. Verification mail remains. No activation reward notification. |
| `V2SmsVerificationService`, `V2IdentityCorrelation`, HMAC keyring | SMS source unchanged: initial SMS time, OTP/session and referral hook stay separate from the reward. Activation shares user-row then sorted phone-hash advisory-lock order and active/previous-key matching. |
| `V2PointService`, `V2CoinExpiryPolicy` | Canonical operation/lot/wallet/ledger helpers; free grant, 180-day expiry and durable `pre_registration.bonus:{user.public_id}` key. No rank points. |
| `V2RegistrationQualification` | Only a committed grant switches signup-once and first-purchase qualification to current `created_at`. Queries authoritative DB timestamp even if the request holds an older User instance. |
| `V2PointPurchaseEligibilityService`, `V2PointProductReadService` | Shared Card/PayPay/Konbini eligibility and displayed first-purchase expiry use the same origin; half-open 24-hour window and per-product usage rules unchanged. |
| `V2DrawEligibilityService` | Signup-once publish-start comparison uses activated origin. Existing first-time-user `created_at` window, daily limits, prior usage and draw accounting remain unchanged. |
| `V2AdminUserReadService` | Existing registration-date list filter, detail and referral-history `registered_at` intentionally show the new registration time after successful activation. Original application time is separately available in the added detail endpoint. Payment/draw/referral-event timestamps are not rewritten. |
| Reporting/history | No historical payment, draw, point or referral event is rewritten. Any existing live-user registration-date classification follows the Human-authorized `created_at` change. |
| Permissions/navigation | Read for owner/admin/operator; manage and execute for owner/admin; existing realm/MFA/CSRF/Origin boundaries preserved. ADMINFRESH-20260918 Phase 2 removed action-level Fresh challenges; the legacy freshness predicate is not a business gate. |

## Contracts and Storefront handoff

- `POST /api/v2/auth/pre-register`: four required fields (`full_name`, `birth_date`,
  `email`, `password`), optional existing `advertising_code`; rejects unknown
  fields. Server checks enabled and strictly before cutoff. Application timestamp
  equals initial `created_at`; no grant occurs here.
- `GET /api/v2/campaigns/pre-registration`: enabled, cutoff, intended free amount,
  accepting/closed/not-started state. Disabled by default; no inferred OPEN date.
- `GET /api/v2/me/pre-registration-bonus`: authenticated User realm, no SMS
  prerequisite, private/no-store. State distinguishes incomplete verification,
  eligibility, awaiting activation and actual granted amount/time/expiry. It
  never exposes phone/HMAC, audit internals or exception details.
- Thin identity client methods: `preRegister`, `getPreRegistrationCampaign`,
  `getPreRegistrationBonus`. Testkit `PUBLIC_PRE_REGISTRATION_FIXTURES` distinguishes
  eligibility from actual grant. Storefront must render these server states,
  not independently infer entitlement from timestamps.
- Admin: GET/PUT `/admin/api/v2/settings/pre-registration`, POST suffixes
  `/preview` and `/activate`; GET `/admin/api/v2/users/{userId}/pre-registration`.
  OpenAPI is the request/error authority. Settings require exact revision and
  idempotency key; activation requires the current valid Admin session, role and exact revision.

This PR does not implement Storefront pages, Basic exceptions, S3 assets, LINE
links, membership ranks, OPEN operations or an API-wide pre-OPEN block.

## Transaction and retry model

The first post-cutoff manual activation fixes one campaign-wide amount/revision
and the cohort whose three timestamps are strictly before cutoff. Turning off
registration does not invalidate that cohort. Cutoff is immutable after this
snapshot. Amount edits are rejected until no pending/failed target remains;
later edits never change the frozen amount or prior grants.

Each request processes at most 100 targets, pending before failed. The UI exposes
this bound, remaining counts, confirmation and explicit continuation; there is
no scheduler or automatic grant. A session-level PostgreSQL advisory lock rejects
overlapping Admin batches with retryable 409; each target is its own transaction.
User row, phone hashes, wallet, business key, grant record and audit serialize
the successful change. Audit failure rolls back the entire user's grant and
timestamp change. Temporary failures retain the old timestamps/balances and
become retryable; unavailable accounts/phones and already-rewarded numbers are
excluded with safe reasons. Successful users are never reprocessed.

Activation batch membership is durable. Reusing a key resumes an interrupted
unfinished batch, or replays its completed response. A new key processes the
next pending/failed batch. The UI retains the key after uncertain transport
failure and requires confirmation again; session expiry never auto-executes.
After a reload, refresh state before continuing. Unique user/phone/operation
constraints and permanent business keys remain the final duplicate barriers.
Phone values are encrypted in existing identity storage only; dedicated grant
history retains HMAC, not plaintext. Previous correlation keys must remain
available under the existing rotation policy to recognize historical grants.

## Database and rollback

Migration `2026_10_16_000087_create_v2_pre_registration.php` adds one nullable User
column (no backfill), singleton settings, target progress, immutable grant history
and durable batch records. Existing immutable qualification guards are untouched.
Applied only to disposable, network-isolated synthetic test databases, never to
OLD Test, NEW, Staging, Production or an operational database.

Before operational activation, independently approve source deployment, migration,
contract publication, cutoff/amount configuration and manual activation. Ordinary
rollback before use means keeping receipt disabled and not activating. After use,
do not drop history, reverse timestamps or delete ledger/grants: use an approved
forward correction/adjustment. Old code unaware of activated qualification must
not be restored after grants without a reviewed compatibility plan.

## Verification and deferred work

Focused HTTP/domain, real-connection multi-Admin/partial-failure tests, contract
negative tests, generated/client/testkit checks, migration guards, Admin unit and
Chromium desktop/mobile E2E cover this change. Exact executed totals, any failures,
full-suite outcomes and final-head CI/self-review are recorded in the Draft PR.
Synthetic browser tests mock the Admin API; they are not live deployment acceptance.
Human Browser review, live provider tests, load acceptance at operational scale,
Storefront E2E, deployment and every operational activation remain NOT RUN.

Draft-only delivery does not authorize Ready for Review, auto-merge or merge.
