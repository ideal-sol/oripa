# Banner CSV import: F2-B Source / deferred activation

Authority: `BANNERCSV_F2B_HUMAN_SPEC_APPROVED_20261013`,
`BANNERCSV_F2B_SOURCE_IMPLEMENTATION_GO_20261013`, superseded only for row limit
and automatic deletion by
`BANNERCSV_F2B_IMPLEMENTATION_RESUME_MAX200_GC_HOLD_20261013`.
Parallel source coordination: `F2B_S3_PARALLEL_SOURCE_WORK_APPROVED`.
Isolated test resumption: `BANNERCSV_F2B_SAFETY_FIX_AND_IMPLEMENTATION_RESUME_GO_20261013`.

Strict Change, R3, Activation deferred. DO NOT MERGE / ACTIVATE / DEPLOY without
separate Human GO. Source and synthetic verification are not S3 acceptance.

## Input and retained results

New submissions accept 1–200 data rows, excluding the header, and 2 MiB UTF-8
with BOM support. Physical line numbers, quoting, trim and header aliases remain
unchanged. All validation precedes Job/Row/Idempotency/Asset/Banner writes.
The separate validation-error display cap remains 200. F3 remains 1,000 rows.
Migration 000082 retains its 1–1,000 CHECK; result contracts retain legacy history
capacity rather than making old jobs unreadable. Only input acceptance is reduced.

New Banners are draft/non-top; that does not make an image URL inaccessible.
Existing published version, publication status, display/link/order/window and
external ID are preserved. Refresh defaults OFF; OFF does not inspect the URL of
an existing Banner. Identical image SHA-256 reuses the existing Asset.

## BANNERCSV_F2B_CANONICAL_INTERFACE_REVIEW_PACKAGE

### Ownership and interfaces

F2-B alone edits `V2ContentContactAdminService.php`. Shared validation, Object Key
generation and Asset DB insertion remain Canonical. Ordinary Admin upload retains
session/MFA authorization, its response shape, idempotency and exception cleanup.
The Import path no longer invokes that cleanup-bearing private upload method.
At pre-publication review S3 #553 remains OPEN/Draft at
`5e63e1fbae79242a1726f99553c9e88fd14b6da6`; its unmerged changes are not imported.
Resolver/Normalizer/Middleware/Provider and Storage configuration remain S3-owned.
Shared Worklog/Policy files compare cleanly in a read-only three-way check; the
Asset response contract is unchanged. Later S3 heads require separate integration
review, including its pending 503 fix, rather than assuming this snapshot final.

`uploadBannerImportAsset(rowId, leaseToken, image)` delegates to the Canonical
`V2BannerImportAssetOperationService`. The adapter's `recoverStored` and `register`
must be invoked outside any DB transaction. `prepareBannerImportAsset` and
`finalizeBannerImportAsset` accept only a validated Import execution capability.
Finalize checks the exact row, operation identity, lease generation and matched
inspection. No generic worker Admin authority or synthetic session is introduced.
The worker checks the current creator state and content.manage, independently of
logout/session expiry. API authentication/MFA is unchanged.

### Durable transaction boundary

1. Validate image bytes through Canonical; reserve UUID and fixed Object Key.
2. Commit an independent short transaction containing operation intent and audit.
3. Inspect Storage outside a transaction. Before the only allowed Put, commit
   `writing` and `put_attempts=1`; recheck permission/lease and heartbeat.
4. Inspect Key/destination, length, MIME, full SHA-256 and ledger identity. ETag
   alone is never sufficient. Commit Asset/idempotency/Row/operation together.
5. Attach using the existing Canonical Banner-version transaction. Resolve the
   ledger only when the Asset is referenced; otherwise retain review evidence.

Nested transactions are rejected at the I/O entry boundary, not treated as
durable commits. A separate DB-connection witness verifies the committed intent
before Put in synthetic tests. `content_banner_asset_operations` has immutable
identity and DELETE rejection; no cascading Job/Row FK. Existing reuse is recorded
as `operation_type=reuse`, not proof of ownership of a newly written Object.

### Recovery and automatic Delete HOLD

Only `prepared` with no prior Put can start a Put. `writing` or uncertain results
are reconciled read-only. Fully matching Objects resume DB finalization with the
same operation/Asset identity, including after URL expiry. Registered Assets are
reused after Banner failure. Terminal rows are not repeated; attempts stay bounded.

Missing Objects after a possibly started Put are **not** permission to write again.
An old delayed writer may still complete. No competing Put, overwrite or Delete is
issued. Mismatch, access denial, timeout and unknown results retain safe reason
codes; after bounded attempts, the row fails while the ledger survives for manual
review. A late Object after the row becomes terminal requires separate review;
this is not an unbounded recovery loop or a claimed cleanup guarantee.

Import failure/recovery/lease handoff/expiry never calls Storage Delete. Existing,
shared and migration-owned Objects are not collected. There is no deletion UI,
prefix deletion, lifecycle policy or operational manual deletion in this Change.
Automatic GC and both resolved/unresolved ledger retention expiry are HOLD until
separate approval. Manual review does not authorize deletion.

### Storage connection prerequisites — S3 reviewer

F2-B uses Laravel Storage, not an AWS SDK upload/delete implementation. The
configured `filesystems.default` disk must be local or S3. A nonempty
`v2_banner_import.storage_generation` is required; an opaque fingerprint binds the
generation, disk and destination settings without adding Bucket/CDN data to Asset
rows. Changing storage destination/generation does not silently redirect recovery.

For S3, the existing disk configuration must specify SDK retries=0 and numeric
HTTP connect_timeout in (0,5], timeout/read_timeout in (0,20]. Missing/unbounded
configuration fails closed. The S3 owner must review these existing-adapter inputs
and credential-resolution/transport bounds before integration. This Change does
not edit Storage config, the S3 provider/ACL hook, resolver, normalizer or middleware.
The stream verifier caps bytes and uses a read deadline in addition to transport
timeouts. A lease expiry always fences DB completion, even during slow I/O.

S3 PR #553 owns its four 503-fix paths, adapter/ACL, storage configuration and
delivery. No unmerged S3 Source is imported. Asset Response Contract is unchanged.
Offline SDK-handler verification is not live S3 ACL, timeout or compensation proof.
Record the latest S3 head and shared Policy/Worklog three-way comparison on the PR.

## Worker, URL TTL and retention

Source command: `php artisan v2:content:work-banner-imports` processes one approved
job per invocation. SIGTERM/SIGINT request stop at a safe row boundary and prevent
new claims. DB lease is 120 seconds; heartbeats occur before bounded work phases.
One operational supervisor is required per shared DB, with DB claims as an
additional guard. Actual supervisor, network, shutdown grace and schedule remain
unconfigured pending Runtime GO.

The independent `--purge-only` path removes expired encrypted URL secrets even
with the feature OFF and without Storage or external requests. URL TTL remains
1,800 seconds using Laravel Crypt. No raw CSV, URL/host or cookie/session token is
stored in the operation ledger or returned/logged. Job/Row results last 30 days;
purge preserves all operation records and marks unfinished expired history for
manual review. Backups may outlive live secret deletion; backup retention remains
an operational decision. Failed CSV blanks image URLs and preserves formula safety.

`v2_banner_import.enabled` stays strictly false by default, including missing or
invalid configuration. Flag OFF prohibits claim/row/Fetch/Asset/Banner mutation;
purge is intentionally independent. No operational worker or scheduler is started.

## Migration and verification boundary

Migration `2026_10_13_000084_create_v2_banner_asset_operations.php` is reserved under
the Allocation Lock and created as forward-only Source. 000082/000083 and existing
Asset immutability guards are unchanged. Only task-owned synthetic PostgreSQL
databases/schemas may apply it. DB guard exact inventory/checksum/rollback rejection
and restore evidence remain required. No operational database application is implied.

Tests use isolated Laravel/PostgreSQL, fake Storage, mock DNS/HTTP, and an in-memory
SDK handler. Browser API mocks verify UI only, not Backend safety. Final exact-head
results, prior failures and Required Checks belong on the Draft PR. Previous STOP
was the automatic-GC writer/reference exclusion boundary; Human formally removed
automatic deletion from this scope rather than waiving ordinary upload safety.

### Offline test harness

`scripts/ci/banner_import_isolation.py` creates only task-labelled temporary
containers and an internal network, using already-present test images. It refuses
an API `.env` or cached configuration. The API runs unprivileged, read-only, with
all capabilities dropped, only the task Source mounted, and disposable writable
storage/cache. The new PostgreSQL instance has no host port or operational data.
No host HOME, Docker socket, shared credentials or other worktree is mounted.
Metadata discovery is disabled, credential files point to `/dev/null`, proxies
are absent and DNS is loopback-only. Child processes inherit the same isolated
network. Preflight asserts no default route, internal-only attachments, local
effective Storage, the exact synthetic DB and the default-disabled feature.

Run `start`, then `preflight`, then `migrate`, then `test --evidence <task-output>
--name <run> --filter <focused-filter>` with this harness. Never run these tests
against operational PostgreSQL or a host PHP process with ambient credentials.
PHPUnit additionally forces the local disk; the timestamp regression asserts it
before creating any fixture. Storage Fake tests perform no real Storage API call;
the S3 adapter test supplies an in-memory SDK handler rather than HTTP transport.
The existing 30-day history and all ledger retention rules remain unchanged.

Before isolation was corrected, two timestamp tests reached real S3 transport
through ordinary upload/exception cleanup. Original logs and `SAFETY-STOP.md`
remain outside the public repository. Original Put outcome, credential identity,
request total and real AWS changes remain **UNKNOWN**. This Source task performs
no additional AWS inquiry and does not claim absence of historical AWS impact.
The latest Human GO permits isolated development independently of that separate
operational investigation. Current Security HOLD is not lifted by these tests.

Remaining separate acceptance: S3 adapter/ACL + 503 review, destination and timeout
configuration, live S3/CloudFront behavior, real approved-host egress, runtime worker
and independent purge scheduling, backup/retention policy, OLD Test and Human
browser acceptance. Production/NEW deployment and automatic GC are not authorized.
