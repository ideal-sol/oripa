# OLD Test Draft Preview — DRAFTPREVIEW-20261119

Status: construction Draft; Strict Change / Activation deferred. Human authorized
construction, focused verification, Required CI and Strict review only. Merge,
installation, image dispatch/import, Runtime switch, migrations and Worker or
financial operations require separate Human GO. The committed empty plan list
enables no application PR. Production has no Draft authorization path.

## Authority and reuse

This is the explicit OLD Test exception to the pre-merge activation prohibition
in Governance Revision 3, Release Gates Revision 2 and Autonomous Operations ADR
Revision 1. It changes neither document's formal Merge/Production gates. All five
Required Checks and a fresh exact-head Strict review remain mandatory for merge.
The existing merged-source authority and `preview-image-build.yml` remain intact.

The dedicated main-only workflow uses the same native AMD64 runner, immutable
images, `preview_image_artifact.py` packaging/verification, exact OS Security
Recipe, outer digest, safe extraction, inner checksums and OCI/image verification
as `old-test-main-artifact.yml`. It supports `api-only`, `admin-only`, and `normal`
(API + Admin). It never deploys. Artifact names include `old-test-draft`; they
cannot satisfy the merged-source retrieval path. Provenance records Draft mode,
PR, source/tree, control, plan, early checks, run ID and attempt.

## Approve once per PR, iterate exact heads

Before using a feature PR, merge a Human-reviewed entry in
`manifests/old-test-draft-preview.json` on protected main. Each entry specifies
`task_id`, numeric `source_pr`, exact `branch`, merged `baseline_sha`, `image_mode`,
`migration: "none"`, exact `allowed_paths`, and nonempty `focused_commands`
(argument arrays, executed in payload root without a shell). Include locked
dependency preparation and meaningful tests for the target behavior; a no-op,
zero-test discovery or tests with skipped assertions are not an accepted plan.
The baseline must include the approved test harness. API tests needing a DB must
use a disposable synthetic DB, never OLD Test data. Such a plan may include its
bounded DB preparation; it does not inherit the Full DB integration suite.

Plans permit changes only under selected application roots (`apps/admin/src`,
API `app`, `routes`, `resources`). Schema/migrations, dependencies/locks,
workflows, security recipes and test harness changes cannot enter this fast path.
Renames and a truncated comparison fail closed. Migration work follows the
existing separately approved forward-only route. Source-only checks cannot prove
live schema compatibility: existing runtime migration inventory checks also pass.

For each new Draft head, dispatch `old-test-draft-preview.yml` **from main** with
Task, PR and full `source_sha`. Mode comes from the approved plan, not arbitrary
dispatch input. GitHub must report the exact open internal Draft PR and main
base. Reuse successful exact-head `policy-gate` and `security-gate` evidence;
check App, workflow, event, head and latest check result. Run the approved focused
commands, build only selected images once and verify the Artifact. Full DB CI,
quality-gate, integration-gate, ci-gate and squash merge need not finish for Draft
preparation. Focused validation replaces general quality validation here, avoiding
an additional full Frontend Build before the selected formal image Build.
Revalidate PR/main before publication and again during retrieval/preflight.

Reuse a nonexpired successful Artifact for the same head/control/plan; list
before dispatch and do not build it again. A new head requires new focused and
security evidence and new image identity. PR/control-scoped BuildKit layer cache
reuses unchanged layers; cache is never authority and cannot replace tests or
image verification. Current-main drift invalidates old authority; fail closed
and revalidate rather than silently promoting evidence from another control.

After the reviewed wrapper is separately provisioned, retrieval uses
`old-test-draft-artifacts TASK PR HEAD` and
`download-old-test-draft-artifact TASK PR HEAD ARTIFACT_ID DESTINATION`.
Both retain the existing root-private Strict Task Policy, outer digest, run
identity, provenance and exact inventory guards. The source validator comes from
the control Git object. The installed wrapper is not changed by this PR.

The existing deploy helper supports `prepare --draft-preview` and dry-run only,
with the existing exact artifact/source/baseline inputs. No migration or
Storefront selection is accepted. Direct apply/rollback through its CLI is held:
the separately bound Canonical Executor owns effects and Recovery.

## Runtime acceptance and previous HOLD protections

Source merge alone never enables Runtime. Before the next exact Runtime GO,
bind the existing host-private Executor/Adapter/Gate to this Draft authority and
the reviewed helper, retaining their hashes in the exact envelope. This binding
is a separate installation task; no private host code is copied into this public
repository. If that Executor cannot consume Draft mode, HOLD before stop. Do not
invoke the helper directly or reinterpret Draft images as merged images.

The exact candidate must bind the Artifact digest/provenance, Source/Tree,
Control/Recipe, selected image IDs, old images and full retained service set.
Existing OS/package vulnerability scan, SBOM/provenance and native dependency
evidence remain mandatory for each selected real image, including Admin. Early
source security CI is not an image security scan. No empty Gate set or invented
receipt is acceptable. Keep the Gate's 300-second TTL and fresh measurements;
cache/test evidence never renews a receipt.

Preserve these existing Executor requirements during that bounded binding:

- Health uses the approved Host header and exact OLD Test origin. Check Basic
  authentication challenge on public HTTPS and the approved authenticated probe;
  never log credentials or accept a login page as API health.
- Journal/evidence parents are root-owned 0700, files 0600, non-symlink and outside
  repositories. Preserve immutable candidate versus separate progress journal.
- Run isolated startup/health against the real selected image, without public
  ports or production credentials; verify image ID, native modules and networks.
  Private API readiness precedes egress; full health follows it.
- Preserve financial Fence/Drain, deployment lock, writer inventory and existing
  Worker/Timer pause/resume rules. A Draft plan never grants financial mutation
  or Worker authority. Admin-only leaves API and workers unchanged.
- Recovery restores the retained approved images/config using the same health
  and Basic checks. No automatic DB restore, down migration, vulnerable-image
  fallback or retry of an uncertain effect.
- Preserve bounded phase/reason diagnostics without raw exception, response,
  ENV or credentials. Exception diagnostics must not weaken fail-closed behavior.

The new source authorizer emits only an exception class on unexpected failure.
Negative authorization regressions run in Required CI. Live Host/Basic/Journal,
isolated-image, Gate receipt, Fence/Drain and Recovery acceptance must be executed
against the separately reviewed installed binding before Runtime GO; this source
PR does not claim that operational acceptance.

## Next Human GO

Review the construction PR's exact Head/Tree, focused tests, Required Checks 5/5
and fresh Strict review. Then separately authorize merge and bounded wrapper /
Executor binding installation. Approve a per-PR focused plan and formal Artifact
preparation before reviewing the exact Runtime candidate. Runtime switching,
migrations, Worker and financial operations each retain their explicit GO scope.
