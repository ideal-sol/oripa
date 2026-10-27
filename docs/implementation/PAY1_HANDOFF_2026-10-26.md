# PAY1 — 決済共通基盤 Source handoff

Change: PAY1-20261026 / Issue: none / Risk: R3 / Lane: Strict Change /
Application Runtime Activation: deferred. Draft only; merge is not authorized.

## Authority and source investigation

The 2026-10-26 implementation instruction and payment specification v2.2 were
read from the supplied input bundle. The specification SHA-256 is
`70d47974a5287b9916a0c8467bd8d1352a71eaf074c7d3a21d37f62bc8d5b58d`.
The originally named input directory was absent; the matching bundle was found
in the existing work input directory and verified before implementation.
Base: `c802ff91cf3d5a96b695f70e3d91194c3bed7567`.
The existing primary checkout was preserved; work uses dedicated branch
`feat/PAY1-20261026` and a dedicated worktree.
Draft PR: https://github.com/ideal-sol/oripa/pull/620. `PAY-20261026` is the
CI-compatible identifier for this same PAY1 Change, not a second Change.
During work, protected main advanced to `0372d25ea61a4e6f15485568819f8f1029443b73`.
Its Production Source authority record/test/worklog changes were inspected and
do not overlap payment behavior, migration allocation or the contract candidate.

| Existing component | PAY1 treatment |
| --- | --- |
| V2PaymentService and point transaction runner | Reuse settlement, idempotency, wallet/lot locks, rank, adjustment and outbox authority; append progress and freeze receipt in that transaction |
| fincode payment/card/webhook/return/resume | Retain code/config/data; gate new acceptance and public card operations; broaden successful history to stored providers |
| Public/Admin contracts and thin client | Add method discovery, reference events, form_post contract, labels and progress; regenerate artifacts |
| Admin payment read/adjustment | Reuse financial read and existing adjustment authorization; add provider/period/notification/review filters and progress |
| Reporting/export/agency/gacha purchase conditions | Apply shared commercial-origin predicate to payment and associated adjustment totals |
| Member rank prelaunch exclusion | Preserve immutable origin validation and exclusion; do not compensate an origin twice |
| Template mail | Reuse coin_purchase_completed reservation, unique event key, after-commit sender, status and attempts constraints |

## Migration and classification

Migration allocation lock was acquired before reserving
`2026_10_26_000099_add_v2_payment_common_foundation.php`; repository refs, active
policies/worktrees and parallel PRs showed no competing 000099 reservation.
It adds the two methods to the CHECK, an immutable commercial classification,
retained append-only progress, and immutable receipt snapshots. No existing
payment, card, wallet, point ledger or rank ledger is deleted or rewritten.
Mail delivery constraints remain unchanged.

Every fincode row present at the serialized migration is explicitly classified
as test with reason `pay1_existing_fincode`. New fincode rows are classified at
creation by the configured canonical live/test endpoint, with immutable reason.
The provider name is not a permanent exclusion. The SQL predicate
`v2_payment_commercial(payment_id)` combines the classification with existing
prelaunch exclusions and is reusable by future Paidy buyer-data construction.
Bulk financial reads use `V2PaymentCommercialQuery` with the same two indexed
`NOT EXISTS` exclusions so PostgreSQL can optimize them as anti-joins; retain
the SQL predicate for database guards. Neither path excludes fincode by name.
PAY2–PAY4 must classify their own test-mode transactions before granting/ranking.

| Read path | Historical test classification |
| --- | --- |
| Member/Admin payment history and adjustment detail | Remains visible |
| Monthly/daily/dashboard sales, refunds and chargebacks | Excluded |
| Sales/adjustment CSV and agency sales | Excluded |
| Gacha purchase count/amount | Excluded |
| New rank accounting and purchase bonus | Excluded; existing prelaunch rules preserved |
| Wallet/point/rank balances already granted | **Not corrected by PAY1** |

The existing first-user product one-use/pending-payment guard remains unchanged:
it prevents duplicate grants for the same product and is not a purchase-total
report. Activation data inspection must include these historical reservations.

Operational data has not been read. Before activation, separately authorized
read-only inspection must quantify existing test payments, their lots/current
balances, rank origins/bonuses and pending events. Existing test coins or rank
balances may remain. A display filter does not resolve those balances. If
correction is required, propose a bounded, auditable adjustment using existing
adjustment/prelaunch authority and obtain separate Human GO; never edit ledgers.

Rollback is forward-only. Do not run down or remove audit data. Before activation
the source can be withdrawn; after schema application use a reviewed forward fix
or the approved backup/restore procedure. Creation and isolated application do
not authorize application to OLD Test, staging or Production.

## Config and contract candidate

Default `V2_PAYMENT_METHODS_ENABLED` is
`credit_card,paypay,konbini,virtual_account`; `V2_PAYMENT_CREDIT_CARD_PROVIDER`
defaults to `fincode`; `V2_PAYMENT_SAVED_CARDS_ENABLED` defaults to true.
The future intended setting is `credit_card,netridecash,paidy`, card provider
`univapay`, saved cards false. No operational ENV was changed.
Unimplemented providers fail closed with unavailable/503. A UnivaPay product
over 100,000 JPY hides the card row and rejects POST with 422; 100,000 is within
the amount limit. Techcoin over 999,999 JPY is unavailable/422. Only server
product prices are used. Method switches do not reroute stored payments.

Candidate bundle: `2.0.0-alpha.47`, Public/Admin contract `2.0.0-alpha.43`;
Webhook remains alpha.40. Client and Testkit alpha.47 are source candidates,
not published packages. Site schema alpha.23 and all immutable ledger entries,
including alpha.46, remain unchanged. No contract artifact publication is authorized.

New Public operations: authenticated `GET /api/v2/payment-methods` and
authenticated/CSRF-protected `POST /api/v2/payments/{payment_id}/client-events`.
Client records are reference-only, max three per kind per payment, owner scoped,
and never authorize settlement. Provider routing, inline setup and real provider
actions remain PAY2–PAY4. Storefront purchase UI is a separate task.

Progress timestamps are server receipt time in UTC, displayed in JST. Provider
settlement time remains the existing verified event time. Retention defaults to
365 days; `V2_PAYMENT_EVENT_RETENTION_DAYS` can change future event retention.
The purge command deletes expired events only. Scheduling is disabled by default
through `V2_PAYMENT_PROGRESS_PURGE_ENABLED`; no worker or scheduler was started.
New provider event storage retains a redacted envelope and replay hash, never
raw webhook bodies or headers. Adapters must verify amount/member/mode/source
before settlement and record normalized reconciliation results. Expired success
must not auto-grant; provider adapters must expose the review result.

## Purchase mail Human decision

The latest Human decision explicitly defers retry implementation. The existing
unique reservation, pending/sending/sent/failed states and attempts <= 1 remain.
Payment transport errors use `delivery_requires_review` and existing audit
records; they are not proof of non-delivery and are never automatically retried.
A process interrupted while sending also requires operator verification.
An empty transport result also records `delivery_requires_review` with no
`sent_at`; only an explicit Laravel SentMessage result establishes acceptance.
No mail failure reverses or repeats settlement, point grants or rank work.
Retries only for provably pre-send failures require a future specification.
Exactly-once external SMTP acceptance is not claimed.

Receipts snapshot product, amount, method, JST success time, payment ID, paid
coins, product/limited/rank free bonuses, actual free-lot expiry, total grant and
post-grant balance. Zero bonuses are omitted. Paid coins have no expiry under
current Point Authority; a blanket 180-day paid-coin expiry is not introduced.
The existing template/signature and registered recipient mechanism are reused.

## Activation handoff and verification

No real provider connection, commercial charge, Production credential, operational
DB query, runtime deployment, artifact dispatch, migration or worker activation
was performed. Separate owner will combine PAY1–PAY4 and required Storefront
implementation for OLD Test. Subsequent work must validate provider credentials,
notification/reconciliation adapters, mail-provider duplicate settings, pending
payments across switches, data audit, migration backup/forward recovery and
joint end-to-end flows before activation.

Executed results and exact-head CI/self-review links are maintained in the Draft
PR and worklog. Browser/E2E or visual PASS must not be inferred from component
tests. The final local checks below passed in separate runs; initial failed runs
remain recorded. Final-head CI and self-review results are recorded in PR #620. Draft, branch and worktree are retained for
review; Ready, merge, deployment and stable tagging are outside this authorization.

| Local verification | Executed result |
| --- | --- |
| PAY1 foundation, mail, provider-switch resume/Webhook | 25 tests / 215 assertions PASS |
| Unknown provider compatibility, concurrent fincode/Webhook, historical migration upgrade | 3 tests / 118 assertions PASS after SQL function rebuild correction |
| Admin payment adjustment, gacha purchase conditions, rank purchase bonus | 71 tests / 554 assertions PASS; 4 dedicated prelaunch cases skipped in this run |
| Dedicated prelaunch atomic publication/origin protection | 1 test / 164 assertions PASS, isolated DB and explicit flag |
| Reporting/export regression including mixed commercial/test origins | 9 tests / 72 assertions PASS |
| Public/OpenAPI | 66 tests PASS; bundles/generated candidate validation PASS |
| Client / Testkit | 42 / 54 tests PASS; generation, typecheck, lint, build, export/network checks PASS |
| Admin | payment components18 + client1 + dashboard18 PASS; typecheck/lint/build PASS |
| Contract release | 45 tests PASS; immutable historical release assertions retained |
| Database guard suite | 89 tests PASS |
| Policy | local gate PASS; 340/341 suite cases passed, remaining stale negative fixture corrected and focused rerun1 PASS |
| Quality / Security | local gates PASS; five fresh dependency audits0 findings, secret candidates0 |

Backend results total109 distinct focused cases /1123 assertions across the
listed runs. This is not a claim that the complete V2 suite passed in one run.
Three additional dedicated prelaunch scenarios, Browser/E2E, visual and real
provider/runtime checks were not executed. Initial broad regression failures,
Admin load timeouts and stale contract fixtures were investigated and corrected;
no assertion or protection was removed. Migration099's functions use the existing
CREATE OR REPLACE convention because disposable migrate:fresh retains functions.

## Formal CI authorization boundary

The latest Human decision resolves the previous CI authorization HOLD. The
existing `platform-ci.yml` PR job `production-arm64-artifact` may automatically
Build, Package, Verify and Load its validation Candidate Artifact and save it to
GitHub Actions. SHA, manifest and provenance verification remain mandatory.
This exception permits normal final-head CI only; it grants no Production use.
No workflow, applicability rule, Required Check or security gate is weakened.
The old published-head run failed and skipped downstream jobs; none of those
results counts as final-head PASS. Corrected Source is delivered to the same
Draft PR #620, where final Required Checks, Artifact ID and fresh exact-head
self-review are recorded. The existing branch/worktree and local passing
evidence are reused. The allocation lock was reacquired before correcting
Migration099's payment-method comparison to explicit text, preserving its
allowlist and exact pg_dump/restore schema identity. Retain the lock through
Draft handoff and reacquire it before any later migration edit.

Separate Production Artifact Workflow dispatch, release publication, registry
push, runtime transfer/load/start, Source Authority changes, operational
DB/ENV/worker changes, OLD Test activation, Ready, merge and auto-merge remain
prohibited. After Draft completion stop for separate Human Source Merge GO.
OLD Test remains deferred until PAY1–PAY4 and required Storefront work are ready
for the separate activation owner. Target status after all final checks and
review pass: `PAY1_SOURCE_DRAFT_READY_OLD_TEST_DEFERRED`.
