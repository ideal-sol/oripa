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
`--services storefront`. It does not stop API/Admin, SMS, or F2-B.

Only two exact existing tracked Runtime differences are accepted: the single
`allowedDevOrigins: ["test.luxe-pack.biz"]` addition and the `next dev` generated
`.next/dev/types` references in `next-env.d.ts`. Their hashes are bound to the
candidate. Prepare the same reviewed overlay in the new checkout beforehand;
the deployment script never edits or cleans either source checkout. Other
tracked differences, untracked source, missing dependencies, or an unverified
vendored contract cause HOLD.

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
Docker health, then rejoins its existing egress network. The generic Preview
egress helper is not used for API8621. Original Compose chain, environment,
ports, mounts, private addressing and networks remain authoritative.

Storefront stops its existing systemd unit and starts the prepared exact source
on the same loopback port 3241 using `next dev`. There is no parallel process,
new port, production build, Nginx edit or reload. The recorded old definition can
be recreated even if systemd unloads its transient unit.

F2-B's existing runner keeps all logic, schedules and lock behavior; only the
exact `IMAGE` constant follows the verified API image. Only previously active
SMS/timers are resumed. Minimal acceptance checks selected OLD Test HTTP
surfaces and Docker API readiness. No authenticated action or business/provider
smoke is performed. Human browser acceptance remains separate.

## Migration and recovery

With no `--migration`, there is no backup, migration, DB writer-wide stop, or DB
smoke. Source migration inventory must still match the declared baseline.

For an approved migration candidate, repeat `--migration <EXACT_FILENAME>` in
approved order and select API and Admin. The candidate source must contain exactly
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
with `--rollback-schema-compatible`. Partial/uncertain migration or unknown
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

These tests do not connect to Runtime. Live systemd, network, Artifact import,
backup/migration and browser acceptance require a future separate Runtime GO.
