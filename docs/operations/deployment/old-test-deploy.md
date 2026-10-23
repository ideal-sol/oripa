# OLD Test deployment

Status: Source implementation / deferred Runtime acceptance. This runbook does
not authorize execution. Source review/merge and one exact-candidate OLD Test
Runtime GO are separate. No Production, feature activation, grants, provider
requests, ENV, S3, Nginx, or CI configuration changes belong to this command.

## Prepare once

Use the existing Human-managed root execution path. Keep source checkouts and
dependencies prepared outside this command. Reuse the reviewed Source Authority,
approved merged Platform payload, compatible exact Storefront SHA/client/testkit,
and canonical OLD Test Artifact described in [preview-image-build.md](preview-image-build.md).
Do not dispatch another build for an already usable Artifact.

`prepare` creates only one root-private candidate JSON in an existing root-owned
0700 directory outside the repositories. It does not download, extract, build,
load, stop services, back up, migrate, or install dependencies. Supply an existing
root-private Artifact directory previously obtained through the formal
`download-old-test-artifact` wrapper. If it is absent, expired, or rejected, HOLD;
obtaining an Artifact is a separate existing preparation operation, not an
implicit permission to write files from `prepare`.

```bash
python3 scripts/ops/old_test_deploy.py prepare \
  --task-id <DEPLOY_TASK> --candidate <PRIVATE_DIR>/candidate.json \
  --artifact-directory <FORMALLY_DOWNLOADED_DIRECTORY> \
  --artifact-id <ID> --artifact-digest sha256:<DIGEST> \
  --platform-source <EXACT_APPROVED_PAYLOAD_CHECKOUT> \
  --current-platform-source <EXACT_CURRENT_DB_BASELINE_CHECKOUT> \
  --services api admin
```

For Storefront add `storefront` to services and supply `--storefront-source`,
`--storefront-sha`, and `--storefront-unit`. A Storefront-only candidate uses
`--services storefront` with only those three Storefront inputs, Task and candidate
path: omit all Platform source and Artifact options. No Platform Artifact lookup,
expiration check, download or image load occurs; API/Admin, SMS and F2-B are untouched.

Only two exact existing tracked Runtime differences are accepted: the single
`allowedDevOrigins: ["test.luxe-pack.biz"]` addition and the `next dev` generated
`.next/dev/types` references in `next-env.d.ts`. Their hashes are bound to the
candidate. Prepare the same reviewed overlay in the new checkout beforehand;
the deployment script never edits or cleans either source checkout. Other
tracked differences, untracked source or missing dependencies cause HOLD. The
unchanged vendored-contract verifier runs from the selected checkout during
apply before service stop; its temporary extraction is outside prepare.

The candidate fixes Task, source PR/SHA, Source/Control/Recipe Authority, Artifact
ID/digest/provenance/file fingerprints, selected services, ordered migrations,
old image/container identities and Compose configuration fingerprint, and
applicable Worker/Timer/Storefront rollback state. Secrets are never serialized.
Current-main authority drift and expiration remain governed by the existing
Wrapper. There is no offline exception or Artifact retention extension.

Prepare relies on the private formal download record and read-only fingerprints;
the canonical full archive verifier runs again during `apply`, before stopping
anything. Its temporary decompression is deliberately outside `prepare`.

## One Runtime GO and apply

Review the candidate and returned `candidate_identity`. The single Human GO must
identify that SHA256, services, ordered migration files, existing Worker/Timer
pause/resume and F2-B Image pin update, and the bounded rollback below. Passing
`--go` records that separately granted authority; knowing a hash alone is not
Human approval. A Source Merge, old report, or dry-run is never Runtime GO.

```bash
python3 scripts/ops/old_test_deploy.py apply \
  --candidate <PRIVATE_DIR>/candidate.json --go <APPROVED_CANDIDATE_IDENTITY>
```

All three commands accept `--dry-run`. Dry-run performs read-only preflight only;
it never loads an image, writes a candidate or override, stops/starts a service,
backs up, migrates, or runs HTTP readiness probes that could compile `next dev`.

Apply revalidates the candidate, current authority/Artifact and current Runtime
before changes. It uses the exact control-bound canonical verifier and its
host/decompression/OCI helpers, loading only selected missing images. It checks
candidate image environment continuity before stopping anything. No rebuild,
pull, republish, or new artifact format is introduced.

API changes pause both existing F2-B timers, wait for worker/purge completion,
then hold their existing common lock. The script stops only changed services;
Migration additionally stops Admin and the existing SMS service. It preserves
the independent SMS image. Admin starts first; API starts private-only, passes
internal readiness, then rejoins its existing egress network and passes full
Docker and HTTP health. The generic Preview
egress helper is not used for API8621. Original Compose chain, environment,
ports, mounts, private addressing and networks remain authoritative.

Every private-network health poll verifies the selected immutable image ID and
the recorded private network set before API egress is connected. A healthy
container with another image, an unexpected network, or prematurely attached
egress causes HOLD. The same check applies when switching to the approved old
image during rollback. Network IDs and running state are also checked before
and after each HTTP probe; post-connect image/network checks remain in place.

Private readiness calls the existing `/api/health` inside the selected API
container. It requires exactly the known structured response, a timezone-bearing
timestamp, and `app`, `db`, and `redis` equal to `ok`. HTTP 200 requires Storage
`ok`. HTTP 503 is accepted only at this private stage when the preserved
`FILESYSTEM_DISK=s3` configuration reports Storage `error` and all internal
checks are `ok`. This is internal readiness, not complete health. Unknown fields,
duplicate keys, malformed responses, unexpected status codes or internal
dependency failures HOLD. Only connection-refused startup responses are retried;
other transport failures HOLD. The bounded 30-second probe covers the existing
20-second Storage timeout; no Storage setting, ENV or API is modified.

After egress, the same structured response must be HTTP 200 with all four checks
`ok`, and Docker must report `healthy`. Storage failure never counts as release
success. Existing final API/session/Admin acceptance remains mandatory. Rollback
uses the same two-stage readiness and image/network identity checks.

Storefront stops its existing systemd unit and starts the prepared exact source
on the same loopback port 3241 using `next dev`. There is no parallel process,
new port, production build, Nginx edit or reload. The recorded old definition can
be recreated under its recorded old unit name even if systemd unloads its transient
unit. Rollback reuses an available old unit only after checking its recorded user,
working directory, command, public environment, restart/kill policy and stop timeout;
an inactive matching unit is started, not recreated. Conflicting unit definitions
HOLD before stopping the new Storefront. New deployment unit-name collisions HOLD
during preflight. Neither source overlay is deleted or rewritten.

F2-B's existing runner keeps all logic, schedules and lock behavior; only the
exact `IMAGE` constant follows the verified API image. Only previously active
SMS/timers are resumed. Minimal acceptance checks selected OLD Test HTTP
surfaces and Docker API readiness. No authenticated action or business/provider
smoke is performed. Human browser acceptance remains separate.

Storefront HTTPS health allows up to 12 retries at five-second intervals within
a 90-second retry window so `next dev` can become ready after a transient 502.
Each transfer retains its 120-second limit and the command retains its 300-second
outer timeout; the retry window does not interrupt an in-flight transfer.
HTTP 200 remains mandatory. API/Admin retry settings are unchanged; no
`--retry-all-errors`, authentication exception or health skip is used.

## Migration and recovery

With no `--migration`, there is no backup, migration, DB writer-wide stop, or DB
smoke. For API/Admin, PHP migration inventory must still match the declared baseline;
README.md and other non-PHP documentation are not migrations. Historical PHP bytes
and the exact approved additions remain checked. Storefront-only needs no Platform baseline.

For an approved migration candidate, repeat `--migration <EXACT_FILENAME>` in
approved order and select API. Admin is fenced as a DB writer and restarted with
its old image unless an Admin image update was explicitly selected. API-only
Artifacts do not require rebuilding or replacing Admin. The candidate source must contain exactly
baseline migrations plus those files, with unchanged historical bytes. Existence
of 000088 or later is never approval. Incompatible or partially approved source
inventories HOLD; this command does not synthesize a reduced Guard inventory.

The adapter calls unchanged canonical `validate_values`,
`assert_database_target`, `schema_inventory`, and `validate_schema_inventory`
functions with the existing OLD Test Compose/secret-continuity inputs. The
generic `persistent`/`smoke` commands are not deployment commands and are never
called. Canonical full-set/schema rejection remains HOLD, not a bypass.

After writer fencing and pre-Guard, backup uses the established file-backed
`pg_dump --format=custom --no-owner --no-privileges --file` inside the DB container,
full `pg_restore --file=/dev/null` archive parse, then copies to private storage
and records SHA256. It never streams the dump through captured stdout. Full
archive parsing is not a restore rehearsal. Keep the container-side private dump
and protected local backup until the existing retention procedure disposes of
them. Apply each approved file through the verified API image's existing one-shot
Compose path, then run the canonical target Guard. No migration number is
allocated by this command.

```bash
python3 scripts/ops/old_test_deploy.py rollback \
  --candidate <PRIVATE_DIR>/candidate.json --go <APPROVED_CANDIDATE_IDENTITY>
```

Before service stop, failure leaves the existing app in place; after timer
fencing, rollback restores only the previously active timers. After switching,
rollback restores retained old image IDs, Storefront source/start definition and
matching F2-B pin. Old images and source directories are never deleted.

After any attempted Migration, rollback requires a completely verified target
schema and previously reviewed old-app compatibility recorded during prepare
with `--rollback-schema-compatible`. Migration 000097/000098 candidates cannot
opt into old-writer rollback with that flag: after an attempt they require
forward-only recovery review. Partial/uncertain migration or unknown
compatibility HOLD with services stopped for investigation. Schema stays forward;
there is no automatic DB restore or down migration. Backup/Guard/switch/health
failure never unconditionally resumes workers in a `finally` block.

Candidate progress is retained in the same JSON. Do not re-run `apply` on an
attempted candidate or edit progress to bypass a failure. Review its phase and
use bounded rollback or an independently reviewed forward recovery.

## Offline verification

```bash
python3 -m unittest tests.ops.test_old_test_deploy
python3 -m unittest tests.ops.test_old_test_source_authority tests.ops.test_preview_image_pipeline
```

These tests do not connect to Runtime. The Git regression reads real immutable
payload `b5c28015ef94cb80f6702a61b1237cacd91d9863` (87 PHP migrations plus README)
and base `77723309fdd9f27039f7b5cbe804fa3e2a50fbc1` (92 plus README); both Git
objects must be available. It materializes only temporary test-owned files and
references the existing object store read-only; no checkout or index is changed.
Live systemd, network, Artifact import,
backup/migration and browser acceptance require a future separate Runtime GO.

## OLDDEPLOY-205 Source and installed Executor boundary

The repository helper already selects API/Admin from the canonical OLD Test
AMD64 Artifact. Its wrapper revalidates GitHub Artifact identity/expiry and
Source Authority; the control-bound verifier checks archives, OCI revision and
image ID before service stop. It never promotes a locally patched emergency
image to canonical Artifact authority. Loading a selected image uses the
verified archive only, and Compose retains `--no-build --no-deps --pull never`.

Source review on 2026-10-23 also found a separate, host-local execution layer:
`oripa-old-safe-deploy/executor.py`, its `p2-adapter-implementation/adapter.py`,
and `local-gate-implementation/gate.py`. These are not tracked by this repository.
The installed Executor requires `local_exception` / Option B API authority and
chooses that API image instead of the candidate's canonical API image. Its API
switch connects egress before waiting for health. Neither behavior is changed
by this Source PR. Do not invoke the repository helper directly to bypass this
installed security layer or replace installed bytes from this Draft.

The actual receipt issuer is host-local `Gate.verify`; the validator is
`Gate.validate_receipt`, called by Executor `Session.check` and the adapter.
After measurements and input revalidation, issuance sets `observed_at` to the
current clock and `expires_at` to the earlier of `observed_at + 300` and request
expiry. Validation requires `observed_at <= now < expires_at` and a positive
lifetime no longer than the policy TTL. Exactly 300 seconds is expired, not a
grace period. Acquisition, archive verification, migration and health time do
not restart this clock.

Receipts bind policy/request/candidate/Gate hashes, candidate identity,
selection identity (including Source/Image), operation and authority mode.
The request also binds Artifact ID/digest/authority/files, Control and recipe.
Existing receipts are validated rather than renewed by `Session.check`.
Historical acceptance evidence records `receipt_expired` after HTTP acceptance;
this is an elapsed-time HOLD, not permission to extend TTL or reuse the receipt.

The OLDDEPLOY-205 private offline harness loads the exact installed Gate bytes
read-only, mocks inputs/probes/clock, and blocks subprocess execution. It covers
299.999/300/300.001 seconds, future issuance, acquisition delay, changed binding,
wrong Source/Image, request expiry, and new verification versus expired receipt
reuse. This is method-level evidence only: it does not verify real policy inputs,
issue an operational receipt, approve Runtime, or prove a refresh integration.
It is deliberately not a host-dependent GitHub test or a copied/new Gate.

The subsequent integrated-fix GO authorizes a root-private proposal for the
existing host-local Executor/Adapter, kept outside this public Source PR. It
selects canonical API/Admin images, reuses verified archive loading before Gate
measurement, keeps an immutable candidate and separate progress journal, drains
F2-B before taking its existing lock, and avoids Backup without migrations.
Receipt checks surround operations and each readiness poll, service/network
switch, worker resume and completion. Only an honestly expired, originally
bounded receipt can trigger fresh verification by the unchanged Gate; binding,
request or authority failures remain HOLD. A fresh result is validated again
across all required roles. Irreversible operations are never automatically retried;
the journal distinguishes successful commands from unknown outcomes.

Direct Adapter deployment dispatch is held because P2 progress mutation would
invalidate the original Gate request's candidate binding. Canonical deployment
uses the existing Executor's exact envelope; independent worker review remains
verification-only. This proposal is not installed or enabled by Source changes.
Its exact byte hashes, differences and isolated regression results belong to the
separate private handoff. No host code, receipt or private evidence is committed.

Before a future OLD Test release, separately authorize Source Merge and the
reviewed host proposal's installation, then prepare final Source/Control/Artifact
authority and a fresh exact candidate. Preserve the existing Gate and 300-second TTL;
an expired receipt requires fresh measurement/revalidation through that Gate,
with unchanged Source/Image/Artifact bindings and applicable locks. Do not add
automatic exception renewal or retry a consumed candidate. Review the exact
patched current images as rollback assets; canonical provenance alone does not
permit returning to vulnerable PHP. Bind final approved Source/Control through
the separately reviewed manifests, then obtain the formal Artifact and exact
candidate Runtime GO. Source merge, installation, Artifact dispatch, operational
DB work and member-rank Activation remain separate, unperformed operations.
