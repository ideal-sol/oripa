# Exchange-only Platform Draft handoff

Change `EXCHANGEONLY-20261104`; R3; Strict Change; Application Runtime Activation
`deferred`. Human authorizes Draft PR only. No Ready, merge, deployment, operational
migration, release, tag, Notion access or Storefront repository change is authorized.
Base: `ac0c256d674c220f59c0d9557576520aab56e067`; PR651 is already merged.

## Contract

- Banner management input/response adds `exchange_only`, default false. CSV
  creation defaults false; CSV updates preserve the existing value.
- Prize registration accepts optional `banner_id`. Laravel resolves the current
  banner/image/external ID and rejects ambiguous or inconsistent selections.
  The browser cannot independently assign the classification. Composition copy
  uses `source_prize_id`, verified against the persisted image and external ID,
  to preserve the source classification. Selecting a different banner clears it.
- Public lineup, login/signup lineup, draw prize reference and owned prize
  responses add `exchange_only`. Owned values are acquisition snapshots.
  `allowed_actions.shipping` is false with reason `exchange_only`; existing
  point exchange and selection eligibility still apply. Direct shipping retains
  `409 / PRIZE_NOT_SHIPPABLE`, with transaction rollback.
- `GET /api/v2/me/prizes?status_group=stored&limit=100` and
  `status_group=shipping&limit=100` filter before cursor pagination.
  Shipping includes requested, packing, shipping-linked hold, shipped, delivered
  and return_requested. Stored includes stored and hold without a shipping item.
  Unfiltered calls keep all statuses and default20. Each group's cursor is scoped;
  passing a stored cursor into shipping is rejected. Legacy unfiltered cursors
  remain supported.
- Thin Client: `listPrizes(cursor?)` remains valid; new calls may use
  `listPrizes({ status_group: "stored", limit: 100, cursor })`.
- Testkit exports `PUBLIC_EXCHANGE_ONLY_PRIZE_FIXTURE` for the shipping-disabled,
  exchange-enabled owned-prize case; combine with existing normal/shipping-only fixtures.

## Storefront handoff

Use the generated Public contract and thin Client, never direct `/api/v2` fetch.
Render the specification's purchase notice and mixed-shipping confirmation;
do not introduce customer exchange-only badges. Keep exchange-only selections
after confirmed successful shipping of the eligible subset. Do not change
selection on uncertain/failed shipping. Include classification in result grouping.
These Storefront UI changes are not implemented in this repository.

Source contracts are `openapi/public/openapi.yaml` and `openapi/admin/openapi.yaml`.
Regenerate with `pnpm openapi:bundle`, `pnpm admin:generate`,
`pnpm storefront:generate`, and `pnpm testkit:generate`.
No new artifact has been published. Existing alpha.49 artifacts do not contain
this change and must not be overwritten or represented as supporting it.
The canonical ledger now records an **unreleased pending** alpha.50 candidate:
Client/Testkit alpha.50, Public/Admin Contract alpha.46, Webhook alpha.45,
93 Public operations, unchanged referenced Site Schema alpha.23. Public digest:
`c5cbb72aa0b5a2fa8a76d9e034ae223d2209ea468dcba68de8a8dad2b58eb2a3`.
This source/version intent fixes the release gate's comparison against published
alpha.49; it is not an Artifact, release authorization or Site adoption pin.
All immutable history, latest alpha.49 evidence and existing digests are preserved.
After separate Human merge/release approval, recheck main, candidate conflicts,
Artifact inventory and Release Lock, then use the canonical exact merged-main
publication workflow. Record the actual source SHA, manifest/package hashes and
Artifact evidence in a separate release-ledger reconciliation PR. The Site must
verify that published manifest and pin actual versions/integrity hashes.

## Migration and rollback

`2026_11_04_000105_add_v2_exchange_only_prizes.php` is reserved under the shared
Migration Allocation Lock. Existing migration bytes are unchanged. Four boolean
columns default false, with economic and shipping constraints, immutable owned
snapshots and published classification guards. No backfill changes existing
rights. Hold remains legal for financial processing; shipping items are forbidden.

Operational application is deferred. Before activation, apply schema before
writers and validate the Site's exact artifact compatibility. After exchange-only
rights exist, do not restore an older shipping writer that ignores them. Retain
schema and ownership history and use a forward correction; `down()` refuses.

Notion inventory excludes exchange-only identifiers from aggregates and warnings.
Archived banners and classified prizes retain exclusions. F6 skips external PATCH,
including zero updates for Notion-only IDs, and leaves excluded cached values alone.
Lottery inventory and probability remain authoritative and unchanged.

## Verification evidence

Executed test counts and final head review are recorded in the PR and Worklog.
Synthetic DB application is separate from operational application. Browser/E2E,
visual acceptance, real Notion, production capacity and operational deployment
are not claimed. CI status is the exact Draft head's emitted check status, not
inferred from local checks. No merge recommendation overrides the Human HOLD.

Specification SHA256:
`57dca153e36442297cfb79c2c4e20c25b13f1575c555c547dfb629481d29a41d`.
Platform prompt SHA256:
`c4b7effcfc2380cfff969d7cfb4acbeba6acfda1176913277e72b75a9a7e1890`.
