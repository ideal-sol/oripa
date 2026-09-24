# Public Prize Presentation Read Contract

Change: PRIZEIMAGE-20260924. Risk R3, Lane Strict Change, Application Runtime
Activation immediate (OLD Test API only). Issue none. Storefront source,
Production, and the new server are outside this Change.

## Initial Authority

- Exact development base, local main, origin/main, and live protected main:
  `69a260c1aa52c677fde72526de3d1d1853efea09`.
- Original checkout: `docs/CONTACT-20260920-old-test-activation-record` at
  `fda73b02ffa5dfe6b532d51effdacf6211ba85aa`.
- Existing Worklog modifications and untracked `.worktrees/` belong to prior
  Contact tasks and are preserved. Development uses an isolated task worktree
  and `feat/PRIZEIMAGE-20260924-public-read`.
- OLD Test API OCI revision at start:
  `428820aaf26fffac3aeb3dd6233d321ed6921736`.
- Previous published Client/Testkit: `2.0.0-alpha.38`; Public OpenAPI:
  `2.0.0-alpha.34`. Immutable package authority is
  `manifests/storefront-contract-releases.json`, source
  `e16f65504dc5286de2fcd70988b770d1a16d1eaf`, Artifact `10786577343`.

## Gacha Detail

Use the existing Client Gacha detail GET. `data.prizes[]` is the only added
collection; it is not duplicated under ranks.

| Purpose | Field |
| --- | --- |
| Prize ID / name | `data.prizes[].id` / `.name` |
| Rank relation | `data.prizes[].rank_id` equals `data.ranks[].rank_id` |
| Prize thumbnail | `data.prizes[].presentation_asset.path` (nullable asset) |
| Prize total inventory | `data.prizes[].total_inventory` |
| Existing order | `data.prizes[].display_order`, preserving collection order |
| Rank ID / name | `data.ranks[].rank_id` / `.rank_name` |
| Rank heading image | `data.ranks[].lineup_image.path` |
| Quantity badge decision | `data.ranks[].show_total_stock` |
| Rank ordering | `data.ranks[].display_order` |

Group the complete prize collection by rank ID. Show every prize thumbnail and
use its own `total_inventory` for a badge only when the rank flag is true.
The quantity remains available when false. Do not use `ranks[].total_stock`
for a prize badge.

The thumbnail and name authority is the published
`catalog_gacha_version_prizes.presentation_asset_id` / `display_name` snapshot.
Admin prize registration initially writes the master and draft relation. Version
creation copies the prize snapshot (and cloning preserves the source Version
snapshot). Existing published-prize presentation edits change the master without
rewriting that Version snapshot. This read therefore does not follow later
master thumbnail edits.

Quantity comes directly from `prize_inventories.total_quantity`, joined by
`gacha_version_prize_id`, without subtracting awards, shipping, or exchange.
Order is `catalog_gacha_version_prizes.sort_order`, then relation ID, matching the
existing Version read order. Keep response order for ties; no new sorting rule
is introduced.

## Draw Result GET

Use the existing authenticated `GET /api/v2/draw-requests/{drawRequestId}`.
After a draw POST, fetch this GET to obtain the additive presentation fields.
POST responses, persisted `response_data`, idempotent replay, and selection are
unchanged.

| Purpose | Field |
| --- | --- |
| Actual awarded prize | `results[].prize.id` / `.prize.name` |
| Large prize image | `results[].prize.presentation_asset.path` |
| Rank ID / name | `results[].rank.id` / `.rank.name` |
| Rank heading image | `results[].rank_lineup_image.path` (nullable asset) |
| Result order | Existing `results[]` order / `sequence_number` |
| Bulk prize cards | `prize_counts[].prize`, `.rank`, `.rank_lineup_image` |
| High-rank cards | Same added field under `high_rank_results[]` |

The existing prize asset ID and metadata come from the immutable draw response
snapshot, captured for the actual selected prize at draw time. GET resolves that
same ID through the existing public image boundary and converts its path to the
public content-asset route. It never substitutes a rank image or rereads the
current prize-master thumbnail. Existing draw-time presentation behavior is
preserved, including master presentation changes captured by later draws.

Rank lineup images use `draw_results.rank_master_revision_id` and
`catalog_rank_master_revisions.lineup_image_asset_id`, the same Rank lineup
authority and public resolver as catalog presentation, fixed to the awarded
Rank revision. Later Rank edits do not rewrite old result presentation.
Use rank name as fallback. `result_image_snapshot` remains unchanged and is not
the new lineup field. Draw results gain no stock quantities or badge controls.

## Assets And Compatibility

Prize images use `/api/v2/content/assets/{publicId}` through
`V2ContentReadService::assetContent`: image, public, non-archived, existing file.
Missing/private/archived assets retain the existing null/404 fallback boundary.
Rank images use the existing catalog presentation resolver and its revision
reference, public, non-archived, and checksum checks. No storage route, permission,
Admin URL, or secret is added to the new presentation data.

Public OpenAPI is `2.0.0-alpha.35`; Client/Testkit candidate is
`2.0.0-alpha.39`. The new properties are optional additions for compatibility.
Site Schema remains the existing immutable `2.0.0-alpha.23`. Generated types
come only from the bundled canonical Public OpenAPI. No migration is created.

Do not pin the candidate until canonical post-merge publication and readback
succeed. Exact package Artifact ID, merged source, digests, CI, self-review,
activation, and closeout evidence will be recorded in the Change PR. The
publication workflow is `storefront-contract-artifact-publish.yml`; the release
ledger currently declares the next candidate, not a completed publication.

Rollback uses the retained prior OLD Test API image and prior Client pin; no
database rollback or correction is required. Browser/visual verification and
Storefront implementation belong to the subsequent Site task.
