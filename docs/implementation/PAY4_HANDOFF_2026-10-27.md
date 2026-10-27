# PAY4 — Paidy Source handoff

Task: PAY4-20261027 / CI Change ID: PDY-20261027 (one Change) / Issue: none.
Risk: R3 / Lane: Strict Change / Application Runtime Activation: deferred.
Branch: `feat/PDY-20261027`, dedicated isolated worktree.
**Draft only. Source Merge, Ready, auto-merge and Runtime Activation are prohibited
until Production reflection completes and Human separately gives explicit GO.**

## Authority and source investigation

The supplied implementation instruction and specification v2.2 were read and
the specification SHA-256 verified before implementation:
`70d47974a5287b9916a0c8467bd8d1352a71eaf074c7d3a21d37f62bc8d5b58d`.
The input bundle and provider documentation are not copied into this public repo.
Root and applicable nested AGENTS, canonical Task Start/Governance REV3, Release
Gates REV2, GitHub Operations ADR REV1, payment, security and API/package
authorities apply. Primary checkout and its unrelated dirty Worklog are retained.

Base is the live protected main and PAY1 formal merge:
`85c2b72c3595b68dda60388ff584cefff775c181` (PR #620).
PAY2 is a separate unmerged Draft PR #622; read-only source reference:
`dc269f0361424edcd87162f39335e41e82bd2966`.
PAY4 neither stacks on nor cherry-picks PAY2. Its branch, worktree, PR and HEAD
are not changed. No Source Lock is needed for independent Draft worktrees;
overlapping semantic integration is explicitly deferred below.

The GitHub App transport policy is scoped to this branch and exact changed
paths, with Draft/checks/review operations only. It has no Ready, merge,
auto-merge, release, Production artifact dispatch or Runtime authority.

## Reuse and implementation

| Boundary | PAY4 implementation |
| --- | --- |
| New payment | Existing SMS/product eligibility/idempotency and frozen product/bonus snapshots; Paidy requires_action with 60-minute deadline |
| Checkout | `paidy_checkout`, fixed official script URL, public key only, server amount/order_ref, quantity1, tax/shipping0 |
| Identity | Owning member's verified email and verified Japanese mobile; nonempty full name only when family/given separation is available; no address |
| Buyer data | Non-Paidy commercial succeeded purchases, PAY1 prelaunch/test exclusions and successful refund/CB exclusions; JST elapsed days and rolling three calendar months |
| Confirmation | Exact Retrieve, ID/order_ref/JPY/integer amount/immutable test flag checks, then Capture with `{}`; valid payment objects across all2xx |
| Settlement | PAY1 `confirmSucceeded`, user/payment/wallet/lot locks, point ledger, limited bonus, rank purchase bonus/accounting, receipt and one mail reservation |
| Webhook | Durable minimal receipt and Outbox only; URL token404; no synchronous provider IO even with sync queue |
| Recovery | Dedicated Paidy Outbox selectors, persistent lease reclaim,1/5/15/60-minute retry schedule, worker default OFF |
| Admin | Existing provider/reference/history plus processing/late-success/reversal/grant-failure review; manual financial operations unchanged |
| fincode | Code/config/cards/Webhook/return/resume/history retained; optional Paidy presentation added to the common existing read |

Public route: `POST /api/v2/payments/{payment_id}/paidy-authorizations`.
Auth, owner, SMS, CSRF and Idempotency-Key apply. Another member gets404;
another provider409. The request accepts only `paidy_payment_id`.
The response remains `private, no-store`; browser reports cannot settle money.
Paidy resume is409. Method visibility gates new starts only; stored provider
reconciliation and history continue after hiding the method.
Coin balance reuses the current-user point reader, including its existing
expired/reserved-lot handling, rather than duplicating raw wallet arithmetic.

`V2PaidyResponseClassifier` is shared by browser, Webhook worker and scheduled
recovery. AUTHORIZED with empty captures does not grant. CLOSED needs exact
capture sum and no refunds; empty captures fail, refunds require review without
automatic debit, and mismatches/invalid responses never grant. Capture400
`payment.authorization.expired` fails. Other4xx,5xx, transport failure and invalid
2xx immediately Retrieve again. Processing is never automatically expired or
closed for uncertainty. Captured events on expired/failed/canceled records, or
captures occurring after the deadline, never automatically grant.

## Persistent operation claim and recovery limits

No new Migration is required. Existing Outbox unique deduplication keys and
row-locked lease records suffice. The refund-only `payment_provider_operations`
constraint is deliberately preserved. Before sending Capture or Close, a
per-payment unique `payment.paidy.operation` claim is committed separately from
HTTP. Its delivered status means **claim recorded**, not provider success.
Only `payment.paidy` / `paidy.lookup,paidy.payment` jobs execute the worker.
No generic executable money job or automatic refund is introduced.

The permanent claim prevents a second Capture even after an expired lookup
lease, crash or ambiguous response. Subsequent attempts Retrieve and may settle
an already captured payment once. If the provider remains authorized, the claim
is retained and review is required; PAY4 does not automatically resend an
ambiguous money request. This conservative choice can leave a truly unsent
Capture for manual investigation. Do not remove the claim to force retry.
Provider HTTP never holds a database transaction.

If Capture succeeds but grant fails, its grant transaction rolls back, review is
recorded and the durable lookup retries Retrieve/settlement. Existing terminal
state and common financial locks are checked again inside the grant transaction.
Purchase mail retains PAY1's unique reservation and one-attempt policy: ambiguous
mail delivery is review-only, with no resend or automatic point reversal.

A Webhook contains a Paidy payment_id; recovery GET resolves order_ref from the
provider, never from its untrusted notification. If both browser and Webhook
lose an ID before association, Paidy's documented exact-GET interface cannot
discover it from a local UUID. The pending job retains local evidence; no guessed
ID or undocumented provider listing/search API is invented. Merchant dashboard
investigation is required in that case.

## Configuration and provider acceptance

Configuration names only; no operational values have been set:

- `V2_PAIDY_PUBLIC_KEY`, `V2_PAIDY_SECRET_KEY`
- `V2_PAIDY_API_BASE_URL` (canonical `https://api.paidy.com`)
- `V2_PAIDY_API_VERSION` (`2018-04-10`)
- `V2_PAIDY_STORE_NAME`, `V2_PAIDY_WEBHOOK_PATH_TOKEN`
- `V2_PAIDY_MODE` (explicit `test` or `live`; missing/unknown fails closed)
- `V2_PAIDY_WORKER_ENABLED` (default false)

Official docs specify pk_/sk_ prefixes but do not establish a dependable
test/live suffix. The explicit mode is therefore required and classification
is frozen at Payment creation, then checked against Retrieve's boolean test.
Key/mode validation by the merchant remains a real-provider acceptance item.
Base URL/version are fixed to documented values; redirects are disabled.

Webhook route: `POST /webhooks/v2/paidy/{token}`.
Command/schedule: `v2:paidy:work --limit=20`, every minute only while enabled.
No ENV, Worker, Scheduler, Queue, Nginx or operational DB has been changed.
Merchant configuration must redact tokenized paths from access logs in the
separately authorized deployment task. No invented signature or IP requirement
is imposed. Receipt persistence failure returns503 rather than acknowledging loss.

Official references reviewed: [Paidy API](https://paidy.com/docs/api/jp/),
[payment lifecycle](https://paidy.com/docs/jp/payments.html),
[Webhook envelope](https://paidy.com/docs/jp/webhook.html).
The documented buyer_data age means elapsed registration days, consistent with
v2.2; ltv/user_id and age are supplied, optional absent last-purchase data omitted.
Generic shipping/billing-address requirements in the recurring-payment API do
not override the explicit digital-goods Checkout requirement and P1 assumption.

| Item | Deferred Human/provider acceptance |
| --- | --- |
| P1 | Enable digital-goods mode with optional name/address |
| P2 | Merchant approval for oripa use and actual limits |
| P3 | Legal/brand-required terms and disclosures |
| P4 | Validate actual Webhook delivery/origin operational handling |
| P5 | Overlay/browser behavior and real callback acceptance |
| Operations | Test/live key pairing, token-path logging redaction, alerts and worker ownership |

## Contract candidate and verification

Public/Admin remain unpublished alpha.43 candidates; Client/Testkit alpha.47.
Public operation count87 adds `confirmPaidyAuthorization` and Checkout schemas.
Webhook candidate alpha.41 independently adds Paidy to main's alpha.40.
All immutable releases including alpha.46 and site-schema alpha.23 are retained.
No package publication, registry push or release artifact dispatch is authorized.

Source verification outcomes are recorded below. Exact final HEAD/Tree, all five
Required Checks, validation-only Candidate Artifact IDs and machine-readable
self-review are recorded in the final Draft PR and Worklog.
Initial contract66-case run found the expected operation inventory86→87 update;
the corrected69-case run passed. Host composer install correctly rejected PHP8.3;
testing uses the required PHP8.4 container without dependency or lockfile changes.
An initial Admin command accidentally selected the full suite; it was stopped
after unrelated timing failures and replaced with the focused payment test.
Assertions and timeouts are not weakened.

The first isolated Paidy26-case run found one fixture error and17 failed
assertions. The common cause was using new progress event names outside PAY1's
existing database CHECK; both transport and review now use its accepted
`provider_api_called` event with allowlisted detail. No migration/constraint is
changed. The rank fixture now rolls back activation inside its transaction,
preserving the irreversible activation guard. Anonymous mutation fixtures supply
real CSRF conditions before asserting401. Focused Capture201 recovery now passes
1 case/14 assertions; rerun the full Paidy-focused selection on final source.
The CI fixture registry also adds the authorized operation to its exact inventory;
the negative generated-fixture test retains an explicit87→86 corruption.

Corrected isolated verification covered30 distinct Paidy cases:28 passed in the
29-case run (238 assertions including the failed fixture), followed by2/20 PASS
for the corrected credentialed-CSRF fixture and added public start API case.
The earlier failed anonymous case omitted Laravel test `withCredentials()`;
the real CSRF implementation and401 expectation remain unchanged. Covered
Capture201,403/timeout/5xx/invalid2xx recovery, no second Capture, grant rollback
and recovery, immutable classification/rank/mail, terminal/late results, ID reuse,
verified identity, Webhook durability/reversal and actual forked process races.
Final formal CI independently runs all applicable cases on the exact head.
OpenAPI69, Client43, Testkit55, Admin focused12, policy255 and immutable release34
tests PASS. Admin typecheck/lint and package generation/typecheck/lint/build/export/
network boundaries are checked as applicable. fincode/PAY1 regression results and
formal final-head checks are recorded in the Draft PR before completion.

Nontransaction HTTP/race classes restore the disposable V2 test schema once per
class, matching the existing committed-race harness's isolation principle. The
initial local fincode rerun was stopped after its whole-database count assertions
found leftover committed Paidy fixtures. Reset the isolated schema and rerun;
never delete or relax immutable financial histories to satisfy fixture counts.
The cleanup bootstraps a testing-only PostgreSQL application and applies the
existing V2 migration root; it cannot authorize an operational database reset.
Final classifier review also rejects normalized invalid calendar dates and
malformed/fractional refund elements as uncertain2xx results, retaining durable
reconciliation. Added assertions preserve these negative cases. Any earlier-head
CI/Candidate is historical only; Source changes renew every formal head gate.

No Migration is created or reserved; allocation lock: none. Existing migrations
are applied only in the disposable task-scoped synthetic PostgreSQL database.
Real Paidy API/keys, actual authorization/Capture/billing, merchant approvals,
real Webhook delivery, Browser/visual acceptance, Storefront JS, real mail,
OLD Test/Preview and Production Runtime are **NOT RUN**.

Only the automatic formal PR CI `platform-ci.yml` validation Candidate
Build/Package/Verify/Load and Actions storage are allowed. Candidates are not
releases, deployment approval or Production Source Authority.

## PAY2 integration and rollout hold

After separate Human approval, merge PAY2 first; another integration owner must
then integrate PAY4 into latest main, preserving both providers and renewing
final-head checks/review. Potential conflicts include:

- `V2PaymentMethodPolicy`, `V2PaymentService`, `V2FincodePaymentService`,
  `V2AdminPaymentReadService` and PaymentController;
- API/Webhook/console routes and provider worker selectors;
- Public/Admin/Webhook OpenAPI and generated bundles/types;
- Client payments facade, Testkit fixtures/generated inventory/package metadata;
- Candidate release manifest/digest and CI inventory fixtures;
- Admin payment detail/history, associated tests and Worklog.

PAY2's independent Webhook alpha.41 contains UnivaPay; PAY4's contains Paidy.
Neither is a published immutable release. Integration must retain both operations
and recompute candidate versions/digests/counts rather than overwrite one side.
Do not lower gates or alter fincode routing during conflict resolution.

PAY4/PAY3 and the separate Storefront work precede a later coordinated OLD Test
rollout. This task does not authorize any rollout. Retain the Draft, branch and
worktree for that integration. No merge cleanup or local-main switch is performed.
Rollback before activation is withdrawing this Source candidate. After any future
activation, disable new Paidy starts while retaining pending reconciliation and
all ledgers, then use reviewed forward fixes; no automatic refund/data deletion.

Target status after exact-head checks and review:
`PAY4_SOURCE_DRAFT_READY_PRODUCTION_MERGE_HOLD`.
