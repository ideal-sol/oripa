# LINE-only Production preparation — LINEPROD-20261112

Strict Change / R3 / Application Runtime Activation: deferred. Issue none.
Draft only. Ready/Merge, formal Build and Runtime each await explicit Human GO.
This document contains no private ingress addresses or credentials.

## Fixed release boundary

- API: PR682 `b66e9a817d53dc77c2b327c3ccdd19790e204d57`, tree
  `0ef56df581778c3a00eeb0caf382db874f1d7965`; includes PR665/679.
- Storefront: PR233 `48a6db137645500b4f1f06033e41ee29eba56b0e`, tree
  `7189a9499db2b923e68b5a46e3a84720949d5886`; excludes TOP100 PR235.
- Contract: immutable alpha.52, Artifact13742019147; no repack/publication.
- API-only Platform activation. Existing Admin/Agency Images stay unchanged.
- Preserve Paidy LIVE/public ON, Paidy timer, UnivaPay LIVE, member-rank loop,
  five existing timers, F2-B, GTM, SMS/Mail and all saved business settings.
- LINE reward remains disabled, amount0, expiration180days, no friend URL.

## Preparation blockers, not implicitly waived

Apache mod_remoteip intentionally exposes the real client as REMOTE_ADDR.
PR682 trusts X-Forwarded-Proto only when that address matches the configured
proxy. A Docker Gateway address is not proof of the effective Laravel peer.
Do not remove mod_remoteip, trust client IPs, widen trust or claim HTTPS PASS.
Reused the existing isolated Apache verification on the current API Image:
20 checks / seven synthetic HTTP cases PASS, no Production requests or Build.
Distinct IPv4/IPv6 client addresses survive mod_remoteip. With the exact test
ingress trusted for X-Forwarded-Proto, HTTPS is recognized before address
rewriting but rejected after REMOTE_ADDR becomes the client. This reproduces
the incompatibility; it is NOT a successful Production HTTPS validation.
The unchanged PR682 source therefore has no single ingress IP that satisfies
both current mod_remoteip semantics and the requested scheme trust. Resolve
this explicitly before binding a Production proxy value. No value is
authorized by this Draft. The historical ALB append/ingress assumption still
needs current Human confirmation because the installed role cannot read ALB.

Storefront Platform Authority merge SHA cannot exist before Platform merge.
Its Draft stores null and blocks formal dispatch. After approved Platform merge,
bind the actual full SHA and refresh affected Storefront checks/self-review.
Never insert an OLD Test or historic Paidy Authority SHA to satisfy the gate.

## Canonical Build, after separate GO

Use unchanged platform-production-arm64-artifact.yml with change_id
TRUSTPROXY-20261111, pr_number682 and exact API source above; retain current
Production origin inputs. The existing workflow generates three Images; only
API is eligible for activation. Do not load/activate generated Admin/Agency.
Use the existing Storefront production-artifact.yml with exact PR233 source,
approved Production origins/assets/GTM and new Platform Authority binding.
Build off-host once. Verify archive/manifest/digests/OCI/architecture and stage
without starting services. Formal image IDs are pending, never fabricated.

## Human ENV input

Human supplies LINE_LOGIN_CHANNEL_ID, LINE_LOGIN_CHANNEL_SECRET,
V2_LINE_LOGIN_REDIRECT_URI, LINE_MESSAGING_CHANNEL_SECRET,
LINE_MESSAGING_CHANNEL_ACCESS_TOKEN, V2_LINE_FRIENDSHIP_CHECK_ENABLED,
V2_LINE_LOGIN_BOT_PROMPT and, only after the proxy gate, V2_TRUSTED_PROXY_IPS.
Confirm Login-channel/official-account linkage and exact public HTTPS callback.
Do not copy OLD values, dump ENV, replace whole service ENV or alter rewards.

## Webhook candidate

Add only exact /webhooks/v2/line on the public host, POST-only, necessary
authentication exemption, access/error logging disabled for that location.
Reject nonPOST before upstream; retain application signature verification.
Preserve existing Paidy/UnivaPay locations and remote-IP normalization.
Preserve Apache remoteip mount; add LINE logging exclusion to the existing
Apache log-protection path. Test config syntax and synthetic rejection/log
capture without live provider payloads. Do not install/reload during preparation.
Real signature/receipt/DB persistence acceptance occurs after Human ALB OFF.

## One maintenance window, existing Canonical helper

Reuse the successful Paidy cutover orchestration, creator, PHP security guard,
atomic reference updates, Storefront current-link swap and recovery journal.
Adapt its exact release inputs after formal artifacts exist; do not run its old
Paidy CLI against this release. Latest Human delegates later Migration/Cutover
to Codex; preserve the technical guards without simulating old Human TTY input.
Any executor delegation adaptation must be reviewed/tested before Cutover GO.

1. Verify final sources/images, snapshot target/recovery owner, preserved ENV
   metadata, helper hashes, rollback assets and current ledger/schema.
2. After Runtime GO and Human ALB ON, acquire existing deployment lock; fence
   Admin/manual/external financial writers. Public maintenance alone is not a
   writer fence.
3. Save enablement and active state of all six timers. Stop scheduling and let
   running operations finish. Gracefully stop the member-rank PHP loop through
   its existing SIGTERM control; verify zero loop processes. Acquire F2-B locks.
4. Existing prize-auto-exchange failure is baseline evidence, not an automatic
   rerun/reset/fix. Require no running process, live lease or uncertain external
   effect and independent financial checks. New/unclassified failures HOLD.
   Preserve failed evidence and original timer schedule when restoring.
5. Require a new target-DB Snapshot/backup available under Human confirmation.
   Use existing canonical exact-file pre-reader/receipt/one-shot/post-reader
   sequencing for 2026_11_05_000107_add_v2_line_subject_vault.php ONLY.
   No full migrate, down, retry, ledger edits or data correction. Check Ledger
   106→107 and new columns/constraints/triggers/functions against exact source.
   DDL can acquire strong table locks; timeout/uncertain result HOLDs.
6. Retain old API container and matched ingress config. Create new API via
   Canonical with same-service ENV preservation and approved named additions;
   retain remoteip and Paidy logging mounts. Start through exact image guard.
   Check internal health, client-IP/HTTPS and financial integrity.
7. Swap staged PR233 Storefront atomically; verify Build/Manifest/GTM and health.
   Admin/Agency/SMS/Mail remain on existing Images.
8. Update F2-B image pin, UnivaPay work/reconcile and prize worker container IDs,
   member-rank control Source/Image pins. Paidy remains API-name-bound with its
   dedicated worker ENV. Read back loaded units before restoring six timer
   states and exactly one member-rank loop. Never restore historic OFF/TEST.
9. Human ALB OFF after internal acceptance; Human real LINE roundtrip then
   signature/receipt/persistence, session and duplicate-suppression acceptance.
   Reward remains disabled; no grant is expected or initiated in this release.

## Recovery

Close LINE ingress before an older API can receive LINE traffic. Keep Paidy
LIVE and its protected webhook. Drain/fence before recovery; never two APIs
writing concurrently. Restore this release's captured API/Storefront/ingress
and reference files, not pre-Paidy assets. Verify health before restoring saved
timer/loop states. Retain failed artifacts and journal; no blind re-execution.
Migration107 is forward-only: retain schema and encrypted data. Old-API/schema
compatibility must pass before runtime rollback; otherwise forward correction.
DB restore is a separate Human recovery decision, never an automatic rollback.

## Completion boundary

This is a reviewable plan, not an executable bound release. New artifacts,
proxy compatibility, exact executor bindings and focused offline validation
remain required before claiming NEWPROD_LINE_ALPHA52_PREPARATION_READY.
