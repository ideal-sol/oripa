# PAY3 — Techcoin NetRideCash Source handoff

## PAY234-INTEGRATION-20261028 — integrated Source-only authorization

Latest Human GO on2026-10-28 authorizes existing PR622 → PR623 → PR624 Source
integration and canonical squash merges only. Historical Draft/Source Merge HOLD
statements below are superseded within that scope. R3 / Strict Change;
Application Runtime Activation remains deferred. Provider acceptance, real DB,
ENV/Worker/Scheduler/Runtime, Production Authority, formal Artifact/package
publication, tag/release/registry and Storefront PR192 remain excluded.

PAY2 reviewed `dc269f0361424edcd87162f39335e41e82bd2966` / tree `2752d1d14491c38eb0061daf8dbdaafa504013e6` merged as
`ccb5887cf80d7edfe2fc715e3b731b5f60825ffa`. PAY4 reviewed `6972abb0256e10918b9e3749f22aae3ae504fcab` / tree
`7c233a59ecaff345543d25f8b3c47493471bdba9` merged as `80e8f7448eafd70d88f7a457f067e461ee88f5e5`. Both canonical merge actors:
`ideal-sol-oripa-codex[bot]`. Each reviewed tree equals its squash tree, content diff0;
Required5 and fresh Strict review passed. Exact CI/review/merge evidence is in
the respective PR records and the batch closeout.

PAY3 retains original `4352ae7be5cf84a4e5517d0750fed8fe80da24fe` and imports PAY2+PAY4 main by an ordinary
merge. The final candidate retains all three provider operations plus existing
fincode/LINE and PAY1 financial/mail/rank authority. Canonical generation produces
Public90 and Webhook5 operations. Unpublished Client/Testkit alpha47,
Public/Admin alpha43 and Webhook alpha41 form one combined candidate; published
alpha46/history and Site-schema alpha23 remain immutable. No formal publication
or Runtime use is authorized by validation-only CI Candidates.

Paidy specification6.4.3(2) Capture retry deviation remains SEV-2 / Human-provider
decision OPEN before acceptance/Activation. Retain permanent Capture claims,
Retrieve-only uncertainty recovery, foreign-order Close protection and review;
never infer permission to release claims or resend Capture. U/P/T merchant,
real SDK/3DS/Webhook/browser/mail acceptance remain pending. A separate task must
publish integrated alpha47, connect/merge Storefront PR192 and obtain OLD Test /
Production Activation GO. Source rollback uses a separately reviewed forward fix;
never alter financial histories or automatically refund.

Final PR624 head/tree, Required5, fresh review, canonical squash, protected main
and exact tree/content verification are recorded externally in PR624/batch
closeout to avoid self-referential commit hashes. No new migration or allocation;
operational migration application0, formal publication0, Runtime Activation0.


Task: PAY3-20261027 / canonical CI Change ID: TEC-20261027 (one Change).
Issue: none / Risk: R3 / Lane: Strict Change / Application Runtime Activation: deferred.
Branch: `feat/TEC-20261027`, dedicated isolated worktree.
Draft PR: https://github.com/ideal-sol/oripa/pull/624.
**Draft only. Ready, Merge, auto-merge and Runtime Activation remain prohibited
until current Production reflection completes and Human separately gives explicit GO.**

## Authority and task start

The supplied PAY3 implementation instruction and payment specification v2.2 were
read before implementation. Verified specification SHA-256:
`70d47974a5287b9916a0c8467bd8d1352a71eaf074c7d3a21d37f62bc8d5b58d`.
Implementation instruction SHA-256:
`cc980afae2871ed120152e63ce760e6df8de8f2a425d82c354ea6ce4c8a73e57`.
Input/provider documents are not copied into this public repository.
Root and applicable API/OpenAPI/packages/Admin/docs AGENTS, canonical Governance
REV3, Release Gates REV2, GitHub Operations ADR REV1 and relevant migration,
security, data/payment, API and package authorities govern this Change.

Fixed base is latest protected main and PAY1 formal merge PR620:
`85c2b72c3595b68dda60388ff584cefff775c181`.
The primary checkout's unrelated dirty worklog and worktrees are preserved.
PAY2 PR622 is a separate open Draft, read-only head
`dc269f0361424edcd87162f39335e41e82bd2966`.
PAY4 PR623 is a separate open Draft, read-only remote head
`a546cd85731fa371fcec6f81c0c1fd3c23734463` at investigation. Its local active
checkout may contain newer work; it is neither modified nor used as our base.
No cherry-pick, rebase, merge, push or PR mutation is performed on either Change.

No Issue is needed for this bounded Draft; deferred operational decisions are
tracked here and in the PR. Source Lock: none; independent Draft worktrees and
explicitly deferred integration avoid a current write collision. A root-owned
exact-path transient GitHub App transport policy authorizes only this branch's
push, Draft, checks and review. It excludes Ready, merge and release operations.

Final HEAD/Tree, exact changed-file inventory, remote equality, formal CI and
Artifact identities are recorded in the Draft PR and exact-head machine-readable
self-review. They are external records to avoid a self-referential commit hash.

## Implementation and reused authority

| Boundary | PAY3 behavior |
| --- | --- |
| Configuration/method discovery | Dedicated Techcoin config; valid10-digit merchant code, fixed HTTPS endpoint,10055, random path token and explicit accounting mode; optional IPv4/IPv6/CIDR restriction |
| Purchase start | Existing SMS/product eligibility/idempotency and frozen product/bonus snapshots; Techcoin requires_action and24-hour deadline; no card or browser-supplied financial fields |
| form_post | Existing PaymentFormPostAction, exactly clientip/payment_code/cookie/price/sendid/email in that order; owning member's verified email; private,no-store |
| cookie | Lowercase Payment UUID without hyphens,32 hex characters; same Payment/idempotent start reuses it; separate Payment has a new UUID |
| sendid | Injective26-character lowercase base32hex encoding of all128 UUID bits; no hash truncation, bigint conversion or precision loss; same canonical member UUID at issue and verification |
| Kickback | Token404; configured IP/CIDR403; strict raw scalar parsing; DB amount/member/method/provider/JPY/code/reference checks before financial mutation |
| Settlement | PAY1 verified-event/status boundary and one user/payment/wallet/lot financial transaction; points, limited bonus, rank, purchase bonus, receipt and one existing mail reservation |
| Concurrency | Financial admission/transaction runner; reference advisory lock before user/payment locks; existing provider/reference, grant and event uniqueness |
| Review | Bounded progress reason and immutable payment.review.* Provider Event; survives retained progress purge; no automatic points, refund or historical balance repair |
| Returns | Fixed303 canonical Storefront paths; strict original ref; no financial/event/rank/mail mutation or request-selected URL |
| Admin | Existing method/provider labels, provider_payment_reference, review filters/detail; selecting review includes unpaid states |
| fincode | Existing provider code, config, cards, webhook, returns, resume, history and all financial data retained |

No Migration is created or reserved. Migration000099 and all applied migration
history are unchanged. Allocation Lock: none. Only existing migrations are
applied to disposable task-owned synthetic PostgreSQL, over a Unix socket with
network-none containers, no host ports and no operational credentials/ENV.
There is no operational DB read or application. API/contract changes are additive;
auth, paid-point value, paid expiry, draw and infrastructure authority are unchanged.

## Verification details and financial edge cases

The kickoff form is not financial proof. A3,000-JPY DB Payment receiving price100,
noncanonical digits, float/exponent/sign/whitespace/overflow or a different member
never grants. Incoming price is compared as a strict integer representation with
the saved Payment amount. rel is only0/1. Optional sendpoint is bounded reference
text only and cannot choose a Payment, redirect or grant authority.
Raw form values are parsed before any general trim/empty normalization can repair
them; duplicate form fields/array encoding are rejected. Malformed encoding is422;
the bounded notification body limit is16KiB. No raw notification/email/token/IP is
saved or logged by this adapter; exception SQL bindings are not reported.

Optional order_cpt uses the existing unique provider/reference constraint and a
transaction-scoped advisory lock, preventing reuse across members. Without it,
cookie is the stored provider reference. A different reference on an already
bound Payment is review, including later arrival of order_cpt after cookie fallback.
Exact repeated financial inputs are200 with no repeated settlement. Successful
and failed races serialize; terminal results never regress. Both terminal state
and actual expires_at are checked under the lock before granting. A failed or
expired Payment never grants on a late success. Unknown cookie cannot resolve a
Payment and is acknowledged without creating financial or PII records.

Techcoin supplies no trustworthy occurrence timestamp. Its Provider Event keeps
provider_occurred_at=NULL. Only this provider may use verified server received_at
for common settlement, limited-bonus and rank timing. Other providers retain the
mandatory provider occurrence timestamp check. The existing column named
signature_verified_at denotes the common verified adapter boundary; PAY3 does
not claim a signature exists or add an invented signature scheme.

Real DB/transaction failure rolls back status, reference, event, wallet/ledger,
receipt and mail reservation, then returns500. A separate bounded review is
attempted after rollback; a completely unavailable DB cannot persist that review.
T4 retransmission/alert behavior must be accepted operationally before enablement.
No provider API or alternative money-confirmation channel is introduced.

Test classification is inserted atomically before any grant. Test payments never
enter commercial rank, purchase count/amount, sales/CSV or agency totals. Live
payments use the same PAY1 commercial predicate. Classification is immutable and
is not changed when configuration changes. Existing fincode test coins/ranks or
balances are not repaired. Refunds/chargebacks remain existing Admin manual
two-phase operations. Purchase mail reuses the unique coin_purchase_completed
reservation; no retry, ambiguous-delivery resend or return-triggered mail exists.

## Configuration and fixed provider registration URLs

Configuration key names only:

- `V2_TECHCOIN_CLIENT_IP` (merchant code, not sender IP; default empty)
- `V2_TECHCOIN_ENDPOINT` (default fixed `https://secure.tc-site.com/access`)
- `V2_TECHCOIN_NETRIDECASH_PAYMENT_CODE` (default10055)
- `V2_TECHCOIN_KICKBACK_PATH_TOKEN` (default empty;32–256 URL-safe characters)
- `V2_TECHCOIN_KICKBACK_ALLOWED_IPS` (default empty; optional)
- `V2_TECHCOIN_MODE` (new explicit `test`/`live` accounting classification; default empty)

MODE is necessary because5.2 provides no test/live key or verified remote mode.
It classifies accounting, not a nonexistent provider test API. Missing/invalid
mode fails closed for new starts. Production environment never implies live.
IP restriction is optional and an empty list does not disable payments. Historical
notifications do not depend on method visibility, endpoint, merchant code or the
current accounting mode; token, optional IP and saved payment checks still apply.
The existing V2_PAYMENT_METHODS_ENABLED ordering,999999-JPY limit and saved-card
policy remain. The real origin and token are configured by Human, never in Source.

1. `POST https://{public-origin}/webhooks/v2/techcoin/{V2_TECHCOIN_KICKBACK_PATH_TOKEN}`
2. `GET https://{public-origin}/api/v2/payment-returns/techcoin/complete?ref=<COOKIE>`
3. `GET https://{public-origin}/api/v2/payment-returns/techcoin/failure?ref=<COOKIE>`

Provider substitutes <COOKIE> with the submitted cookie. Complete redirects to
`/points/purchase/thanks?pid={Payment.id}`; failure to
`/points/purchase/{PointProduct.id}?pid={Payment.id}`; invalid/unknown ref to
`/points`. All use the existing canonical Storefront origin validator and
no-store/no-referrer. Storefront records returned progress through its existing
owner-scoped client-events endpoint; the unauthenticated return GET writes nothing.

## Contract candidates and validation

Public/Admin remain unpublished2.0.0-alpha.43 candidates. Client/Testkit remain
unpublished2.0.0-alpha.47 candidates. PAY3 independently extends Webhook alpha.40
to unpublishedalpha.41 with Techcoin; existing LINE/fincode are retained. Public
operation inventory becomes88 (two new return GETs). The existing form_post type
is reused; no duplicate action type or unnecessary browser financial SDK is added.
All bundles/generated types/Testkit inventory/candidate digest are regenerated.
Immutablealpha.46, historical artifacts and Site schemaalpha.23 are unchanged.
No artifact or package is published.

Local, final-head CI and Not Run results are kept separate in the Worklog/PR.
Initial47-case verification failed46 setup cases because a synthetic password did
not satisfy the existing Argon2id constraint; fixture corrected to PasswordPolicy.
Corrected47 cases/577 assertions PASS. Initial OpenAPI68 tests passed67; stale
86-operation inventory assertion corrected to88 without changing disclosure tests.
Additional accounting/HTTP, true process races, common/fincode regression,
package/Admin, formal Required Checks and security evidence are recorded in the
Draft PR. A local component test is never a Browser/E2E or visual PASS.

Additional local accounting/manual-refund and authenticated HTTP cases passed;
the retention fixture initially advanced PHP time without PostgreSQL time and
was corrected to historical app-created progress (1 case/7 assertions PASS).
Actual forked start/kickback/reference races passed1 case/45 assertions.
Client43, Testkit54, immutable release34 and policy255 passed; Admin typecheck
passed. Local Admin rendering and worker startup hit unchanged timeouts on a
host observed at load average63/swap7.3GiB; those failures are retained and must
be distinguished from final-head formal CI. No timeout or assertion was weakened.

Initial formal PR CI40481921141 stopped at missing PR headings Scope/Specification
sources; body corrected and locally validated against the exact42-path diff.
Supplemental same-head formal CI40482355143 via the approved task-check dispatch
passed Policy/Security/support but its existing PR-event-only Candidate job is
not applicable to workflow_dispatch. This is not Candidate or final-head PASS.
This evidence update pushes a normal PR synchronize event so the unchanged
formal PR CI can generate/verify its authorized validation-only Candidate.

Only the automatic formal PR CI validation Candidate Build/Package/Verify/Load
and Actions storage is authorized. The Candidate must match the exact Source
HEAD and Manifest. It is not a release, Runtime Activation or Production Authority.
No independent production artifact dispatch, registry push, runtime transfer/load/
start, Source Authority change, Stable Tag or release is performed.

## T1–T9 and operational acceptance

| Item | Remaining condition |
| --- | --- |
| T1 | Signature unavailable is the approved implementation assumption; no invented signature |
| T2 | Sender IP list optional; only configure if obtained, with actual trusted-proxy behavior verified |
| T3 | Human confirms whether order_cpt can be enabled and its actual format; cookie fallback is implemented, actual contract setting NOT VERIFIED |
| T4 | Human confirms resend availability/interval/count, failure alerts and character encoding; provisional no-resend/UTF-8 is NOT VERIFIED |
| T5 | Actual NetRideCash refund availability/method remains NOT VERIFIED; no auto-refund API |
| T6 | Provisional no delayed-payment flow; if provider differs, re-approve deadline behavior before activation |
| T7 | Member/site amount/count restrictions remain provider/Human decisions |
| T8 | Real test environment and merchant code NOT VERIFIED; synthetic mocks only here |
| T9 | Provider purchase-email behavior NOT VERIFIED; avoid duplicate operational notices through Human configuration |

Human registers fixed URLs separately for OLD Test and NEW Production with
different tokens. Hosting owner supplies Basic-auth Webhook exception, reverse
proxy reachability/trust and token-path access-log redaction. Do not include real
values in logs/PRs/chat. Real provider roundtrip, Browser/form POST/return polling,
actual mail, merchant acceptance, OLD Test and all Production acceptance are
NOT RUN. T3/T4 and actual test roundtrip are mandatory before enabling new starts.

Storefront is a separate repository/task: render ordered hidden POST fields
without interpretation, show redirect progress, use fixed return display/polling,
API method labels and supplied unmodified NetRideCash logo, and apply Human legal/
commerce wording. No write is made to ideal-sol/luxe-pack-storefront.

## PAY2/PAY4 integration and stop boundary

After independent Human approval, intended merge order is PAY2 → PAY4 → PAY3.
Each next PR must integrate latest protected main, preserve all provider-specific
operations, regenerate candidate versions/digests/counts and rerun formal CI and
fresh exact-head self-review. Matching alpha.41 numbers do not mean matching bytes.
PAY3 has no dependency on either unmerged Draft and does not overwrite their code.

The read-only common-path intersection and exact changed-file inventory are
recorded in the PR; principal collision sites include PaymentService start/result
and timestamp handling, PaymentMethodPolicy, FincodePaymentService presentation,
PaymentController, AdminPaymentReadService, API/Webhook routes, Public/Admin/
Webhook OpenAPI and generated bundles/types, Testkit fixture/inventory/package
metadata, candidate release manifest, policy inventory tests, Admin payment detail/
history and Worklog. PAY2/PAY4 console/worker selectors must also be preserved
during later integration; PAY3 adds no worker or scheduler.

Rollback before activation is withdrawal of this Source candidate. After a future
approved activation, disable only new starts while continuing pending kickbacks/
history, preserve ledgers and review mismatches through existing manual authority.
No down migration, automatic refund or balance correction is an authorized rollback.

Target: `PAY3_SOURCE_DRAFT_READY_PRODUCTION_MERGE_HOLD` after final checks/review.
Retain Draft, branch and worktree for review. No merge actor/squash SHA, no branch
cleanup or primary checkout/main synchronization applies to this Draft stop.
Separate explicit Human GO after Production reflection remains required.

## Exact read-only shared-path inventory

| Path | PAY2 | PAY4 |
| --- | --- | --- |
| `apps/admin/src/components/payments/admin-payment-detail.tsx` | yes | yes |
| `apps/admin/src/components/payments/admin-payment-history.tsx` | yes | yes |
| `apps/admin/src/lib/admin-api/generated.ts` | yes | yes |
| `apps/admin/test/admin-payment-detail.test.tsx` | yes | yes |
| `apps/api/app/Domain/Payment/V2/Services/V2AdminPaymentReadService.php` | yes | yes |
| `apps/api/app/Domain/Payment/V2/Services/V2FincodePaymentService.php` | yes | yes |
| `apps/api/app/Domain/Payment/V2/Services/V2PaymentMethodPolicy.php` | yes | yes |
| `apps/api/app/Domain/Payment/V2/Services/V2PaymentService.php` | yes | yes |
| `apps/api/app/Http/Controllers/V2/V2PaymentController.php` | yes | yes |
| `apps/api/routes/api.php` | yes | yes |
| `apps/api/routes/webhook.php` | yes | yes |
| `manifests/storefront-contract-releases.json` | yes | yes |
| `openapi/admin/openapi.yaml` | yes | yes |
| `openapi/bundled/admin.openapi.json` | yes | yes |
| `openapi/bundled/public.openapi.json` | yes | yes |
| `openapi/bundled/webhook.openapi.json` | yes | yes |
| `openapi/public/openapi.yaml` | yes | yes |
| `openapi/webhook/openapi.yaml` | yes | yes |
| `packages/storefront-client/src/generated/public.ts` | yes | yes |
| `packages/storefront-client/test/client.test.mjs` | yes | yes |
| `packages/storefront-testkit/package.json` | yes | yes |
| `packages/storefront-testkit/src/generated/public-contract.ts` | yes | yes |
| `packages/storefront-testkit/src/payment-foundation.ts` | yes | yes |
| `packages/storefront-testkit/test/testkit.test.mjs` | yes | yes |
| `scripts/ci/policy_gate.py` | yes | yes |
| `tests/ci/openapi/test_member_rank_public_contract.py` | yes | yes |
| `tests/ci/policy/test_policy_gate.py` | yes | yes |
| `tests/release/test_storefront_contract_artifact.py` | yes | yes |
| `worklogs/new_ver_main.md` | yes | yes |
