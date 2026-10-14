# PRIZECSV_F5_TO_F6_AGGREGATION_INTERFACE_HANDOFF

Task: INVENTORY-20261014 / Human Feature PRIZECSV-F5-20261014.
Authority: PRIZECSV_F5_SOURCE_IMPLEMENTATION_GO_20261014.
Risk R3; Lane Strict Change; Application Runtime Activation deferred.
Source review only. This document grants no merge, deployment or activation authority.

## Interfaces

`App\Domain\Catalog\Services\V2CardInventoryService` owns all F5 arithmetic.

| Interface | Consumers | Result |
| --- | --- | --- |
| `cards(context, filters)` | Authenticated Admin | Bounded card page and unlinked relation count |
| `card(context, externalId, filters)` | Authenticated Admin | Card totals, bounded Gacha/version breakdown, all ownership status counts |
| `unlinkedPrizes(context, filters)` | Authenticated Admin | Bounded unlinked version-prize relations and total relation count |
| `snapshotExport()` | Trusted internal F6 worker code, never a public route | Temporary NDJSON stream of all cards and unlinked relation count |

The three Admin entrypoints call the existing authorizer with `catalog.read`.
All current fixed Admin roles, including Operator, have that permission.
The export is a trusted internal interface and must not be wired directly to
an unauthenticated HTTP endpoint. F6 must authorize its own execution entrypoint.

The corresponding GET routes are `/admin/api/v2/inventory/cards`,
`/admin/api/v2/inventory/cards/{externalId}`, and
`/admin/api/v2/inventory/unlinked-prizes`. No Idempotency-Key is needed.
Public and Storefront contracts are unchanged.

## Snapshot and memory contract

Each HTTP aggregation is one PostgreSQL CTE statement. Its totals, page,
ownership status counts, integrity checks and warning counts share that
statement's MVCC snapshot. No waiting row lock is acquired by this service.
Separate cursor HTTP requests have independent snapshots.

`snapshotExport()` requires no existing transaction, starts a top-level
REPEATABLE READ / READ ONLY transaction and reads at most 100 cards per page.
Each page is written to a temporary stream; no full card array is retained.
It commits before returning the rewound stream. F6 must read this stream with
`fgets`, decode one NDJSON record at a time, and `fclose` it in a finally block.
Only after export returns may F6 make external requests. The caller must not
keep a database transaction open around external communication. Exceptions
close the temporary stream and roll back the read transaction.

The output captures one inventory snapshot, not a guarantee that inventory
remains unchanged after export. F6 should keep that distinction in its status.
An export may be regenerated after failure; it does not change business data.

## Selection and arithmetic

- For draft/scheduled, select the highest version_number with status draft and
  archived_at null. This is the actual current editing-version selection in
  V2AdminCatalogReadService::mapGacha and V2CatalogMasterMutationService's
  current-version mapping. Scheduling retains a draft; a failed schedule does
  not release its planned allocation. This is not a selection of every version
  or the numerically latest version regardless of status.
- Published/sales_paused use published_version_id and verify the version belongs
  to the Gacha. Next drafts do not allocate additional units. Unpublished and
  archived Gachas allocate none; archived_at takes priority over management_status.
- Selected nonarchived Prize relations use available_quantity. Only draft and
  scheduled relations without an inventory row use initial_inventory. Missing
  published inventory fails with PRIZE_INVENTORY_UNAVAILABLE / 409; no repair or
  zero fallback is performed. Sale end time and withdrawn_quantity do not add
  or remove allocation independently of these rules.
- Ownership joins user_prizes.draw_result_id to draw_results.id and requires
  is_qa_draw false for every status. Counts are aggregated before joining
  allocation, so one inventory row cannot multiply ownership counts.
- Stored means stored, exchange_processing, shipping_requested, packing, hold.
  Shipped means shipped, delivered, return_requested. Returned means returned.
  Required quantity is allocation plus stored. Converted, expired and the
  reference-only shipped/returned totals are not added to required quantity.
- Ownership survives unpublished/archived origins and old versions. Detail
  includes historical ownership versions, marked separately from selected
  allocation versions. Its won field counts all non-QA ownership statuses and
  is informational, not another reservation component.
- F4 archived masters and composition orphans allocate no units. Identity
  enumeration is the union of Prize external IDs and nonarchived Banner IDs;
  a historical Prize ID can therefore remain visible with zero totals.

## Presentation and pagination

Banner presentation uses its latest Content Version, one image link and a
nonarchived public image asset. Canonical asset paths use public UUIDs through
`/api/v2/content/assets/{id}` and the existing Admin asset delivery component.
No bucket key, storage identifier or signed URL is returned. Missing library
title/image is null; it does not fabricate inventory or block a Prize-only ID.

Card ordering uses PostgreSQL C collation by external_id. Authenticated opaque
cursors bind resource, normalized filters, limit and last key. Search uses
literal substrings, so `%` and `_` are not SQL wildcard operators. Each page
is limited to 100 rows; detail and unlinked pages have their own cursor scope.
Status counts and card totals cover the complete card, not just a detail page.

Unlinked count means the number of version-prize relations selected for
allocation or retaining non-QA stored ownership. Quantity means allocation
plus stored units for that relation. Orphan masters without either reference
are not warnings. These units are excluded from card totals.

Notion on-hand, available and read-at fields remain null. A negative-only
filter returns an empty page; the Admin control is disabled and labeled
unconnected. There is no sync button, credential input, job, external client,
worker or Notion-specific persistence in F5.

## Verification and rollout boundary

Tests use an independent synthetic PostgreSQL instance and network-none
execution containers connected only through a task-owned Unix socket.
Backend storage is local/fake, execution uses an explicit synthetic environment,
and AWS metadata/shared credential lookup is disabled. Dependency acquisition
occurs outside isolated test execution. Existing migrations are applied only
to new synthetic databases. No new migration or index is needed for F5.

The snapshot concurrency test is intentionally run separately with
V2_F5_SNAPSHOT_TEST=1 against an empty oripa_v2_f5_snapshot_synthetic database.
It commits fixtures, executes a real Draw in a separate PHP process between
export pages, checks the old snapshot and then confirms the committed new draw.
The normal transaction-based suite skips that one test; its separate result
must be reported, never inferred. The 1,000-ID test measures bounded cursor
enumeration and reports elapsed time and process peak memory.

Exact tests, retained failures, performance measurements, final head, Required
Checks and self-review belong in the PR and worklog. Existing F3 1,000-row
performance HOLD remains unresolved. Browser verification uses synthetic API
fixtures; it is not authenticated verification against OLD Test.

Rollback before activation is to leave the Draft PR unmerged. A later approved
deployment can remove this read-only UI/API source without data rollback.
OLD/NEW/Production, AWS resources, application ENV, operational databases,
existing Draw/Shipping/Point writes and existing task locks remain untouched.
