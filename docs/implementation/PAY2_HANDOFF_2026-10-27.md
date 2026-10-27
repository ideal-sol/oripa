# PAY2 — UnivaPay Source handoff

Change: PAY2-20261027 / CI ID: PAY-20261027 (same Change) / Issue: none.
Risk: R3 / Lane: Strict Change / Application Runtime Activation: deferred.
Draft only. Ready, Source merge, auto-merge and all Runtime changes are prohibited
until Production reflection finishes and Human separately gives explicit GO.

## Authority and isolation

The supplied implementation instruction and specification v2.2 were read before
implementation. Verified specification SHA-256:
`70d47974a5287b9916a0c8467bd8d1352a71eaf074c7d3a21d37f62bc8d5b58d`.
Provider documents and input bundles are not committed.
Base and PAY1 formal merge: `85c2b72c3595b68dda60388ff584cefff775c181`, PR #620.
Root/nested AGENTS and canonical governance, release, security, data/payment and
API/package authorities govern this Change. The old primary checkout's unrelated
dirty worklog and existing worktrees are preserved. Dedicated branch:
`feat/PAY2-20261027`. No Source Lock is needed for the isolated payment scope.
An exact-path transient GitHub App transport policy limits delivery to Draft,
checks and review; it has no Ready, merge, auto-merge or artifact-release authority.

## Reused authority and implementation

| Component | PAY2 behavior |
| --- | --- |
| PAY1 method policy | UnivaPay configuration availability; server product amount; hide card row over 100,000 JPY; fincode limit unchanged |
| Common payment creation | SMS, purchase eligibility, idempotency, product/limited bonus snapshots, immutable test/live classification |
| Common start/status/grant | 60-minute action; verified GET only; shared transaction, user/payment/wallet/lot locks, rank, receipt and one mail reservation |
| Provider events/progress | Immutable minimal receipts, separate provider occurrence and server receipt times, allowlisted reconciliation details |
| Existing Transactional Outbox | Atomic durable receipt and job with event ID/charge ID only; lease reclaim and retry; no sync queue execution |
| Admin review/history/M9 | Stored provider reference, mismatch/duplicate/late-success/reversal review; manual refund/chargeback authority unchanged |
| fincode | Existing config, code, cards, Webhook, returns, resume and history retained; stored payments continue after method switches |

No new Migration is required. Migration000099 is unchanged. No migration number
was reserved and no allocation lock acquired. Existing migrations are applied
only to the dedicated disposable synthetic PostgreSQL database for tests.
No operational database was read or modified.

UnivaPay is inline `checkout=payment`, `inline=true`, `capture=true`,
`tokenType=one_time`. Only the form JWT is public. Verified email and optional
verified Japanese phone are returned only to the owning member, with no-store.
The fixed thanks URL uses the existing canonical Storefront origin validator,
without requiring fincode API keys. No card field, including null, is accepted
for new UnivaPay payments. No card number/CVV/token passes through the adapter.
UI must compare form amount against `next_action.amount` before submit.

Charge GET verifies ID, store, current and immutable creation mode, member and
payment metadata, JPY, exact integer amount and one_time. The existing unique
provider/charge index plus transaction/advisory/user/payment locks prevents
charge reuse and duplicate grants. A second charge requires review. Authorization
alone never grants. Expired/failed/canceled and late successes never auto-grant;
refund/cancel notifications never call a refund API or reverse points.
Unknown HTTP/response results remain uncertain. Provider error text is discarded;
only bounded numeric error codes and normalized statuses can enter progress.

`test` payments are immutably classified test before settlement; `live` payments
are commercial. PAY1 queries and rank services consume that classification.
Existing coins, rank balances, point lots and historical fincode data are not
corrected or deleted. Mail uses the existing unique `coin_purchase_completed`
reservation. No resend/retry capability is added to purchase mail; ambiguous
delivery remains operator review.

## Configuration and operational ownership

Config names only; no real values have been registered:

- `V2_UNIVAPAY_API_BASE_URL` (canonical `https://api.univapay.com`)
- `V2_UNIVAPAY_STORE_ID`
- `V2_UNIVAPAY_FORM_APP_TOKEN`
- `V2_UNIVAPAY_API_APP_TOKEN`
- `V2_UNIVAPAY_API_SECRET`
- `V2_UNIVAPAY_MODE` (`test` or `live`)
- `V2_UNIVAPAY_WEBHOOK_PATH_TOKEN`
- `V2_UNIVAPAY_WEBHOOK_AUTH_TOKEN`
- `V2_UNIVAPAY_NOTIFY_ALLOWED_IPS` (optional exact IP list; empty accepts)
- `V2_UNIVAPAY_WORKER_ENABLED` (default false)

Existing `V2_PAYMENT_CREDIT_CARD_PROVIDER=fincode` remains the default and the
future switchback. Method visibility affects new starts only. Webhook processing,
reconciliation and stored history use the stored provider independently.

Webhook endpoint: `POST /webhooks/v2/univapay/{token}`. Wrong path is404;
wrong normalized Authorization or configured IP restriction is401. Receipt and
Outbox insertion are transactional; no HTTP lookup occurs in the request, even
with Laravel's sync queue. Bounded database lock/statement waits protect the
response budget. Persistence failure returns503 and does not acknowledge loss.
Unknown/malformed authenticated bodies get a minimal ignored receipt and200.
Provider receipt rows remain immutable; Outbox owns delivery state.

Dedicated one-shot commands are `v2:univapay:work --limit=20` and
`v2:univapay:reconcile --limit=100`. Both reject execution while the worker flag
is false. Source schedules are every minute and every five minutes respectively,
also gated OFF. Outbox lease reclaim handles worker crash; failed lookups retry
after60 seconds. These workers claim only `payment.univapay/univapay.lookup`.
No real Worker, Queue, Scheduler, ENV, Nginx or service has been changed.

Reconciliation selects pending payments after5 minutes and terminal investigation
through24 hours after their60-minute expiry. Unknown charge IDs use paginated
creation-date/amount/mode search; metadata is checked locally, then every candidate
gets an exact GET. At most10 pages per scan; incomplete discovery is uncertainty.
Repeated scans account for list lag. API `created_on` is retained as provider
charge time, distinct from server observation; actual issuer completion timing
must be verified in real tests before enabling time-sensitive production flows.

## Provider documentation and unexecuted acceptance

Official references consulted: [authentication](https://docs.univapay.com/docs/api/general/authentication/),
[charge GET](https://docs.univapay.com/docs/api/charges/request/get/),
[charge LIST](https://docs.univapay.com/docs/api/charges/request/list/),
[Webhook envelope](https://docs.univapay.com/docs/api/webhooks/overview/).
No API request or actual charge was made. The LIST amount boundary description
differs from the official SDK wording; discovery includes a1-JPY margin and exact
GET comparison is authoritative. JWT claims are checked for local consistency
when present; authenticity and store permissions remain provider validation.

| Item | Remaining real-provider/Human acceptance |
| --- | --- |
| U1 | Inline update metadata roundtrip, including both payment/member IDs |
| U2 | Actual Authorization normalization, retry and stopped-webhook alerts |
| U3 | Metadata filter grammar, pagination and list lag; current fallback is date/amount/mode discovery |
| U8 | Merchant restrictions to Visa/Mastercard; no unconfirmed inline paymentMethods assumption |
| U10 | Japanese phone local leading zero; optional phone field permits form input fallback |
| U12 | submit Promise/event charge-ID shape and browser callback ordering |
| Operational setup | Form-domain registration; disable optional provider purchase-completion email, retain required authentication notices |
| Hosting | Basic-auth exception, proxy trust if IP restriction used, token path redaction in access logs, worker and scheduler ownership |

Inline browser UI, test cards, 3DS popup, merchant settings, actual mail, Browser/E2E,
visual acceptance, OLD Test and Production are **NOT RUN**. These are separate
tasks, not implied by mock or component PASS. Provider secrets/test cards are not
used in this Change. No Storefront Repository changes are made.

## Contract candidate and verification record

PAY1's unpublished Client/Testkit bundle alpha.47 is extended, Public/Admin
alpha.43 retained as unpublished candidates, and Webhook candidate alpha.41
adds UnivaPay to existing alpha.40 behavior. Immutable alpha.46, all historical
artifacts and site-schema alpha.23 are unchanged. New Public operation:
`reportUnivapayCharge`; optional inline form and action schemas are additive.
No contract publication/dispatch is authorized.

Executed evidence, failures and corrections are recorded in the PR/worklog.
Initial24 mock cases:22 passed; corrected a strict float fixture and removed an
attempt to update immutable receipt state (Outbox alone owns delivery state).
Initial OpenAPI68 cases:67 passed; operation inventory updated86→87 while
retaining disclosure assertions. Final exact HEAD/Tree, local results, five
Required Checks, CI-only Candidate Artifact ID and machine-readable self-review
are recorded in the final Draft PR. No earlier-head CI is reused as final PASS.

Only automatic formal PR CI Candidate Build/Package/Verify/Load and Actions
storage is allowed. It is validation-only, never a formal release artifact.
Independent artifact dispatch, registry push, runtime use, Production Source
Authority/provenance change and Stable Tags remain prohibited.

PAY4/PAY3 and the separate Storefront implementation must complete before another
owner considers the coordinated OLD Test rollout. Production reflection completion
and separate explicit Human Source Merge GO are required before PAY2 Ready/merge.
Target status after final checks/review: `PAY2_SOURCE_DRAFT_READY_PRODUCTION_MERGE_HOLD`.
Retain Draft, branch and worktree at handoff; no merge cleanup or main switch.
Rollback before activation is source withdrawal. After a future activation,
disable new UnivaPay starts while retaining pending reconciliation/history,
preserve all financial records, and use a reviewed forward fix. No automatic
refund, point reversal or rollback of historical ledgers is authorized.
