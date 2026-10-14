# F6 Notion Inventory Sync — Activation Proposal

Task: `NOTION-20261014` / `PRIZECSV-F6-20261014`. Risk R3, Strict Change,
Application Runtime Activation: deferred. Source implementation is not runtime
approval. Keep the PR Draft pending Human Source Review; no merge or real Notion
acceptance is authorized by this task.

## Authority and boundaries

Use the [F5 aggregation handoff](../PRIZECSV_F5_TO_F6_AGGREGATION_INTERFACE_HANDOFF.md)
and finalized architecture authorities. F5 `snapshotExport()` alone supplies the
all-card snapshot, including QA exclusions and version selection. F6 stages its
validated NDJSON after the repeatable-read transaction commits and always closes
the stream. Notion traffic never runs inside a business/database transaction.

Notion is an observation and synchronization destination, never an authority for
draw, shipping, point accounting, or inventory. Public API and Storefront are
unchanged. Neither formula A nor B is selected: available stock is always the
Notion formula result. Optional shipped mapping accommodates B without creating
or modifying a formula. A changed page has **unknown/null available stock** until
a later complete read; its old formula is never presented as newly verified.
Incomplete rollups and null numeric values remain unknown, not zero. Ambiguous
formula types fail schema validation. Long title/rich-text IDs are completed via
the page-property endpoint before matching; invalid IDs are skipped.

## Configuration

Canonical sources: `apps/api/config/v2_notion.php` and
`infrastructure/notion/compose.notion.yaml`. No new dependency is required.
Use the [Site credential boundary](site-credential-boundary.md). Each Site must
have its own restricted integration and data source. Never enter tokens in the
Admin UI, store them in the database/repository, or print resolved Compose config.
Supply the same environment to API and worker from the approved external secret
management path. Do not share tokens or data sources across customers.
The API overlay uses Compose interpolation; the worker takes all settings directly
from its Site-specific `env_file`, without an `environment` override. Provide the
complete worker settings there; omitted values use application defaults (OFF),
not host-shell Notion values. Compose `environment` overrides `env_file` even
when empty, so do not add the API's interpolation block to the worker.

| Environment | Default / constraint |
| --- | --- |
| `V2_NOTION_ENABLED` | `false`; OFF creates no jobs, makes no calls, writes no synchronization values |
| `V2_NOTION_TOKEN` | Required secret; environment only |
| `V2_NOTION_DATA_SOURCE_ID` | Required UUID; data source, not database ID |
| API version | Fixed `2026-03-11`; not an arbitrary environment override |
| `V2_NOTION_PROPERTY_EXTERNAL_ID` | 管理ID; title, rich_text, unique_id, formula(string) |
| `V2_NOTION_PROPERTY_ON_HAND` | 手持ち枚数; number, formula(number), rollup(number); read-only |
| `V2_NOTION_PROPERTY_AVAILABLE` | 空き在庫; formula(number); read-only |
| `V2_NOTION_PROPERTY_REQUIRED` | 確保必要数; number |
| `V2_NOTION_PROPERTY_ALLOCATION` | 企画割当; number |
| `V2_NOTION_PROPERTY_STORED` | 当選保管中; number |
| `V2_NOTION_PROPERTY_SHIPPED` | Unset; optional number |
| `V2_NOTION_PROPERTY_SYNCED_AT` | Unset; optional date; changed pages only |
| `V2_NOTION_SYNC_INTERVAL_MINUTES` | 15; 1–1440 |
| `V2_NOTION_REQUESTS_PER_SECOND` | 3; greater than 0, at most 10; obey provider entitlement |
| `V2_NOTION_MAX_RETRIES` | 5 additional attempts; 0–5 |
| `V2_NOTION_TIMEOUT_SECONDS` | 20; 1–60 |
| `V2_NOTION_LEASE_SECONDS` | 120; 90–3600 |
| `V2_NOTION_FAILURE_BACKOFF_SECONDS` | 300; 30–86400 |
| `V2_NOTION_WORKER_IMAGE` | Reviewed API image pinned by digest, not mutable tag |
| `V2_NOTION_WORKER_ENV_FILE` | Site-specific external API environment file |

Mappings must be distinct and their schema property IDs must not collide.
Unknown mapping keys, wrong schema types, incomplete queries and missing required
settings fail closed. API status exposes only configured/enabled flags, job
summaries and safe codes; it never exposes the token or data source ID.

## Worker, persistence and recovery

Migration `2026_10_14_000085_create_v2_notion_inventory_sync.php` adds five tables:
`notion_sync_runs`, `notion_sync_snapshot`, `notion_sync_pages`,
`notion_inventory_cache`, `notion_sync_state`. It is forward-only. Existing
migrations and domain records are not modified. Preserve sync/audit history;
there is no destructive retention or rollback command in this feature.

`php artisan v2:notion:sync` processes one eligible job. The Compose worker uses
`--loop` and checks every five seconds. Scheduling is worker-owned: 15 minutes
since the last successful completion, with failure backoff. Manual requests can
retry sooner. Business post-commit hooks coalesce with queued/processing work;
an enqueue failure cannot change an already committed business result. Periodic
sync catches missed reservations. Draw, Preview and idempotent replays do not
schedule work. Catalog composition/inventory changes use the canonical mutation
service; scheduled activation and V2 prize status transitions have explicit hooks.
The legacy `UserPrizeExchangeService` is not a V2 entry point and is unchanged.

A partial unique index and enqueue advisory lock permit one queued/processing
job. A session advisory lock permits one worker across processes; it does **not**
hold an open transaction during HTTP calls. Use a direct PostgreSQL session,
not transaction-pooling PgBouncer. Random hashed leases, connection identity
checks, heartbeat renewals and fenced writes prevent stale-worker completion.
On crash, the session lock is released; after lease expiry the next worker marks
the abandoned run failed, preserves successful counters and starts a new snapshot
after backoff. It re-reads Notion rather than replaying old payloads. SIGTERM/INT
stops the command; interrupted work is recovered through the same lease path.

All HTTP calls share the database rate state with microsecond precision. 429/529
honor Retry-After without shortening it; 5xx/network failures use bounded
additional retries. `public_api_request_blocked` is not automatically retried.
Unknown PATCH outcomes are read back before another PATCH, including the final
attempt. This suppresses duplicates but does not claim external exactly-once.

Schema and **all** query pages are validated before any PATCH. Pagination is 100;
each query/retry includes `filter_properties[]` with the validated mapping's
property IDs, including configured optional columns, excluding unrelated ones.
`request_status.type` must be complete; the 10,000-page-item ceiling and repeated
or missing cursors fail closed. Duplicate IDs skip every corresponding page;
F5-only IDs are reported, Notion-only IDs are reset to zero, invalid IDs skip.
No page creation/deletion/trash operation, formula edit or read-only column PATCH
exists. Page errors do not discard earlier successes. A partial failure fails the
run and does not advance last-success time. Warnings alone may complete.

Cache observations include actual read time. Duplicate/missing pages invalidate
prior mappings only after a complete query. Negative filtering happens in the F5
SQL statement before pagination. F3 Preview uses cached on-hand, F5's shared CTE
and its existing import plan delta; warnings never block Apply or call Notion.

## Admin verification after separate GO

1. Confirm a reviewed, migrated API/worker artifact with feature OFF.
2. Confirm the Site data source schema and read/write integration capabilities;
   humans decide the real mapping, token storage and stock A/B interpretation.
3. Enable API and worker consistently; start only the approved worker profile.
4. Read `GET /admin/api/v2/integrations/notion/status` with `catalog.read`.
5. With `catalog.manage`, POST `/admin/api/v2/integrations/notion/sync-runs` with
   an Idempotency-Key. Observe queued, processing, completed/failed through GET
   collection and `/sync-runs/{id}`. Same key replays its job; another key also
   returns an active job, while preserving its own idempotency mapping.
6. Verify changed counts, duplicate/missing warnings, nullable observations,
   negative filter and last-success time. Operator can read but cannot enqueue.
7. Review sanitized audit actions `notion.sync.requested`, `.completed`, `.failed`.

## Egress proposal — not activated

Only HTTPS `api.notion.com:443` is used, with fixed API version, bearer header,
timeouts and redirects disabled. No caller-supplied URL exists. Infrastructure
must also constrain DNS/egress and prevent HTTP/debug logging of credentials.

The existing [API egress helper](api-egress.md) enforces **API-only membership**.
Do not attach this worker using that helper or weaken its assertions. The supplied
Compose overlay proposes a worker on the Site private and API-egress networks;
an independently reviewed worker-compatible egress activation plan/helper is a
prerequisite. Docker 25 create-time routing constraints also need Site validation.
Do not apply the overlay as-is to the existing Preview boundary. Merge the source
with the Site's base Compose only in a separately approved deployment task.
Admin, database and Redis must never gain external network membership.

## Error handling

| Safe code | Meaning / operator action |
| --- | --- |
| `NOTION_DISABLED`, `NOTION_CONFIGURATION_INVALID` | Check feature and non-secret configuration presence |
| `NOTION_SCHEMA_MISMATCH` | Fix mappings/types; this run sends no PATCH |
| `NOTION_QUERY_INCOMPLETE`, `NOTION_RESPONSE_INVALID` | Incomplete pagination/response; do not treat the subset as all cards |
| `NOTION_SNAPSHOT_INVALID`, `NOTION_TRANSACTION_OPEN` | Investigate internal snapshot/transaction invariant |
| `NOTION_ACCESS_DENIED`, `NOTION_PAGE_UNAVAILABLE` | Check integration permissions and page state |
| `NOTION_RATE_LIMITED`, `NOTION_REQUEST_FAILED` | Inspect safe counters; retry after provider/backoff guidance |
| `NOTION_PARTIAL_FAILURE`, `NOTION_SYNC_FAILED` | Preserve successes, inspect page error codes, re-sync |
| `NOTION_LEASE_LOST`, `NOTION_LEASE_EXPIRED` | Allow fenced crash recovery; do not manually clear another worker lock |
| `NOTION_RUN_NOT_FOUND`, `NOTION_REQUEST_INVALID` | Check request/job identity |
| `IDEMPOTENCY_KEY_REQUIRED`, `IDEMPOTENCY_CONFLICT` | Supply a valid request key; follow existing idempotency contract |
| `NOTION_STOCK_UNKNOWN`, `NOTION_STOCK_INSUFFICIENT` | Nonblocking F3 Preview warnings, not authority to change inventory |

## Rollback and deferred acceptance

After a separate runtime GO, disable the feature in both API and worker, stop the
worker gracefully, confirm no outgoing requests/active worker, and retain the
additive database tables and audit history. Restore a compatible prior immutable
artifact only through the approved Site rollout. Do not migrate down or undo
Notion values automatically. Corrections require a new validated full sync or a
reviewed forward migration. OFF leaves historical observations unchanged.

Still pending: Human Source Review/merge GO; real data source, mapping and token;
token custody; A/B formula choice; worker egress/runtime activation; real Notion
acceptance. F3 1,000-row performance HOLD, F5 OLD Test deferred, concurrent S3/F4
OLD Test work and AWS connection impact UNKNOWN remain unchanged.

## Official protocol references

Checked for the fixed 2026-03-11 protocol, not real Site credentials or data:

- [Compose environment and env_file precedence](https://docs.docker.com/reference/compose-file/services/#env_file)

- [Versioning](https://developers.notion.com/reference/versioning)
- [Retrieve data source](https://developers.notion.com/reference/retrieve-a-data-source)
- [Query data source and completeness](https://developers.notion.com/reference/query-a-data-source)
- [Update page](https://developers.notion.com/reference/patch-page)
- [Property values](https://developers.notion.com/reference/page-property-values)
- [Retrieve property](https://developers.notion.com/reference/retrieve-a-page-property)
- [Request limits](https://developers.notion.com/reference/request-limits)
- [2025-09-03 data sources](https://developers.notion.com/guides/get-started/upgrade-guide-2025-09-03)
- [2026-03-11 trash and request status](https://developers.notion.com/guides/get-started/upgrade-guide-2026-03-11)
