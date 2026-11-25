# OLD Test deployment

## Simple deployment

Phase 2 introduces a source entry for OLD Test. Canonical governs every live
deployment until explicit adoption. During coexistence both entries use the
existing exclusive deployment lock. Commercial Production retains its own
Authority, Security and Release Gates. Ordinary feature PRs, Required Checks,
lane-specific review and squash merge follow the existing development rules.

After adoption the operator prepares a plan for a merged change, for example

```text
oldtest-deploy api admin --session OLDTEST-EXAMPLE --prepare --platform-source PR@FULL_SHA --contract sha256:DIGEST
oldtest-deploy api admin --session OLDTEST-EXAMPLE
```

The second command requires the Human GO for the first command's plan.
Select `api`, `admin`, `storefront`, their combinations, `all`, or
`worker NAME`. Use `--storefront-source PR@FULL_SHA` for Storefront, and
repeat `--migration FILE.php` for each reviewed additive migration in order.
The contract digest identifies the approved published manifest. Validate the
selected application and the retained counterpart against the formal manifest,
client, testkit, API compatibility and actual installed dependencies. Use the
approved alpha.54 release for the existing installation.

The private, immutable plan records targeted repository/PR/SHA, recipes,
contract, previous identities, config fingerprint, Worker states, migration
and recovery scope. The journal records fixed-head checks, review comment,
tree, artifact/run IDs, digests, intent and observed results. The Human-only
approval channel writes `go.json` containing `session`, `plan` (the displayed
digest), `decision: GO`, UTC epoch `issued` and `expires`. The maximum
session is one hour. The deployment caller cannot issue GO. One GO covers
acquisition, necessary build, selected effects and bounded health retries.
Recheck a changed configuration, identity or expired approval before further
effects. Keep the existing session journal when renewing the same scope.

Reuse formally admitted local images after checking OCI revision, image ID,
architecture, recipe, contract and current target security. Remote expiration
applies to new downloads. On a cache miss, first find an existing matching
formal artifact or in-flight run. Dispatch the existing AMD64 workflow once
for the missing API/Admin selection. The opt-in path requires the adopted
dispatcher identity and approval digest. The default workflow path continues
to require Canonical Authority. Stage Storefront using the established
unprivileged build, real dependency layout, pinned Node and systemd
`next start`. Preserve the current release until staged readiness passes.

Perform additive migrations against the verified OLD Test database and
reconcile source inventory with the ordered ledger. Any unplanned pending
migration, changed historical migration, unknown outcome or destructive
operation needs reconciliation. Without declared migrations verify schema
compatibility from pinned inventories without touching the DB. Drain only the
declared writers. Take a backup only if declared. Apply each pending file
before switching API. Preserve provider/notification fences, existing ENV and
financial invariants. Financial pending IDs, historical totals and quota
counts are outside the ordinary release gate.

When both are selected, establish Admin process readiness before switching
API. Update running API image/pin consumers. Verify active exec-oneshots on
their existing schedule. Leave independent SMS and inactive services alone.
Check the sender lock has been released before resuming LINE. A running
oneshot can temporarily have no next elapse. A successful business rejection
of an expired reservation is a valid job outcome. Internal HTTP 200,
API app/DB/Redis/storage, effective proxy mapping and the existing external
TLS/Basic 401 challenge constitute service health. Browser actions remain
with the Human.

### Host integration and acceptance

The Canonical Executor consumes an exact execution envelope. The installed PHP
verifier instead takes a policy and a verification request. For each selected
PHP operation the simple entry prepares that request, binding the GO, source,
artifact, image, recipe, logical target and session deadline. Enroll
`php-gate-inputs` to supply approved evidence and independently pinned Gate,
template and recipe bytes. Enroll `authorize-php-request` in the private
authority to verify the Human GO and the exact effect and issue the policy
grant for the computed request hash. The entry checks that all other template
constraints are identical, then calls native `verify` and `validate_receipt`.
The private journal records the binding and receipt digests. A denied image,
absent grant or changed scope holds. Receipt expiration requires fresh
measurement inside the original GO. Canonical policy files stay read-only.

Phase 4 enrollment requires the following checks before enabling the entry.

| Binding | Acceptance before enrollment |
| --- | --- |
| GitHub App | Enroll `github-read` with the read-only App installation for each selected repository. The entry permits only the fixed metadata and pinned content endpoints used for source/contract/artifact checks. Enroll the fixed OLD Test workflow, main ref, plan digest and approved inputs. Existing Canonical dispatch accepts only task, PR and SHA. Enable the scoped dispatcher and workflow opt-in together after review. Adopt an in-flight run by identity. The fetcher must stage the private ZIP by artifact ID and verify the GitHub digest, with no caller-selected URL or destination. |
| Admin and independent SMS | Enroll target-specific security measurement. Historical Admin scan/SBOM/native-runtime evidence and SMS exceptions have release-specific authority. Bind fresh measurements to the selected identity and effect. |
| Worker | Observe each actual unit independently. Rebind D4 and both UnivaPay consumers to the new API container ID. Verify the expected API image used by the F2B worker and purge runner separately. Name-based prize exchange and scheduled publication use their next scheduled run. Preserve every inactive unit. Inspect process health independently of business warnings. |
| Storefront | Verify the staged release manifest, build ID, installed pnpm links, contract packages, Node and proxy routing. Bind staged health to the selected directory, listener and service. Prove that the old unit can be started again, including its transient unit definition, before stopping it. |
| Coexistence | Install disabled on OLD Test after separate approval. Exercise the registered private operations with isolated resources, including the App grant and target-specific verifier. Confirm both entries honor the same lock and can read the retained recovery assets. Registration requires no application restart. |

Normal releases use zero release PRs, zero release-specific CI runs, one scoped
GO and zero manually prepared candidates or envelopes. A cache miss needs one
Platform workflow for the selected images and at most one Storefront build.
The entry serializes each session independently while it checks GitHub, obtains
artifacts or waits for a build. It takes the common lock for the local plan
snapshot, a required Docker import, and the runtime transition. After acquiring
the transition lock it rechecks host state and each retained identity before
any switch. Brief contention gets a bounded retry. Resume the same session
to reuse its verified artifacts. Enable cold acquisition only after verifying
the scoped dispatch and adoption of an existing build.
Resume only observed safe steps. To return to Canonical, close or reconcile
outstanding intents and release the shared lock. Use the retained source/image
only after checking schema compatibility. An applied migration needs its own
database recovery decision.

Launch only the reviewed, protected installed code with a clean Python and
command environment. Keep private state and GO under the operator's ownership.
Never grant the deployment user Docker group membership or general sudo.
The private site boundary is deliberately separate from the public repository.
Its fixed operations are defined by `Site.call` in
`scripts/ops/old_test_simple.py`. At adoption install the small source
bundle and the reviewed private procedures in its `private/` directory.
The root-private `/etc/oripa-old-test-simple/bindings.json` contains
`environment` (`old-test`) and an `operations` map. Each entry selects
a local module basename, its `sha256` digest and a named `function`.
The caller cannot supply any of these. Bindings are pinned in the plan.
Each enrolled function takes a copy of the scoped plan, the logical target,
operation data and a session-check callback. Only read-only proposal receives
an empty callback. Check the session immediately before every private effect,
bound subprocess timeouts to the approval window and return sanitized data.
Use actual private measurements for the Security and environment gates.
Phase 2 includes the coordinator, artifact admission, selected
Compose/systemd switch, migration command and HTTP probes. Existing private
Canonical executors consume exact envelopes and cannot be substituted for
this interface. A missing binding holds before a switch.

| Site operation | Required observation or effect |
| --- | --- |
| propose, preflight | Measure OLD Test host identity, selected runtime/config/ENV, private networks and mounts, TEST provider/notification fences, effective proxy, capacity, inodes, compatibility and Canonical entry inactivity. Read only the relevant worker inventory. Bind old state and approved recipes. |
| find-asset, verify-retained, cache-admission | Return a protected previous admission, a formally downloaded archive ID, or a cache miss. Accept cache writes only from the trusted entry after inner/outer verification and local OCI inspection. Verify Storefront vendored packages, actual dependencies and build identity. |
| ensure-formal-build, prepare-build | Use only the approved fixed GitHub dispatcher/download operations or the existing Storefront builder. Adopt the same run on resume. Distinguish an expired remote artifact, a verified local image and an unknown dispatch. |
| security, switch-binding, database-binding | Measure the actual selected image/build, policy and configuration, bound to the GO and session deadline. Check mount/network/ENV and staged units immediately before effects. Verify actual database identity, non-destructive classification, next ledger file and required writer drain. |
| observe, migration-inventory | Reconcile actual before/after identity, ordered ledger and effects. Return done only on positive evidence. Partial, conflicting or ambiguous state holds. Read-only ledger commands that need Artisan JSON use `SHELL_VERBOSITY=0`. |
| drain, resume, switch for registered workers, exec-binding, worker-states, worker-lock-released | Permit fixed registered actions only on their approved existing runtime. Revalidate running/held state. For a pin bind the actual new API container/image. Limit sender lock ownership to the drain and release before resume. |
| health | Prove effective local proxy routing/listener ownership for the selected role, or query the registered worker/timer process, last result and next trigger. Provide only the sanitized metadata required by the entry. |

Before adoption exercise these bindings offline against the actual private
interfaces, including source review format, GitHub App permissions, migration
ledger, retained units, Gate measurements and the common lock. Keep the
simple workflow switch disabled until this passes and Human approves installation.
No host procedure may accept caller-provided paths, ENV, shell or units.

After an interrupted effect, resume the same session. Adopt a measured
successful effect. Hold an ambiguous switch, build dispatch, migration or
financial effect for reconciliation. Preserve successful target results and
verified builds. For fallback, finish or reconcile the in-flight operation,
disable the new entry and use Canonical under the same lock with its existing
GO. Reusing the previous app image requires verified schema compatibility.
Database restore, reverse migration and out-of-scope ENV changes need a
separate Human decision.

Keep current and necessary previous images/releases, stopped-worker inputs,
all in-flight development/Production Authority assets and referenced evidence.
After success retire unreferenced completed worktrees, duplicate ZIP/tar
files, temporary verification copies and task-local Trivy DB copies. Retain
only needed backup generations and the small source/digest/journal/handoff
records. Review storage at 80% usage. Cleanup failure is a maintenance item
after technical PASS. The Docker volume deletion prohibition stays in force.

## Canonical deployment

Status: Source implementation / deferred Runtime acceptance. This runbook does
not authorize execution. Source review/merge and one exact-candidate OLD Test
Runtime GO are separate. No Production, feature activation, grants, provider
requests, ENV, S3, Nginx, or CI configuration changes belong to this command.

## Node 22.23.5: Admin-only preparation

The protected OLD Test Workflow, Source Authority validator, archive verifier
and candidate helper support `image_mode=admin-only`, `platform=linux/amd64`.
This mode is valid only for OLD Test. It builds/packages exactly Admin, skips
the API recipe and API Build, rejects extra images, and retains protected-main,
merged Source/Tree, Required Checks and `activation_authorized=false` validation.
It does not change the current approved-source manifest or Production Authority.

Both Admin Docker stages use the multi-platform Node22.23.5 Alpine index
`sha256:1314fb30ec37592d5845e5a160d522c0e680972aa534549940b8d73bde88481a`.
Registry metadata resolves linux/amd64 and linux/arm64 to Node22.23.5 and
Alpine3.24.4. The new Alpine image must still pass the existing image security
scan and isolated startup/health/native-module checks after formal Build;
metadata resolution is not runtime acceptance. No pnpm/Next/React/Lock update
is part of this change. Published alpha.52 package engine pins remain historical.

Use `--services admin` and no migrations. The helper stops/switches only Admin;
it does not fence API writers, touch timers, rebind F2-B/UnivaPay or change
Storefront. Preserve all nonselected services in the outer Canonical exact
envelope and validate their unchanged identity before and after each effect.

The only permitted image ENV difference for this Node maintenance is an explicit
candidate member (included in its identity, never added after approval):

```json
{"image_environment_changes":{"admin":{"NODE_VERSION":{"before":"22.22.3","after":"22.23.5"}}}}
```

It requires Admin-only/no migration and exact old/new image IDs. All other ENV
keys must remain identical. The inverse mapping applies only to the preserved
old image during independently authorized recovery. No blanket ENV exception.

The installed host Executor remains a separate hash-pinned security boundary.
Source merge alone does not install or enable it. During formal preparation,
bind its Admin-only selection to the reviewed candidate helper, exact new
Source/Control/Artifact, deployment lock, fresh Security/Artifact observations,
TTL300 receipt and old-image recovery. The existing PHP Gate cannot be claimed
as Node security verification. Reuse the existing image scan for Admin and
require its real successful evidence; no empty Gate set, invented receipt or
direct helper `apply` is an alternative to the installed Executor. If the
installed exact-envelope path cannot authorize this selection, HOLD before
any stop and complete its bounded reviewed binding first. Do not use normal
mode to work around that boundary by rebuilding or restarting API.

After Merge GO: bind the actual Squash SHA in one OLD Test Authority update,
Build Admin once, verify image architecture/OCI Source/Node22.23.5/Alpine3.24.4,
image scan and Next health, then prepare the exact envelope with all retained
services and rollback identity. Runtime remains subject to separate Exact GO.

Agency is a separate follow-up: retain its current immutable Source, package
and Lock hashes and image in private evidence; build from that exact application
tree with only its two Node base lines (and any actually needed engine assertion)
changed. Do not build Agency from current main, which changes its Next/UI/Lock.
Old Frontend and shared-host Preview Node processes remain unpatched by this
Admin/Storefront task. Current NEW Production and its pinned Artifacts are
unchanged; future common Source Builds use the new base after separate approval.

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
