# GACHA-ELIGIBILITY-G1-20261022 handoff

Status: implementation candidate; Draft PR only. Risk R3, Strict Change,
Application Runtime Activation deferred. Human Source Merge GO is required.
This record implements the approved Gacha eligibility v1.2 / G1 instruction
and member-rank v1.5 final integrated edition dated 2026-10-15. It does not
authorize deployment, contract publication, feature activation or G2.

## Source behavior

- Standard Gacha audience, rank, purchase count and purchase amount are ANDed
  in that order; purchase reasons precede the existing daily usage limit.
- `none/eq/gte/lt` enforce matching API and database bounds, nullability and
  zero-count/positive-amount contradictions. Published values are immutable;
  draft create/update/composition, copy and fixture import preserve conditions.
- Laravel aggregates succeeded payments once per authenticated list request.
  Any succeeded refund/chargeback excludes the whole payment from count and
  amount, even after reversal. Pending adjustments, free bonuses, point grants
  and draws do not count. No provider protocol or accounting writes change.
- Real draws evaluate after the User lock and rank materialization; count and
  amount are immutable transactional snapshots. QA bypass remains, with null
  snapshots. Standard `daily_draw_limit=0` still allows repeated draws.
- Rank feature OFF allows only rank `none`, including shared login evaluation.
  The existing M5 enabled-login global daily limit is deliberately retained.
- Public conditions expose all four settings. Personalized progress is limited
  to private/no-store list and presentation responses. Existing public-cache
  detail stays anonymous: conditions present, progress null. Only unmet `gte`
  returns remaining values. Storefront rendering is unchanged.
- Admin keeps existing permissions, MFA and audience/rank controls; adds bounded
  purchase inputs, summary, list/detail display and published locking. Purchase
  mutations record redacted scalar before/after audit fields.

## Migration and compatibility

`2026_10_22_000095_add_v2_gacha_purchase_conditions.php` was allocated under the
Migration Allocation Lock after verifying refs and reservations through 000094.
Only dedicated synthetic databases are authorized for validation. No published
row UPDATE or prior migration edit is used. Existing guards remain intact.
The missing `(user_id,status)` payments index is added; adjustment lookup already
has a payment-leading index. No aggregate table is introduced.

OpenAPI additions and generated candidate artifacts remain additive. Omitted
inputs normalize to none/null. The pending alpha.46 candidate checksum follows
the bundle; released alpha.45 and all immutable release ledger entries remain
untouched. Nothing is published.

Forward-only rollback: preserve schema and immutable snapshots. Do not run an
older writer against conditioned Gachas; fence affected draws and use reviewed
forward recovery. Operational migration and writer coordination require a
separate activation decision.

## Verification and remaining gates

Executed results, exact head, GitHub checks, review evidence and any failed or
not-run checks are recorded in the Draft PR and `worklogs/new_ver_main.md`.
PC/mobile Playwright uses mocked Admin transport; real Laravel HTTP/database
and multi-process concurrency tests are separate evidence, not live-provider
or deployed-runtime validation. Production/OLD Test verification is not run.

## G2 handoff

Start only after separate Human authorization and a new G2 branch/worktree/PR
based on the approved source head. G2 must remove M5's enabled-login global
daily rule in favor of the approved per-Gacha rule and change login audience
constraints deliberately, with dedicated contracts, SQL and regression tests.
G1 neither relaxes nonstandard purchase conditions nor changes login daily
count/audience storage. Do not reuse G1's migration allocation or infer release,
Source Merge or runtime GO from successful G1 checks.
