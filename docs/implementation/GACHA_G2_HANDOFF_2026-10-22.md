# GACHA-ELIGIBILITY-G2-20261022 Source handoff

R3 / Strict Change / Application Runtime Activation deferred. Draft PR only.
Final checks and exact HEAD/Tree are recorded in the PR, not inferred here.
Status remains `HUMAN_SOURCE_MERGE_HOLD_PHP_HOTFIX` even when all gates pass.

## Authority and scope

Base: G1 squash `c14f35a1fe6c8265e98f1f92849a0d2e25d83e87`, PR601.
Gacha eligibility v1.2 SHA256
`6650073cdf55b2c0f96ee237991b125b24451355a89c583d790d33a808551cac`
and latest Human G2 instructions override rank v1.5 final integrated2026-10-15
section11.5 global-login/day and fixed-audience rules.

- login_daily reuses G1 audience/rank/purchase-count/purchase-amount AND
  evaluation and lifetime succeeded-payment aggregate. Successful refund or
  chargeback excludes the whole payment, including after reversal.
- Each Gacha ID allows one completed non-QA draw per User/JST day, regardless
  of rank activation. Different Gachas remain independently available after
  purchases or rank promotion. Version changes cannot reset Gacha-ID usage.
- Reasons remain authentication, signup qualification, audience, rank, count,
  amount, then usage. Existing sale/inventory priority and idempotency remain.
- Login draws retain User locking, rank materialization and immutable purchase
  snapshots. QA bypass and signup special cases remain; standard G1 unlimited
  draws and accounting/probability authority are unchanged.
- Admin reuses existing condition controls, adds login audience/day inputs,
  preserves copy/read-only publication and displays per-Gacha/day wording.
  signup_once exposes no condition controls and rejects them in API/domain/DB.
- Public login summaries add optional conditions; private/no-store eligibility
  adds optional progress/reasons. Anonymous/no-condition progress is null.
  Authenticated lists aggregate purchases once. Storefront UI is unchanged.

## Migration and contract

000096 was reserved after all-ref/active-reservation checks under the Migration
Allocation Lock. It replaces only two existing guard functions, preserving all
economic/publication/rank/snapshot constraints and adding login day bounds.
No old migration or published row is rewritten. signup_once retains all_users,
7 days, no rank/purchases, free price, one-time qualification and existing guards.
Forward-only refusal remains explicit. Preserve snapshots and use reviewed
forward recovery; older writers must not serve conditioned login Gachas.

Public/Admin OpenAPI and generated client/testkit are additive candidates.
Pending alpha.46 alone follows the bundle hash; released alpha.45 and immutable
release records are untouched. No package/contract publication is authorized.

## Validation and isolation

Executed PASS/FAIL/NOT RUN, concurrency, HTTP, migration/backup-restore,
Admin PC/mobile and Required Checks are distinguished in the Draft PR.
Old M5 tests now require different-Gacha success, same-Gacha denial and preserved
rank-lock behavior; G1's nonstandard negative case now specifically targets
signup_once. This follows the explicit new specification, not a gate bypass.

Local validation uses only G2-named synthetic resources, no exposed DB port,
network-none Unix sockets, no production ENV/credentials or Docker socket.
No Hotfix container/image/volume/network or Deployment Lock is manipulated.
Test builds and temporary CI artifacts are not formal release artifacts.

## Operational handoff — not execution authority

G1+G2 require separate operational approval and migrations through000096 in
order, matched application/contract versions and rollback planning. Source
merge must first await Hotfix Runtime Technical Acceptance, proof against old
PHP rollback and explicit Human Source Merge resumption, then renewed
Base/HEAD/CI/fresh-review checks. OLD Test/Production activation needs separate
GO. Do not deploy an older PHP artifact or infer Runtime readiness from Source
checks. Existing security HOLD is not waived. No Ready, merge, auto-merge,
official release artifact, deployment, operational migration, activation,
backfill, worker start or contract publication is performed by this task.
